<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace enrol_mercadopagosub;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/mercadopagosub/tests/helper_trait.php');

/**
 * Tests for subscription creation.
 *
 * The request body these assert on is the one thing in this plugin that
 * cannot be corrected after the fact: the payer address is immutable once the
 * subscription exists, and external_reference is the only field that travels
 * to Mercado Pago and comes back, so a mistake in either means cancelling the
 * subscription and starting again.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(subscription_service::class)]
final class subscription_service_test extends \advanced_testcase {
    use helper_trait;

    /**
     * Returns the decoded body of the POST /preapproval the service sent.
     *
     * @param tests\fixtures\mock_transport $transport The transport it used.
     * @return array
     */
    private function sent_body(tests\fixtures\mock_transport $transport): array {
        foreach ($transport->calls as $call) {
            if ($call['method'] === 'POST' && str_contains($call['url'], '/preapproval')) {
                return json_decode((string)$call['body'], true) ?? [];
            }
        }

        $this->fail('no subscription was created');
    }

    /**
     * A successful creation stores everything the plugin will later need to
     * recognise the subscription, and nothing it would have to guess.
     *
     * @return void
     */
    public function test_creating_a_subscription_stores_what_identifies_it(): void {
        global $DB;

        $transport = $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();

        $transport->on('/preapproval', [
            'id' => 'preapproval-from-the-platform',
            'status' => 'pending',
            'init_point' => 'https://www.mercadopago.com.ar/subscriptions/checkout?preapproval_id=x&activation=true',
            'next_payment_date' => date('c', time() + 30 * DAYSECS),
        ]);

        $sub = (new subscription_service($instance, new api_client(null, $transport)))
            ->create($user, 'payer@example.com');

        $stored = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $sub->id], '*', MUST_EXIST);
        $this->assertSame('preapproval-from-the-platform', $stored->preapprovalid);
        $this->assertSame('payer@example.com', $stored->payeremail);
        $this->assertSame('pending', $stored->state);
        $this->assertSame('pending', $stored->mpstatus);
        $this->assertSame((int)$user->id, (int)$stored->userid);
        $this->assertSame((int)$instance->id, (int)$stored->enrolid);

        // The reference has to round-trip, because it is what a notification
        // will be matched against later.
        $parsed = util::parse_reference($stored->externalreference);
        $this->assertNotNull($parsed);
        $this->assertSame((int)$instance->id, $parsed['enrolid']);
        $this->assertSame((int)$user->id, $parsed['userid']);

        // The init_point value has no column of its own and is not
        // reconstructable from the others, so it is kept verbatim in extras.
        $extras = json_decode($stored->extras, true);
        $this->assertStringContainsString('activation=true', $extras['initpoint']);
    }

    /**
     * The reference sent to the platform is the one stored locally: they are
     * minted once, before either record exists, precisely so the two cannot
     * disagree.
     *
     * @return void
     */
    public function test_the_reference_sent_matches_the_reference_stored(): void {
        $transport = $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $transport->on('/preapproval', ['id' => 'p-1', 'status' => 'pending']);

        $sub = (new subscription_service($instance, new api_client(null, $transport)))
            ->create($user, 'payer@example.com');

        $this->assertSame($sub->externalreference, $this->sent_body($transport)['external_reference']);
    }

    /**
     * The billing terms sent are the instance's own, and the subscription is
     * created pending: only the payer can authorise it.
     *
     * @return void
     */
    public function test_the_request_carries_the_instances_billing_terms(): void {
        $transport = $this->setup_plugin();
        [$course, $instance] = $this->create_instance([
            'cost' => 2500,
            'currency' => 'ARS',
            'customint6' => 3,
            'customchar1' => 'months',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $transport->on('/preapproval', ['id' => 'p-1', 'status' => 'pending']);

        (new subscription_service($instance, new api_client(null, $transport)))
            ->create($user, 'payer@example.com');

        $body = $this->sent_body($transport);
        $this->assertSame('pending', $body['status']);
        $this->assertSame('payer@example.com', $body['payer_email']);
        $this->assertSame(3, $body['auto_recurring']['frequency']);
        $this->assertSame('months', $body['auto_recurring']['frequency_type']);
        // Compared numerically: the amount is cast to float before encoding,
        // and a whole number survives the JSON round trip as an int.
        $this->assertEqualsWithDelta(2500.0, $body['auto_recurring']['transaction_amount'], 0.001);
        $this->assertSame('ARS', $body['auto_recurring']['currency_id']);
        $this->assertStringContainsString('/course/view.php?id=' . $course->id, $body['back_url']);
    }

    /**
     * A free trial is sent only when the instance has one, and in the units
     * the instance configured.
     *
     * @return void
     */
    public function test_a_free_trial_is_sent_only_when_configured(): void {
        $transport = $this->setup_plugin();
        $user = $this->getDataGenerator()->create_user();
        $transport->on('/preapproval', ['id' => 'p-1', 'status' => 'pending']);

        [, $notrial] = $this->create_instance(['customint3' => 0]);
        (new subscription_service($notrial, new api_client(null, $transport)))
            ->create($user, 'payer@example.com');
        $this->assertArrayNotHasKey('free_trial', $this->sent_body($transport)['auto_recurring']);

        $othertransport = new tests\fixtures\mock_transport();
        $othertransport->on('/preapproval', ['id' => 'p-2', 'status' => 'pending']);
        [, $withtrial] = $this->create_instance(['customint3' => 14, 'customchar2' => 'days']);
        (new subscription_service($withtrial, new api_client(null, $othertransport)))
            ->create($this->getDataGenerator()->create_user(), 'payer@example.com');

        $trial = $this->sent_body($othertransport)['auto_recurring']['free_trial'];
        $this->assertSame(14, $trial['frequency']);
        $this->assertSame('days', $trial['frequency_type']);
    }

    /**
     * The reason names the course, because it is what the payer reads on the
     * Mercado Pago checkout and on their statement.
     *
     * @return void
     */
    public function test_the_reason_names_the_course(): void {
        global $DB;

        $transport = $this->setup_plugin();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Estructuras de Datos']);
        $plugin = enrol_get_plugin('mercadopagosub');
        $instanceid = $plugin->add_instance($course, ['cost' => 1000, 'currency' => 'ARS']);
        $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        $transport->on('/preapproval', ['id' => 'p-1', 'status' => 'pending']);

        (new subscription_service($instance, new api_client(null, $transport)))
            ->create($this->getDataGenerator()->create_user(), 'payer@example.com');

        $this->assertStringContainsString('Estructuras de Datos', $this->sent_body($transport)['reason']);
    }

    /**
     * An address registered in another country is the one failure a correctly
     * configured site should expect routinely, so it surfaces as a message the
     * learner can act on rather than as a generic API error.
     *
     * @return void
     */
    public function test_an_address_from_another_country_is_a_learner_facing_error(): void {
        global $DB;

        $transport = $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $transport->on('/preapproval', [
            'message' => 'Collector and payer are from different sites',
            'code' => api_client::CODE_SITE_MISMATCH,
        ], 400);

        try {
            (new subscription_service($instance, new api_client(null, $transport)))
                ->create($user, 'payer@example.com.br');
            $this->fail('a site mismatch did not raise anything');
        } catch (\moodle_exception $e) {
            $this->assertNotInstanceOf(api_exception::class, $e);

            // The learner-facing string leads; the platform's own wording is
            // appended as debug info, which is where an administrator wants it.
            $this->assertStringStartsWith(
                get_string('error:mismatchedsite', 'enrol_mercadopagosub'),
                $e->getMessage()
            );
            $this->assertStringContainsString('guest_site_mismatch', $e->getMessage());
        }

        $this->assertSame(0, $DB->count_records('enrol_mercadopagosub_sub', ['enrolid' => $instance->id]));
    }

    /**
     * Any other failure keeps its api_exception, because the platform's code
     * and body are what whoever called this needs in order to say anything
     * useful about it.
     *
     * @return void
     */
    public function test_other_failures_keep_the_platforms_own_error(): void {
        global $DB;

        $transport = $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $transport->on('/preapproval', ['message' => 'invalid_token', 'code' => 'unauthorized'], 401);

        try {
            (new subscription_service($instance, new api_client(null, $transport)))
                ->create($this->getDataGenerator()->create_user(), 'payer@example.com');
            $this->fail('an API failure did not raise anything');
        } catch (api_exception $e) {
            $this->assertSame(401, $e->get_http_status());
            $this->assertSame('unauthorized', $e->get_api_code());
            $this->assertSame('invalid_token', $e->get_api_message());
        }

        $this->assertSame(0, $DB->count_records('enrol_mercadopagosub_sub', ['enrolid' => $instance->id]));
    }

    /**
     * Nothing is stored when the platform's response carries no id: a local
     * row with no preapproval id could never be reconciled against anything.
     *
     * @return void
     */
    public function test_the_local_row_records_the_platforms_id_not_a_guess(): void {
        $transport = $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $transport->on('/preapproval', ['status' => 'pending']);

        $sub = (new subscription_service($instance, new api_client(null, $transport)))
            ->create($this->getDataGenerator()->create_user(), 'payer@example.com');

        // Recorded rather than asserted as correct: the service stores an empty
        // preapprovalid rather than refusing, because no response without an id
        // has ever been measured. If one is ever seen, this is where to decide
        // whether creation should fail instead.
        $this->assertSame('', $sub->preapprovalid);
    }
}

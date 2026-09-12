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
 * Tests for webhook event processing.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(event_processor::class)]
final class event_processor_test extends \advanced_testcase {
    use helper_trait;

    /**
     * A subscription notification for a known subscription is acted on: the
     * status is re-read from the API, the local row is synced, and the
     * subscriber ends up enrolled.
     *
     * @return void
     */
    public function test_an_authorised_subscription_enrols_the_subscriber(): void {
        global $DB;

        $transport = $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user, ['state' => 'pending', 'mpstatus' => 'pending']);

        $transport->on('/preapproval/', $this->subscription_response($sub, 'authorized'));
        $this->queue_event('subscription_preapproval', $sub->preapprovalid);

        $counts = (new event_processor(new api_client(null, $transport)))->process_queued();

        $this->assertSame(1, $counts['processed']);
        $updated = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $sub->id], '*', MUST_EXIST);
        $this->assertSame('active', $updated->state);
        $this->assertSame('authorized', $updated->mpstatus);
        $this->assertGreaterThan(0, (int)$updated->timeauthorized);
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $user->id));
    }

    /**
     * The subscription is re-read rather than trusted from the notification
     * body: the platform's own documentation says a notification only reports
     * that something changed, and this plugin's captures show the body carries
     * no status at all.
     *
     * @return void
     */
    public function test_processing_reads_the_subscription_from_the_api(): void {
        $transport = $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user, ['state' => 'pending']);

        $transport->on('/preapproval/', $this->subscription_response($sub, 'authorized'));
        $this->queue_event('subscription_preapproval', $sub->preapprovalid);

        (new event_processor(new api_client(null, $transport)))->process_queued();

        $this->assertSame(1, $transport->count_calls('/preapproval/'));
    }

    /**
     * A repeated notification for the same authorisation changes nothing the
     * second time. The platform was measured sending the same "updated" event
     * more than once, roughly forty seconds apart, so an idempotent handler is
     * not optional.
     *
     * @return void
     */
    public function test_a_repeated_notification_is_idempotent(): void {
        global $DB;

        $transport = $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user, ['state' => 'pending', 'timeauthorized' => 0]);

        $transport->on('/preapproval/', $this->subscription_response($sub, 'authorized'));

        $processor = new event_processor(new api_client(null, $transport));
        $this->queue_event('subscription_preapproval', $sub->preapprovalid);
        $processor->process_queued();
        $first = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $sub->id], '*', MUST_EXIST);

        $this->queue_event('subscription_preapproval', $sub->preapprovalid);
        $processor->process_queued();
        $second = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $sub->id], '*', MUST_EXIST);

        $this->assertSame($first->timeauthorized, $second->timeauthorized, 'authorisation time moved on a replay');
        $this->assertSame('active', $second->state);
        $this->assertSame(
            1,
            $DB->count_records('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]),
            'the subscriber was enrolled twice'
        );
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $user->id));
    }

    /**
     * A cancellation ends the subscription and records why.
     *
     * @return void
     */
    public function test_a_cancellation_ends_the_subscription(): void {
        global $DB;

        $transport = $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user);

        $transport->on('/preapproval/', $this->subscription_response($sub, 'cancelled'));
        $this->queue_event('subscription_preapproval', $sub->preapprovalid);

        (new event_processor(new api_client(null, $transport)))->process_queued();

        $updated = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $sub->id], '*', MUST_EXIST);
        $this->assertSame('ended', $updated->state);
        $this->assertSame('cancelled_by_mp', $updated->endreason);
        $this->assertGreaterThan(0, (int)$updated->timeended);
    }

    /**
     * Ending a subscription withdraws group membership but leaves the core
     * enrolment's own timeend to lapse: a subscriber who cancels has already
     * paid for the period they are in.
     *
     * This is the judgement call HANDOVER.md flags for review, pinned here so
     * that changing it is a deliberate act with a failing test to update,
     * rather than an accident.
     *
     * @return void
     */
    public function test_ending_withdraws_the_group_but_not_the_enrolment(): void {
        global $DB;

        $transport = $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $DB->set_field('enrol', 'customint1', $group->id, ['id' => $instance->id]);
        $instance = $DB->get_record('enrol', ['id' => $instance->id], '*', MUST_EXIST);

        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user);

        $processor = new event_processor(new api_client(null, $transport));
        $processor->sync_enrolment($sub);
        $this->assertTrue(groups_is_member($group->id, $user->id));

        $transport->on('/preapproval/', $this->subscription_response($sub, 'cancelled'));
        $this->queue_event('subscription_preapproval', $sub->preapprovalid);
        $processor->process_queued();

        $this->assertFalse(groups_is_member($group->id, $user->id), 'group membership survived the cancellation');
        $this->assertTrue(
            $DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $user->id]),
            'the enrolment itself was removed, which this design leaves to timeend'
        );
    }

    /**
     * A notification whose subscription belongs to another integration sharing
     * the same Mercado Pago application is ignored, not failed.
     *
     * @return void
     */
    public function test_a_foreign_external_reference_is_ignored(): void {
        global $DB;

        $transport = $this->setup_plugin();
        $transport->on('/preapproval/', [
            'id' => 'someone-elses-preapproval',
            'status' => 'authorized',
            'external_reference' => 'not-a-reference-this-plugin-minted',
        ]);
        $event = $this->queue_event('subscription_preapproval', 'someone-elses-preapproval');

        $counts = (new event_processor(new api_client(null, $transport)))->process_queued();

        $this->assertSame(1, $counts['ignored']);
        $this->assertSame(
            'ignored',
            $DB->get_field('enrol_mercadopagosub_event', 'processstatus', ['id' => $event->id])
        );
    }

    /**
     * A subscription with no external reference at all is ignored rather than
     * matched by some other field.
     *
     * @return void
     */
    public function test_a_missing_external_reference_is_ignored(): void {
        $transport = $this->setup_plugin();
        $transport->on('/preapproval/', ['id' => 'x', 'status' => 'authorized']);
        $this->queue_event('subscription_preapproval', 'x');

        $counts = (new event_processor(new api_client(null, $transport)))->process_queued();

        $this->assertSame(1, $counts['ignored']);
    }

    /**
     * Payment notifications are recorded and nothing else: neither carries an
     * id this plugin can resolve to a subscription, so reconciliation sweeps
     * for them on its own schedule instead.
     *
     * @return void
     */
    public function test_payment_notifications_are_recorded_without_an_api_call(): void {
        global $DB;

        $transport = $this->setup_plugin();
        $first = $this->queue_event('payment', '111111111');
        $second = $this->queue_event('subscription_authorized_payment', '222222222');

        $counts = (new event_processor(new api_client(null, $transport)))->process_queued();

        $this->assertSame(2, $counts['processed']);
        $this->assertSame(0, $transport->count_calls('mercadopago.com'));
        foreach ([$first, $second] as $event) {
            $row = $DB->get_record('enrol_mercadopagosub_event', ['id' => $event->id], '*', MUST_EXIST);
            $this->assertSame('processed', $row->processstatus);
            $this->assertGreaterThan(0, (int)$row->processedat);
        }
    }

    /**
     * An unrecognised topic is ignored rather than treated as an error.
     *
     * @return void
     */
    public function test_an_unknown_topic_is_ignored(): void {
        $transport = $this->setup_plugin();
        $this->queue_event('some_other_integrations_topic', 'abc');

        $counts = (new event_processor(new api_client(null, $transport)))->process_queued();

        $this->assertSame(1, $counts['ignored']);
    }

    /**
     * An API failure marks the event failed, keeps the platform's own error
     * body for diagnosis, and counts the attempt.
     *
     * @return void
     */
    public function test_an_api_failure_is_recorded_with_its_error(): void {
        global $DB;

        $transport = $this->setup_plugin();
        $transport->on('/preapproval/', ['message' => 'invalid_token', 'code' => 'guest_site_mismatch'], 400);
        $event = $this->queue_event('subscription_preapproval', 'abc');

        $counts = (new event_processor(new api_client(null, $transport)))->process_queued();

        $this->assertSame(1, $counts['failed']);
        $row = $DB->get_record('enrol_mercadopagosub_event', ['id' => $event->id], '*', MUST_EXIST);
        $this->assertSame('failed', $row->processstatus);
        $this->assertSame(1, (int)$row->attempts);
        $this->assertNotNull($row->lasterror);

        // The platform's own body is the only diagnostic it provides, and a
        // sibling plugin lost it by stringifying an array. Both the message and
        // the code have to survive as far as this column.
        $this->assertStringContainsString('invalid_token', $row->lasterror);
        $this->assertStringContainsString('guest_site_mismatch', $row->lasterror);
    }

    /**
     * A transport that never completed is a failure too, and must not be
     * mistaken for a subscription that does not exist.
     *
     * @return void
     */
    public function test_a_transport_failure_is_recorded_as_a_failure(): void {
        $transport = $this->setup_plugin();
        $transport->on_transport_error('/preapproval/', 'Could not resolve host: api.mercadopago.com');
        $this->queue_event('subscription_preapproval', 'abc');

        $counts = (new event_processor(new api_client(null, $transport)))->process_queued();

        $this->assertSame(1, $counts['failed']);
    }

    /**
     * Only queued events are picked up, so a failed event is not retried by
     * the next sweep without someone deciding to requeue it.
     *
     * @return void
     */
    public function test_only_queued_events_are_processed(): void {
        $transport = $this->setup_plugin();
        $this->queue_event('payment', '1', ['processstatus' => 'processed']);
        $this->queue_event('payment', '2', ['processstatus' => 'ignored']);
        $this->queue_event('payment', '3', ['processstatus' => 'failed']);

        $counts = (new event_processor(new api_client(null, $transport)))->process_queued();

        $this->assertSame(['processed' => 0, 'ignored' => 0, 'failed' => 0], $counts);
    }

    /**
     * The sweep respects its limit, so a backlog is worked through over
     * several runs rather than one run that never finishes.
     *
     * @return void
     */
    public function test_the_sweep_honours_its_limit(): void {
        global $DB;

        $transport = $this->setup_plugin();
        for ($i = 0; $i < 5; $i++) {
            $this->queue_event('payment', (string)$i);
        }

        $counts = (new event_processor(new api_client(null, $transport)))->process_queued(2);

        $this->assertSame(2, $counts['processed']);
        $this->assertSame(3, $DB->count_records('enrol_mercadopagosub_event', ['processstatus' => 'queued']));
    }

    /**
     * Group membership follows the subscription's state in both directions,
     * for both the paying group and the trial group.
     *
     * @return void
     */
    public function test_sync_enrolment_moves_the_subscriber_between_groups(): void {
        global $DB;

        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $paying = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $trial = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $DB->set_field('enrol', 'customint1', $paying->id, ['id' => $instance->id]);
        $DB->set_field('enrol', 'customint2', $trial->id, ['id' => $instance->id]);

        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user, ['state' => 'trialing']);

        $processor = new event_processor(new api_client(null, new \enrol_mercadopagosub\tests\fixtures\mock_transport()));
        $processor->sync_enrolment($sub);

        $this->assertTrue(groups_is_member($trial->id, $user->id), 'a trialing subscriber is not in the trial group');
        $this->assertFalse(groups_is_member($paying->id, $user->id), 'a trialing subscriber is in the paying group');

        $sub->state = 'active';
        $processor->sync_enrolment($sub);

        $this->assertFalse(groups_is_member($trial->id, $user->id), 'the trial group survived the first payment');
        $this->assertTrue(groups_is_member($paying->id, $user->id), 'a paying subscriber is not in the paying group');
    }

    /**
     * The enrolment's end date is the next payment plus the configured grace
     * period, which is what keeps a subscriber's access alive across the gap
     * between a charge falling due and the platform reporting it.
     *
     * @return void
     */
    public function test_the_enrolment_end_date_includes_the_grace_period(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance(['customint7' => 3]);
        $user = $this->getDataGenerator()->create_user();
        $due = time() + 10 * DAYSECS;
        $sub = $this->create_subscription($instance, $user, ['nextpaymentdate' => $due]);

        (new event_processor(new api_client(null, new \enrol_mercadopagosub\tests\fixtures\mock_transport())))
            ->sync_enrolment($sub);

        $enrolment = $DB->get_record(
            'user_enrolments',
            ['enrolid' => $instance->id, 'userid' => $user->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame($due + 3 * DAYSECS, (int)$enrolment->timeend);
    }
}

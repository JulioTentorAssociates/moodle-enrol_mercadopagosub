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
 * Tests for the API client.
 *
 * The endpoint paths here were measured against the live platform, not read
 * from the reference: the plausible-looking /preapproval/{id}/authorized_payments
 * returns 404, and only /authorized_payments/search?preapproval_id= answers.
 * Pinning them in tests is what stops a later tidy-up from quietly reverting
 * to the documented-but-wrong one.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api_client::class)]
final class api_client_test extends \advanced_testcase {
    use helper_trait;

    /**
     * The account endpoint is the cheapest check that credentials work at all.
     *
     * @return void
     */
    public function test_get_account_reads_users_me(): void {
        $transport = $this->setup_plugin();
        $transport->on('/users/me', ['id' => 1, 'site_id' => 'MLA']);

        $account = (new api_client(null, $transport))->get_account();

        $this->assertSame('MLA', $account['site_id']);
        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertSame('https://api.mercadopago.com/users/me', $transport->calls[0]['url']);
    }

    /**
     * A subscription is read by id, and the id is URL-encoded rather than
     * concatenated raw.
     *
     * @return void
     */
    public function test_get_subscription_encodes_the_id(): void {
        $transport = $this->setup_plugin();
        $transport->on('/preapproval/', ['id' => 'a b/c', 'status' => 'authorized']);

        (new api_client(null, $transport))->get_subscription('a b/c');

        $this->assertSame(
            'https://api.mercadopago.com/preapproval/a%20b%2Fc',
            $transport->calls[0]['url']
        );
    }

    /**
     * Creating a subscription posts the body as JSON and carries an
     * idempotency key, so a retried request cannot create a second
     * subscription for the same learner.
     *
     * @return void
     */
    public function test_create_subscription_posts_json_with_an_idempotency_key(): void {
        $transport = $this->setup_plugin();
        $transport->on('/preapproval', ['id' => 'p-1', 'status' => 'pending']);

        (new api_client(null, $transport))->create_subscription(['reason' => 'Course', 'status' => 'pending']);

        $call = $transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.mercadopago.com/preapproval', $call['url']);
        $this->assertSame(['reason' => 'Course', 'status' => 'pending'], json_decode($call['body'], true));
    }

    /**
     * Payments are listed through the search endpoint, which is the only one
     * that answers, and both result shapes the platform has returned are read.
     *
     * @return void
     */
    public function test_authorized_payments_use_the_search_endpoint(): void {
        $transport = $this->setup_plugin();
        $transport->on('/authorized_payments/search', ['results' => [['id' => 'pay-1']]]);

        $payments = (new api_client(null, $transport))->get_authorized_payments('preapproval-1');

        $this->assertSame('pay-1', $payments[0]['id']);
        $this->assertSame(
            'https://api.mercadopago.com/authorized_payments/search?preapproval_id=preapproval-1',
            $transport->calls[0]['url']
        );
    }

    /**
     * An "elements" body is read too: the platform has returned both keys.
     *
     * @return void
     */
    public function test_authorized_payments_accepts_either_result_key(): void {
        $transport = $this->setup_plugin();
        $transport->on('/authorized_payments/search', ['elements' => [['id' => 'pay-2']]]);

        $this->assertSame('pay-2', (new api_client(null, $transport))->get_authorized_payments('p-1')[0]['id']);
    }

    /**
     * A body with neither key yields an empty list rather than a fatal.
     *
     * @return void
     */
    public function test_authorized_payments_returns_empty_for_an_unexpected_body(): void {
        $transport = $this->setup_plugin();
        $transport->on('/authorized_payments/search', ['paging' => ['total' => 0]]);

        $this->assertSame([], (new api_client(null, $transport))->get_authorized_payments('p-1'));
    }

    /**
     * A status change is a PUT carrying just the status.
     *
     * @return void
     */
    public function test_setting_a_status_sends_a_put(): void {
        $transport = $this->setup_plugin();
        $transport->on('/preapproval/', ['id' => 'p-1', 'status' => 'cancelled']);

        (new api_client(null, $transport))->set_subscription_status('p-1', 'cancelled');

        $this->assertSame('PUT', $transport->calls[0]['method']);
        $this->assertSame(['status' => 'cancelled'], json_decode($transport->calls[0]['body'], true));
    }

    /**
     * Every request authenticates with the resolved access token and asks for
     * JSON, which is what makes credential precedence observable at the wire.
     *
     * @return void
     */
    public function test_requests_carry_the_resolved_credentials(): void {
        global $CFG;

        $transport = $this->setup_plugin();
        $CFG->enrol_mercadopagosub = ['accesstoken' => 'APP_USR-from-config'];
        $transport->on('/users/me', ['id' => 1]);

        (new api_client(null, $transport))->get_account();

        $headers = $transport->calls[0]['headers'];
        $this->assertContains('Authorization: Bearer APP_USR-from-config', $headers);
        $this->assertContains('Accept: application/json', $headers);
        $this->assertContains('Content-Type: application/json', $headers);
    }

    /**
     * Only the call that creates something carries an idempotency key. A
     * retried creation must not produce a second subscription; a retried read
     * has nothing to deduplicate.
     *
     * @return void
     */
    public function test_only_creation_is_idempotency_keyed(): void {
        $transport = $this->setup_plugin();
        $transport->on('/preapproval', ['id' => 'p-1', 'status' => 'pending']);

        $client = new api_client(null, $transport);
        $client->create_subscription(['reason' => 'Course']);
        $client->get_subscription('p-1');

        $keyed = static fn(array $call): array => array_values(array_filter(
            $call['headers'],
            static fn(string $header): bool => str_starts_with($header, 'X-Idempotency-Key: ')
        ));

        $this->assertCount(1, $keyed($transport->calls[0]), 'creation carried no idempotency key');
        $this->assertCount(0, $keyed($transport->calls[1]), 'a read carried an idempotency key');
    }

    /**
     * Two creations get different keys: the key exists to make one retried
     * request safe, not to collapse two genuine subscriptions into one.
     *
     * @return void
     */
    public function test_each_creation_gets_its_own_key(): void {
        $transport = $this->setup_plugin();
        $transport->on('/preapproval', ['id' => 'p-1', 'status' => 'pending']);

        $client = new api_client(null, $transport);
        $client->create_subscription(['reason' => 'One']);
        $client->create_subscription(['reason' => 'Two']);

        $key = static fn(array $call): string => implode('', array_filter(
            $call['headers'],
            static fn(string $header): bool => str_starts_with($header, 'X-Idempotency-Key: ')
        ));

        $this->assertNotSame($key($transport->calls[0]), $key($transport->calls[1]));
    }

    /**
     * Without credentials nothing is sent at all: the failure is local, and
     * the plugin does not spend a request finding that out.
     *
     * @return void
     */
    public function test_no_credentials_means_no_request(): void {
        $transport = $this->setup_plugin();
        set_config('accesstoken', '', 'enrol_mercadopagosub');

        try {
            (new api_client(null, $transport))->get_account();
            $this->fail('a request was attempted without credentials');
        } catch (api_exception $e) {
            $this->assertSame(0, $e->get_http_status());
        }

        $this->assertSame([], $transport->calls);
    }

    /**
     * An HTTP error keeps the platform's own body, which is the only
     * diagnostic it provides. A sibling plugin stringified this array and
     * logged the literal "Array" for every failure.
     *
     * @return void
     */
    public function test_an_http_error_carries_the_platforms_body(): void {
        $transport = $this->setup_plugin();
        $transport->on('/users/me', [
            'message' => 'invalid access token',
            'code' => 'unauthorized',
            'status' => 401,
        ], 401);

        try {
            (new api_client(null, $transport))->get_account();
            $this->fail('a 401 did not raise');
        } catch (api_exception $e) {
            $this->assertSame(401, $e->get_http_status());
            $this->assertSame('unauthorized', $e->get_api_code());
            $this->assertSame('invalid access token', $e->get_api_message());
            $this->assertSame('unauthorized', $e->get_body()['code']);
        }
    }

    /**
     * An exchange that never completed is distinguished from one the platform
     * refused: the first is this site's problem, the second is not.
     *
     * @return void
     */
    public function test_a_transport_failure_is_not_an_http_failure(): void {
        $transport = $this->setup_plugin();
        $transport->on_transport_error('/users/me', 'curl errno 28');

        try {
            (new api_client(null, $transport))->get_account();
            $this->fail('a transport failure did not raise');
        } catch (api_exception $e) {
            $this->assertSame(0, $e->get_http_status());
            $this->assertSame([], $e->get_body());
            $this->assertSame('', $e->get_api_code());
        }
    }

    /**
     * Secrets never reach the exception's debug info, which is what ends up in
     * a log or on screen for a developer.
     *
     * @return void
     */
    public function test_an_error_body_is_redacted_before_it_is_stored(): void {
        $transport = $this->setup_plugin();
        $transport->on('/preapproval', [
            'message' => 'rejected',
            'card' => ['number' => '4509953566233704'],
            'payer' => ['identification' => ['number' => '12345678']],
        ], 400);

        try {
            (new api_client(null, $transport))->create_subscription(['reason' => 'x']);
            $this->fail('a 400 did not raise');
        } catch (api_exception $e) {
            $debug = $e->debuginfo ?? '';
            $this->assertStringNotContainsString('4509953566233704', $debug);
            $this->assertStringNotContainsString('12345678', $debug);
            $this->assertStringContainsString('redacted', $debug);

            // The undredacted body still reaches code that asks for it: it is
            // the log that must not carry card data, not the caller.
            $this->assertSame('4509953566233704', $e->get_body()['card']['number']);
        }
    }
}

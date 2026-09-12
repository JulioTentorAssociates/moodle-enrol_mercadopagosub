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
 * Tests for the reconciliation sweep.
 *
 * The overdue rule these exercise is this plugin's own construction, not
 * something the API reports: a due date that has passed with no 'processed'
 * payment covering it means overdue, and a covering payment appearing later
 * means recovered. 'processed' is the only status ever measured for a
 * completed charge, so everything else is treated as "not paid for this
 * period" — conservative by design, and pinned here so it stays deliberate.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(payment_reconciler::class)]
final class payment_reconciler_test extends \advanced_testcase {
    use helper_trait;

    /**
     * Builds one element of an authorized_payments/search result.
     *
     * @param string $id Payment id.
     * @param string $status Payment status.
     * @param int $debitdate When the charge was taken.
     * @param array $overrides Extra or replacement fields.
     * @return array
     */
    private function payment(string $id, string $status, int $debitdate, array $overrides = []): array {
        return $overrides + [
            'id' => $id,
            'status' => $status,
            'status_detail' => 'accredited',
            'transaction_amount' => 1000,
            'currency_id' => 'ARS',
            'debit_date' => date('c', $debitdate),
        ];
    }

    /**
     * Runs a sweep with a scripted payments list and subscription response.
     *
     * @param array $payments Elements for authorized_payments/search.
     * @param array $subscription Body for GET /preapproval/{id}.
     * @return array The sweep's counts.
     */
    private function sweep(array $payments, array $subscription): array {
        $transport = new tests\fixtures\mock_transport();
        $transport->on('/authorized_payments/search', ['results' => $payments]);
        $transport->on('/preapproval/', $subscription);

        $client = new api_client(null, $transport);

        return (new payment_reconciler($client, new event_processor($client)))->reconcile_all();
    }

    /**
     * A completed charge is recorded, with the period it paid for worked out
     * from the subscription's own billing frequency.
     *
     * @return void
     */
    public function test_a_processed_payment_is_recorded_with_its_period(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user);
        $debitdate = time() - DAYSECS;

        $this->sweep(
            [$this->payment('pay-1', 'processed', $debitdate)],
            $this->subscription_response($sub, 'authorized')
        );

        $payment = $DB->get_record('enrol_mercadopagosub_payment', ['mppaymentid' => 'pay-1'], '*', MUST_EXIST);
        $this->assertSame((int)$sub->id, (int)$payment->subid);
        $this->assertSame('processed', $payment->status);
        $this->assertSame('accredited', $payment->statusdetail);
        $this->assertSame('ARS', $payment->currency);
        $this->assertEqualsWithDelta($debitdate, (int)$payment->debitdate, 1);
        $this->assertEqualsWithDelta($debitdate, (int)$payment->periodstart, 1);
        $this->assertEqualsWithDelta(
            (new \DateTimeImmutable('@' . $payment->debitdate))->modify('+1 months')->getTimestamp(),
            (int)$payment->periodend,
            1
        );
    }

    /**
     * A daily subscription gets a one-day period, not a one-month one.
     *
     * @return void
     */
    public function test_the_period_follows_the_billing_frequency(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user, ['frequency' => 7, 'frequencytype' => 'days']);
        $debitdate = time() - DAYSECS;

        $this->sweep(
            [$this->payment('pay-1', 'processed', $debitdate)],
            $this->subscription_response($sub, 'authorized')
        );

        $payment = $DB->get_record('enrol_mercadopagosub_payment', ['mppaymentid' => 'pay-1'], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(
            (int)$payment->debitdate + 7 * DAYSECS,
            (int)$payment->periodend,
            1
        );
    }

    /**
     * A second sweep updates the charge it already knows about rather than
     * duplicating it — the platform runs its own retry cycle, so a payment's
     * status can move on between sweeps.
     *
     * @return void
     */
    public function test_a_repeat_sweep_updates_rather_than_duplicates(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user);
        $debitdate = time() - DAYSECS;

        $transport = new tests\fixtures\mock_transport();
        $transport->on('/authorized_payments/search', ['results' => [$this->payment('pay-1', 'recycling', $debitdate)]]);
        $transport->on('/authorized_payments/search', ['results' => [$this->payment('pay-1', 'processed', $debitdate)]]);
        $transport->on('/preapproval/', $this->subscription_response($sub, 'authorized'));
        $client = new api_client(null, $transport);
        $reconciler = new payment_reconciler($client, new event_processor($client));

        $reconciler->reconcile_all();
        $this->assertSame(1, $DB->count_records('enrol_mercadopagosub_payment', ['subid' => $sub->id]));
        $this->assertSame(
            'recycling',
            $DB->get_field('enrol_mercadopagosub_payment', 'status', ['mppaymentid' => 'pay-1'])
        );

        $reconciler->reconcile_all();
        $this->assertSame(1, $DB->count_records('enrol_mercadopagosub_payment', ['subid' => $sub->id]));
        $this->assertSame(
            'processed',
            $DB->get_field('enrol_mercadopagosub_payment', 'status', ['mppaymentid' => 'pay-1'])
        );
    }

    /**
     * A payment with no id cannot be deduplicated, so it is skipped rather
     * than inserted afresh on every sweep.
     *
     * @return void
     */
    public function test_a_payment_without_an_id_is_skipped(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user);

        $this->sweep(
            [['status' => 'processed', 'transaction_amount' => 1000]],
            $this->subscription_response($sub, 'authorized')
        );

        $this->assertSame(0, $DB->count_records('enrol_mercadopagosub_payment', ['subid' => $sub->id]));
    }

    /**
     * Only 'processed' counts as payment: a period whose only charge is still
     * being retried is not covered.
     *
     * @return void
     */
    public function test_an_unprocessed_payment_does_not_cover_the_period(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $due = time() - 2 * DAYSECS;
        $sub = $this->create_subscription($instance, $user, ['nextpaymentdate' => $due]);

        $counts = $this->sweep(
            [$this->payment('pay-1', 'recycling', $due + HOURSECS)],
            $this->subscription_response($sub, 'authorized')
        );

        $this->assertSame(1, $counts['overdue']);
        $this->assertSame('overdue', $DB->get_field('enrol_mercadopagosub_sub', 'state', ['id' => $sub->id]));
    }

    /**
     * A due date that has passed with nothing covering it moves the
     * subscription to overdue and starts the dunning clock.
     *
     * @return void
     */
    public function test_a_missed_charge_becomes_overdue(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user, ['nextpaymentdate' => time() - DAYSECS]);

        $counts = $this->sweep([], $this->subscription_response($sub, 'authorized'));

        $this->assertSame(1, $counts['reconciled']);
        $this->assertSame(1, $counts['overdue']);
        $updated = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $sub->id], '*', MUST_EXIST);
        $this->assertSame('overdue', $updated->state);
        $this->assertSame(0, (int)$updated->dunningstage);
        $this->assertGreaterThan(0, (int)$updated->dunningsince);
    }

    /**
     * A charge covering the period that was missed brings the subscription
     * back, and clears the dunning clock with it.
     *
     * @return void
     */
    public function test_a_covering_payment_recovers_an_overdue_subscription(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $due = time() - 2 * DAYSECS;
        $sub = $this->create_subscription($instance, $user, [
            'state' => 'overdue',
            'nextpaymentdate' => $due,
            'dunningstage' => 2,
            'dunningsince' => $due,
        ]);

        $counts = $this->sweep(
            [$this->payment('pay-1', 'processed', $due + HOURSECS)],
            $this->subscription_response($sub, 'authorized')
        );

        $this->assertSame(1, $counts['recovered']);
        $updated = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $sub->id], '*', MUST_EXIST);
        $this->assertSame('active', $updated->state);
        $this->assertSame(0, (int)$updated->dunningstage);
        $this->assertSame(0, (int)$updated->dunningsince);
    }

    /**
     * A payment taken before the period that was missed does not cover it.
     *
     * @return void
     */
    public function test_an_older_payment_does_not_cover_a_later_period(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $due = time() - DAYSECS;
        $sub = $this->create_subscription($instance, $user, ['nextpaymentdate' => $due]);

        $counts = $this->sweep(
            [$this->payment('pay-old', 'processed', $due - 30 * DAYSECS)],
            $this->subscription_response($sub, 'authorized')
        );

        $this->assertSame(1, $counts['overdue']);
    }

    /**
     * A subscription whose due date is still ahead is left alone.
     *
     * @return void
     */
    public function test_a_subscription_still_within_its_period_is_untouched(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user, ['nextpaymentdate' => time() + WEEKSECS]);

        $counts = $this->sweep([], $this->subscription_response($sub, 'authorized'));

        $this->assertSame(1, $counts['reconciled']);
        $this->assertSame(0, $counts['overdue']);
        $this->assertSame(0, $counts['recovered']);
        $this->assertSame('active', $DB->get_field('enrol_mercadopagosub_sub', 'state', ['id' => $sub->id]));
    }

    /**
     * A subscription cancelled at the platform ends during the sweep, and the
     * overdue rule does not then second-guess that ending.
     *
     * @return void
     */
    public function test_a_cancelled_subscription_ends_rather_than_going_overdue(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user, ['nextpaymentdate' => time() - DAYSECS]);

        $counts = $this->sweep(
            [],
            $this->subscription_response($sub, 'cancelled', ['next_payment_date' => null])
        );

        $this->assertSame(0, $counts['overdue']);
        $this->assertSame('ended', $DB->get_field('enrol_mercadopagosub_sub', 'state', ['id' => $sub->id]));
    }

    /**
     * Every sweep re-reads the subscription itself rather than relying on a
     * webhook having arrived: measured version gaps mean some notifications
     * never turn up at all.
     *
     * @return void
     */
    public function test_the_sweep_reads_the_subscription_independently(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $user);

        $transport = new tests\fixtures\mock_transport();
        $transport->on('/authorized_payments/search', ['results' => []]);
        $transport->on('/preapproval/', $this->subscription_response($sub, 'authorized'));
        $client = new api_client(null, $transport);
        (new payment_reconciler($client, new event_processor($client)))->reconcile_all();

        $this->assertSame(1, $transport->count_calls('/preapproval/'));
        $this->assertSame(1, $transport->count_calls('/authorized_payments/search'));
    }

    /**
     * Ended subscriptions are not swept: they cannot become overdue and the
     * platform has nothing further to report about them.
     *
     * @return void
     */
    public function test_ended_subscriptions_are_not_swept(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->create_subscription($instance, $user, ['state' => 'ended', 'nextpaymentdate' => time() - DAYSECS]);
        $this->create_subscription($instance, $this->getDataGenerator()->create_user(), ['state' => 'pending']);

        $counts = $this->sweep([], ['id' => 'x', 'status' => 'authorized']);

        $this->assertSame(0, $counts['reconciled']);
    }

    /**
     * One subscription failing at the API does not stop the sweep, and the
     * failure is counted rather than swallowed.
     *
     * @return void
     */
    public function test_an_api_failure_is_counted_and_the_sweep_continues(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $this->create_subscription($instance, $this->getDataGenerator()->create_user());
        $this->create_subscription($instance, $this->getDataGenerator()->create_user());

        $transport = new tests\fixtures\mock_transport();
        $transport->on('/authorized_payments/search', ['message' => 'server error'], 500);
        $client = new api_client(null, $transport);

        $counts = (new payment_reconciler($client, new event_processor($client)))->reconcile_all();

        $this->assertSame(0, $counts['reconciled']);
        $this->assertSame(2, $counts['failed']);
        $this->assertDebuggingCalledCount(2);
    }

    /**
     * The sweep honours its limit and takes the least recently synced
     * subscriptions first, so a large site catches up over several runs
     * instead of one run that never finishes.
     *
     * @return void
     */
    public function test_the_sweep_takes_the_stalest_subscriptions_first(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $now = time();
        $stale = $this->create_subscription(
            $instance,
            $this->getDataGenerator()->create_user(),
            ['timesynced' => $now - 10 * DAYSECS]
        );
        $fresh = $this->create_subscription(
            $instance,
            $this->getDataGenerator()->create_user(),
            ['timesynced' => $now]
        );

        $transport = new tests\fixtures\mock_transport();
        $transport->on('/authorized_payments/search', ['results' => []]);
        $transport->on('/preapproval/', $this->subscription_response($stale, 'authorized'));
        $client = new api_client(null, $transport);
        $counts = (new payment_reconciler($client, new event_processor($client)))->reconcile_all(1);

        $this->assertSame(1, $counts['reconciled']);
        $this->assertGreaterThan(
            (int)$stale->timesynced,
            (int)$DB->get_field('enrol_mercadopagosub_sub', 'timesynced', ['id' => $stale->id]),
            'the stalest subscription was not the one swept'
        );
        $this->assertSame(
            (int)$fresh->timesynced,
            (int)$DB->get_field('enrol_mercadopagosub_sub', 'timesynced', ['id' => $fresh->id]),
            'a freshly synced subscription was swept before a stale one'
        );
    }
}

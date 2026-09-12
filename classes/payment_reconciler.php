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

/**
 * Sweeps every locally active subscription against Mercado Pago on its own
 * fixed schedule, independent of any webhook having arrived.
 *
 * This is the design point event_processor's own docblock defers to: a
 * `payment` or `subscription_authorized_payment` notification is marked
 * processed and does nothing else, because neither carries an id this plugin
 * can search on directly, and — the reasoning recorded here for review, not
 * previously frozen — webhooks are demonstrably unreliable as a trigger signal
 * anyway (measured `version` gaps mean some deliveries never arrive at all).
 * A subscription this plugin has not heard from in a while must still be
 * checked; that is what this class is for.
 *
 * Two things happen for each subscription swept, in this order:
 *
 *   1. Every authorized payment Mercado Pago reports is recorded in
 *      enrol_mercadopagosub_payment, keyed by mppaymentid so a repeat sweep
 *      updates rather than duplicates.
 *   2. The subscription itself is re-fetched and synced through
 *      event_processor::sync_from_response() — the same method a webhook-driven
 *      update uses — so enrolment and group membership stay correct regardless
 *      of which path noticed the change first.
 *
 * Overdue detection follows from what is left after both of those: if the
 * subscription's own next_payment_date has passed and no successful payment
 * covers it, Mercado Pago's own subscription object gives no signal of this on
 * its own — `status` measured so far stays `authorized` regardless
 * (API-FINDINGS.md's `retry_attempt`/`next_retry_date` live on the *payment*
 * record, not the subscription). This class is therefore the only place that
 * can ever set local `state = 'overdue'`.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class payment_reconciler {
    /** @var string The only authorized_payment status this plugin has ever measured for a completed charge. */
    private const STATUS_PROCESSED = 'processed';

    /** @var api_client Client used to re-fetch authoritative state. */
    private api_client $client;

    /** @var event_processor Shared sync logic, so enrolment/group handling never diverges by path. */
    private event_processor $processor;

    /**
     * Constructor.
     *
     * @param api_client|null $client Defaults to one built from resolved credentials.
     * @param event_processor|null $processor Defaults to one sharing $client.
     */
    public function __construct(?api_client $client = null, ?event_processor $processor = null) {
        $this->client = $client ?? new api_client();
        $this->processor = $processor ?? new event_processor($this->client);
    }

    /**
     * Sweeps up to $limit locally active subscriptions, oldest-synced first.
     *
     * Capped and ordered by timesynced deliberately, matching the index
     * db/install.xml already defines for exactly this query: a large site
     * catches up over several runs rather than one that never finishes, and
     * the subscriptions least recently checked are the ones most likely to be
     * stale.
     *
     * @param int $limit
     * @return array{reconciled: int, overdue: int, recovered: int, failed: int}
     */
    public function reconcile_all(int $limit = 100): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(
            ['trialing', 'active', 'overdue'],
            SQL_PARAMS_NAMED,
            'state'
        );

        $subs = $DB->get_records_select(
            'enrol_mercadopagosub_sub',
            "state {$insql}",
            $params,
            'timesynced ASC',
            '*',
            0,
            $limit
        );

        $counts = ['reconciled' => 0, 'overdue' => 0, 'recovered' => 0, 'failed' => 0];

        foreach ($subs as $sub) {
            try {
                $outcome = $this->reconcile_one($sub);
                $counts['reconciled']++;
                if ($outcome !== null) {
                    $counts[$outcome]++;
                }
            } catch (api_exception $e) {
                $counts['failed']++;
                debugging(
                    'enrol_mercadopagosub: reconciliation failed for sub ' . $sub->id . ': ' . $e->getMessage(),
                    DEBUG_NORMAL
                );
            }
        }

        return $counts;
    }

    /**
     * Reconciles one subscription.
     *
     * @param \stdClass $sub Local row, as read at the start of the sweep.
     * @return string|null 'overdue', 'recovered', or null when neither transition happened.
     * @throws api_exception
     */
    private function reconcile_one(\stdClass $sub): ?string {
        global $DB;

        // The due date this sweep is checking against — captured before either
        // the payments list or a fresh subscription GET can change it.
        $duedate = (int)$sub->nextpaymentdate;

        foreach ($this->client->get_authorized_payments($sub->preapprovalid) as $payment) {
            $this->upsert_payment($sub, $payment);
        }

        // Authoritative for status and next_payment_date, via the same sync
        // path a webhook-driven update uses. A sweep must not depend on a
        // webhook having arrived to know current truth.
        $response = $this->client->get_subscription($sub->preapprovalid);
        $this->processor->sync_from_response($sub, $response);

        // Reload: sync_from_response saved its own changes directly to the
        // database, not necessarily back onto this in-memory copy in every field.
        $sub = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $sub->id], '*', MUST_EXIST);

        if ($sub->state === 'ended') {
            // Already handled — a cancellation found during sync_from_response
            // is not this method's overdue logic to second-guess.
            return null;
        }

        $covered = $duedate <= 0 || $this->has_processed_payment_since($sub->id, $duedate);
        $ispastdue = $duedate > 0 && $duedate < time() && !$covered;

        if ($ispastdue && $sub->state !== 'overdue') {
            $sub->state = 'overdue';
            $sub->dunningstage = 0;
            $sub->dunningsince = time();
            $sub->timemodified = time();
            $DB->update_record('enrol_mercadopagosub_sub', $sub);
            $this->processor->sync_enrolment($sub);

            return 'overdue';
        }

        if (!$ispastdue && $sub->state === 'overdue') {
            $sub->state = 'active';
            $sub->dunningstage = 0;
            $sub->dunningsince = 0;
            $sub->timemodified = time();
            $DB->update_record('enrol_mercadopagosub_sub', $sub);
            $this->processor->sync_enrolment($sub);

            return 'recovered';
        }

        return null;
    }

    /**
     * Whether a successful payment has been recorded on or after a given moment.
     *
     * Only enrol_mercadopagosub_payment.status === 'processed' counts — the one
     * value this plugin has ever measured for a completed charge
     * (API-FINDINGS.md §3). Any other status is treated as not yet a payment
     * for this period, which is a conservative default given the fuller set of
     * possible statuses is unmeasured, not a confirmed enumeration.
     *
     * @param int $subid
     * @param int $since Unix timestamp.
     * @return bool
     */
    private function has_processed_payment_since(int $subid, int $since): bool {
        global $DB;

        return $DB->record_exists_select(
            'enrol_mercadopagosub_payment',
            'subid = :subid AND status = :status AND debitdate >= :since',
            ['subid' => $subid, 'status' => self::STATUS_PROCESSED, 'since' => $since]
        );
    }

    /**
     * Inserts or updates one payment record.
     *
     * Keyed by mppaymentid, which db/install.xml declares unique for exactly
     * this reason: a repeat sweep must update a payment whose status has moved
     * on (for example Mercado Pago's own retry cycle resolving a prior attempt)
     * rather than create a second row for the same charge.
     *
     * @param \stdClass $sub The local subscription this payment belongs to.
     * @param array $payment One element of get_authorized_payments()'s result.
     * @return void
     */
    private function upsert_payment(\stdClass $sub, array $payment): void {
        global $DB;

        $mppaymentid = (string)($payment['id'] ?? '');
        if ($mppaymentid === '') {
            // Cannot dedupe without a stable id; nothing safe to do with this
            // element rather than risk creating a duplicate row on every sweep.
            return;
        }

        $status = (string)($payment['status'] ?? '');
        $debitdate = util::to_timestamp($payment['debit_date'] ?? ($payment['date_created'] ?? null));

        $existing = $DB->get_record('enrol_mercadopagosub_payment', ['mppaymentid' => $mppaymentid]);
        $record = $existing ?: new \stdClass();

        $record->subid = $sub->id;
        $record->mppaymentid = $mppaymentid;
        $record->status = $status;
        $record->statusdetail = (string)($payment['status_detail'] ?? ($payment['payment']['status_detail'] ?? ''));
        $record->amount = (float)($payment['transaction_amount'] ?? $sub->amount);
        $record->currency = (string)($payment['currency_id'] ?? $sub->currency);
        $record->debitdate = $debitdate;
        $record->timemodified = time();

        if ($status === self::STATUS_PROCESSED) {
            $record->periodstart = $debitdate;
            $record->periodend = $this->compute_period_end($debitdate, $sub);
        } else if (!$existing) {
            // Unmeasured for any status other than 'processed' — API-FINDINGS.md
            // never captured one. Left at 0 rather than guessed at, per
            // db/install.xml's own field default.
            $record->periodstart = 0;
            $record->periodend = 0;
        }

        $record->payload = util::encode_for_storage(util::redact($payment), 2048);

        if ($existing) {
            $DB->update_record('enrol_mercadopagosub_payment', $record);
        } else {
            $record->timecreated = time();
            $DB->insert_record('enrol_mercadopagosub_payment', $record);
        }
    }

    /**
     * Derives the end of the period one successful payment paid for.
     *
     * Mercado Pago does not expose this on the payment object itself — it is
     * this plugin's own bookkeeping, one billing period (the subscription's own
     * frequency/frequencytype) after the moment the charge was taken, matching
     * API-FINDINGS.md §3: "An integration can treat next_payment_date directly
     * as the end of the paid period," anchored to authorisation, not creation.
     *
     * @param int $debitdate Unix timestamp the charge was taken.
     * @param \stdClass $sub Local subscription, for its billing frequency.
     * @return int Unix timestamp, or 0 when $debitdate itself is unknown.
     */
    private function compute_period_end(int $debitdate, \stdClass $sub): int {
        if ($debitdate <= 0) {
            return 0;
        }

        $unit = $sub->frequencytype === 'days' ? 'days' : 'months';
        $interval = max(1, (int)$sub->frequency);

        return (new \DateTimeImmutable('@' . $debitdate))->modify("+{$interval} {$unit}")->getTimestamp();
    }
}

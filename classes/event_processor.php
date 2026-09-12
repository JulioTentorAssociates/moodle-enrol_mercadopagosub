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
 * Does the work webhook.php deliberately does not.
 *
 * Reads enrol_mercadopagosub_event rows with processstatus = 'queued' and acts
 * on them. The payload column is never read as data here — its own schema
 * comment says so ("Treated as a signal, never as a source of truth") — every
 * business fact this class acts on comes from a fresh API call, keyed only by
 * the resource id the notification pointed at.
 *
 * This is also why acting on an event is safe regardless of signaturestatus.
 * A forged notification can, at worst, make this class re-fetch and re-sync
 * one of this site's own subscriptions sooner than its next scheduled check —
 * it cannot make this class believe anything the platform itself did not just
 * say, because nothing here is ever read from the notification body. This
 * reasoning is this session's judgement call, not a prior measurement; it is
 * recorded in HANDOVER.md for review rather than applied silently.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class event_processor {
    /** @var api_client Client used to re-fetch authoritative state. */
    private api_client $client;

    /**
     * Constructor.
     *
     * @param api_client|null $client Defaults to one built from resolved credentials.
     */
    public function __construct(?api_client $client = null) {
        $this->client = $client ?? new api_client();
    }

    /**
     * Processes up to $limit queued events, oldest first.
     *
     * Capped deliberately: a scheduled task that never returns is worse than
     * one that catches up over several runs.
     *
     * @param int $limit
     * @return array{processed: int, ignored: int, failed: int}
     */
    public function process_queued(int $limit = 50): array {
        global $DB;

        $events = $DB->get_records('enrol_mercadopagosub_event', ['processstatus' => 'queued'], 'id ASC', '*', 0, $limit);

        $counts = ['processed' => 0, 'ignored' => 0, 'failed' => 0];

        foreach ($events as $event) {
            $outcome = $this->process_one($event);
            $counts[$outcome]++;
        }

        return $counts;
    }

    /**
     * Processes one event and writes its outcome back to the row.
     *
     * @param \stdClass $event Row from enrol_mercadopagosub_event.
     * @return string One of 'processed', 'ignored', 'failed'.
     */
    private function process_one(\stdClass $event): string {
        global $DB;

        $event->attempts = (int)$event->attempts + 1;

        try {
            $outcome = $this->dispatch($event);
            $event->lasterror = null;
        } catch (api_exception $e) {
            $outcome = 'failed';
            $event->lasterror = util::encode_for_storage(
                ['message' => $e->getMessage(), 'code' => $e->get_api_code()],
                1024
            );
        }

        $event->processstatus = $outcome;
        $event->processedat = time();
        $DB->update_record('enrol_mercadopagosub_event', $event);

        return $outcome;
    }

    /**
     * Routes an event by topic.
     *
     * @param \stdClass $event
     * @return string One of 'processed', 'ignored'. Failure is signalled by
     *                throwing api_exception, not by a return value.
     * @throws api_exception
     */
    private function dispatch(\stdClass $event): string {
        switch ($event->topic) {
            case 'subscription_preapproval':
                return $this->process_preapproval_event($event);

            case 'payment':
            case 'subscription_authorized_payment':
            case 'subscription_preapproval_plan':
                // Neither carries an id this plugin can search on directly —
                // HANDOVER.md, "This settles webhook.php's handling,
                // definitively". The reconciliation task (not yet written)
                // sweeps authorized_payments for every locally active
                // subscription on its own fixed schedule; it does not need
                // these events to tell it when to run. Recording arrival here
                // is for audit only.
                return 'processed';

            default:
                // Unrecognised topic, or one belonging to another integration
                // sharing the same application — db/install.xml's own comment
                // on the resourceid column anticipates exactly this.
                return 'ignored';
        }
    }

    /**
     * Handles a subscription_preapproval notification.
     *
     * data.id on this notification type is the preapproval id itself — the one
     * case where the notification points directly at something this plugin can
     * look up without guessing.
     *
     * @param \stdClass $event
     * @return string 'processed' or 'ignored'.
     * @throws api_exception
     */
    private function process_preapproval_event(\stdClass $event): string {
        global $DB;

        $response = $this->client->get_subscription($event->resourceid);
        $externalreference = (string)($response['external_reference'] ?? '');

        if ($externalreference === '') {
            return 'ignored';
        }

        $sub = $DB->get_record('enrol_mercadopagosub_sub', ['externalreference' => $externalreference]);
        if ($sub === false) {
            // Same application, different plugin or a stale reference — not an
            // error, just not ours.
            return 'ignored';
        }

        $this->sync_from_response($sub, $response);

        return 'processed';
    }

    /**
     * Syncs one local row against a freshly-fetched subscription, and whatever
     * follows from the transition: enrolment, and paid/trial group membership.
     *
     * Public deliberately: the reconciliation task calls this too, against its
     * own independent GET /preapproval/{id}, so that a sweep does not depend on
     * a webhook having arrived to know current truth — see
     * payment_reconciler for why that independence matters.
     *
     * Trial detection is deliberately not implemented here. Mercado Pago's own
     * `status` only ever reports pending/authorized/paused/cancelled; nothing
     * measured so far distinguishes "authorized, still inside its free trial"
     * from "authorized, paying normally" at the subscription level — API-
     * FINDINGS.md §2 shows next_payment_date lands on the trial's end date, but
     * this plugin has not measured what a webhook or a GET reports about a
     * subscription's status *during* an active trial specifically. Every
     * transition into 'authorized' is treated as 'active' here. Distinguishing
     * 'trialing' needs that measurement first, not a guess now.
     *
     * @param \stdClass $sub Local row, will be updated in place and saved.
     * @param array $response Decoded GET /preapproval/{id} response.
     * @return void
     */
    public function sync_from_response(\stdClass $sub, array $response): void {
        global $DB;

        $mpstatus = (string)($response['status'] ?? $sub->mpstatus);
        $wasactive = in_array($sub->state, ['trialing', 'active', 'overdue'], true);

        $sub->mpstatus = $mpstatus;

        $newnextpayment = util::to_timestamp($response['next_payment_date'] ?? null);
        if ($newnextpayment > 0) {
            $sub->nextpaymentdate = $newnextpayment;
        }

        $payerid = (string)($response['payer_id'] ?? '');
        if ($payerid !== '') {
            $sub->payerid = $payerid;
        }

        $paymentmethodid = (string)($response['payment_method_id'] ?? '');
        if ($paymentmethodid !== '') {
            $sub->paymentmethodid = $paymentmethodid;
        }

        $sub->timemodified = time();
        $sub->timesynced = time();

        switch ($mpstatus) {
            case 'authorized':
                if (!$wasactive) {
                    // See the class-level trial-detection note above: always
                    // 'active', never 'trialing', until that measurement exists.
                    $sub->state = 'active';
                    $sub->timeauthorized = $sub->timeauthorized ?: time();
                }
                break;

            case 'cancelled':
                $sub->state = 'ended';
                $sub->endreason = $sub->endreason ?: 'cancelled_by_mp';
                $sub->timeended = $sub->timeended ?: time();
                break;

            case 'pending':
                // Measured: pending only ever moves to authorized or cancelled,
                // never back to pending from further along. Nothing to do.
                break;

            default:
                // A 'paused' status, or anything else: this plugin's own code never sets
                // these (HANDOVER.md, "Mercado Pago paused is not used").
                // Recorded via mpstatus above; not acted on further.
                break;
        }

        $DB->update_record('enrol_mercadopagosub_sub', $sub);

        $this->sync_enrolment($sub);
    }

    /**
     * Grants, extends, or withdraws course access to match the local state
     * this row was just saved with.
     *
     * Public deliberately: the reconciliation task calls this too, after a
     * state change it decided on its own (an overdue transition, or recovery
     * from one) rather than from a fresh subscription response — that path has
     * no API response to hand sync_from_response(), only a row already saved
     * with its new state.
     *
     * Withdrawal on 'ended' is limited to group membership, deliberately: the
     * core enrolment's own timeend, already set to nextpaymentdate plus the
     * configured grace period, is left to lapse naturally rather than cut short
     * — a subscriber who cancels has already paid for the period they are in.
     * This is this session's judgement call, not a measured requirement; flagged
     * for review rather than applied silently.
     *
     * @param \stdClass $sub
     * @return void
     */
    public function sync_enrolment(\stdClass $sub): void {
        global $DB;

        $instance = $DB->get_record('enrol', ['id' => $sub->enrolid, 'enrol' => 'mercadopagosub']);
        if ($instance === false) {
            return;
        }

        $haspaidaccess = in_array($sub->state, ['trialing', 'active', 'overdue'], true);

        if ($haspaidaccess) {
            $plugin = enrol_get_plugin('mercadopagosub');
            $timeend = $sub->nextpaymentdate > 0
                ? $sub->nextpaymentdate + ((int)$instance->customint7 * DAYSECS)
                : 0;

            $existing = $DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $sub->userid]);
            if (!$existing) {
                $plugin->enrol_user($instance, $sub->userid, $instance->roleid, 0, $timeend);
            } else {
                $plugin->update_user_enrol($instance, $sub->userid, ENROL_USER_ACTIVE, null, $timeend);
            }
        }

        $this->sync_group((int)$instance->customint1, $sub->userid, $haspaidaccess && $sub->state !== 'trialing');
        $this->sync_group((int)$instance->customint2, $sub->userid, $sub->state === 'trialing');
    }

    /**
     * Adds or removes membership of one group to match a target state.
     *
     * A no-op group id (0, meaning the site never configured one) is treated
     * as nothing to do, not as an error.
     *
     * @param int $groupid
     * @param int $userid
     * @param bool $shouldbemember
     * @return void
     */
    private function sync_group(int $groupid, int $userid, bool $shouldbemember): void {
        if ($groupid <= 0) {
            return;
        }

        $ismember = groups_is_member($groupid, $userid);

        if ($shouldbemember && !$ismember) {
            groups_add_member($groupid, $userid);
        } else if (!$shouldbemember && $ismember) {
            groups_remove_member($groupid, $userid);
        }
    }
}

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

namespace enrol_mercadopagosub\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy Subsystem implementation for enrol_mercadopagosub.
 *
 * Everything this plugin stores about a person hangs off a course context: a
 * subscription row belongs to an enrol instance, and an enrol instance belongs
 * to exactly one course.
 *
 * Two people can have data in a single subscription. The subscriber is
 * `userid`. The payer is `payeremail`, and this plugin is explicit elsewhere
 * that the two are frequently not the same person — an employer or a client
 * company paying for someone else's course is a first-class case here, not an
 * edge one (see the subscriber form's third-party checkbox). When the payer
 * also happens to hold an account on this site, that address is their personal
 * data too, and both requests have to reach it. This is the same shape
 * enrol_paypal uses for its own `business`/`receiver_email` columns, and the
 * asymmetry in deletion is the same as well: deleting the subscriber's data
 * removes the subscription, while deleting the payer's data only clears the
 * address from a subscription that still belongs to someone else.
 *
 * Two things deliberately NOT declared here:
 *
 * - **Group membership.** This plugin moves subscribers between a trial group
 *   and a paying group, but it calls `groups_add_member($groupid, $userid)`
 *   without a component, so the membership rows core writes are ordinary
 *   course group memberships owned by `core_group`, which already exports and
 *   deletes them. Declaring them here would double-count them. (enrol_cohort
 *   and enrol_meta do delegate to `\core_group\privacy\provider`, but their
 *   memberships are component-owned; ours are not.)
 * - **Enrolment itself.** `user_enrolments` and `role_assignments` belong to
 *   `core_enrol` and `core_role`, which report them for every enrolment plugin.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes what this plugin stores and what it sends to Mercado Pago.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection The same collection, with this plugin's items added.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'mercadopago.com',
            [
                'payer_email' => 'privacy:metadata:mercadopago:payer_email',
                'external_reference' => 'privacy:metadata:mercadopago:external_reference',
                'reason' => 'privacy:metadata:mercadopago:reason',
                'back_url' => 'privacy:metadata:mercadopago:back_url',
            ],
            'privacy:metadata:mercadopago'
        );

        $collection->add_database_table(
            'enrol_mercadopagosub_sub',
            [
                'userid' => 'privacy:metadata:enrol_mercadopagosub_sub:userid',
                'enrolid' => 'privacy:metadata:enrol_mercadopagosub_sub:enrolid',
                'preapprovalid' => 'privacy:metadata:enrol_mercadopagosub_sub:preapprovalid',
                'externalreference' => 'privacy:metadata:enrol_mercadopagosub_sub:externalreference',
                'payeremail' => 'privacy:metadata:enrol_mercadopagosub_sub:payeremail',
                'payerid' => 'privacy:metadata:enrol_mercadopagosub_sub:payerid',
                'paymentmethodid' => 'privacy:metadata:enrol_mercadopagosub_sub:paymentmethodid',
                'state' => 'privacy:metadata:enrol_mercadopagosub_sub:state',
                'mpstatus' => 'privacy:metadata:enrol_mercadopagosub_sub:mpstatus',
                'endreason' => 'privacy:metadata:enrol_mercadopagosub_sub:endreason',
                'amount' => 'privacy:metadata:enrol_mercadopagosub_sub:amount',
                'currency' => 'privacy:metadata:enrol_mercadopagosub_sub:currency',
                'frequency' => 'privacy:metadata:enrol_mercadopagosub_sub:frequency',
                'frequencytype' => 'privacy:metadata:enrol_mercadopagosub_sub:frequencytype',
                'trialfrequency' => 'privacy:metadata:enrol_mercadopagosub_sub:trialfrequency',
                'trialfrequencytype' => 'privacy:metadata:enrol_mercadopagosub_sub:trialfrequencytype',
                'nextpaymentdate' => 'privacy:metadata:enrol_mercadopagosub_sub:nextpaymentdate',
                'enddate' => 'privacy:metadata:enrol_mercadopagosub_sub:enddate',
                'dunningstage' => 'privacy:metadata:enrol_mercadopagosub_sub:dunningstage',
                'dunningsince' => 'privacy:metadata:enrol_mercadopagosub_sub:dunningsince',
                'timecreated' => 'privacy:metadata:enrol_mercadopagosub_sub:timecreated',
                'timemodified' => 'privacy:metadata:enrol_mercadopagosub_sub:timemodified',
                'timeauthorized' => 'privacy:metadata:enrol_mercadopagosub_sub:timeauthorized',
                'timeended' => 'privacy:metadata:enrol_mercadopagosub_sub:timeended',
                'extras' => 'privacy:metadata:enrol_mercadopagosub_sub:extras',
            ],
            'privacy:metadata:enrol_mercadopagosub_sub'
        );

        // Columns deliberately left undeclared across the three tables are the
        // ones that describe this plugin's own bookkeeping rather than the
        // person: when a row was last synced, how many times a notification
        // was retried, what the last processing error was. They are listed
        // here so that the omission reads as a decision rather than an
        // oversight — enrol_mercadopagosub_sub.timesynced,
        // enrol_mercadopagosub_payment.timecreated/timemodified, and
        // enrol_mercadopagosub_event.notificationid/requestid/attempts/
        // processedat/lasterror.

        $collection->add_database_table(
            'enrol_mercadopagosub_payment',
            [
                'subid' => 'privacy:metadata:enrol_mercadopagosub_payment:subid',
                'mppaymentid' => 'privacy:metadata:enrol_mercadopagosub_payment:mppaymentid',
                'status' => 'privacy:metadata:enrol_mercadopagosub_payment:status',
                'statusdetail' => 'privacy:metadata:enrol_mercadopagosub_payment:statusdetail',
                'amount' => 'privacy:metadata:enrol_mercadopagosub_payment:amount',
                'currency' => 'privacy:metadata:enrol_mercadopagosub_payment:currency',
                'debitdate' => 'privacy:metadata:enrol_mercadopagosub_payment:debitdate',
                'periodstart' => 'privacy:metadata:enrol_mercadopagosub_payment:periodstart',
                'periodend' => 'privacy:metadata:enrol_mercadopagosub_payment:periodend',
                'payload' => 'privacy:metadata:enrol_mercadopagosub_payment:payload',
            ],
            'privacy:metadata:enrol_mercadopagosub_payment'
        );

        $collection->add_database_table(
            'enrol_mercadopagosub_event',
            [
                'topic' => 'privacy:metadata:enrol_mercadopagosub_event:topic',
                'resourceid' => 'privacy:metadata:enrol_mercadopagosub_event:resourceid',
                'signaturestatus' => 'privacy:metadata:enrol_mercadopagosub_event:signaturestatus',
                'processstatus' => 'privacy:metadata:enrol_mercadopagosub_event:processstatus',
                'receivedat' => 'privacy:metadata:enrol_mercadopagosub_event:receivedat',
                'payload' => 'privacy:metadata:enrol_mercadopagosub_event:payload',
            ],
            'privacy:metadata:enrol_mercadopagosub_event'
        );

        $collection->add_subsystem_link(
            'core_message',
            [],
            'privacy:metadata:core_message'
        );

        return $collection;
    }

    /**
     * Course contexts holding data for this user, as subscriber or as payer.
     *
     * @param int $userid The user to search for.
     * @return contextlist Contexts where this plugin holds something about them.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // The payeremail column is stored as typed, not normalised, so both
        // sides are lowercased here rather than trusting either one.
        $sql = "SELECT ctx.id
                  FROM {enrol_mercadopagosub_sub} s
                  JOIN {enrol} e ON e.id = s.enrolid
                  JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :contextcourse
                  JOIN {user} u ON u.id = s.userid OR LOWER(u.email) = LOWER(s.payeremail)
                 WHERE u.id = :userid";

        $contextlist->add_from_sql($sql, [
            'contextcourse' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Users with data in the given context, as subscriber or as payer.
     *
     * @param userlist $userlist The userlist to add matching users to.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_course) {
            return;
        }

        $sql = "SELECT u.id
                  FROM {enrol_mercadopagosub_sub} s
                  JOIN {enrol} e ON e.id = s.enrolid
                  JOIN {user} u ON u.id = s.userid OR LOWER(u.email) = LOWER(s.payeremail)
                 WHERE e.courseid = :courseid";

        $userlist->add_from_sql('id', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Exports every subscription this user appears in, per course context.
     *
     * A subscriber gets the whole record: the subscription, its payments, and
     * the webhook notifications that carried its preapproval id. A person who
     * is only the payer gets the billing view — their own address, what was
     * charged and when — and nothing that identifies the subscriber, because
     * the subscriber's identity is not the payer's personal data.
     *
     * @param approved_contextlist $contextlist Approved contexts for one user.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();
        $useremail = \core_text::strtolower($user->email);

        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT s.*, e.courseid
                  FROM {enrol_mercadopagosub_sub} s
                  JOIN {enrol} e ON e.id = s.enrolid
                  JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :contextcourse
                 WHERE ctx.id {$contextsql}
                   AND (s.userid = :userid OR LOWER(s.payeremail) = :payeremail)
              ORDER BY e.courseid ASC, s.id ASC";

        $params = $contextparams + [
            'contextcourse' => CONTEXT_COURSE,
            'userid' => $user->id,
            'payeremail' => $useremail,
        ];

        // Records arrive ordered by course, so a course's collected
        // subscriptions are written out when the next course starts, and once
        // more after the loop for the last one.
        $bycourse = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            // Cast both sides: the database hands back strings, and an
            // identity comparison against an int user id would never match.
            $issubscriber = (int) $record->userid === (int) $user->id;
            $bycourse[$record->courseid][] = self::export_shape($record, $issubscriber);
        }
        $records->close();

        foreach ($bycourse as $courseid => $subscriptions) {
            writer::with_context(\context_course::instance($courseid))->export_data(
                [get_string('privacy:path:subscriptions', 'enrol_mercadopagosub')],
                (object) ['subscriptions' => $subscriptions]
            );
        }
    }

    /**
     * Builds one exported subscription.
     *
     * @param \stdClass $record Row from enrol_mercadopagosub_sub, plus courseid.
     * @param bool $issubscriber Whether the exporting user is the subscriber.
     * @return \stdClass The structure handed to the writer.
     */
    private static function export_shape(\stdClass $record, bool $issubscriber): \stdClass {
        $export = (object) [
            'payeremail' => $record->payeremail,
            'state' => $record->state,
            'mpstatus' => $record->mpstatus,
            'amount' => $record->amount,
            'currency' => $record->currency,
            'frequency' => $record->frequency,
            'frequencytype' => $record->frequencytype,
            'nextpaymentdate' => self::datetime($record->nextpaymentdate),
            'timecreated' => transform::datetime($record->timecreated),
            'payments' => self::export_payments($record->id),
        ];

        if (!$issubscriber) {
            // The payer's copy stops here. Everything below identifies the
            // subscription itself — the preapproval id, the external reference
            // and the notification trail — which belongs to the subscriber.
            return $export;
        }

        $export->preapprovalid = $record->preapprovalid;
        $export->externalreference = $record->externalreference;
        $export->payerid = $record->payerid;
        $export->paymentmethodid = $record->paymentmethodid;
        $export->trialfrequency = $record->trialfrequency;
        $export->trialfrequencytype = $record->trialfrequencytype;
        $export->endreason = $record->endreason;
        $export->enddate = self::datetime($record->enddate);
        $export->dunningstage = $record->dunningstage;
        $export->dunningsince = self::datetime($record->dunningsince);
        $export->timeauthorized = self::datetime($record->timeauthorized);
        $export->timeended = self::datetime($record->timeended);
        $export->timemodified = transform::datetime($record->timemodified);
        $export->notifications = self::export_events($record->preapprovalid);

        return $export;
    }

    /**
     * Exports the payment rows of one subscription.
     *
     * @param int $subid enrol_mercadopagosub_sub.id.
     * @return array One entry per recorded payment, oldest first.
     */
    private static function export_payments(int $subid): array {
        global $DB;

        $payments = [];
        $records = $DB->get_recordset('enrol_mercadopagosub_payment', ['subid' => $subid], 'debitdate ASC, id ASC');
        foreach ($records as $record) {
            $payments[] = (object) [
                'mppaymentid' => $record->mppaymentid,
                'status' => $record->status,
                'statusdetail' => $record->statusdetail,
                'amount' => $record->amount,
                'currency' => $record->currency,
                'debitdate' => self::datetime($record->debitdate),
                'periodstart' => self::datetime($record->periodstart),
                'periodend' => self::datetime($record->periodend),
                'payload' => $record->payload,
            ];
        }
        $records->close();

        return $payments;
    }

    /**
     * Exports the webhook notifications that carried this preapproval id.
     *
     * Only `subscription_preapproval` notifications name the preapproval
     * directly; `payment` and `subscription_authorized_payment` carry ids in a
     * space this plugin cannot resolve to a subscription, so they are not
     * attributable to anyone and are not exported.
     *
     * @param string $preapprovalid The subscription's Mercado Pago preapproval id.
     * @return array One entry per notification, oldest first.
     */
    private static function export_events(string $preapprovalid): array {
        global $DB;

        if ($preapprovalid === '') {
            return [];
        }

        $events = [];
        $records = $DB->get_recordset('enrol_mercadopagosub_event', ['resourceid' => $preapprovalid], 'receivedat ASC, id ASC');
        foreach ($records as $record) {
            $events[] = (object) [
                'topic' => $record->topic,
                'signaturestatus' => $record->signaturestatus,
                'processstatus' => $record->processstatus,
                'receivedat' => transform::datetime($record->receivedat),
                'payload' => $record->payload,
            ];
        }
        $records->close();

        return $events;
    }

    /**
     * Formats a timestamp, keeping "not set" distinguishable from the epoch.
     *
     * Several columns here use 0 (and enddate uses null) to mean "has not
     * happened", so passing them straight to transform::datetime() would
     * export January 1970 as though it were a real date.
     *
     * @param int|null $timestamp Unix timestamp, 0, or null.
     * @return string|null The formatted date, or null when there is none.
     */
    private static function datetime(?int $timestamp): ?string {
        return empty($timestamp) ? null : transform::datetime($timestamp);
    }

    /**
     * Deletes every subscription belonging to one course.
     *
     * @param \context $context The context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_course) {
            return;
        }

        $subids = $DB->get_fieldset_select(
            'enrol_mercadopagosub_sub',
            'id',
            'enrolid IN (SELECT id FROM {enrol} WHERE courseid = :courseid AND enrol = :enrol)',
            ['courseid' => $context->instanceid, 'enrol' => 'mercadopagosub']
        );

        self::delete_subscriptions($subids);
    }

    /**
     * Deletes one user's data across the approved course contexts.
     *
     * Asymmetric by design. As subscriber, the subscription and everything
     * hanging off it goes. As payer of somebody else's subscription, only the
     * address is cleared: the subscription is that other person's record and
     * removing it would destroy their enrolment history to satisfy a request
     * that was never about them.
     *
     * @param approved_contextlist $contextlist Approved contexts for one user.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        $courseids = [];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_course) {
                $courseids[] = $context->instanceid;
            }
        }

        if (empty($courseids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $instancesql = "enrolid IN (SELECT id FROM {enrol} WHERE courseid {$insql} AND enrol = :enrol)";
        $instanceparams = $inparams + ['enrol' => 'mercadopagosub'];

        $subids = $DB->get_fieldset_select(
            'enrol_mercadopagosub_sub',
            'id',
            "{$instancesql} AND userid = :userid",
            $instanceparams + ['userid' => $user->id]
        );
        self::delete_subscriptions($subids);

        $DB->set_field_select(
            'enrol_mercadopagosub_sub',
            'payeremail',
            '',
            "{$instancesql} AND LOWER(payeremail) = :payeremail",
            $instanceparams + ['payeremail' => \core_text::strtolower($user->email)]
        );
    }

    /**
     * Deletes several users' data within one course context.
     *
     * @param approved_userlist $userlist Approved users in one context.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_course) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $instancesql = 'enrolid IN (SELECT id FROM {enrol} WHERE courseid = :courseid AND enrol = :enrol)';
        $instanceparams = ['courseid' => $context->instanceid, 'enrol' => 'mercadopagosub'];

        $subids = $DB->get_fieldset_select(
            'enrol_mercadopagosub_sub',
            'id',
            "{$instancesql} AND userid {$usersql}",
            $instanceparams + $userparams
        );
        self::delete_subscriptions($subids);

        $DB->set_field_select(
            'enrol_mercadopagosub_sub',
            'payeremail',
            '',
            "{$instancesql} AND LOWER(payeremail) IN (SELECT LOWER(email) FROM {user} WHERE id {$usersql})",
            $instanceparams + $userparams
        );
    }

    /**
     * Removes subscriptions and everything that hangs off them.
     *
     * The notification rows go by preapproval id, which is the only link this
     * plugin has between a queued webhook and a subscriber. Notifications of
     * the other two types carry ids in a space that resolves to no local row
     * at all, so they are left where they are — they identify nobody once the
     * subscription they would have been reconciled against is gone.
     *
     * Note for whoever runs this against a live site: a subscription that is
     * still authorised at Mercado Pago keeps charging after its local row is
     * deleted. Nothing here can prevent that — the deletion request is not the
     * place to make network calls that might fail — so cancelling the
     * preapproval in the seller's dashboard is an operational step that has to
     * accompany a deletion request for an active subscriber. Incoming
     * notifications for a deleted subscription are harmless: event_processor
     * already treats an unknown external reference as "not ours" and marks the
     * event ignored.
     *
     * @param array $subids enrol_mercadopagosub_sub ids to remove.
     */
    private static function delete_subscriptions(array $subids) {
        global $DB;

        if (empty($subids)) {
            return;
        }

        [$subsql, $subparams] = $DB->get_in_or_equal($subids, SQL_PARAMS_NAMED);

        $preapprovalids = $DB->get_fieldset_select(
            'enrol_mercadopagosub_sub',
            'preapprovalid',
            "id {$subsql}",
            $subparams
        );
        $preapprovalids = array_filter($preapprovalids, static function ($value) {
            return (string) $value !== '';
        });

        $DB->delete_records_select('enrol_mercadopagosub_payment', "subid {$subsql}", $subparams);

        if (!empty($preapprovalids)) {
            [$refsql, $refparams] = $DB->get_in_or_equal($preapprovalids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('enrol_mercadopagosub_event', "resourceid {$refsql}", $refparams);
        }

        $DB->delete_records_select('enrol_mercadopagosub_sub', "id {$subsql}", $subparams);
    }
}

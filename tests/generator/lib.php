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

/**
 * Data generator for enrol_mercadopagosub.
 *
 * Creates subscription rows in a given state without going anywhere near
 * Mercado Pago. Every scenario that matters here starts from a subscription
 * that already exists and is in some state — active, overdue, ended — and none
 * of those states can be reached through the user interface, because reaching
 * them requires the platform to charge somebody. Without a generator, a Behat
 * feature can only ever test the empty case.
 *
 * @package   enrol_mercadopagosub
 * @category  test
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_mercadopagosub_generator extends component_generator_base {
    /** @var int Number of subscriptions created, used to keep ids distinct. */
    protected int $subscriptioncount = 0;

    /** @var int Number of payments created. */
    protected int $paymentcount = 0;

    /** @var int Number of notifications created. */
    protected int $eventcount = 0;

    /**
     * Resets the counters between tests.
     *
     * @return void
     */
    public function reset(): void {
        $this->subscriptioncount = 0;
        $this->paymentcount = 0;
        $this->eventcount = 0;
    }

    /**
     * Adds a subscription enrolment method to a course.
     *
     * A feature needs this before it can create subscriptions, because a
     * subscription points at an enrol instance and the only other way to get one
     * is to fill in the instance form — which is itself the thing under test in
     * half of these scenarios, and far too slow to repeat as a precondition in
     * the other half.
     *
     * Defaults deliberately leave "Allow new subscriptions" off
     * (ENROL_INSTANCE_DISABLED): enabling an instance runs the credentials and
     * HTTPS checks, which a scenario about something else should not have to
     * satisfy. Pass 'status' => 0 where that is the point.
     *
     * @param array|stdClass $record Must carry courseid; may override any instance field.
     * @return stdClass The enrol row, with its id.
     */
    public function create_instance($record): stdClass {
        global $DB;

        $record = (array)$record;

        if (empty($record['courseid'])) {
            throw new coding_exception('An enrolment method needs a courseid.');
        }

        $course = $DB->get_record('course', ['id' => $record['courseid']], '*', MUST_EXIST);
        unset($record['courseid']);

        // ENROL_INSTANCE_ENABLED is 0 and DISABLED is 1, which is exactly
        // backwards from how anybody reads a table cell. Accept the words.
        if (isset($record['status']) && !is_numeric($record['status'])) {
            $record['status'] = $record['status'] === 'enabled'
                ? ENROL_INSTANCE_ENABLED
                : ENROL_INSTANCE_DISABLED;
        }

        $fields = $record + [
            'status' => ENROL_INSTANCE_DISABLED,
            'cost' => 1000,
            'currency' => 'ARS',
            'roleid' => $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST),
            'customint1' => 0,
            'customint2' => 0,
            'customint3' => 0,
            'customint4' => ENROL_DO_NOT_SEND_EMAIL,
            'customint5' => 0,
            'customint6' => 1,
            'customint7' => 0,
            'customchar1' => 'months',
            'customchar2' => 'days',
        ];

        $instanceid = enrol_get_plugin('mercadopagosub')->add_instance($course, $fields);

        return $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }

    /**
     * Creates a subscription row.
     *
     * The enrolment itself is not granted here: whether a subscriber is
     * enrolled follows from the state, and it is event_processor's job to
     * decide that. Pass 'enrol' => true to have the generator do it anyway,
     * which is what a scenario about somebody already studying needs.
     *
     * @param array|stdClass $record Must carry enrolid (or courseid) and userid.
     * @return stdClass The inserted row, with its id.
     */
    public function create_subscription($record): stdClass {
        global $DB;

        $record = (array)$record;

        if (empty($record['enrolid'])) {
            if (empty($record['courseid'])) {
                throw new coding_exception('A subscription needs either enrolid or courseid.');
            }
            $instance = $DB->get_record(
                'enrol',
                ['courseid' => $record['courseid'], 'enrol' => 'mercadopagosub'],
                '*',
                IGNORE_MULTIPLE
            );
            if (!$instance) {
                throw new coding_exception(
                    'Course ' . $record['courseid'] . ' has no mercadopagosub enrolment method to subscribe to.'
                );
            }
            $record['enrolid'] = $instance->id;
        }

        if (empty($record['userid'])) {
            throw new coding_exception('A subscription needs a userid.');
        }

        $this->subscriptioncount++;
        $now = time();
        $state = $record['state'] ?? 'active';

        $defaults = [
            'preapprovalid' => 'behat-preapproval-' . $this->subscriptioncount,
            'externalreference' => \enrol_mercadopagosub\util::make_reference(
                (int)$record['enrolid'],
                (int)$record['userid']
            ),
            'payeremail' => 'payer' . $this->subscriptioncount . '@example.com',
            'state' => $state,
            'endreason' => null,
            'mpstatus' => $state === 'pending' ? 'pending' : ($state === 'ended' ? 'cancelled' : 'authorized'),
            'amount' => 1000,
            'currency' => 'ARS',
            'frequency' => 1,
            'frequencytype' => 'months',
            'trialfrequency' => null,
            'trialfrequencytype' => null,
            'nextpaymentdate' => $state === 'overdue' ? $now - DAYSECS : $now + 30 * DAYSECS,
            'enddate' => null,
            'payerid' => null,
            'paymentmethodid' => null,
            'dunningstage' => 0,
            'dunningsince' => $state === 'overdue' ? $now - DAYSECS : 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timeauthorized' => $state === 'pending' ? 0 : $now,
            'timeended' => $state === 'ended' ? $now : 0,
            'timesynced' => $now,
            'extras' => null,
        ];

        $enrol = !empty($record['enrol']);
        unset($record['courseid'], $record['enrol']);

        $row = (object)array_merge($defaults, $record);
        $row->id = $DB->insert_record('enrol_mercadopagosub_sub', $row);

        if ($enrol) {
            $instance = $DB->get_record('enrol', ['id' => $row->enrolid], '*', MUST_EXIST);
            enrol_get_plugin('mercadopagosub')->enrol_user(
                $instance,
                $row->userid,
                $instance->roleid,
                0,
                (int)$row->nextpaymentdate
            );
        }

        return $row;
    }

    /**
     * Creates a payment row against an existing subscription.
     *
     * @param array|stdClass $record Must carry subid.
     * @return stdClass The inserted row, with its id.
     */
    public function create_payment($record): stdClass {
        global $DB;

        $record = (array)$record;
        if (empty($record['subid'])) {
            throw new coding_exception('A payment needs a subid.');
        }

        $this->paymentcount++;
        $now = time();
        $debitdate = (int)($record['debitdate'] ?? $now - DAYSECS);

        $row = (object)array_merge([
            'mppaymentid' => 'behat-payment-' . $this->paymentcount,
            'status' => 'processed',
            'statusdetail' => 'accredited',
            'amount' => 1000,
            'currency' => 'ARS',
            'debitdate' => $debitdate,
            'periodstart' => $debitdate,
            'periodend' => $debitdate + 30 * DAYSECS,
            'timecreated' => $now,
            'timemodified' => $now,
            'payload' => json_encode(['id' => 'behat-payment-' . $this->paymentcount]),
        ], $record);

        $row->id = $DB->insert_record('enrol_mercadopagosub_payment', $row);

        return $row;
    }

    /**
     * Creates a queued notification, as the webhook endpoint would have.
     *
     * @param array|stdClass $record May carry topic, resourceid, signaturestatus.
     * @return stdClass The inserted row, with its id.
     */
    public function create_event($record = []): stdClass {
        global $DB;

        $record = (array)$record;
        $this->eventcount++;
        $topic = $record['topic'] ?? 'subscription_preapproval';
        $resourceid = $record['resourceid'] ?? 'behat-preapproval-' . $this->eventcount;

        $row = (object)array_merge([
            'topic' => $topic,
            'resourceid' => $resourceid,
            'notificationid' => 'behat-notification-' . $this->eventcount,
            'requestid' => 'behat-request-' . $this->eventcount,
            'signaturestatus' => 'verified',
            'processstatus' => 'queued',
            'attempts' => 0,
            'receivedat' => time(),
            'processedat' => 0,
            'lasterror' => null,
            'payload' => json_encode([
                'action' => 'updated',
                'type' => $topic,
                'data' => ['id' => $resourceid],
            ]),
        ], $record);

        $row->id = $DB->insert_record('enrol_mercadopagosub_event', $row);

        return $row;
    }
}

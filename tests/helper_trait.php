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

use enrol_mercadopagosub\tests\fixtures\mock_transport;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/mercadopagosub/tests/fixtures/mock_transport.php');

/**
 * Shared setup for the enrol_mercadopagosub test suite.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait helper_trait {
    /**
     * Enables the plugin with fake credentials and returns a scripted transport.
     *
     * Credential resolution ranks the process environment above the site
     * settings, so a machine with real credentials exported would otherwise
     * have this suite talking to a live account. PHPUnit's bootstrap discards
     * $CFG->enrol_mercadopagosub but cannot discard the environment, so this
     * clears it explicitly.
     *
     * @return mock_transport The transport to hand to an api_client.
     */
    protected function setup_plugin(): mock_transport {
        global $CFG;

        $this->resetAfterTest();

        foreach (['ACCESS_TOKEN', 'PUBLIC_KEY', 'WEBHOOK_SECRET'] as $name) {
            putenv('MERCADOPAGOSUB_' . $name);
        }

        // The plugin refuses to enable an instance on a site that is not
        // served over HTTPS, and Mercado Pago requires it for back_urls.
        $CFG->wwwroot = str_replace('http://', 'https://', $CFG->wwwroot);

        set_config('accesstoken', 'APP_USR-test-token-0123456789', 'enrol_mercadopagosub');
        set_config('webhooksecret', 'test-webhook-secret', 'enrol_mercadopagosub');

        $enabled = enrol_get_plugins(true);
        $enabled['mercadopagosub'] = true;
        set_config('enrol_plugins_enabled', implode(',', array_keys($enabled)));

        return new mock_transport();
    }

    /**
     * Creates a course with an enabled subscription enrolment instance.
     *
     * @param array $overrides Instance fields to override, e.g. customint5.
     * @return array{0: \stdClass, 1: \stdClass} The course and the enrol instance.
     */
    protected function create_instance(array $overrides = []): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $plugin = enrol_get_plugin('mercadopagosub');

        $fields = [
            'status' => ENROL_INSTANCE_ENABLED,
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
        $fields = $overrides + $fields;

        $instanceid = $plugin->add_instance($course, $fields);
        $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);

        return [$course, $instance];
    }

    /**
     * Inserts a subscription row for a user on an instance.
     *
     * @param \stdClass $instance Enrol instance.
     * @param \stdClass $user Subscriber.
     * @param array $overrides Row fields to override.
     * @return \stdClass The inserted row, with its id.
     */
    protected function create_subscription(\stdClass $instance, \stdClass $user, array $overrides = []): \stdClass {
        global $DB;

        $now = time();

        $record = (object)($overrides + [
            'enrolid' => $instance->id,
            'userid' => $user->id,
            'preapprovalid' => 'preapproval-' . $user->id . '-' . random_int(1000, 9999),
            'externalreference' => util::make_reference($instance->id, $user->id),
            'payeremail' => 'payer' . $user->id . '@example.com',
            'state' => 'active',
            'endreason' => null,
            'mpstatus' => 'authorized',
            'amount' => 1000,
            'currency' => 'ARS',
            'frequency' => 1,
            'frequencytype' => 'months',
            'trialfrequency' => null,
            'trialfrequencytype' => null,
            'nextpaymentdate' => $now + WEEKSECS,
            'enddate' => null,
            'payerid' => null,
            'paymentmethodid' => null,
            'dunningstage' => 0,
            'dunningsince' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timeauthorized' => $now,
            'timeended' => 0,
            'timesynced' => $now,
            'extras' => null,
        ]);

        $record->id = $DB->insert_record('enrol_mercadopagosub_sub', $record);

        return $record;
    }

    /**
     * Queues a webhook event row, as webhook.php would have written it.
     *
     * @param string $topic Notification type.
     * @param string $resourceid The data.id it carried.
     * @param array $overrides Row fields to override.
     * @return \stdClass The inserted row, with its id.
     */
    protected function queue_event(string $topic, string $resourceid, array $overrides = []): \stdClass {
        global $DB;

        $record = (object)($overrides + [
            'topic' => $topic,
            'resourceid' => $resourceid,
            'notificationid' => (string)random_int(100000, 999999),
            'requestid' => 'request-' . random_int(1000, 9999),
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
        ]);

        $record->id = $DB->insert_record('enrol_mercadopagosub_event', $record);

        return $record;
    }

    /**
     * Builds a GET /preapproval/{id} response body.
     *
     * @param \stdClass $sub The local row it should correspond to.
     * @param string $status Mercado Pago status to report.
     * @param array $overrides Extra or replacement fields.
     * @return array
     */
    protected function subscription_response(\stdClass $sub, string $status, array $overrides = []): array {
        return $overrides + [
            'id' => $sub->preapprovalid,
            'status' => $status,
            'external_reference' => $sub->externalreference,
            'payer_id' => '1234567890',
            'payment_method_id' => 'visa',
            'next_payment_date' => date('c', time() + 30 * DAYSECS),
        ];
    }
}

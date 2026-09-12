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
 * Creates a plan-less Mercado Pago subscription for one enrolment instance.
 *
 * The request body follows API-FINDINGS.md §5 and the shape probe.php measured
 * as working (baseline_body()): reason, external_reference, payer_email,
 * auto_recurring (frequency, frequency_type, transaction_amount, currency_id,
 * and free_trial when the instance has a trial), back_url, status "pending".
 * notification_url is deliberately not sent — API-FINDINGS.md §1 measured it as
 * accepted and silently discarded; the real notification URL is configured once,
 * by hand, in Your integrations.
 *
 * Everything this plugin will later need to identify the subscription again
 * comes from what this class does here: external_reference is minted by
 * util::make_reference() before the call, so the local row and the API request
 * agree on it before either exists at the platform, and preapprovalid is read
 * back from the response, never guessed.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subscription_service {
    /** @var \stdClass Enrolment instance this subscription pays for. */
    private \stdClass $instance;

    /** @var api_client Client used to call the Mercado Pago API. */
    private api_client $client;

    /**
     * Constructor.
     *
     * @param \stdClass $instance Enrolment instance record.
     * @param api_client|null $client Defaults to one built from resolved credentials.
     */
    public function __construct(\stdClass $instance, ?api_client $client = null) {
        $this->instance = $instance;
        $this->client = $client ?? new api_client();
    }

    /**
     * Creates the subscription and its local record.
     *
     * @param \stdClass $user The learner starting the subscription. The payer may
     *                        be someone else entirely — see $payeremail.
     * @param string $payeremail The Mercado Pago address that will pay. Binding
     *                           and immutable once created — API-FINDINGS.md §12.
     * @return \stdClass The inserted enrol_mercadopagosub_sub row, with ->id set.
     * @throws \moodle_exception When the address belongs to an account
     *                           registered on another Mercado Pago site.
     * @throws api_exception On any other failure. Left unwrapped deliberately:
     *                       api_exception already carries the platform's error
     *                       code and body for whatever calls this to inspect,
     *                       and wrapping it here would throw that away.
     */
    public function create(\stdClass $user, string $payeremail): \stdClass {
        global $DB;

        $reference = util::make_reference($this->instance->id, $user->id);
        $body = $this->build_request_body($payeremail, $reference);

        try {
            $response = $this->client->create_subscription($body);
        } catch (api_exception $e) {
            if ($e->get_api_code() === api_client::CODE_SITE_MISMATCH) {
                // A named, learner-facing outcome, not a generic API failure:
                // this is the one error a correctly-configured site should
                // expect to see routinely, per API-FINDINGS.md §7.
                throw new \moodle_exception(
                    'error:mismatchedsite',
                    'enrol_mercadopagosub',
                    '',
                    null,
                    $e->getMessage()
                );
            }

            throw $e;
        }

        $hastrial = (int)$this->instance->customint3 > 0;

        $record = new \stdClass();
        $record->enrolid = $this->instance->id;
        $record->userid = $user->id;
        $record->preapprovalid = (string)($response['id'] ?? '');
        $record->externalreference = $reference;
        $record->payeremail = $payeremail;
        $record->state = 'pending';
        $record->mpstatus = (string)($response['status'] ?? 'pending');
        $record->amount = (float)$this->instance->cost;
        $record->currency = (string)$this->instance->currency;
        $record->frequency = (int)$this->instance->customint6;
        $record->frequencytype = (string)$this->instance->customchar1;
        $record->trialfrequency = $hastrial ? (int)$this->instance->customint3 : null;
        $record->trialfrequencytype = $hastrial ? (string)$this->instance->customchar2 : null;
        $record->nextpaymentdate = util::to_timestamp($response['next_payment_date'] ?? null);
        $record->enddate = null;
        $record->payerid = null;
        $record->paymentmethodid = null;
        $record->dunningstage = 0;
        $record->dunningsince = 0;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->timeauthorized = 0;
        $record->timeended = 0;
        $record->timesynced = time();
        // The init_point value has no column of its own — API-FINDINGS.md §11 notes it
        // changes shape once authorised (loses &activation=true) and its domain
        // varies by site, so it is not reconstructable from other columns and
        // is kept verbatim here rather than in a dedicated field.
        $record->extras = util::encode_for_storage([
            'initpoint' => (string)($response['init_point'] ?? ''),
        ]);

        $record->id = $DB->insert_record('enrol_mercadopagosub_sub', $record);

        return $record;
    }

    /**
     * Assembles the request body.
     *
     * @param string $payeremail The address supplied by the subscriber form.
     * @param string $reference External reference already minted for this attempt.
     * @return array
     */
    private function build_request_body(string $payeremail, string $reference): array {
        global $DB;

        $course = $DB->get_record(
            'course',
            ['id' => $this->instance->courseid],
            'id, fullname',
            MUST_EXIST
        );

        $autorecurring = [
            'frequency' => (int)$this->instance->customint6,
            'frequency_type' => (string)$this->instance->customchar1,
            'transaction_amount' => (float)$this->instance->cost,
            'currency_id' => (string)$this->instance->currency,
        ];

        if ((int)$this->instance->customint3 > 0) {
            $autorecurring['free_trial'] = [
                'frequency' => (int)$this->instance->customint3,
                'frequency_type' => (string)$this->instance->customchar2,
            ];
        }

        return [
            'reason' => get_string(
                'subscriptionreason',
                'enrol_mercadopagosub',
                format_string($course->fullname, true, ['context' => \context_course::instance($course->id)])
            ),
            'external_reference' => $reference,
            'payer_email' => $payeremail,
            'auto_recurring' => $autorecurring,
            'back_url' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            'status' => 'pending',
        ];
    }
}

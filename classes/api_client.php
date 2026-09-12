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
 * The Mercado Pago Subscriptions API, as this plugin uses it.
 *
 * Knows the endpoints, the request bodies and what a failure means. Knows nothing
 * about how bytes travel: that is the transport's job, and swapping curl for an
 * SDK is a matter of passing a different implementation to the constructor.
 *
 * Every method returns the decoded body on success and raises api_exception on
 * failure, carrying the platform's own body so the caller can branch on its code.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {
    /** @var string Base URL of the API. */
    private const BASE_URL = 'https://api.mercadopago.com';

    /** @var string Returned when the payer address belongs to an account in another country. */
    public const CODE_SITE_MISMATCH = 'guest_site_mismatch';

    /** @var credentials Credentials this client authenticates with. */
    private credentials $credentials;

    /** @var transport How requests are carried out. */
    private transport $transport;

    /**
     * Constructor.
     *
     * @param credentials|null $credentials Defaults to the resolved site credentials.
     * @param transport|null $transport Defaults to Moodle's curl wrapper.
     */
    public function __construct(?credentials $credentials = null, ?transport $transport = null) {
        $this->credentials = $credentials ?? credentials::resolve();
        $this->transport = $transport ?? new curl_transport();
    }

    /**
     * Reads the account the credentials belong to.
     *
     * Reports the marketplace site, and therefore the currency this site can
     * charge in and the country a subscriber's own account must belong to. Also
     * the cheapest possible check that the credentials work at all.
     *
     * @return array Decoded account record.
     * @throws api_exception
     */
    public function get_account(): array {
        return $this->request('GET', '/users/me');
    }

    /**
     * Creates a subscription with no associated plan.
     *
     * The caller supplies the whole body. This method does not assemble it: the
     * shape depends on instance settings and on whether a free trial applies, and
     * hiding that behind named arguments would obscure what is actually sent.
     *
     * The payer address in that body is binding. Only an account holding that
     * address can authorise the resulting subscription, and it cannot be changed
     * afterwards — an update is accepted and silently discarded. A wrong address
     * means cancelling this subscription and creating another.
     *
     * @param array $body Request body.
     * @return array Decoded subscription, including id and init_point.
     * @throws api_exception
     */
    public function create_subscription(array $body): array {
        return $this->request('POST', '/preapproval', $body, true);
    }

    /**
     * Reads a subscription.
     *
     * This is the call that decides what an enrolment should look like. A
     * notification only says that something changed; the answer comes from here.
     *
     * @param string $preapprovalid Subscription identifier.
     * @return array Decoded subscription.
     * @throws api_exception
     */
    public function get_subscription(string $preapprovalid): array {
        return $this->request('GET', '/preapproval/' . rawurlencode($preapprovalid));
    }

    /**
     * Changes the status of a subscription.
     *
     * Legal transitions, measured against the live API:
     *   pending    -> cancelled only; paused and authorized are both refused.
     *   authorized -> paused, authorized, cancelled, all accepted.
     *
     * Authorisation is never available to the collector. Only the payer can
     * authorise a subscription, and the API says so in as many words.
     *
     * Pausing does not move next_payment_date, so a subscription resumed after
     * that date has passed is in undocumented territory. This plugin does not
     * resume: a returning subscriber gets a new subscription at current prices.
     *
     * @param string $preapprovalid Subscription identifier.
     * @param string $status One of paused, authorized, cancelled.
     * @return array Decoded subscription after the change.
     * @throws api_exception
     */
    public function set_subscription_status(string $preapprovalid, string $status): array {
        return $this->request(
            'PUT',
            '/preapproval/' . rawurlencode($preapprovalid),
            ['status' => $status]
        );
    }

    /**
     * Lists the payments generated by a subscription.
     *
     * The reference labels these "invoices" while the guides call them authorized
     * payments, and only this path answers. The plausible-looking
     * /preapproval/{id}/authorized_payments returns 404.
     *
     * @param string $preapprovalid Subscription identifier.
     * @return array List of payment records, possibly empty.
     * @throws api_exception
     */
    public function get_authorized_payments(string $preapprovalid): array {
        $response = $this->request(
            'GET',
            '/authorized_payments/search?preapproval_id=' . rawurlencode($preapprovalid)
        );

        $results = $response['results'] ?? $response['elements'] ?? [];

        return is_array($results) ? $results : [];
    }

    /**
     * Performs one API call.
     *
     * @param string $method HTTP verb.
     * @param string $path Path below the base URL, with a leading slash.
     * @param array|null $body Request body, JSON encoded when present.
     * @param bool $idempotent Whether to send an idempotency key.
     * @return array Decoded response body.
     * @throws api_exception
     */
    private function request(string $method, string $path, ?array $body = null, bool $idempotent = false): array {
        if (!$this->credentials->is_complete()) {
            throw new api_exception('error:nocredentials');
        }

        $headers = [
            'Authorization: Bearer ' . $this->credentials->get_access_token(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($idempotent) {
            $headers[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
        }

        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);

        $response = $this->transport->request($method, self::BASE_URL . $path, $headers, $encoded);

        if ($response->transporterror !== '') {
            throw new api_exception('error:transport', 0, [], $response->transporterror);
        }

        if (!$response->is_successful()) {
            // The decoded body travels with the exception rather than being cast to
            // a string. A sibling plugin stringified an array here and logged the
            // literal "Array" for every API failure, throwing away the only
            // diagnostic the platform provides. Error shape is inconsistent — some
            // failures carry a code, some only a message — so the caller needs the
            // structure, not a rendering of it.
            throw new api_exception(
                'error:apifailed',
                $response->status,
                $response->body,
                util::encode_for_storage(util::redact($response->body), 1024)
            );
        }

        return $response->body;
    }
}

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
 * Verifies the x-signature header Mercado Pago sends on every notification.
 *
 * The manifest format below was deliberately left unverified in earlier
 * sessions of this project — HANDOVER.md said so explicitly, rather than
 * writing this from memory. It is now confirmed against Mercado Pago's own
 * developer documentation (mercadopago.com/developers/.../notifications/webhooks)
 * and cross-checked against three independent third-party implementations
 * (a Mercado Pago SDK maintainer's own forum answer, and two unrelated
 * integration write-ups), all agreeing on the same construction:
 *
 *   manifest = "id:{data.id};request-id:{x-request-id};ts:{ts};"
 *   expected = hex(HMAC-SHA256(manifest, webhook_secret))
 *   valid    = expected === v1
 *
 * ts and v1 arrive together in one header, x-signature, as
 * "ts=<epoch>,v1=<hex>" — measured directly against this plugin's own capture
 * log, matching what the documentation shows.
 *
 * data.id is specified as coming from the notification URL's query string, not
 * the JSON body. In every capture this plugin has taken so far the two values
 * were identical, but the caller should still prefer the query string value —
 * see webhook.php, which extracts it directly rather than through PHP's
 * superglobals, because PHP silently rewrites a "data.id" query key to
 * "data_id" in $_GET.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webhook_signature {
    /**
     * Verifies a signature.
     *
     * @param string $header Raw x-signature header value.
     * @param string $requestid Raw x-request-id header value.
     * @param string $dataid The data.id value from the notification URL's query string.
     * @param string $secret The webhook secret configured for this credential.
     * @return bool True only when both ts and v1 were present and v1 matches.
     */
    public static function verify(string $header, string $requestid, string $dataid, string $secret): bool {
        $parsed = self::parse($header);
        if ($parsed === null) {
            return false;
        }

        [$ts, $v1] = $parsed;
        $manifest = self::build_manifest($dataid, $requestid, $ts);
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $v1);
    }

    /**
     * Splits the x-signature header into its ts and v1 components.
     *
     * @param string $header Raw header value, for example "ts=123,v1=abc".
     * @return array{0: string, 1: string}|null [ts, v1], or null if either was
     *                                            absent or empty.
     */
    public static function parse(string $header): ?array {
        $ts = null;
        $v1 = null;

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            $key = trim($key);
            $value = trim($value);
            if ($key === 'ts') {
                $ts = $value;
            } else if ($key === 'v1') {
                $v1 = $value;
            }
        }

        if ($ts === null || $v1 === null || $ts === '' || $v1 === '') {
            return null;
        }

        return [$ts, $v1];
    }

    /**
     * Builds the manifest string that gets HMAC-signed.
     *
     * The id is lowercased defensively — one of the three implementations this
     * was cross-checked against does the same, on the theory that the platform
     * might not always send it in the same case it was created with. Every id
     * this plugin has actually captured was already lowercase hex, so this has
     * not been observed to matter in practice; it is kept because it costs
     * nothing and only one of the sources bothered to mention it, which is
     * exactly the kind of detail worth erring toward rather than assuming away.
     *
     * @param string $dataid The data.id value.
     * @param string $requestid The x-request-id header value.
     * @param string $ts The ts component of x-signature.
     * @return string
     */
    public static function build_manifest(string $dataid, string $requestid, string $ts): string {
        return sprintf('id:%s;request-id:%s;ts:%s;', strtolower($dataid), $requestid, $ts);
    }
}

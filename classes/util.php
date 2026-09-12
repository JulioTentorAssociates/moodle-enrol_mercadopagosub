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
 * Small helpers shared across the plugin.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {
    /** @var string Marker appended to a payload that had to be cut short. */
    private const TRUNCATION_MARKER = '..."(truncated)"}';

    /** @var int Default ceiling for anything written to a text column. */
    public const STORAGE_MAX_LENGTH = 8192;

    /** @var string Prefix identifying an external reference minted by this plugin. */
    public const REFERENCE_PREFIX = 'mps';

    /**
     * Keys whose values must never reach the database or the log.
     *
     * Matched case-insensitively against array keys at any depth. Card and
     * identification data appear inside Mercado Pago error bodies, which this
     * plugin stores verbatim for diagnosis.
     *
     * Email addresses are deliberately absent from this list. In this plugin the
     * payer is frequently not the learner — an employer or a client company pays
     * for someone else's course — so the address is billing data, it is the only
     * identifier that can be matched against a Mercado Pago account, and an
     * administrator helping with a rejected payment needs to see it. It is stored
     * in its own column as well; redacting it from payloads would hide it in
     * exactly the diagnostics where it matters without removing it from the site.
     *
     * @var string[]
     */
    private const SENSITIVE_KEYS = [
        'access_token',
        'card',
        'card_number',
        'cardholder',
        'client_secret',
        'first_name',
        'identification',
        'last_name',
        'number',
        'phone',
        'public_key',
        'security_code',
        'token',
        'webhook_secret',
    ];

    /**
     * Replaces the value of every sensitive key with a fixed placeholder.
     *
     * Structure is preserved so that a redacted payload remains readable as
     * evidence: what is removed is the value, never the shape.
     *
     * @param mixed $data Decoded payload, or any scalar.
     * @return mixed The same structure with sensitive values replaced.
     */
    public static function redact($data) {
        if (!is_array($data)) {
            return $data;
        }

        $redacted = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $redacted;
    }

    /**
     * Encodes a payload as JSON, capped at a maximum byte length.
     *
     * The room reserved for the marker is derived from the marker itself. An
     * earlier plugin in this family hardcoded the figure, got it wrong by one,
     * and produced payloads one byte over the cap on every truncation.
     *
     * @param mixed $data Payload to encode. Redact it first if it came from the API.
     * @param int $maxlength Maximum length of the returned string, in bytes.
     * @return string JSON, never longer than $maxlength.
     */
    public static function encode_for_storage($data, int $maxlength = self::STORAGE_MAX_LENGTH): string {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = json_encode(['encoding_failed' => json_last_error_msg()]);
        }

        if (strlen($encoded) <= $maxlength) {
            return $encoded;
        }

        $room = $maxlength - strlen(self::TRUNCATION_MARKER);
        if ($room <= 0) {
            return substr(self::TRUNCATION_MARKER, 0, $maxlength);
        }

        return substr($encoded, 0, $room) . self::TRUNCATION_MARKER;
    }

    /**
     * Mints the external reference for a new subscription.
     *
     * This is the only field that travels to Mercado Pago and comes back, so it
     * carries everything needed to find the local record without a lookup table.
     * The site fragment matters because two Moodle sites can legitimately share
     * one Mercado Pago account, and their enrolment ids will collide.
     *
     * The random tail exists because a subscriber who leaves and returns gets a
     * new subscription for the same instance and user, and the column is unique.
     *
     * @param int $enrolid Enrolment instance id.
     * @param int $userid User id.
     * @return string A reference of about 45 characters.
     */
    public static function make_reference(int $enrolid, int $userid): string {
        global $CFG;

        $site = substr(hash('sha256', $CFG->wwwroot), 0, 8);

        return implode('-', [
            self::REFERENCE_PREFIX,
            $site,
            $enrolid,
            $userid,
            bin2hex(random_bytes(6)),
        ]);
    }

    /**
     * Extracts the enrolment instance and user from a reference minted here.
     *
     * Used as a fallback when a notification arrives for a subscription this site
     * has no row for. A reference that does not parse, or whose site fragment does
     * not match, belongs to somebody else and must be ignored rather than guessed at.
     *
     * @param string $reference The external reference as returned by the API.
     * @return array|null ['enrolid' => int, 'userid' => int] or null when foreign.
     */
    public static function parse_reference(string $reference): ?array {
        global $CFG;

        $parts = explode('-', $reference);
        if (count($parts) !== 5 || $parts[0] !== self::REFERENCE_PREFIX) {
            return null;
        }

        if ($parts[1] !== substr(hash('sha256', $CFG->wwwroot), 0, 8)) {
            return null;
        }

        if (!ctype_digit($parts[2]) || !ctype_digit($parts[3])) {
            return null;
        }

        return ['enrolid' => (int)$parts[2], 'userid' => (int)$parts[3]];
    }

    /**
     * Converts a Mercado Pago timestamp to a Unix timestamp.
     *
     * The API returns ISO 8601 with an explicit offset, for example
     * 2026-09-30T11:21:44.000-04:00. Parsing failures return 0 rather than
     * throwing, because a malformed date in a notification must not abort the
     * processing of everything else in it.
     *
     * @param string|null $value Timestamp as received.
     * @return int Unix timestamp, or 0 when absent or unparseable.
     */
    public static function to_timestamp(?string $value): int {
        if ($value === null || trim($value) === '') {
            return 0;
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception $e) {
            return 0;
        }
    }
}

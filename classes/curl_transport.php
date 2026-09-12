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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Transport built on Moodle's curl wrapper.
 *
 * Using core's wrapper rather than raw curl means proxy configuration, the
 * outgoing request security helper and the site's own timeout conventions apply
 * without this plugin reimplementing any of them.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class curl_transport implements transport {
    /** @var int Seconds to wait for a complete response. */
    private const TIMEOUT = 30;

    /** @var int Seconds to wait for the connection. */
    private const CONNECT_TIMEOUT = 10;

    /**
     * Performs one HTTP exchange.
     *
     * @param string $method HTTP verb, uppercase.
     * @param string $url Absolute URL.
     * @param string[] $headers Header lines.
     * @param string|null $body Encoded request body.
     * @return http_response
     */
    public function request(string $method, string $url, array $headers, ?string $body = null): http_response {
        $curl = new \curl();
        $curl->setHeader($headers);

        $options = [
            'CURLOPT_TIMEOUT' => self::TIMEOUT,
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => false,
        ];

        $encoded = $body ?? '';

        switch ($method) {
            case 'GET':
                $raw = $curl->get($url, [], $options);
                break;
            case 'POST':
                $raw = $curl->post($url, $encoded, $options);
                break;
            case 'PUT':
                // VERIFY on a real site. curl::put() is shaped for file uploads and
                // its handling of a raw JSON body is not confirmed for this release,
                // so the verb is set explicitly on a POST instead. The design probe
                // performed these PUTs with plain curl and they behaved as expected.
                $options['CURLOPT_CUSTOMREQUEST'] = 'PUT';
                $raw = $curl->post($url, $encoded, $options);
                break;
            default:
                throw new \coding_exception('Unsupported HTTP method: ' . $method);
        }

        $errno = $curl->get_errno();
        if ($errno) {
            return new http_response(0, [], '', 'curl errno ' . $errno);
        }

        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);
        $raw = is_string($raw) ? $raw : '';

        $decoded = [];
        if ($raw !== '') {
            $candidate = json_decode($raw, true);
            if (is_array($candidate)) {
                $decoded = $candidate;
            }
        }

        return new http_response($status, $decoded, $raw);
    }
}

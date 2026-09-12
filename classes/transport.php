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
 * The one thing this plugin needs from an HTTP mechanism.
 *
 * This plugin ships with curl_transport and no vendored SDK. Mercado Pago's own
 * documentation for the Subscriptions API is written in curl, the design of this
 * plugin was verified against the live API with curl, and the surface in use is
 * four endpoints. Adding an SDK would add several hundred files to audit and keep
 * byte-identical, for no capability the plugin exercises.
 *
 * The interface exists so that decision stays reversible. Replacing the mechanism
 * means writing one class that implements this interface and passing it to
 * api_client; nothing else in the plugin refers to curl, to HTTP headers, or to
 * request encoding. In particular, api_client owns the endpoint paths, the request
 * bodies and the interpretation of failures — a transport only moves bytes.
 *
 * An implementation must not throw on an HTTP error status. A 400 is a completed
 * exchange and is reported through http_response::$status, because the platform's
 * error body is the only diagnostic it provides and discarding it has, in a sibling
 * plugin, cost real debugging time. Reserve $transporterror for exchanges that
 * never completed at all.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface transport {
    /**
     * Performs one HTTP exchange.
     *
     * @param string $method HTTP verb, uppercase.
     * @param string $url Absolute URL.
     * @param string[] $headers Header lines, each in "Name: value" form.
     * @param string|null $body Request body, already encoded, or null for none.
     * @return http_response Never null; failures are described in the response.
     */
    public function request(string $method, string $url, array $headers, ?string $body = null): http_response;
}

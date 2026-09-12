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

namespace enrol_mercadopagosub\tests\fixtures;

use enrol_mercadopagosub\http_response;
use enrol_mercadopagosub\transport;

/**
 * A transport that answers from a script instead of the network.
 *
 * Responses are queued against a substring of the request URL, so a test says
 * what the platform would return for "/preapproval/" without restating the
 * whole endpoint. Every exchange is recorded, which is how the tests assert
 * that a sweep really did issue its own GET rather than relying on whatever
 * the previous call left behind.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mock_transport implements transport {
    /** @var array<string, array<int, http_response>> Queued responses, keyed by URL fragment. */
    private array $queues = [];

    /** @var array<int, array{method: string, url: string, headers: string[], body: ?string}> Every exchange, in order. */
    public array $calls = [];

    /**
     * Queues a successful JSON response for requests whose URL contains $fragment.
     *
     * @param string $fragment Substring of the URL this response answers.
     * @param array $body Decoded body to return.
     * @param int $status HTTP status to report.
     * @return self
     */
    public function on(string $fragment, array $body, int $status = 200): self {
        $this->queues[$fragment][] = new http_response($status, $body, json_encode($body));

        return $this;
    }

    /**
     * Queues a failed exchange — one that never completed at all.
     *
     * @param string $fragment Substring of the URL this response answers.
     * @param string $error Transport-level error description.
     * @return self
     */
    public function on_transport_error(string $fragment, string $error): self {
        $this->queues[$fragment][] = new http_response(0, [], '', $error);

        return $this;
    }

    /**
     * How many requests were made whose URL contains $fragment.
     *
     * @param string $fragment
     * @return int
     */
    public function count_calls(string $fragment): int {
        return count(array_filter(
            $this->calls,
            static fn(array $call): bool => str_contains($call['url'], $fragment)
        ));
    }

    /**
     * Answers one request.
     *
     * The last queued response for a fragment is reused once the queue runs
     * dry, so a test that sweeps twice does not have to queue the same
     * subscription twice to say "nothing changed".
     *
     * @param string $method HTTP verb.
     * @param string $url Absolute URL.
     * @param array $headers Header lines.
     * @param string|null $body Request body.
     * @return http_response
     */
    public function request(string $method, string $url, array $headers, ?string $body = null): http_response {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        foreach ($this->queues as $fragment => $responses) {
            if (!str_contains($url, $fragment)) {
                continue;
            }
            if (count($responses) > 1) {
                return array_shift($this->queues[$fragment]);
            }

            return $responses[0];
        }

        return new http_response(404, ['message' => 'no response queued for ' . $url], '');
    }
}

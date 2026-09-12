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
 * Raised when a Mercado Pago API call fails.
 *
 * The decoded body is carried through rather than flattened to a string. Error
 * shape on this API is inconsistent — some failures return a code and some return
 * only a message — so the caller needs the structure, not a rendering of it.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends \moodle_exception {
    /** @var int HTTP status, or 0 when the request never completed. */
    protected int $httpstatus;

    /** @var array Decoded response body. Empty when the body was not JSON. */
    protected array $body;

    /**
     * Constructor.
     *
     * @param string $errorcode Language string key in this component.
     * @param int $httpstatus HTTP status code.
     * @param array $body Decoded response body.
     * @param string|null $debuginfo Additional detail for developers.
     */
    public function __construct(
        string $errorcode,
        int $httpstatus = 0,
        array $body = [],
        ?string $debuginfo = null
    ) {
        $this->httpstatus = $httpstatus;
        $this->body = $body;

        parent::__construct($errorcode, 'enrol_mercadopagosub', '', null, $debuginfo);
    }

    /**
     * Returns the HTTP status.
     *
     * @return int
     */
    public function get_http_status(): int {
        return $this->httpstatus;
    }

    /**
     * Returns the decoded body.
     *
     * @return array
     */
    public function get_body(): array {
        return $this->body;
    }

    /**
     * Returns the platform's error code, when it supplied one.
     *
     * Known values worth branching on:
     *   guest_site_mismatch — the address belongs to an account in another country.
     *
     * @return string Empty when the body carried no code.
     */
    public function get_api_code(): string {
        return (string)($this->body['code'] ?? '');
    }

    /**
     * Returns the platform's message, when it supplied one.
     *
     * @return string
     */
    public function get_api_message(): string {
        return (string)($this->body['message'] ?? '');
    }
}

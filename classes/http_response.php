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
 * What a transport hands back for one HTTP exchange.
 *
 * Deliberately narrow. Anything an SDK would add — typed models, retry metadata,
 * pagination cursors — is not represented here, because representing it would
 * make the interface impossible for a plain HTTP implementation to satisfy.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_response {
    /** @var int HTTP status code. Zero when the exchange never completed. */
    public readonly int $status;

    /** @var array Decoded JSON body. Empty when the body was absent or not JSON. */
    public readonly array $body;

    /** @var string Raw body, kept for diagnostics when decoding failed. */
    public readonly string $raw;

    /** @var string Transport-level failure description. Empty on a completed exchange. */
    public readonly string $transporterror;

    /**
     * Constructor.
     *
     * @param int $status HTTP status code.
     * @param array $body Decoded body.
     * @param string $raw Raw body.
     * @param string $transporterror Transport failure description, if any.
     */
    public function __construct(int $status, array $body = [], string $raw = '', string $transporterror = '') {
        $this->status = $status;
        $this->body = $body;
        $this->raw = $raw;
        $this->transporterror = $transporterror;
    }

    /**
     * Whether the exchange completed with a 2xx status.
     *
     * @return bool
     */
    public function is_successful(): bool {
        return $this->transporterror === '' && $this->status >= 200 && $this->status < 300;
    }
}

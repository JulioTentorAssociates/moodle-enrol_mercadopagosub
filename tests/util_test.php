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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the shared helpers.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(util::class)]
final class util_test extends \advanced_testcase {
    /**
     * Sensitive values are replaced while the shape of the payload survives.
     *
     * @return void
     */
    public function test_redact_replaces_values_and_keeps_structure(): void {
        $redacted = util::redact([
            'status' => 'approved',
            'card' => ['last_four_digits' => '1234'],
            'payer' => [
                'email' => 'payer@example.com',
                'identification' => ['type' => 'DNI', 'number' => '12345678'],
                'first_name' => 'Someone',
            ],
        ]);

        $this->assertSame('approved', $redacted['status']);
        $this->assertSame('[redacted]', $redacted['card']);
        $this->assertSame('[redacted]', $redacted['payer']['identification']);
        $this->assertSame('[redacted]', $redacted['payer']['first_name']);
        $this->assertArrayHasKey('payer', $redacted);
    }

    /**
     * The payer's address survives redaction, deliberately.
     *
     * It is billing data an administrator needs in order to trace a rejected
     * payment, it is stored in its own column anyway, and in this plugin the
     * payer is frequently not the learner. See util::SENSITIVE_KEYS.
     *
     * @return void
     */
    public function test_redact_keeps_the_payer_email(): void {
        $redacted = util::redact(['payer' => ['email' => 'payer@example.com']]);

        $this->assertSame('payer@example.com', $redacted['payer']['email']);
    }

    /**
     * Key matching ignores case, as the API is not consistent about it.
     *
     * @return void
     */
    public function test_redact_matches_keys_case_insensitively(): void {
        $redacted = util::redact(['Access_Token' => 'APP_USR-secret', 'CARD' => '4509']);

        $this->assertSame('[redacted]', $redacted['Access_Token']);
        $this->assertSame('[redacted]', $redacted['CARD']);
    }

    /**
     * A scalar is returned untouched rather than wrapped or rejected.
     *
     * @return void
     */
    public function test_redact_passes_scalars_through(): void {
        $this->assertSame('plain', util::redact('plain'));
        $this->assertSame(7, util::redact(7));
        $this->assertNull(util::redact(null));
    }

    /**
     * A payload within the cap is stored verbatim.
     *
     * @return void
     */
    public function test_encode_for_storage_leaves_short_payloads_alone(): void {
        $encoded = util::encode_for_storage(['id' => 'abc'], 100);

        $this->assertSame('{"id":"abc"}', $encoded);
    }

    /**
     * Truncation never exceeds the cap, which is where a sibling plugin was
     * off by one: it reserved a hardcoded figure for the marker instead of
     * deriving it, and produced payloads one byte over on every truncation.
     *
     * @return void
     */
    public function test_encode_for_storage_never_exceeds_the_cap(): void {
        $long = ['payload' => str_repeat('x', 5000)];

        foreach ([20, 64, 100, 512] as $cap) {
            $encoded = util::encode_for_storage($long, $cap);
            $this->assertLessThanOrEqual($cap, strlen($encoded), "cap of {$cap} exceeded");
        }
    }

    /**
     * A cap too small for the marker still returns something within the cap.
     *
     * @return void
     */
    public function test_encode_for_storage_handles_a_cap_below_the_marker(): void {
        $encoded = util::encode_for_storage(['payload' => str_repeat('x', 100)], 5);

        $this->assertSame(5, strlen($encoded));
    }

    /**
     * Slashes and accented characters are stored as themselves, so that a
     * stored payload stays readable as evidence.
     *
     * @return void
     */
    public function test_encode_for_storage_does_not_escape_slashes_or_unicode(): void {
        $encoded = util::encode_for_storage(['url' => 'https://mp.com/x', 'name' => 'Jujuy Ñ']);

        $this->assertStringContainsString('https://mp.com/x', $encoded);
        $this->assertStringContainsString('Ñ', $encoded);
    }

    /**
     * A reference minted here parses back to the instance and user it names.
     *
     * @return void
     */
    public function test_reference_round_trips(): void {
        $this->resetAfterTest();

        $reference = util::make_reference(42, 7);
        $parsed = util::parse_reference($reference);

        $this->assertNotNull($parsed);
        $this->assertSame(42, $parsed['enrolid']);
        $this->assertSame(7, $parsed['userid']);
    }

    /**
     * Two references for the same instance and user differ, because the column
     * is unique and a subscriber who leaves and returns needs a second row.
     *
     * @return void
     */
    public function test_reference_is_unique_per_call(): void {
        $this->resetAfterTest();

        $this->assertNotSame(util::make_reference(42, 7), util::make_reference(42, 7));
    }

    /**
     * A reference minted by another site is foreign and must not be claimed,
     * even though its shape is valid and its ids would resolve locally.
     *
     * @return void
     */
    public function test_parse_reference_rejects_another_site(): void {
        global $CFG;

        $this->resetAfterTest();

        $reference = util::make_reference(42, 7);
        $CFG->wwwroot = 'https://somewhere.else.example.com';

        $this->assertNull(util::parse_reference($reference));
    }

    /**
     * Malformed references are rejected rather than guessed at.
     *
     * @return void
     */
    public function test_parse_reference_rejects_malformed_input(): void {
        $this->resetAfterTest();

        $reference = util::make_reference(42, 7);
        $parts = explode('-', $reference);

        $this->assertNull(util::parse_reference(''));
        $this->assertNull(util::parse_reference('mps-1-2'));
        $this->assertNull(util::parse_reference('xxx-' . implode('-', array_slice($parts, 1))));
        $this->assertNull(util::parse_reference(
            implode('-', [$parts[0], $parts[1], 'notanumber', $parts[3], $parts[4]])
        ));
    }

    /**
     * Mercado Pago timestamps carry an explicit offset and must be honoured.
     *
     * @return void
     */
    public function test_to_timestamp_reads_an_offset(): void {
        $this->assertSame(
            (new \DateTimeImmutable('2026-09-30T15:21:44+00:00'))->getTimestamp(),
            util::to_timestamp('2026-09-30T11:21:44.000-04:00')
        );
    }

    /**
     * An absent or unparseable date yields 0 rather than throwing, so that one
     * bad field in a notification cannot abort the rest of its processing.
     *
     * @return void
     */
    public function test_to_timestamp_returns_zero_for_anything_unusable(): void {
        $this->assertSame(0, util::to_timestamp(null));
        $this->assertSame(0, util::to_timestamp(''));
        $this->assertSame(0, util::to_timestamp('   '));
        $this->assertSame(0, util::to_timestamp('not a date'));
    }
}

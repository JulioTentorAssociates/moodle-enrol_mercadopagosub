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
 * Tests for x-signature verification.
 *
 * The manifest these exercise is the one Mercado Pago documents:
 * "id:{data.id};request-id:{x-request-id};ts:{ts};", HMAC-SHA256 with the
 * webhook secret, compared against v1. Everything here builds its expected
 * value with hash_hmac() directly rather than through the class under test,
 * so a change to the manifest breaks these rather than passing silently.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(webhook_signature::class)]
final class webhook_signature_test extends \advanced_testcase {
    /** @var string A webhook secret of the shape Mercado Pago issues. */
    private const SECRET = 'f1e2d3c4b5a6978877665544332211aabbccddee';

    /** @var string A preapproval id of the shape the platform sends. */
    private const DATA_ID = '2c938084726fca480172750000000000';

    /** @var string An x-request-id of the shape the platform sends. */
    private const REQUEST_ID = 'fb1e0c8d-1b2a-4c3d-9e8f-0a1b2c3d4e5f';

    /**
     * Builds the header the platform would send for the given parts.
     *
     * @param string $ts Timestamp component.
     * @param string $dataid The data.id the signature was computed over.
     * @param string $requestid The x-request-id the signature was computed over.
     * @param string $secret Secret to sign with.
     * @return string A complete x-signature header value.
     */
    private function sign(
        string $ts,
        string $dataid = self::DATA_ID,
        string $requestid = self::REQUEST_ID,
        string $secret = self::SECRET
    ): string {
        $manifest = sprintf('id:%s;request-id:%s;ts:%s;', strtolower($dataid), $requestid, $ts);

        return 'ts=' . $ts . ',v1=' . hash_hmac('sha256', $manifest, $secret);
    }

    /**
     * A correctly signed notification verifies.
     *
     * @return void
     */
    public function test_verify_accepts_a_correct_signature(): void {
        $header = $this->sign('1757700000');

        $this->assertTrue(
            webhook_signature::verify($header, self::REQUEST_ID, self::DATA_ID, self::SECRET)
        );
    }

    /**
     * Every component of the manifest is load-bearing: changing any one of
     * them while keeping the signature must fail verification.
     *
     * @return void
     */
    public function test_verify_rejects_any_tampered_component(): void {
        $header = $this->sign('1757700000');

        $this->assertFalse(
            webhook_signature::verify($header, self::REQUEST_ID, 'a-different-preapproval-id', self::SECRET),
            'a substituted data.id was accepted'
        );
        $this->assertFalse(
            webhook_signature::verify($header, 'a-different-request-id', self::DATA_ID, self::SECRET),
            'a substituted request id was accepted'
        );
        $this->assertFalse(
            webhook_signature::verify($header, self::REQUEST_ID, self::DATA_ID, 'the-wrong-secret'),
            'the wrong secret was accepted'
        );
        $this->assertFalse(
            webhook_signature::verify(
                str_replace('ts=1757700000', 'ts=1757700001', $header),
                self::REQUEST_ID,
                self::DATA_ID,
                self::SECRET
            ),
            'a substituted timestamp was accepted'
        );
    }

    /**
     * An empty secret verifies nothing, even though HMAC accepts one.
     *
     * A site with no webhook secret configured yet must land in the "absent"
     * branch rather than accidentally matching a signature computed with an
     * empty key.
     *
     * @return void
     */
    public function test_verify_rejects_a_signature_from_a_real_secret_when_none_is_configured(): void {
        $header = $this->sign('1757700000');

        $this->assertFalse(
            webhook_signature::verify($header, self::REQUEST_ID, self::DATA_ID, '')
        );
    }

    /**
     * The id is lowercased before signing, so an uppercased id still verifies.
     *
     * @return void
     */
    public function test_verify_is_insensitive_to_the_case_of_the_id(): void {
        $header = $this->sign('1757700000', strtolower(self::DATA_ID));

        $this->assertTrue(
            webhook_signature::verify($header, self::REQUEST_ID, strtoupper(self::DATA_ID), self::SECRET)
        );
    }

    /**
     * A header this plugin cannot read is not a signature failure but an
     * absent signature; verify() reports false and webhook.php distinguishes
     * the two by asking parse() first.
     *
     * @return void
     */
    public function test_verify_rejects_unparseable_headers(): void {
        foreach (['', 'garbage', 'ts=123', 'v1=abc', 'ts=,v1=abc', 'ts=123,v1='] as $header) {
            $this->assertFalse(
                webhook_signature::verify($header, self::REQUEST_ID, self::DATA_ID, self::SECRET),
                "header '{$header}' was accepted"
            );
        }
    }

    /**
     * parse() returns both components, in order, for a well-formed header.
     *
     * @return void
     */
    public function test_parse_splits_the_header(): void {
        $this->assertSame(['1757700000', 'abc123'], webhook_signature::parse('ts=1757700000,v1=abc123'));
    }

    /**
     * Whitespace around the separators is tolerated, and the order of the two
     * components is not assumed.
     *
     * @return void
     */
    public function test_parse_tolerates_spacing_and_order(): void {
        $this->assertSame(['1757700000', 'abc123'], webhook_signature::parse(' ts = 1757700000 , v1 = abc123 '));
        $this->assertSame(['1757700000', 'abc123'], webhook_signature::parse('v1=abc123,ts=1757700000'));
    }

    /**
     * A header missing either component yields null, which is what tells
     * webhook.php to record the notification as unsigned rather than failed.
     *
     * @return void
     */
    public function test_parse_returns_null_when_a_component_is_missing(): void {
        $this->assertNull(webhook_signature::parse(''));
        $this->assertNull(webhook_signature::parse('ts=1757700000'));
        $this->assertNull(webhook_signature::parse('v1=abc123'));
        $this->assertNull(webhook_signature::parse('ts=1757700000,v1='));
        $this->assertNull(webhook_signature::parse('nothing-like-a-signature'));
    }

    /**
     * The manifest is exactly what the documentation specifies, including the
     * trailing semicolon, which is easy to lose and silently breaks every
     * verification if it is.
     *
     * @return void
     */
    public function test_manifest_has_the_documented_shape(): void {
        $this->assertSame(
            'id:abc;request-id:req-1;ts:123;',
            webhook_signature::build_manifest('ABC', 'req-1', '123')
        );
    }
}

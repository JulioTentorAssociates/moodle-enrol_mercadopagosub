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
 * Tests for credential resolution.
 *
 * The precedence these pin down is a deployment contract, not an internal
 * detail: an operator who puts credentials in config.php expects them to win
 * over whatever is in the site settings, and a machine with the environment
 * variables set expects those to win over the settings too. Getting the order
 * wrong means a site silently charging through the wrong Mercado Pago account.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(credentials::class)]
final class credentials_test extends \advanced_testcase {
    /**
     * Clears the three environment variables so a real server's own
     * credentials cannot leak into the assertions.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        foreach (['ACCESS_TOKEN', 'PUBLIC_KEY', 'WEBHOOK_SECRET'] as $name) {
            putenv('MERCADOPAGOSUB_' . $name);
        }
    }

    /**
     * Leaves the environment as it was found.
     *
     * @return void
     */
    protected function tearDown(): void {
        foreach (['ACCESS_TOKEN', 'PUBLIC_KEY', 'WEBHOOK_SECRET'] as $name) {
            putenv('MERCADOPAGOSUB_' . $name);
        }

        parent::tearDown();
    }

    /**
     * With nothing configured anywhere, resolution reports no source rather
     * than inventing one, and the plugin is not usable.
     *
     * @return void
     */
    public function test_nothing_configured_resolves_to_none(): void {
        $this->resetAfterTest();

        $credentials = credentials::resolve();

        $this->assertSame('none', $credentials->get_source());
        $this->assertFalse($credentials->is_complete());
        $this->assertFalse($credentials->can_verify_signatures());
        $this->assertSame('(not set)', $credentials->get_redacted_token());
    }

    /**
     * Site settings are the fallback when neither config.php nor the
     * environment supplies a token.
     *
     * @return void
     */
    public function test_settings_are_used_when_nothing_outranks_them(): void {
        $this->resetAfterTest();

        set_config('accesstoken', 'APP_USR-from-settings', 'enrol_mercadopagosub');
        set_config('webhooksecret', 'secret-from-settings', 'enrol_mercadopagosub');

        $credentials = credentials::resolve();

        $this->assertSame('settings', $credentials->get_source());
        $this->assertSame('APP_USR-from-settings', $credentials->get_access_token());
        $this->assertTrue($credentials->is_complete());
        $this->assertTrue($credentials->can_verify_signatures());
    }

    /**
     * The environment outranks the settings.
     *
     * @return void
     */
    public function test_environment_outranks_settings(): void {
        $this->resetAfterTest();

        set_config('accesstoken', 'APP_USR-from-settings', 'enrol_mercadopagosub');
        putenv('MERCADOPAGOSUB_ACCESS_TOKEN=APP_USR-from-environment');

        $credentials = credentials::resolve();

        $this->assertSame('environment', $credentials->get_source());
        $this->assertSame('APP_USR-from-environment', $credentials->get_access_token());
    }

    /**
     * config.php outranks everything, which is the case an operator relies on
     * when overriding a machine's environment for one site.
     *
     * @return void
     */
    public function test_config_outranks_environment_and_settings(): void {
        global $CFG;

        $this->resetAfterTest();

        set_config('accesstoken', 'APP_USR-from-settings', 'enrol_mercadopagosub');
        putenv('MERCADOPAGOSUB_ACCESS_TOKEN=APP_USR-from-environment');
        $CFG->enrol_mercadopagosub = [
            'accesstoken' => 'APP_USR-from-config',
            'publickey' => 'PUB-from-config',
            'webhooksecret' => 'secret-from-config',
        ];

        $credentials = credentials::resolve();

        $this->assertSame('config', $credentials->get_source());
        $this->assertSame('APP_USR-from-config', $credentials->get_access_token());
        $this->assertSame('PUB-from-config', $credentials->get_public_key());
        $this->assertSame('secret-from-config', $credentials->get_webhook_secret());
    }

    /**
     * A source is chosen on the access token alone, not on the other two
     * fields: a config.php block carrying only a webhook secret must not
     * outrank a settings-configured token.
     *
     * @return void
     */
    public function test_a_partial_config_block_does_not_outrank_a_real_token(): void {
        global $CFG;

        $this->resetAfterTest();

        set_config('accesstoken', 'APP_USR-from-settings', 'enrol_mercadopagosub');
        $CFG->enrol_mercadopagosub = ['webhooksecret' => 'secret-from-config'];

        $credentials = credentials::resolve();

        $this->assertSame('settings', $credentials->get_source());
        $this->assertSame('APP_USR-from-settings', $credentials->get_access_token());
    }

    /**
     * A malformed config.php entry is ignored rather than fatal.
     *
     * @return void
     */
    public function test_a_non_array_config_entry_is_ignored(): void {
        global $CFG;

        $this->resetAfterTest();

        $CFG->enrol_mercadopagosub = 'APP_USR-not-an-array';
        set_config('accesstoken', 'APP_USR-from-settings', 'enrol_mercadopagosub');

        $this->assertSame('settings', credentials::resolve()->get_source());
    }

    /**
     * A site can create and read subscriptions before its notification
     * endpoint is configured, so a missing webhook secret does not make the
     * credentials incomplete — it only disables signature verification.
     *
     * @return void
     */
    public function test_a_missing_webhook_secret_does_not_make_credentials_incomplete(): void {
        $this->resetAfterTest();

        set_config('accesstoken', 'APP_USR-token', 'enrol_mercadopagosub');

        $credentials = credentials::resolve();

        $this->assertTrue($credentials->is_complete());
        $this->assertFalse($credentials->can_verify_signatures());
    }

    /**
     * Test credentials are recognised, so an administrator can be warned that
     * the site is configured against an account no real buyer can pay.
     *
     * @return void
     */
    public function test_test_credentials_are_recognised(): void {
        $this->resetAfterTest();

        set_config('accesstoken', 'TEST-1234567890', 'enrol_mercadopagosub');
        $this->assertTrue(credentials::resolve()->is_test_credential());

        set_config('accesstoken', 'APP_USR-1234567890', 'enrol_mercadopagosub');
        $this->assertFalse(credentials::resolve()->is_test_credential());
    }

    /**
     * Nothing that could be replayed against the API survives redaction, in
     * either the diagnostic string or the debug representation.
     *
     * @return void
     */
    public function test_the_token_never_appears_in_full_in_diagnostics(): void {
        global $CFG;

        $this->resetAfterTest();

        $token = 'APP_USR-0123456789-abcdef-secret-tail';
        $CFG->enrol_mercadopagosub = [
            'accesstoken' => $token,
            'publickey' => 'PUB-key',
            'webhooksecret' => 'a-secret',
        ];

        $credentials = credentials::resolve();
        $redacted = $credentials->get_redacted_token();

        // What __debugInfo() exposes is what a var_dump() or a print_r() in a
        // log would show; print_r() itself is a forbidden function in Moodle
        // code, so the same thing is done here through the magic method.
        $debug = json_encode($credentials->__debugInfo());

        $this->assertStringStartsWith('APP_USR-0123', $redacted);
        $this->assertStringEndsWith('...', $redacted);
        $this->assertStringNotContainsString('secret-tail', $redacted);
        $this->assertStringNotContainsString('secret-tail', $debug);
        $this->assertStringNotContainsString('a-secret', $debug);
    }

    /**
     * A token short enough that a prefix would give most of it away is
     * replaced outright instead.
     *
     * @return void
     */
    public function test_a_short_token_is_replaced_rather_than_trimmed(): void {
        global $CFG;

        $this->resetAfterTest();

        $CFG->enrol_mercadopagosub = ['accesstoken' => 'short-token'];

        $this->assertSame('***', credentials::resolve()->get_redacted_token());
    }
}

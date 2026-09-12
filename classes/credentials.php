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
 * Resolves the Mercado Pago credentials this site should use.
 *
 * Three sources, in descending precedence:
 *
 *   1. $CFG->enrol_mercadopagosub in config.php
 *   2. Process environment variables
 *   3. Plugin settings stored in the database
 *
 * config.php outranks the environment deliberately. PHP-FPM does not reliably
 * pass environment variables through to workers, so a value that is visible to
 * the CLI can be absent from the web request that actually needs it. Anything
 * placed in config.php is visible to both.
 *
 * Settings rank last because they are the only source an administrator can change
 * without shell access, which also makes them the easiest to change by accident.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class credentials {
    /** @var string Access token used as a bearer credential. */
    private string $accesstoken;

    /** @var string Public key. Not used server side; exposed for future front-end work. */
    private string $publickey;

    /** @var string Secret used to verify the x-signature header on notifications. */
    private string $webhooksecret;

    /** @var string Where the values came from, for diagnostics. */
    private string $source;

    /**
     * Constructor.
     *
     * @param string $accesstoken Access token.
     * @param string $publickey Public key.
     * @param string $webhooksecret Webhook secret.
     * @param string $source One of config, environment, settings, none.
     */
    private function __construct(
        string $accesstoken,
        string $publickey,
        string $webhooksecret,
        string $source
    ) {
        $this->accesstoken = $accesstoken;
        $this->publickey = $publickey;
        $this->webhooksecret = $webhooksecret;
        $this->source = $source;
    }

    /**
     * Resolves credentials from the highest-precedence source that supplies a token.
     *
     * Sources are not merged. A partially configured source is used as it stands,
     * so that a half-finished configuration fails visibly instead of silently
     * borrowing a token from one place and a secret from another.
     *
     * @return self Possibly empty; call is_complete() before using it.
     */
    public static function resolve(): self {
        $fromconfig = self::from_config();
        if ($fromconfig->accesstoken !== '') {
            return $fromconfig;
        }

        $fromenvironment = self::from_environment();
        if ($fromenvironment->accesstoken !== '') {
            return $fromenvironment;
        }

        return self::from_settings();
    }

    /**
     * Reads $CFG->enrol_mercadopagosub.
     *
     * @return self
     */
    private static function from_config(): self {
        global $CFG;

        $values = $CFG->enrol_mercadopagosub ?? null;
        if (!is_array($values)) {
            return new self('', '', '', 'none');
        }

        return new self(
            (string)($values['accesstoken'] ?? ''),
            (string)($values['publickey'] ?? ''),
            (string)($values['webhooksecret'] ?? ''),
            'config'
        );
    }

    /**
     * Reads the three environment variables.
     *
     * @return self
     */
    private static function from_environment(): self {
        return new self(
            (string)(getenv('MERCADOPAGOSUB_ACCESS_TOKEN') ?: ''),
            (string)(getenv('MERCADOPAGOSUB_PUBLIC_KEY') ?: ''),
            (string)(getenv('MERCADOPAGOSUB_WEBHOOK_SECRET') ?: ''),
            'environment'
        );
    }

    /**
     * Reads the plugin settings.
     *
     * @return self
     */
    private static function from_settings(): self {
        $accesstoken = (string)(get_config('enrol_mercadopagosub', 'accesstoken') ?: '');

        return new self(
            $accesstoken,
            (string)(get_config('enrol_mercadopagosub', 'publickey') ?: ''),
            (string)(get_config('enrol_mercadopagosub', 'webhooksecret') ?: ''),
            $accesstoken === '' ? 'none' : 'settings'
        );
    }

    /**
     * Whether there is enough here to call the API.
     *
     * The webhook secret is not required for that: a site can create and read
     * subscriptions while its notification endpoint is still unconfigured.
     *
     * @return bool
     */
    public function is_complete(): bool {
        return $this->accesstoken !== '';
    }

    /**
     * Whether notification signatures can be verified.
     *
     * @return bool
     */
    public function can_verify_signatures(): bool {
        return $this->webhooksecret !== '';
    }

    /**
     * Whether the token looks like a test credential.
     *
     * Used to warn an administrator that a production site is configured against
     * a test account, which creates subscriptions that no real buyer can pay.
     *
     * @return bool
     */
    public function is_test_credential(): bool {
        return str_starts_with($this->accesstoken, 'TEST-');
    }

    /**
     * Returns the access token.
     *
     * @return string
     */
    public function get_access_token(): string {
        return $this->accesstoken;
    }

    /**
     * Returns the public key.
     *
     * @return string
     */
    public function get_public_key(): string {
        return $this->publickey;
    }

    /**
     * Returns the webhook secret.
     *
     * @return string
     */
    public function get_webhook_secret(): string {
        return $this->webhooksecret;
    }

    /**
     * Returns which source supplied these credentials.
     *
     * @return string One of config, environment, settings, none.
     */
    public function get_source(): string {
        return $this->source;
    }

    /**
     * Returns a form of the token that is safe to print in a diagnostic.
     *
     * @return string
     */
    public function get_redacted_token(): string {
        if ($this->accesstoken === '') {
            return '(not set)';
        }

        return strlen($this->accesstoken) <= 12
            ? '***'
            : substr($this->accesstoken, 0, 12) . '...';
    }

    // __debugInfo is a real PHP magic method, which this sniff's own list of
    // magic methods predates. Disabled around the method rather than on one
    // line, because an ignore comment between a docblock and its function
    // detaches the two and trips moodle.Commenting.MissingDocblock instead.
    // phpcs:disable moodle.NamingConventions.ValidFunctionName.MagicLikeMethod
    /**
     * Prevents credentials from reaching a log through var_dump or print_r.
     *
     * @return array
     */
    public function __debugInfo(): array {
        return [
            'source' => $this->source,
            'accesstoken' => $this->get_redacted_token(),
            'publickey' => $this->publickey === '' ? '(not set)' : '(set)',
            'webhooksecret' => $this->webhooksecret === '' ? '(not set)' : '(set)',
        ];
    }
    // phpcs:enable moodle.NamingConventions.ValidFunctionName.MagicLikeMethod
}

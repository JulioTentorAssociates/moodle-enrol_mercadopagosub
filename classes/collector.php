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
 * Who this site collects money as.
 *
 * Mercado Pago accounts belong to a marketplace site, and a site settles in one
 * currency. That single fact governs three things this plugin would otherwise
 * have to guess at:
 *
 *   - which currency an instance may charge in;
 *   - which country a subscriber's own account has to be registered in, because
 *     an account from another site is refused at creation with guest_site_mismatch;
 *   - whether the credentials in use belong to a test account, which creates
 *     subscriptions no real buyer can ever pay.
 *
 * None of it is configuration. It is a property of the credentials, so it is read
 * from the platform rather than typed in by an administrator who may be wrong.
 *
 * This is deliberately not a country whitelist. Hardcoding a list of supported
 * countries is what has pushed other integrations into shipping a separate plugin
 * per country, and it goes stale every time the platform opens a new market. A
 * site whose account settles in a currency this class does not recognise still
 * works: the administrator sets the currency explicitly and the plugin gets out
 * of the way.
 *
 * The account endpoint returns a large record carrying the account holder's email
 * address, national identification number, postal address and telephone. None of
 * that is needed here, so nothing beyond the five fields below is ever cached or
 * held in memory: the response is reduced as soon as it arrives.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class collector {
    /** @var string Cache identifier. */
    private const CACHE_KEY = 'collector';

    /**
     * Shape of the cached record.
     *
     * Bump this whenever the projection changes. A cached entry written by an
     * earlier version is discarded rather than read with missing keys, which would
     * silently answer "no" to questions it was never asked.
     */
    private const RECORD_VERSION = 1;

    /**
     * Currency each marketplace site settles in.
     *
     * A convenience for filling the form in, not a statement about where this
     * plugin works. An unrecognised site falls through to the site setting.
     *
     * Measured 2026-08-31: the account endpoint carries no currency field of any
     * kind, on either a test or a seller record, so this mapping cannot be
     * replaced by reading one. The full key list is in the handover.
     *
     * @var array<string, string>
     */
    private const SITE_CURRENCY = [
        'MLA' => 'ARS',
        'MLB' => 'BRL',
        'MBO' => 'BOB',
        'MLC' => 'CLP',
        'MCO' => 'COP',
        'MCR' => 'CRC',
        'MEC' => 'USD',
        'MGT' => 'GTQ',
        'MLM' => 'MXN',
        'MNI' => 'NIO',
        'MPA' => 'PAB',
        'MPE' => 'PEN',
        'MPY' => 'PYG',
        'MRD' => 'DOP',
        'MSV' => 'USD',
        'MLU' => 'UYU',
        'MLV' => 'VES',
    ];

    /** @var array Reduced account record. */
    private array $record;

    /**
     * Constructor.
     *
     * @param array $record Reduced record, as produced by reduce().
     */
    private function __construct(array $record) {
        $this->record = $record;
    }

    /**
     * Reads the collecting account, from cache when possible.
     *
     * Returns null when the credentials are missing or the platform cannot be
     * reached. Callers must treat that as "not configured yet" rather than as an
     * error: a course can be set up before the credentials are.
     *
     * @param bool $forcerefresh Bypass the cache.
     * @return self|null
     */
    public static function load(bool $forcerefresh = false): ?self {
        $cache = \cache::make('enrol_mercadopagosub', 'collector');

        if (!$forcerefresh) {
            $cached = $cache->get(self::CACHE_KEY);
            if (is_array($cached) && ($cached['recordversion'] ?? 0) === self::RECORD_VERSION) {
                return new self($cached);
            }
        }

        $credentials = credentials::resolve();
        if (!$credentials->is_complete()) {
            return null;
        }

        try {
            $client = new api_client($credentials);
            $account = $client->get_account();
        } catch (api_exception $e) {
            return null;
        }

        if ($account === []) {
            return null;
        }

        $record = self::reduce($account);
        $cache->set(self::CACHE_KEY, $record);

        return new self($record);
    }

    /**
     * Reduces the account response to the fields this plugin uses.
     *
     * Everything discarded here is personal data belonging to the account holder.
     * It has no use in this plugin, and a cache entry is the wrong place for it.
     *
     * @param array $account Decoded response from the account endpoint.
     * @return array
     */
    private static function reduce(array $account): array {
        return [
            'recordversion' => self::RECORD_VERSION,
            'id' => (int)($account['id'] ?? 0),
            'nickname' => (string)($account['nickname'] ?? ''),
            'siteid' => (string)($account['site_id'] ?? ''),
            'countryid' => (string)($account['country_id'] ?? ''),
            'testaccount' => self::detect_test_account($account),
        ];
    }

    /**
     * Decides whether an account record describes a Mercado Pago test account.
     *
     * Three signals, in decreasing order of reliability. The nickname prefix is
     * last because it is the weakest: the platform reports whether a test account
     * was created with a custom identity, and such an account need not carry the
     * generated TESTUSER name. It is kept as a fallback in case the two structured
     * signals are absent from some account type this has not been run against.
     *
     * @param array $account Decoded response from the account endpoint.
     * @return bool
     */
    private static function detect_test_account(array $account): bool {
        if (isset($account['test_data']['test_user'])) {
            return (bool)$account['test_data']['test_user'];
        }

        if (in_array('test_user', (array)($account['tags'] ?? []), true)) {
            return true;
        }

        return str_starts_with((string)($account['nickname'] ?? ''), 'TESTUSER');
    }

    /**
     * Marketplace site identifier, for example MLA.
     *
     * @return string Empty when the platform did not report one.
     */
    public function get_site_id(): string {
        return (string)($this->record['siteid'] ?? '');
    }

    /**
     * ISO country code of the collecting account.
     *
     * This is the country a subscriber's own Mercado Pago account must belong to.
     *
     * @return string Empty when unknown.
     */
    public function get_country_id(): string {
        return (string)($this->record['countryid'] ?? '');
    }

    /**
     * Account nickname, for display in diagnostics.
     *
     * @return string
     */
    public function get_nickname(): string {
        return (string)($this->record['nickname'] ?? '');
    }

    /**
     * Numeric account identifier, for display in diagnostics.
     *
     * @return int
     */
    public function get_id(): int {
        return (int)($this->record['id'] ?? 0);
    }

    /**
     * Whether this is a Mercado Pago test account.
     *
     * A production site configured against one will create subscriptions that no
     * real buyer can pay: the platform refuses any payment where one party is
     * fictitious and the other is not.
     *
     * @return bool
     */
    public function is_test_account(): bool {
        return (bool)($this->record['testaccount'] ?? false);
    }

    /**
     * The currency this account settles in.
     *
     * @return string ISO code, or an empty string when the site is unrecognised.
     */
    public function get_currency(): string {
        return self::SITE_CURRENCY[$this->get_site_id()] ?? '';
    }

    /**
     * Resolves the currency an instance should charge in.
     *
     * Order: the site setting, if an administrator set one deliberately; then the
     * currency of the collecting account; then nothing, which the instance form
     * turns into a prompt rather than a guess.
     *
     * @return string ISO code, possibly empty.
     */
    public static function resolve_currency(): string {
        $configured = (string)(get_config('enrol_mercadopagosub', 'currency') ?: '');
        if ($configured !== '') {
            return $configured;
        }

        $collector = self::load();

        return $collector === null ? '' : $collector->get_currency();
    }

    /**
     * Discards the cached account.
     *
     * Call after the credentials change: the cached identity belongs to the old
     * ones and every currency and country check would still be answering for them.
     *
     * @return void
     */
    public static function forget(): void {
        \cache::make('enrol_mercadopagosub', 'collector')->delete(self::CACHE_KEY);
    }
}

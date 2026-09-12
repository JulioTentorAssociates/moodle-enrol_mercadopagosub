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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/mercadopagosub/tests/helper_trait.php');

/**
 * Tests for the collecting account.
 *
 * The account decides two things a course cannot override: the currency the
 * site charges in, and the country a subscriber's own Mercado Pago account
 * must belong to.
 *
 * **What these tests cannot reach, and why.** `collector::load()` builds its
 * own `api_client` internally instead of accepting one, which makes it the
 * only service in this plugin whose network path cannot be scripted from a
 * test. Everything below therefore works either from a seeded cache entry or
 * from the paths that never call the API at all. Covering the fetch itself
 * needs `load()` to take an optional `api_client`, the way `event_processor`,
 * `payment_reconciler` and `subscription_service` already do — a one-argument
 * change, flagged in docs/HANDOVER.md rather than made here.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(collector::class)]
final class collector_test extends \advanced_testcase {
    use helper_trait;

    /**
     * Seeds the cache with an account record, as a successful load would.
     *
     * @param array $overrides Fields to replace in the cached record.
     * @return void
     */
    private function seed_cache(array $overrides = []): void {
        \cache::make('enrol_mercadopagosub', 'collector')->set('collector', $overrides + [
            'recordversion' => 1,
            'id' => 123456789,
            'nickname' => 'MERCHANT',
            'siteid' => 'MLA',
            'countryid' => 'AR',
            'testaccount' => false,
        ]);
    }

    /**
     * A cached account is served without touching the API.
     *
     * @return void
     */
    public function test_a_cached_account_is_used(): void {
        $this->setup_plugin();
        $this->seed_cache();

        $collector = collector::load();

        $this->assertNotNull($collector);
        $this->assertSame('MLA', $collector->get_site_id());
        $this->assertSame('ARS', $collector->get_currency());
    }

    /**
     * Currency follows the account's marketplace site for every country this
     * plugin knows about.
     *
     * @return void
     */
    public function test_currency_follows_the_marketplace_site(): void {
        $this->setup_plugin();

        $expected = [
            'MLA' => 'ARS',
            'MLB' => 'BRL',
            'MBO' => 'BOB',
            'MLC' => 'CLP',
            'MCO' => 'COP',
            'MLM' => 'MXN',
            'MPE' => 'PEN',
        ];

        foreach ($expected as $site => $currency) {
            $this->seed_cache(['siteid' => $site]);
            $this->assertSame($currency, collector::load()->get_currency(), "wrong currency for {$site}");
        }
    }

    /**
     * An unrecognised marketplace site yields no currency rather than a guess:
     * nothing restricts this plugin to a fixed list of countries, so a site it
     * has not seen is a configuration question, not a default.
     *
     * @return void
     */
    public function test_an_unknown_site_has_no_currency(): void {
        $this->setup_plugin();
        $this->seed_cache(['siteid' => 'XXX']);

        $this->assertSame('', collector::load()->get_currency());
    }

    /**
     * A cache entry written by an older version of the record's shape is
     * discarded rather than read with missing fields.
     *
     * @return void
     */
    public function test_a_stale_record_version_is_not_trusted(): void {
        $this->setup_plugin();
        $this->seed_cache(['recordversion' => 0]);
        set_config('accesstoken', '', 'enrol_mercadopagosub');

        // With no credentials the refetch cannot happen either, so a null here
        // proves the stale entry was rejected rather than returned.
        $this->assertNull(collector::load());
    }

    /**
     * A currency set explicitly in the plugin settings wins, which is the
     * escape hatch for an account whose site this plugin does not recognise.
     *
     * @return void
     */
    public function test_a_configured_currency_outranks_the_account(): void {
        $this->setup_plugin();
        $this->seed_cache(['siteid' => 'MLA']);
        set_config('currency', 'UYU', 'enrol_mercadopagosub');

        $this->assertSame('UYU', collector::resolve_currency());
    }

    /**
     * With nothing configured and nothing cached, currency resolution is empty
     * rather than an error: a site can install this plugin before it has
     * credentials.
     *
     * @return void
     */
    public function test_no_credentials_means_no_currency(): void {
        $this->setup_plugin();
        collector::forget();
        set_config('accesstoken', '', 'enrol_mercadopagosub');

        $this->assertNull(collector::load());
        $this->assertSame('', collector::resolve_currency());
    }

    /**
     * forget() discards the cached identity, which is what has to happen when
     * the credentials change: the cached account belongs to the old ones.
     *
     * @return void
     */
    public function test_forget_discards_the_cached_account(): void {
        $this->setup_plugin();
        $this->seed_cache();
        $this->assertNotNull(collector::load());

        collector::forget();
        set_config('accesstoken', '', 'enrol_mercadopagosub');

        $this->assertNull(collector::load());
    }

    /**
     * The cached record carries the test-account flag, so an administrator can
     * be warned that no real buyer can pay this site's subscriptions.
     *
     * @return void
     */
    public function test_a_test_account_is_reported_as_one(): void {
        $this->setup_plugin();

        $this->seed_cache(['testaccount' => true]);
        $this->assertTrue(collector::load()->is_test_account());

        $this->seed_cache(['testaccount' => false]);
        $this->assertFalse(collector::load()->is_test_account());
    }
}

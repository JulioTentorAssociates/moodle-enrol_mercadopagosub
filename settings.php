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

/**
 * Admin settings for enrol_mercadopagosub.
 *
 * Deliberately narrow. Only what is needed to make the credentials editable from
 * the UI, drive the core expiry machinery, and let an account settling in an
 * unrecognised currency be used at all. Instance-level defaults (role, billing
 * frequency, grace period, welcome message) already have working fallbacks coded
 * directly in enrol_mercadopagosub_plugin::defaults_for_new_instance() via
 * $this->get_config($name, $default) — that call returns $default whenever no
 * admin_setting for $name has ever been registered, so those remain fixed at
 * their coded defaults until a site actually needs to change them from this
 * screen. Nothing here should be read as "this is the complete set of settings
 * this plugin will ever have."
 *
 * expiredaction is registered here, but declaring it is not the same as it
 * doing anything. enrol_self ships exactly this setting and it is a documented
 * no-op (MDL-66786): the string is a select box that goes nowhere unless the
 * plugin's own lib.php reads it back and acts on it, the way enrol_manual does
 * in its own process_expirations(). That override does not exist yet in this
 * plugin's lib.php. Until it does, this setting is honest about existing and
 * dishonest about doing anything.
 *
 * expirynotifylast is deliberately absent. No enrol plugin surveyed
 * (enrol_manual, enrol_self, enrol_credit, enrol_apply) exposes it as an
 * admin_setting; every one of them uses it, if at all, as bookkeeping the
 * expiry-notification task writes to itself with set_config(), not something an
 * administrator sets. Adding it here on the strength of its name alone would be
 * exactly the kind of invented API surface this project avoids.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Credentials. Ranked last behind config.php and the environment — see
    // classes/credentials.php — so a value saved here can be silently overridden
    // by either. The description strings say so; nothing here can enforce it.
    $settings->add(new admin_setting_heading(
        'enrol_mercadopagosub_credentials',
        get_string('settings:credentials', 'enrol_mercadopagosub'),
        get_string('settings:credentials_desc', 'enrol_mercadopagosub')
    ));

    $settings->add(new \enrol_mercadopagosub\admin_setting_credential(
        'enrol_mercadopagosub/accesstoken',
        get_string('settings:accesstoken', 'enrol_mercadopagosub'),
        get_string('settings:accesstoken_desc', 'enrol_mercadopagosub'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'enrol_mercadopagosub/publickey',
        get_string('settings:publickey', 'enrol_mercadopagosub'),
        get_string('settings:publickey_desc', 'enrol_mercadopagosub'),
        '',
        PARAM_RAW_TRIMMED
    ));

    // Plain admin_setting_configpasswordunmask, not the invalidating subclass:
    // the webhook secret has no bearing on collector's identity of the account,
    // only the access token does.
    $settings->add(new admin_setting_configpasswordunmask(
        'enrol_mercadopagosub/webhooksecret',
        get_string('settings:webhooksecret', 'enrol_mercadopagosub'),
        get_string('settings:webhooksecret_desc', 'enrol_mercadopagosub'),
        ''
    ));

    // Currency override. Blank by default: collector::resolve_currency() only
    // consults this when the collecting account's own site_id maps to nothing in
    // collector::SITE_CURRENCY. Reuses the instance-form help text, which was
    // written with this exact setting in mind.
    $settings->add(new admin_setting_configtext(
        'enrol_mercadopagosub/currency',
        get_string('currency', 'enrol_mercadopagosub'),
        get_string('currency_help', 'enrol_mercadopagosub'),
        '',
        PARAM_ALPHA
    ));

    // Expiry. Governs the core enrolstartdate/enrolenddate period, which is
    // independent of the subscription's own status machine
    // (pending/trialing/active/overdue/ended) that this plugin drives from
    // Mercado Pago's webhooks. See the expiredaction_help string, and the
    // caveat at the top of this file, before assuming this setting is wired up.
    $settings->add(new admin_setting_heading(
        'enrol_mercadopagosub_expiry',
        get_string('settings:expiry', 'enrol_mercadopagosub'),
        ''
    ));

    $expiredactionoptions = [
        ENROL_EXT_REMOVED_KEEP => get_string('extremovedkeep', 'enrol'),
        ENROL_EXT_REMOVED_SUSPEND => get_string('extremovedsuspend', 'enrol'),
        ENROL_EXT_REMOVED_SUSPENDNOROLES => get_string('extremovedsuspendnoroles', 'enrol'),
        ENROL_EXT_REMOVED_UNENROL => get_string('extremovedunenrol', 'enrol'),
    ];
    $settings->add(new admin_setting_configselect(
        'enrol_mercadopagosub/expiredaction',
        get_string('expiredaction', 'enrol_mercadopagosub'),
        get_string('expiredaction_help', 'enrol_mercadopagosub'),
        ENROL_EXT_REMOVED_SUSPEND,
        $expiredactionoptions
    ));

    $notifyhouroptions = [];
    for ($i = 0; $i < 24; $i++) {
        $notifyhouroptions[$i] = $i;
    }
    $settings->add(new admin_setting_configselect(
        'enrol_mercadopagosub/expirynotifyhour',
        get_string('expirynotifyhour', 'core_enrol'),
        '',
        6,
        $notifyhouroptions
    ));
}

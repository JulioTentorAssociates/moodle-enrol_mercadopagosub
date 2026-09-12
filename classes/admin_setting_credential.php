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
 * A masked credential field that forgets the cached collecting account when saved.
 *
 * collector::load() caches the account identity for up to a day, keyed on nothing
 * about the credentials themselves — it assumes whatever is configured now is what
 * was configured when the cache was written. That assumption breaks the moment an
 * administrator changes the access token: the cached currency, country and
 * test-account flag would keep answering for the old account until the cache aged
 * out on its own, silently, for up to 24 hours.
 *
 * Extending admin_setting_configpasswordunmask and overriding write_setting() is
 * the standard way to attach a side effect to saving one admin setting; the parent
 * write is always attempted first; the cache is only forgotten once it succeeds.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_credential extends \admin_setting_configpasswordunmask {
    /**
     * Writes the setting, then forgets the cached account if the value actually changed.
     *
     * @param string $data Submitted value.
     * @return string Empty string on success, an error message otherwise — the
     *                 same contract every admin_setting::write_setting() follows.
     */
    public function write_setting($data) {
        $changed = $this->get_setting() !== $data;

        $result = parent::write_setting($data);

        if ($result === '' && $changed) {
            collector::forget();
        }

        return $result;
    }
}

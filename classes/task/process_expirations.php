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

namespace enrol_mercadopagosub\task;

/**
 * Acts on the core enrolment period ending, per the expiredaction setting.
 *
 * Another thin wrapper, for the same reason as send_expiry_notifications: the
 * real logic — unenrolling, suspending, or suspending-without-roles per
 * ENROL_EXT_REMOVED_* — lives in enrol_plugin::process_expirations() already.
 * Registering this task is what makes the expiredaction setting in
 * settings.php do anything at all; without it, the setting sits in the admin
 * UI exactly as inert as it was in enrol_self before MDL-66786 was raised.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_expirations extends \core\task\scheduled_task {
    /**
     * Task name shown in the scheduled tasks admin screen.
     *
     * @return string
     */
    public function get_name() {
        return get_string('processexpirationstask', 'enrol_mercadopagosub');
    }

    /**
     * Runs the task.
     *
     * @return void
     */
    public function execute() {
        if (!enrol_is_enabled('mercadopagosub')) {
            return;
        }

        $plugin = enrol_get_plugin('mercadopagosub');
        $trace = new \text_progress_trace();
        $plugin->process_expirations($trace);
    }
}

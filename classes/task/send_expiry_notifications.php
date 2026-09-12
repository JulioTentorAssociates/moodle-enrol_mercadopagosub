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
 * Sends warnings ahead of the core enrolment period ending.
 *
 * This is a thin wrapper: enrol_plugin::send_expiry_notifications() already
 * contains the real logic — reading the per-instance expirynotify setting, the
 * site's expirynotifyhour, and the four expirymessage* strings this plugin
 * defines. Nothing here decides anything; the task exists only so that method
 * gets called on a schedule at all.
 *
 * This concerns the core enrolment period (enrolstartdate/enrolenddate), which
 * is independent of the subscription's own status machine
 * (pending/trialing/active/overdue/ended) driven by Mercado Pago's webhooks.
 * A subscriber whose card keeps charging successfully every cycle may still see
 * this warning if the instance was also given a fixed enrolment end date.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_expiry_notifications extends \core\task\scheduled_task {
    /**
     * Task name shown in the scheduled tasks admin screen.
     *
     * @return string
     */
    public function get_name() {
        return get_string('sendexpirynotificationstask', 'enrol_mercadopagosub');
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
        $plugin->send_expiry_notifications($trace);
    }
}

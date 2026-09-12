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
 * Runs payment_reconciler over every locally active subscription.
 *
 * Deliberately not triggered by, or coupled to, any specific webhook — see
 * payment_reconciler's own docblock for why a fixed schedule is the design
 * point here, not an event-driven sweep.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_payments extends \core\task\scheduled_task {
    /**
     * Task name shown in the scheduled tasks admin screen.
     *
     * @return string
     */
    public function get_name() {
        return get_string('reconcilepaymentstask', 'enrol_mercadopagosub');
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

        $reconciler = new \enrol_mercadopagosub\payment_reconciler();
        $counts = $reconciler->reconcile_all();

        mtrace(sprintf(
            'enrol_mercadopagosub: reconciled=%d overdue=%d recovered=%d failed=%d',
            $counts['reconciled'],
            $counts['overdue'],
            $counts['recovered'],
            $counts['failed']
        ));
    }
}

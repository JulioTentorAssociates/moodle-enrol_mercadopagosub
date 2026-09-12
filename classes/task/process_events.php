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
 * Runs event_processor over queued webhook deliveries.
 *
 * Another thin wrapper, same reasoning as the two expiry tasks: the decisions
 * live in event_processor, where they can be tested without a cron run. This
 * class exists only to put them on a schedule.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_events extends \core\task\scheduled_task {
    /**
     * Task name shown in the scheduled tasks admin screen.
     *
     * @return string
     */
    public function get_name() {
        return get_string('processeventstask', 'enrol_mercadopagosub');
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

        $processor = new \enrol_mercadopagosub\event_processor();
        $counts = $processor->process_queued();

        mtrace(sprintf(
            'enrol_mercadopagosub: processed=%d ignored=%d failed=%d',
            $counts['processed'],
            $counts['ignored'],
            $counts['failed']
        ));
    }
}

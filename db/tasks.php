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
 * Scheduled tasks for enrol_mercadopagosub.
 *
 * process_events and reconcile_payments are thin wrappers over this plugin's
 * own classes (event_processor, payment_reconciler) rather than core methods —
 * see those classes for the actual logic and the reasoning behind it. The
 * other two wrap core enrol_plugin methods — see the class-level documentation
 * in classes/task/ for why neither implements its own logic.
 *
 * reconcile_payments is the task that finally closes the gap left open when
 * process_events was written: it is the only place that ever writes
 * enrol_mercadopagosub_payment, and the only place that can ever set local
 * state = 'overdue'. It runs on its own fixed schedule, not triggered by any
 * specific webhook — see payment_reconciler's own docblock for why.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'enrol_mercadopagosub\task\process_events',
        'blocking' => 0,
        'minute' => '*/2',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        'classname' => 'enrol_mercadopagosub\task\reconcile_payments',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        'classname' => 'enrol_mercadopagosub\task\send_expiry_notifications',
        'blocking' => 0,
        'minute' => '*/10',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
    [
        'classname' => 'enrol_mercadopagosub\task\process_expirations',
        'blocking' => 0,
        'minute' => '*/10',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*',
    ],
];

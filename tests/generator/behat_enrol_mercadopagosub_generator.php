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
 * Behat data generator for enrol_mercadopagosub.
 *
 * Lets a feature write, for example:
 *
 *   Given the following "enrol_mercadopagosub > instances" exist:
 *     | course |
 *     | C1     |
 *   And the following "enrol_mercadopagosub > subscriptions" exist:
 *     | course | user     | state   |
 *     | C1     | student1 | active  |
 *
 * The instance comes first: a subscription points at one, and the generator
 * refuses a course that has no subscription method rather than inserting a row
 * that points at nothing.
 *
 * @package   enrol_mercadopagosub
 * @category  test
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_enrol_mercadopagosub_generator extends behat_generator_base {
    /**
     * Entities a feature can create with the "the following ... exist" step.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'instances' => [
                'singular' => 'instance',
                'datagenerator' => 'instance',
                'required' => ['course'],
                'switchids' => ['course' => 'courseid', 'role' => 'roleid'],
            ],
            'subscriptions' => [
                'singular' => 'subscription',
                'datagenerator' => 'subscription',
                'required' => ['user'],
                'switchids' => ['user' => 'userid', 'course' => 'courseid'],
            ],
            'payments' => [
                'singular' => 'payment',
                'datagenerator' => 'payment',
                'required' => ['subid'],
            ],
            'notifications' => [
                'singular' => 'notification',
                'datagenerator' => 'event',
                'required' => [],
            ],
        ];
    }
}

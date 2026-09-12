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
 * Capabilities for the Mercado Pago Subscriptions enrolment plugin.
 *
 * These archetypes depart from enrol_self on purpose. enrol_self grants :config
 * and :manage to editingteacher, which is right for a method that costs nobody
 * anything. This method sets a price, binds a recurring charge to a real payment
 * account and can stop one, so the defaults keep it with manager and away from the
 * teaching role.
 *
 * That is a default, not a policy. A site that wants a dedicated administrative
 * role — which is the arrangement this plugin's documentation recommends — grants
 * these capabilities to it and leaves the teacher archetype alone. A site that
 * genuinely wants teachers pricing their own courses grants :config to
 * editingteacher and gets the enrol_self behaviour back. What the defaults avoid
 * is a site acquiring the ability to charge money without anyone deciding to.
 *
 * Note that the course welcome message is deliberately not covered here: its form
 * field is gated on :config or moodle/course:editcoursewelcomemessage, so a
 * teacher who cannot configure the method can still write what its subscribers
 * are told.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Add or edit an instance of this method in a course. This is the capability
    // that decides who may put a price on a course.
    'enrol/mercadopagosub:config' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Edit the status and dates of an existing enrolment by hand. On this method
    // that means overriding what the subscriber actually paid for, so the local
    // record and the platform stop agreeing.
    'enrol/mercadopagosub:manage' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Remove somebody from the course. Does not stop their subscription: cancelling
    // the charge is a separate capability, deliberately, so that neither action can
    // be taken by accident while intending the other.
    'enrol/mercadopagosub:unenrol' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Leave the course voluntarily. Granted to students, as in enrol_self.
    'enrol/mercadopagosub:unenrolself' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
    ],

    // Start a subscription. The learner-facing capability.
    'enrol/mercadopagosub:subscribe' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],

    // See who is subscribed, their payments and the account each subscription
    // bills. Teachers keep this: knowing whether a student's access is paid up is
    // ordinary teaching information, and it grants no power over the money.
    'enrol/mercadopagosub:viewsubscriptions' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // Cancel a subscriber's subscription at Mercado Pago. Stops a recurring charge
    // against a real payment account, possibly a third party's, and cannot be
    // undone: a cancelled subscription is terminal and coming back means a new one
    // at whatever the price is then.
    'enrol/mercadopagosub:cancelsubscription' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];

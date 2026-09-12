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
 * Shows the Mercado Pago init_point for one subscription as a copyable link.
 *
 * Deliberately not an automatic redirect, per the frozen design decision that
 * payer and learner are routinely different people (HANDOVER.md, "Settled
 * decisions"): an employer or a client company pays for someone else's course,
 * so the person looking at this page is frequently not the person who needs to
 * open the link. A redirect only serves the first case; a link that can be
 * copied and sent elsewhere serves both.
 *
 * This page never talks to Mercado Pago. It reads init_point from the local row,
 * where subscription_service stored it verbatim at creation, inside the extras
 * column rather than a dedicated one — see that class's own documentation for
 * why. Nothing here can tell whether the subscription has actually been paid;
 * that answer belongs to webhook.php and the reconciliation task, neither of
 * which exists yet, so the status line below reflects only this plugin's own
 * last-known local state, not a live check.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$subid = required_param('subid', PARAM_INT);

$subscription = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $subid], '*', MUST_EXIST);
$instance = $DB->get_record('enrol', ['id' => $subscription->enrolid, 'enrol' => 'mercadopagosub'], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id, MUST_EXIST);

require_login($course);

// The learner who owns this subscription may always see their own payment
// link. Anyone else needs a capability that says so explicitly — this page
// exposes a live, single-use checkout URL, not just status information.
$isowner = (int)$subscription->userid === (int)$USER->id;
if (
    !$isowner && !has_any_capability(
        ['enrol/mercadopagosub:viewsubscriptions', 'enrol/mercadopagosub:manage'],
        $context
    )
) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('paymentlink', 'enrol_mercadopagosub'));
}

$PAGE->set_url('/enrol/mercadopagosub/paymentlink.php', ['subid' => $subid]);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('paymentlink', 'enrol_mercadopagosub'));
$PAGE->set_heading($course->fullname);

$extras = json_decode((string)$subscription->extras, true);
$initpoint = is_array($extras) ? (string)($extras['initpoint'] ?? '') : '';

$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('paymentlink', 'enrol_mercadopagosub'));

if ($initpoint === '') {
    // Should not happen: subscription_service always stores whatever the API
    // returned, even an empty string. An empty value here means the API
    // response itself never carried init_point, which is worth surfacing
    // plainly rather than showing a dead link.
    echo $OUTPUT->notification(
        get_string('error:apifailed', 'enrol_mercadopagosub'),
        'error'
    );
    echo $OUTPUT->continue_button($courseurl);
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('paymentlink_help', 'enrol_mercadopagosub'));

echo html_writer::tag(
    'p',
    get_string('paymentlink_status', 'enrol_mercadopagosub', get_string('state:' . $subscription->state, 'enrol_mercadopagosub'))
);

// Presented both as a plain-text field, for copying elsewhere, and as a direct
// link, for the common case where the person reading this page is the one
// paying. Neither is preferred over the other; which one gets used depends on
// who ends up in front of this page, which this plugin cannot know in advance.
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'readonly' => 'readonly',
    'value' => $initpoint,
    'size' => 60,
    'onclick' => 'this.select();',
    'class' => 'form-control',
    'style' => 'max-width: 40em;',
]);

echo html_writer::tag(
    'p',
    html_writer::link($initpoint, get_string('paymentlink_open', 'enrol_mercadopagosub'), [
        'class' => 'btn btn-primary',
        'target' => '_blank',
        'rel' => 'noopener',
    ]),
    ['class' => 'mt-3']
);

echo html_writer::tag('p', html_writer::link($courseurl, get_string('paymentlink_backtocourse', 'enrol_mercadopagosub')));

echo $OUTPUT->footer();

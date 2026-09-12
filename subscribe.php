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
 * Collects the payer's Mercado Pago address and starts a subscription.
 *
 * enrol_page_hook() links here for anyone can_subscribe() allows. That method
 * is the single authority on whether this page should do anything at all, so
 * this file re-checks it rather than trusting that the link it followed was
 * only ever shown to someone entitled to use it — the URL is guessable.
 *
 * NOT YET FUNCTIONAL PAST FORM SUBMISSION: the call to subscription_service
 * below is written against an interface that does not exist yet (HANDOVER.md,
 * "Next iteration", item 5). Submitting this form fatals with a class-not-found
 * error until that class is written. This mirrors, deliberately, the same
 * incremental approach already taken with enrol_page_hook()'s link to this very
 * file before it existed: the contract is written first, the implementation
 * catches up next.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param('id', PARAM_INT); // Enrol instance id.

$instance = $DB->get_record('enrol', ['id' => $id, 'enrol' => 'mercadopagosub'], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id, MUST_EXIST);

require_login($course);

$PAGE->set_url('/enrol/mercadopagosub/subscribe.php', ['id' => $id]);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('subscribe', 'enrol_mercadopagosub'));
$PAGE->set_heading($course->fullname);

$plugin = enrol_get_plugin('mercadopagosub');
$courseenrolurl = new moodle_url('/enrol/index.php', ['id' => $course->id]);

$reason = $plugin->can_subscribe($instance);
if ($reason !== true) {
    // Covers both outcomes of can_subscribe(): a string is a reason worth
    // showing, false means this page does not apply to this user at all. Either
    // way there is nothing to do here but send them back.
    if (is_string($reason)) {
        \core\notification::add($reason, \core\notification::WARNING);
    }
    redirect($courseenrolurl);
}

// Prefill: this user's own most recent subscription address, on this instance or
// any other — a returning subscriber typing the same address twice is the
// common case — falling back to their Moodle account email. Per HANDOVER.md,
// deliberately not the instance-specific history: what matters is which address
// this person tends to pay from, not which course they paid it on last.
$recent = $DB->get_records_sql(
    'SELECT payeremail
       FROM {enrol_mercadopagosub_sub}
      WHERE userid = :userid
   ORDER BY timecreated DESC',
    ['userid' => $USER->id],
    0,
    1
);
$prefill = $recent !== [] ? reset($recent)->payeremail : (string)$USER->email;

$form = new \enrol_mercadopagosub\form\subscribe_form(null, ['instance' => $instance, 'prefill' => $prefill]);

if ($form->is_cancelled()) {
    redirect($courseenrolurl);
} else if ($data = $form->get_data()) {
    // Re-checked, not trusted from the moment the form was rendered: nothing
    // stops several minutes passing, or two tabs, between showing the form and
    // submitting it, and can_subscribe() already refuses a second open
    // subscription for the same user.
    $reason = $plugin->can_subscribe($instance);
    if ($reason !== true) {
        if (is_string($reason)) {
            \core\notification::add($reason, \core\notification::WARNING);
        }
        redirect($courseenrolurl);
    }

    $service = new \enrol_mercadopagosub\subscription_service($instance);
    $subscription = $service->create($USER, $data->payeremail);

    redirect(new moodle_url('/enrol/mercadopagosub/paymentlink.php', ['subid' => $subscription->id]));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('subscribe', 'enrol_mercadopagosub'));
$form->display();
echo $OUTPUT->footer();

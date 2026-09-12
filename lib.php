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
 * Mercado Pago Subscriptions enrolment plugin.
 *
 * Instance column mapping. Core columns are used wherever core already has one,
 * which leaves most of the custom range free:
 *
 *   cost, currency          core, the recurring amount
 *   roleid                  core, role granted while the subscription is paid
 *   enrolstartdate/enddate  core, bounds on when the method may be used
 *   expirynotify            core, warn before access ends
 *   expirythreshold         core, how long before
 *   notifyall               core, warn the teacher too
 *   customint1              group joined once a payment has been taken
 *   customint2              group joined during a free trial
 *   customint3              free trial length, units in customchar2
 *   customint4              welcome message option, mirroring enrol_self
 *   customint5              maximum concurrent subscribers, 0 for no limit
 *   customint6              billing frequency, units in customchar1
 *   customint7              grace days added after a missed payment
 *   customint8              reserved
 *   customchar1             billing frequency type, days or months
 *   customchar2             trial frequency type, days or months
 *   customtext1             welcome message body
 *   customtext2             JSON extras
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Enrolment through a Mercado Pago subscription.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_mercadopagosub_plugin extends enrol_plugin {
    /**
     * Uses core's add and edit instance forms rather than inventing another one.
     *
     * @return bool
     */
    public function use_standard_editing_ui() {
        return true;
    }

    /**
     * Icons shown against this method on the participants page.
     *
     * @param array $instances Instances of this plugin in one course.
     * @return array
     */
    public function get_info_icons(array $instances) {
        return [new pix_icon('icon', get_string('pluginname', 'enrol_mercadopagosub'), 'enrol_mercadopagosub')];
    }

    /**
     * Roles assigned by this plugin are managed by this plugin alone.
     *
     * @return bool
     */
    public function roles_protected() {
        return true;
    }

    /**
     * Manual unenrolment is allowed: a subscriber may need removing whatever the
     * platform thinks, and the enrolment is the site's own record.
     *
     * @param stdClass $instance Enrolment instance.
     * @return bool
     */
    public function allow_unenrol(stdClass $instance) {
        return true;
    }

    /**
     * Allows the status and timing of a user enrolment to be edited by hand.
     *
     * @param stdClass $instance Enrolment instance.
     * @return bool
     */
    public function allow_manage(stdClass $instance) {
        return true;
    }

    /**
     * Whether the current user may add an instance of this method to a course.
     *
     * @param int $courseid Course id.
     * @return bool
     */
    public function can_add_instance($courseid) {
        $context = context_course::instance($courseid, MUST_EXIST);

        if (!has_capability('moodle/course:enrolconfig', $context)) {
            return false;
        }

        return has_capability('enrol/mercadopagosub:config', $context);
    }

    /**
     * Whether the current user may delete an instance.
     *
     * @param stdClass $instance Enrolment instance.
     * @return bool
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);

        return has_capability('enrol/mercadopagosub:config', $context);
    }

    /**
     * Whether the current user may enable or disable an instance.
     *
     * @param stdClass $instance Enrolment instance.
     * @return bool
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);

        return has_capability('enrol/mercadopagosub:config', $context);
    }

    /**
     * Name shown for an instance.
     *
     * @param stdClass $instance Enrolment instance.
     * @return string
     */
    public function get_instance_name($instance) {
        global $DB;

        if (empty($instance)) {
            return get_string('pluginname', 'enrol_mercadopagosub');
        }

        if (!empty($instance->name)) {
            return format_string($instance->name, true, ['context' => context_course::instance($instance->courseid)]);
        }

        $enrol = $this->get_name();
        $role = '';
        if ($instance->roleid && $roles = role_fix_names($DB->get_records('role'), null, ROLENAME_ALIAS)) {
            $role = isset($roles[$instance->roleid]) ? ' (' . $roles[$instance->roleid]->localname . ')' : '';
        }

        return get_string('pluginname', 'enrol_' . $enrol) . $role;
    }

    /**
     * Adds an instance, filling anything the caller left out from site defaults.
     *
     * @param stdClass $course Course record.
     * @param array|null $fields Instance fields.
     * @return int Instance id.
     */
    public function add_instance($course, ?array $fields = null) {
        $fields = (array)$fields + $this->defaults_for_new_instance();

        return parent::add_instance($course, $fields);
    }

    /**
     * Site defaults applied to a newly created instance.
     *
     * @return array
     */
    private function defaults_for_new_instance(): array {
        return [
            'status' => $this->get_config('status', ENROL_INSTANCE_DISABLED),
            'cost' => $this->get_config('cost', '0'),
            'currency' => \enrol_mercadopagosub\collector::resolve_currency(),
            'roleid' => $this->get_config('roleid', 0),
            'customint1' => 0,
            'customint2' => 0,
            'customint3' => 0,
            'customint4' => $this->get_config('sendcoursewelcomemessage', ENROL_DO_NOT_SEND_EMAIL),
            'customint5' => 0,
            'customint6' => $this->get_config('frequency', 1),
            'customint7' => $this->get_config('gracedays', 0),
            'customchar1' => $this->get_config('frequencytype', 'months'),
            'customchar2' => 'days',
        ];
    }

    /**
     * Builds the add and edit instance form.
     *
     * @param stdClass $instance Enrolment instance.
     * @param MoodleQuickForm $mform Form being built.
     * @param context $context Course context.
     * @return void
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context) {
        $mform->addElement('text', 'name', get_string('custominstancename', 'enrol'));
        $mform->setType('name', PARAM_TEXT);

        // Note for anyone editing this: core forces status to enabled when an
        // instance is created by hand, in enrol/editinstance.php, and set_data()
        // then overrides any setDefault() here. The site setting only governs
        // instances created automatically. Tests must set this field explicitly.
        $mform->addElement('select', 'status', get_string('status', 'enrol_mercadopagosub'), [
            ENROL_INSTANCE_ENABLED => get_string('yes'),
            ENROL_INSTANCE_DISABLED => get_string('no'),
        ]);
        $mform->addHelpButton('status', 'status', 'enrol_mercadopagosub');

        $mform->addElement('text', 'cost', get_string('cost', 'enrol_mercadopagosub'), ['size' => 8]);
        $mform->setType('cost', PARAM_RAW);
        $mform->addHelpButton('cost', 'cost', 'enrol_mercadopagosub');

        $mform->addElement(
            'select',
            'currency',
            get_string('currency', 'enrol_mercadopagosub'),
            $this->get_currencies()
        );
        $mform->addHelpButton('currency', 'currency', 'enrol_mercadopagosub');

        $mform->addElement('text', 'customint6', get_string('frequency', 'enrol_mercadopagosub'), ['size' => 4]);
        $mform->setType('customint6', PARAM_INT);
        $mform->addHelpButton('customint6', 'frequency', 'enrol_mercadopagosub');

        $mform->addElement('select', 'customchar1', get_string('frequencytype', 'enrol_mercadopagosub'), [
            'days' => get_string('frequencytype:days', 'enrol_mercadopagosub'),
            'months' => get_string('frequencytype:months', 'enrol_mercadopagosub'),
        ]);
        $mform->addHelpButton('customchar1', 'frequencytype', 'enrol_mercadopagosub');

        $mform->addElement('text', 'customint3', get_string('triallength', 'enrol_mercadopagosub'), ['size' => 4]);
        $mform->setType('customint3', PARAM_INT);
        $mform->addHelpButton('customint3', 'triallength', 'enrol_mercadopagosub');

        $mform->addElement('select', 'customchar2', get_string('trialtype', 'enrol_mercadopagosub'), [
            'days' => get_string('frequencytype:days', 'enrol_mercadopagosub'),
            'months' => get_string('frequencytype:months', 'enrol_mercadopagosub'),
        ]);
        $mform->hideIf('customchar2', 'customint3', 'eq', 0);

        $groups = $this->get_group_options($context);
        $mform->addElement('select', 'customint1', get_string('paidgroup', 'enrol_mercadopagosub'), $groups);
        $mform->addHelpButton('customint1', 'paidgroup', 'enrol_mercadopagosub');

        $mform->addElement('select', 'customint2', get_string('trialgroup', 'enrol_mercadopagosub'), $groups);
        $mform->addHelpButton('customint2', 'trialgroup', 'enrol_mercadopagosub');
        $mform->hideIf('customint2', 'customint3', 'eq', 0);

        $mform->addElement('text', 'customint7', get_string('gracedays', 'enrol_mercadopagosub'), ['size' => 4]);
        $mform->setType('customint7', PARAM_INT);
        $mform->addHelpButton('customint7', 'gracedays', 'enrol_mercadopagosub');

        $mform->addElement('text', 'customint5', get_string('maxenrolled', 'enrol_mercadopagosub'), ['size' => 4]);
        $mform->setType('customint5', PARAM_INT);
        $mform->addHelpButton('customint5', 'maxenrolled', 'enrol_mercadopagosub');

        $roles = $this->extend_assignable_roles($context, $instance->roleid ?? $this->get_config('roleid', 0));
        $mform->addElement('select', 'roleid', get_string('assignrole', 'enrol_mercadopagosub'), $roles);

        $mform->addElement(
            'date_time_selector',
            'enrolstartdate',
            get_string('enrolstartdate', 'enrol_mercadopagosub'),
            ['optional' => true]
        );
        $mform->addElement(
            'date_time_selector',
            'enrolenddate',
            get_string('enrolenddate', 'enrol_mercadopagosub'),
            ['optional' => true]
        );

        // Course welcome message. Gated on the same capability pair core uses, so
        // that a site which takes instance configuration away from teachers can
        // still let them write the welcome text.
        if (
            has_any_capability(
                ['enrol/mercadopagosub:config', 'moodle/course:editcoursewelcomemessage'],
                $context
            )
        ) {
            // Mirrors enrol_self, minus the key-holder sender option, which resolves
            // through enrol/self:holdkey and would silently send nothing here.
            $options = enrol_send_welcome_email_options();
            unset($options[ENROL_SEND_EMAIL_FROM_KEY_HOLDER]);
            $mform->addElement(
                'select',
                'customint4',
                get_string('sendcoursewelcomemessage', 'enrol_mercadopagosub'),
                $options
            );
            $mform->addHelpButton('customint4', 'sendcoursewelcomemessage', 'enrol_mercadopagosub');

            $mform->addElement(
                'textarea',
                'customtext1',
                get_string('customwelcomemessage', 'core_enrol'),
                ['cols' => '60', 'rows' => '8']
            );
            $mform->setDefault('customtext1', get_string('customwelcomemessageplaceholder', 'core_enrol'));
            $mform->hideIf(
                elementname: 'customtext1',
                dependenton: 'customint4',
                condition: 'eq',
                value: ENROL_DO_NOT_SEND_EMAIL,
            );

            // A static element cannot be hidden by hideIf() on its own, hence the
            // dummy group. See MDL-66251.
            $group = [];
            $group[] = $mform->createElement(
                'static',
                'customwelcomemessage_extra_help',
                null,
                get_string(identifier: 'customwelcomemessage_help', component: 'core_enrol'),
            );
            $mform->addGroup($group, 'group_customwelcomemessage_extra_help', '', ' ', false);
            $mform->hideIf(
                elementname: 'group_customwelcomemessage_extra_help',
                dependenton: 'customint4',
                condition: 'eq',
                value: ENROL_DO_NOT_SEND_EMAIL,
            );
        }

        if (has_capability('enrol/mercadopagosub:config', $context) && enrol_accessing_via_instance($instance)) {
            $mform->addElement(
                'static',
                'selfwarn',
                get_string('instanceeditselfwarning', 'core_enrol'),
                get_string('instanceeditselfwarningtext', 'core_enrol')
            );
        }
    }

    /**
     * Validates the instance form.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @param stdClass $instance Enrolment instance.
     * @param context $context Course context.
     * @return array Errors keyed by field name.
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        $errors = [];

        if (!is_numeric($data['cost'])) {
            $errors['cost'] = get_string('error:costnotnumeric', 'enrol_mercadopagosub');
        } else if ($data['cost'] <= 0) {
            $errors['cost'] = get_string('error:costnotpositive', 'enrol_mercadopagosub');
        }

        if ($data['customint6'] < 1) {
            $errors['customint6'] = get_string('error:frequencynotpositive', 'enrol_mercadopagosub');
        }

        if ($data['customint3'] < 0) {
            $errors['customint3'] = get_string('error:trialnegative', 'enrol_mercadopagosub');
        }

        if ($data['customint7'] < 0) {
            $errors['customint7'] = get_string('error:gracenegative', 'enrol_mercadopagosub');
        }

        if (!empty($data['enrolenddate']) && $data['enrolenddate'] < $data['enrolstartdate']) {
            $errors['enrolenddate'] = get_string('error:enrolenddate', 'enrol_mercadopagosub');
        }

        // Guarded with isset() and not !empty(): ENROL_INSTANCE_ENABLED is 0, so
        // !empty() makes this branch dead code. That exact bug shipped in a sibling
        // plugin and disabled the credentials check entirely.
        if (isset($data['status']) && (int)$data['status'] === ENROL_INSTANCE_ENABLED) {
            if (!\enrol_mercadopagosub\credentials::resolve()->is_complete()) {
                $errors['status'] = get_string('error:nocredentials', 'enrol_mercadopagosub');
            } else if (!$this->site_is_https()) {
                $errors['status'] = get_string('error:httpsrequired', 'enrol_mercadopagosub');
            }
        }

        $validation = [
            'name' => PARAM_TEXT,
            'status' => PARAM_INT,
            'currency' => PARAM_TEXT,
            'roleid' => PARAM_INT,
            'customint1' => PARAM_INT,
            'customint2' => PARAM_INT,
            'customint3' => PARAM_INT,
            'customint4' => PARAM_INT,
            'customint5' => PARAM_INT,
            'customint6' => PARAM_INT,
            'customint7' => PARAM_INT,
            'customchar1' => PARAM_ALPHA,
            'customchar2' => PARAM_ALPHA,
            'enrolstartdate' => PARAM_INT,
            'enrolenddate' => PARAM_INT,
        ];

        return array_merge($errors, $this->validate_param_types($data, $validation));
    }

    /**
     * Whether this site is served over HTTPS.
     *
     * Mercado Pago will not send a subscriber back to a plain-http return URL, and
     * credentials must not travel over one either. Refusing here is far cheaper
     * than discovering it at the checkout.
     *
     * @return bool
     */
    protected function site_is_https(): bool {
        global $CFG;

        return strpos($CFG->wwwroot, 'https://') === 0;
    }

    /**
     * Groups available on the instance form.
     *
     * @param context $context Course context.
     * @return array Group id to name, with 0 for none.
     */
    protected function get_group_options($context): array {
        $courseid = $context->instanceid;
        $options = [0 => get_string('none')];

        foreach (groups_get_all_groups($courseid) as $group) {
            $options[$group->id] = format_string($group->name, true, ['context' => $context]);
        }

        return $options;
    }

    /**
     * Whether $USER may start a new subscription through this instance.
     *
     * Not a core override — enrol_plugin has no can_subscribe() of its own — but
     * deliberately shaped like can_self_enrol()/is_self_enrol_available(): true,
     * or a string the learner reads explaining why not, or false to show nothing
     * at all. false and a string are different outcomes on purpose: false means
     * this method does not apply to this user (wrong capability, disabled
     * instance), a string means it does apply and something specific is stopping
     * them, which is worth saying.
     *
     * This is the single authority both enrol_page_hook() and the eventual
     * subscriber form call before creating anything at Mercado Pago. Duplicating
     * these checks in the form instead of sharing this method is how the two
     * would eventually disagree.
     *
     * @param stdClass $instance Enrolment instance.
     * @return true|string|false
     */
    public function can_subscribe(stdClass $instance) {
        global $USER, $DB;

        if ((int)$instance->status !== ENROL_INSTANCE_ENABLED) {
            return false;
        }

        if (!isloggedin() || isguestuser()) {
            return get_string('error:mustbeloggedin', 'enrol_mercadopagosub');
        }

        $context = context_course::instance($instance->courseid);
        if (!has_capability('enrol/mercadopagosub:subscribe', $context)) {
            return false;
        }

        // Defence in depth: edit_instance_validation() already refuses to enable
        // an instance without both of these, but credentials can be cleared, or
        // wwwroot changed, after the instance was already enabled.
        if (!\enrol_mercadopagosub\credentials::resolve()->is_complete() || !$this->site_is_https()) {
            return get_string('error:unavailable', 'enrol_mercadopagosub');
        }

        // A pending subscription counts as "in progress", not as "free to try
        // again": letting a second one start would leave two live preapprovals
        // racing for the same enrolment, and Mercado Pago has no way to cancel
        // one on our behalf if the learner simply abandons the first checkout.
        $hasopen = $DB->record_exists_select(
            'enrol_mercadopagosub_sub',
            'enrolid = :enrolid AND userid = :userid AND state <> :ended',
            ['enrolid' => $instance->id, 'userid' => $USER->id, 'ended' => 'ended']
        );
        if ($hasopen) {
            return get_string('error:alreadysubscribed', 'enrol_mercadopagosub');
        }

        $maxenrolled = (int)$instance->customint5;
        if ($maxenrolled > 0) {
            [$statesql, $stateparams] = $DB->get_in_or_equal(
                ['trialing', 'active', 'overdue'],
                SQL_PARAMS_NAMED,
                'state'
            );
            $count = $DB->count_records_select(
                'enrol_mercadopagosub_sub',
                "enrolid = :enrolid AND state {$statesql}",
                ['enrolid' => $instance->id] + $stateparams
            );
            if ($count >= $maxenrolled) {
                return get_string('error:maxenrolledreached', 'enrol_mercadopagosub');
            }
        }

        return true;
    }

    /**
     * Content shown on the course enrolment page for this method.
     *
     * A subscribe button when can_subscribe() allows it, the reason it does not
     * when it returns a string, or nothing at all when it returns false.
     *
     * subscribe.php does not exist yet — this links to it anyway, because the
     * link is part of this method's contract and the target is next in the
     * handover. It will 404 until that file is written.
     *
     * NOTE: HANDOVER.md previously recorded this signature as verified against
     * Moodle 5.2 source, including that it "builds output with
     * core_enrol\output\enrol_page and core\output\single_button". This session
     * could not re-confirm the constructor of core_enrol\output\enrol_page
     * against the actual source, so it is not used here — inventing it would be
     * exactly the kind of guessed API shape this project avoids. What follows
     * uses only $OUTPUT->box() and single_button, both stable across many
     * Moodle versions. Swap in the renderable once its constructor is confirmed
     * directly against public/enrol/self/lib.php or equivalent on the real 5.2
     * source, if it turns out to be required or preferred over this.
     *
     * @param stdClass $instance Enrolment instance.
     * @return string|null
     */
    public function enrol_page_hook(stdClass $instance) {
        global $OUTPUT;

        $reason = $this->can_subscribe($instance);

        if ($reason === false) {
            return null;
        }

        if ($reason === true) {
            $url = new moodle_url('/enrol/mercadopagosub/subscribe.php', ['id' => $instance->id]);
            $button = new single_button($url, get_string('subscribe', 'enrol_mercadopagosub'), 'get');

            return $OUTPUT->box($OUTPUT->render($button));
        }

        return $OUTPUT->box($reason, 'generalbox alert alert-info');
    }

    /**
     * Currencies offered on the instance form.
     *
     * A Mercado Pago account belongs to one marketplace site and settles in that
     * site's currency, so this is not a choice the course makes: it follows from
     * the credentials. The list therefore holds exactly one entry, read from the
     * collecting account.
     *
     * Deliberately not a whitelist of supported countries. Freezing a country list
     * into an integration is what has produced a separate Mercado Pago plugin per
     * country in the Moodle directory, and it goes stale whenever the platform
     * opens a market. A site whose account settles in a currency this plugin does
     * not recognise sets it explicitly in the plugin settings and carries on. That
     * also covers an operator who holds an account in a country other than the one
     * they teach from, which is a common enough arrangement with other gateways.
     *
     * @return array Currency code to label.
     */
    protected function get_currencies(): array {
        $currency = \enrol_mercadopagosub\collector::resolve_currency();

        if ($currency === '') {
            return ['' => get_string('currency:unknown', 'enrol_mercadopagosub')];
        }

        $names = get_string_manager()->get_list_of_currencies();

        return [$currency => $names[$currency] ?? $currency];
    }
}

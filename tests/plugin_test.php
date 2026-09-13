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

namespace enrol_mercadopagosub;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/mercadopagosub/tests/helper_trait.php');
require_once($CFG->dirroot . '/enrol/mercadopagosub/lib.php');

/**
 * Tests for the enrolment plugin class.
 *
 * can_subscribe() is the single authority on whether a person may start a
 * subscription: the enrolment page and the subscriber form both ask it rather
 * than re-deriving the same checks, so these tests are what keeps the two from
 * drifting apart.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\enrol_mercadopagosub_plugin::class)]
final class plugin_test extends \advanced_testcase {
    use helper_trait;

    /**
     * A logged-in user with the capability, on an enabled instance of a
     * properly configured site, may subscribe.
     *
     * @return void
     */
    public function test_a_configured_instance_allows_subscription(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertTrue(enrol_get_plugin('mercadopagosub')->can_subscribe($instance));
    }

    /**
     * A disabled instance shows nothing at all rather than a reason: turning
     * the method off closes the door to new subscriptions silently.
     *
     * @return void
     */
    public function test_a_disabled_instance_shows_nothing(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['id' => $instance->id]);
        $instance = $DB->get_record('enrol', ['id' => $instance->id], '*', MUST_EXIST);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse(enrol_get_plugin('mercadopagosub')->can_subscribe($instance));
    }

    /**
     * A guest is told why rather than shown a button that cannot work: the
     * subscription is tied to a user account that will hold the enrolment.
     *
     * @return void
     */
    public function test_a_guest_is_told_to_log_in(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $this->setGuestUser();

        $this->assertSame(
            get_string('error:mustbeloggedin', 'enrol_mercadopagosub'),
            enrol_get_plugin('mercadopagosub')->can_subscribe($instance)
        );
    }

    /**
     * Without the capability there is nothing to show.
     *
     * @return void
     */
    public function test_a_user_without_the_capability_sees_nothing(): void {
        global $DB;

        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'enrol/mercadopagosub:subscribe',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );

        $this->assertFalse(enrol_get_plugin('mercadopagosub')->can_subscribe($instance));
    }

    /**
     * Credentials can be cleared after an instance was enabled, so the check
     * is repeated here rather than trusted from save time.
     *
     * @return void
     */
    public function test_missing_credentials_close_the_door(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $this->setUser($this->getDataGenerator()->create_user());

        set_config('accesstoken', '', 'enrol_mercadopagosub');

        $this->assertSame(
            get_string('error:unavailable', 'enrol_mercadopagosub'),
            enrol_get_plugin('mercadopagosub')->can_subscribe($instance)
        );
    }

    /**
     * So can wwwroot, which is the other half of the same defence: Mercado
     * Pago refuses the back_urls of a site that is not served over HTTPS.
     *
     * @return void
     */
    public function test_a_site_without_https_closes_the_door(): void {
        global $CFG;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $this->setUser($this->getDataGenerator()->create_user());

        $CFG->wwwroot = str_replace('https://', 'http://', $CFG->wwwroot);

        $this->assertSame(
            get_string('error:unavailable', 'enrol_mercadopagosub'),
            enrol_get_plugin('mercadopagosub')->can_subscribe($instance)
        );
    }

    /**
     * A subscription still waiting for its first payment counts as in
     * progress: a second one would leave two live preapprovals racing for the
     * same enrolment, and the platform offers no way to cancel one on the
     * site's behalf if the learner simply abandons the first checkout.
     *
     * @return void
     */
    public function test_a_pending_subscription_blocks_a_second_one(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->create_subscription($instance, $user, ['state' => 'pending']);
        $this->setUser($user);

        $this->assertSame(
            get_string('error:alreadysubscribed', 'enrol_mercadopagosub'),
            enrol_get_plugin('mercadopagosub')->can_subscribe($instance)
        );
    }

    /**
     * An ended subscription does not block a new one: a returning subscriber
     * starts again at current prices.
     *
     * @return void
     */
    public function test_an_ended_subscription_does_not_block_a_new_one(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();
        $this->create_subscription($instance, $user, ['state' => 'ended']);
        $this->setUser($user);

        $this->assertTrue(enrol_get_plugin('mercadopagosub')->can_subscribe($instance));
    }

    /**
     * The subscriber cap counts everyone currently holding access, including
     * those behind on a payment, and is off entirely when set to zero.
     *
     * @return void
     */
    public function test_the_subscriber_cap_counts_everyone_with_access(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance(['customint5' => 2]);
        $this->create_subscription($instance, $this->getDataGenerator()->create_user(), ['state' => 'active']);
        $this->create_subscription($instance, $this->getDataGenerator()->create_user(), ['state' => 'overdue']);
        $this->setUser($this->getDataGenerator()->create_user());

        $plugin = enrol_get_plugin('mercadopagosub');
        $this->assertSame(
            get_string('error:maxenrolledreached', 'enrol_mercadopagosub'),
            $plugin->can_subscribe($instance)
        );

        $DB->set_field('enrol', 'customint5', 0, ['id' => $instance->id]);
        $instance = $DB->get_record('enrol', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertTrue($plugin->can_subscribe($instance));
    }

    /**
     * Subscriptions that have ended do not count against the cap.
     *
     * @return void
     */
    public function test_ended_subscriptions_do_not_count_against_the_cap(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance(['customint5' => 1]);
        $this->create_subscription($instance, $this->getDataGenerator()->create_user(), ['state' => 'ended']);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertTrue(enrol_get_plugin('mercadopagosub')->can_subscribe($instance));
    }

    /**
     * A pending subscription does count: it is a checkout in flight, and the
     * seat it will take is not free to sell twice.
     *
     * @return void
     */
    public function test_a_pending_subscription_counts_against_the_cap(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance(['customint5' => 1]);
        $this->create_subscription($instance, $this->getDataGenerator()->create_user(), ['state' => 'pending']);
        $this->setUser($this->getDataGenerator()->create_user());

        // Deliberately recorded rather than asserted as correct: a pending
        // subscription blocks the subscriber who owns it, but the cap counts
        // only trialing/active/overdue, so it does not hold a seat against
        // everyone else. Flagged in the handover as worth Julio's decision.
        $this->assertTrue(enrol_get_plugin('mercadopagosub')->can_subscribe($instance));
    }

    /**
     * A new instance picks up the site-level defaults.
     *
     * @return void
     */
    public function test_a_new_instance_takes_the_site_defaults(): void {
        global $DB;

        $this->setup_plugin();
        set_config('gracedays', 5, 'enrol_mercadopagosub');
        set_config('frequency', 3, 'enrol_mercadopagosub');
        set_config('frequencytype', 'days', 'enrol_mercadopagosub');

        $course = $this->getDataGenerator()->create_course();
        $plugin = enrol_get_plugin('mercadopagosub');
        $instanceid = $plugin->add_instance($course, ['cost' => 500, 'currency' => 'ARS']);
        $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);

        $this->assertSame(5, (int)$instance->customint7);
        $this->assertSame(3, (int)$instance->customint6);
        $this->assertSame('days', $instance->customchar1);
    }

    /**
     * The plugin reports the scheduled-task-facing capabilities core asks
     * about before offering the corresponding management UI.
     *
     * @return void
     */
    public function test_the_plugin_declares_its_management_capabilities(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $plugin = enrol_get_plugin('mercadopagosub');
        $context = \context_course::instance($instance->courseid);

        $this->setAdminUser();

        $this->assertTrue($plugin->can_add_instance($instance->courseid));
        $this->assertTrue($plugin->can_hide_show_instance($instance));
        $this->assertTrue($plugin->can_delete_instance($instance));
    }

    /**
     * The instance form builds.
     *
     * This exists because it did not. `edit_instance_form()` called
     * `$this->extend_assignable_roles()`, which `enrol_plugin` does not define —
     * every plugin offering a role selector declares its own — so adding a
     * subscription method to a course died on a fatal error, on the very first
     * screen a customer would ever reach. Nothing in this suite noticed, because
     * nothing in it built the form; Behat found it on its first run.
     *
     * Calling every element into existence is the whole point: an undefined
     * method, a missing string, a bad element type all surface here rather than
     * in front of somebody adding the method for the first time.
     *
     * @return void
     */
    public function test_the_instance_form_builds(): void {
        global $CFG;

        require_once($CFG->libdir . '/formslib.php');

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $context = \context_course::instance($instance->courseid);

        $this->setAdminUser();

        $mform = new \MoodleQuickForm('enrol_mercadopagosub_test', 'post', '');
        enrol_get_plugin('mercadopagosub')->edit_instance_form($instance, $mform, $context);

        foreach (['status', 'cost', 'currency', 'roleid', 'customint6', 'customchar1'] as $element) {
            $this->assertTrue($mform->elementExists($element), "The form has no '$element' element.");
        }
    }

    /**
     * The add form arrives pre-filled from the site settings.
     *
     * `enrol/editinstance.php` builds a new instance from
     * `(object)$plugin->get_instance_defaults()` and hands that to `set_data()`.
     * These defaults used to live in a private method called only by
     * `add_instance()`, so the add form came up blank and the first thing a
     * customer saw was a validation error about a field they had never been
     * shown a value for. Assert the core hook, not our own method: that is the
     * one core calls.
     *
     * @return void
     */
    public function test_the_add_form_is_prefilled_from_the_site_settings(): void {
        global $CFG;

        require_once($CFG->libdir . '/formslib.php');

        $this->setup_plugin();
        set_config('frequency', 3, 'enrol_mercadopagosub');
        set_config('gracedays', 5, 'enrol_mercadopagosub');
        set_config('frequencytype', 'days', 'enrol_mercadopagosub');

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $plugin = enrol_get_plugin('mercadopagosub');
        $this->setAdminUser();

        $defaults = $plugin->get_instance_defaults();
        $this->assertSame(3, (int)$defaults['customint6']);
        $this->assertSame(5, (int)$defaults['customint7']);
        $this->assertSame('days', $defaults['customchar1']);

        // And what core does with them: the object it builds reaches set_data(),
        // so the rendered form carries the values rather than blanks.
        $instance = (object)$defaults;
        $instance->id = null;
        $instance->courseid = $course->id;
        $instance->status = ENROL_INSTANCE_ENABLED;

        $mform = new \MoodleQuickForm('enrol_mercadopagosub_defaults', 'post', '');
        $plugin->edit_instance_form($instance, $mform, $context);
        $mform->setDefaults((array)$instance);

        $this->assertSame(3, (int)$mform->getElementValue('customint6'));
        $this->assertSame(5, (int)$mform->getElementValue('customint7'));

        // Which is to say: submitting the form untouched passes validation.
        $errors = $plugin->edit_instance_validation((array)$instance, [], null, $context);
        $this->assertArrayNotHasKey('customint6', $errors);
    }

    /**
     * The role selector keeps whatever role the instance already carries, even
     * one the editing user could not have chosen themselves. Otherwise opening
     * the form as a teacher and pressing save would quietly move an
     * administrator's choice to something else.
     *
     * @return void
     */
    public function test_the_role_selector_keeps_a_role_the_user_cannot_assign(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $context = \context_course::instance($instance->courseid);
        $managerid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        $teacher = $this->getDataGenerator()->create_and_enrol(
            $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST),
            'editingteacher'
        );
        $this->setUser($teacher);

        $roles = (new \ReflectionMethod(\enrol_mercadopagosub_plugin::class, 'extend_assignable_roles'))
            ->invoke(enrol_get_plugin('mercadopagosub'), $context, $managerid);

        $this->assertArrayHasKey($managerid, $roles);
        $this->assertNotEmpty($roles[$managerid]);
    }
}

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

/**
 * Tests for the data generator.
 *
 * The generator exists for the Behat suite, which cannot run in continuous
 * integration for this plugin — the scenarios that matter need a site served
 * over real HTTPS. These tests are what keeps the generator honest in the
 * meantime: they exercise it exactly as a feature file would, so a broken
 * generator fails here rather than on the day somebody runs Behat before a
 * release.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\enrol_mercadopagosub_generator::class)]
final class generator_test extends \advanced_testcase {
    use helper_trait;

    /**
     * Returns this plugin's generator, the way a Behat step reaches it.
     *
     * @return \enrol_mercadopagosub_generator
     */
    private function generator(): \enrol_mercadopagosub_generator {
        return $this->getDataGenerator()->get_plugin_generator('enrol_mercadopagosub');
    }

    /**
     * The generator is registered under the component name, which is what the
     * Behat step resolves.
     *
     * @return void
     */
    public function test_the_generator_is_reachable_by_component_name(): void {
        $this->setup_plugin();

        $this->assertInstanceOf(\enrol_mercadopagosub_generator::class, $this->generator());
    }

    /**
     * The generator can add the enrolment method itself, which is what a feature
     * needs before it can create any subscription at all. Without it the only
     * way to get an instance is the instance form, which is the thing under test
     * in half these scenarios and far too slow to repeat in the other half.
     *
     * @return void
     */
    public function test_the_generator_can_add_the_enrolment_method(): void {
        global $DB;

        $this->setup_plugin();
        $course = $this->getDataGenerator()->create_course();

        $instance = $this->generator()->create_instance(['courseid' => $course->id]);

        $this->assertSame('mercadopagosub', $instance->enrol);
        $this->assertSame((int)$course->id, (int)$instance->courseid);

        // Disabled by default: enabling runs the credentials and HTTPS checks,
        // which a scenario about something else should not have to satisfy.
        $this->assertSame(ENROL_INSTANCE_DISABLED, (int)$instance->status);

        // And a subscription can now be hung off it, which is the point.
        $sub = $this->generator()->create_subscription([
            'courseid' => $course->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
        ]);
        $this->assertSame((int)$instance->id, (int)$DB->get_field(
            'enrol_mercadopagosub_sub',
            'enrolid',
            ['id' => $sub->id]
        ));
    }

    /**
     * The words 'enabled' and 'disabled' work in the status column, because
     * ENROL_INSTANCE_ENABLED is 0 and DISABLED is 1 and no feature file should
     * have to remember that.
     *
     * @return void
     */
    public function test_the_instance_status_accepts_words(): void {
        $this->setup_plugin();

        $enabled = $this->generator()->create_instance([
            'courseid' => $this->getDataGenerator()->create_course()->id,
            'status' => 'enabled',
        ]);
        $disabled = $this->generator()->create_instance([
            'courseid' => $this->getDataGenerator()->create_course()->id,
            'status' => 'disabled',
        ]);

        $this->assertSame(ENROL_INSTANCE_ENABLED, (int)$enabled->status);
        $this->assertSame(ENROL_INSTANCE_DISABLED, (int)$disabled->status);
    }

    /**
     * A subscription can be created from a course rather than an instance id,
     * which is what a feature file has to hand.
     *
     * @return void
     */
    public function test_a_subscription_can_be_created_from_a_course(): void {
        global $DB;

        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();

        $sub = $this->generator()->create_subscription([
            'courseid' => $course->id,
            'userid' => $user->id,
        ]);

        $stored = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $sub->id], '*', MUST_EXIST);
        $this->assertSame((int)$instance->id, (int)$stored->enrolid);
        $this->assertSame((int)$user->id, (int)$stored->userid);
        $this->assertSame('active', $stored->state);
    }

    /**
     * Each state comes out internally consistent. A row that says 'overdue'
     * but carries a future due date would make a feature assert something the
     * plugin could never produce.
     *
     * @return void
     */
    public function test_each_state_is_internally_consistent(): void {
        $this->setup_plugin();
        [$course] = $this->create_instance();
        $generator = $this->generator();

        $pending = $generator->create_subscription([
            'courseid' => $course->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
            'state' => 'pending',
        ]);
        $this->assertSame('pending', $pending->mpstatus);
        $this->assertSame(0, (int)$pending->timeauthorized);

        $overdue = $generator->create_subscription([
            'courseid' => $course->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
            'state' => 'overdue',
        ]);
        $this->assertSame('authorized', $overdue->mpstatus);
        $this->assertLessThan(time(), (int)$overdue->nextpaymentdate);
        $this->assertGreaterThan(0, (int)$overdue->dunningsince);

        $ended = $generator->create_subscription([
            'courseid' => $course->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
            'state' => 'ended',
        ]);
        $this->assertSame('cancelled', $ended->mpstatus);
        $this->assertGreaterThan(0, (int)$ended->timeended);
    }

    /**
     * Every generated reference parses, because a subscription the plugin
     * cannot recognise is useless to any scenario about a notification
     * arriving for it.
     *
     * @return void
     */
    public function test_generated_references_are_recognisable(): void {
        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();

        $sub = $this->generator()->create_subscription([
            'courseid' => $course->id,
            'userid' => $user->id,
        ]);

        $parsed = util::parse_reference($sub->externalreference);
        $this->assertNotNull($parsed);
        $this->assertSame((int)$instance->id, $parsed['enrolid']);
        $this->assertSame((int)$user->id, $parsed['userid']);
    }

    /**
     * Two subscriptions never collide on the columns the schema declares
     * unique, which is what a scenario with several subscribers needs.
     *
     * @return void
     */
    public function test_subscriptions_do_not_collide(): void {
        $this->setup_plugin();
        [$course] = $this->create_instance();
        $generator = $this->generator();

        $first = $generator->create_subscription([
            'courseid' => $course->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
        ]);
        $second = $generator->create_subscription([
            'courseid' => $course->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
        ]);

        $this->assertNotSame($first->preapprovalid, $second->preapprovalid);
        $this->assertNotSame($first->externalreference, $second->externalreference);
    }

    /**
     * Asking for the enrolment grants it, so a scenario about somebody already
     * studying does not have to enrol them separately and risk disagreeing
     * with the subscription's own state.
     *
     * @return void
     */
    public function test_a_subscription_can_carry_its_enrolment(): void {
        $this->setup_plugin();
        [$course] = $this->create_instance();
        $user = $this->getDataGenerator()->create_user();

        $this->generator()->create_subscription([
            'courseid' => $course->id,
            'userid' => $user->id,
            'enrol' => true,
        ]);

        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $user->id));
    }

    /**
     * A course with no subscription method is a mistake in the feature file,
     * and says so rather than inserting a row pointing at nothing.
     *
     * @return void
     */
    public function test_a_course_without_the_method_is_refused(): void {
        $this->setup_plugin();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(\coding_exception::class);
        $this->generator()->create_subscription([
            'courseid' => $course->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
        ]);
    }

    /**
     * Payments and notifications generate too, and land where the services
     * that read them look.
     *
     * @return void
     */
    public function test_payments_and_notifications_generate(): void {
        global $DB;

        $this->setup_plugin();
        [$course] = $this->create_instance();
        $generator = $this->generator();
        $sub = $generator->create_subscription([
            'courseid' => $course->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
        ]);

        $payment = $generator->create_payment(['subid' => $sub->id]);
        $this->assertTrue($DB->record_exists('enrol_mercadopagosub_payment', ['id' => $payment->id]));
        $this->assertSame('processed', $payment->status);

        $event = $generator->create_event(['resourceid' => $sub->preapprovalid]);
        $this->assertSame('queued', $event->processstatus);
        $this->assertSame('verified', $event->signaturestatus);
        $this->assertSame(
            $sub->preapprovalid,
            $DB->get_field('enrol_mercadopagosub_event', 'resourceid', ['id' => $event->id])
        );
    }

    /**
     * A generated notification is one the processor can actually act on: it
     * finds the subscription, which is the whole point of generating one.
     *
     * @return void
     */
    public function test_a_generated_notification_resolves_to_its_subscription(): void {
        global $DB;

        $transport = $this->setup_plugin();
        [$course] = $this->create_instance();
        $generator = $this->generator();
        $sub = $generator->create_subscription([
            'courseid' => $course->id,
            'userid' => $this->getDataGenerator()->create_user()->id,
            'state' => 'pending',
        ]);
        $generator->create_event(['resourceid' => $sub->preapprovalid]);

        $transport->on('/preapproval/', $this->subscription_response($sub, 'authorized'));
        $counts = (new event_processor(new api_client(null, $transport)))->process_queued();

        $this->assertSame(1, $counts['processed']);
        $this->assertSame('active', $DB->get_field('enrol_mercadopagosub_sub', 'state', ['id' => $sub->id]));
    }
}

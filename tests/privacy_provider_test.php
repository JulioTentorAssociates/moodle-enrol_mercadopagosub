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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use enrol_mercadopagosub\privacy\provider;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/enrol/mercadopagosub/tests/helper_trait.php');

/**
 * Tests for the privacy provider.
 *
 * A subscription holds data about two people — the subscriber and whoever
 * pays for it — and this plugin treats a third-party payer as a first-class
 * case rather than an edge one. Most of what follows pins down that
 * asymmetry: both people can reach their own data, but a payer's deletion
 * request must not destroy a subscriber's enrolment history.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class privacy_provider_test extends \advanced_testcase {
    use helper_trait;

    /**
     * Inserts a payment row against a subscription.
     *
     * @param \stdClass $sub Subscription the payment belongs to.
     * @param string $mppaymentid Payment id.
     * @return int The inserted row id.
     */
    private function add_payment(\stdClass $sub, string $mppaymentid): int {
        global $DB;

        return $DB->insert_record('enrol_mercadopagosub_payment', (object)[
            'subid' => $sub->id,
            'mppaymentid' => $mppaymentid,
            'status' => 'processed',
            'statusdetail' => 'accredited',
            'amount' => 1000,
            'currency' => 'ARS',
            'debitdate' => time() - DAYSECS,
            'periodstart' => time() - DAYSECS,
            'periodend' => time() + 29 * DAYSECS,
            'timecreated' => time(),
            'timemodified' => time(),
            'payload' => '{"id":"' . $mppaymentid . '"}',
        ]);
    }

    /**
     * The export path this provider writes under.
     *
     * @return array
     */
    private function export_path(): array {
        return [get_string('privacy:path:subscriptions', 'enrol_mercadopagosub')];
    }

    /**
     * Both the subscriber and a payer who holds an account here can find their
     * data, and somebody uninvolved finds nothing.
     *
     * @return void
     */
    public function test_contexts_cover_the_subscriber_and_the_payer(): void {
        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $payer = $this->getDataGenerator()->create_user(['email' => 'employer@example.com']);
        $bystander = $this->getDataGenerator()->create_user();
        $this->create_subscription($instance, $subscriber, ['payeremail' => 'Employer@Example.com']);

        $coursecontext = \context_course::instance($course->id);

        $this->assertSame(
            [(int)$coursecontext->id],
            array_map('intval', provider::get_contexts_for_userid($subscriber->id)->get_contextids())
        );
        $this->assertSame(
            [(int)$coursecontext->id],
            array_map('intval', provider::get_contexts_for_userid($payer->id)->get_contextids()),
            'the payer could not reach a subscription their address is on'
        );
        $this->assertEmpty(provider::get_contexts_for_userid($bystander->id)->get_contextids());
    }

    /**
     * Both people show up as having data in the course context.
     *
     * @return void
     */
    public function test_users_in_context_covers_both_roles(): void {
        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $payer = $this->getDataGenerator()->create_user(['email' => 'employer@example.com']);
        $this->getDataGenerator()->create_user();
        $this->create_subscription($instance, $subscriber, ['payeremail' => 'employer@example.com']);

        $userlist = new userlist(\context_course::instance($course->id), 'enrol_mercadopagosub');
        provider::get_users_in_context($userlist);
        $found = array_map('intval', $userlist->get_userids());

        sort($found);
        $expected = [(int)$subscriber->id, (int)$payer->id];
        sort($expected);
        $this->assertSame($expected, $found);
    }

    /**
     * Only course contexts are considered: asking about a user context must
     * not return the whole site's subscriptions.
     *
     * @return void
     */
    public function test_users_in_context_ignores_other_context_levels(): void {
        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $this->create_subscription($instance, $subscriber);

        $userlist = new userlist(\context_user::instance($subscriber->id), 'enrol_mercadopagosub');
        provider::get_users_in_context($userlist);

        $this->assertEmpty($userlist->get_userids());
    }

    /**
     * A subscriber's export carries the whole record: the subscription, its
     * payments, and the notifications that named it.
     *
     * @return void
     */
    public function test_a_subscriber_exports_the_whole_record(): void {
        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $subscriber);
        $this->add_payment($sub, 'pay-1');
        $this->queue_event('subscription_preapproval', $sub->preapprovalid);

        $context = \context_course::instance($course->id);
        provider::export_user_data(new approved_contextlist(
            $subscriber,
            'enrol_mercadopagosub',
            [$context->id]
        ));

        $data = writer::with_context($context)->get_data($this->export_path());
        $this->assertCount(1, $data->subscriptions);
        $exported = reset($data->subscriptions);

        $this->assertSame($sub->preapprovalid, $exported->preapprovalid);
        $this->assertSame($sub->externalreference, $exported->externalreference);
        $this->assertSame($sub->payeremail, $exported->payeremail);
        $this->assertSame('active', $exported->state);
        $this->assertCount(1, $exported->payments);
        $this->assertSame('pay-1', reset($exported->payments)->mppaymentid);
        $this->assertCount(1, $exported->notifications);
        $this->assertSame('subscription_preapproval', reset($exported->notifications)->topic);
    }

    /**
     * A payer exports the billing view and nothing that identifies the
     * subscription itself, which is the subscriber's data rather than theirs.
     *
     * @return void
     */
    public function test_a_payer_exports_only_the_billing_view(): void {
        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $payer = $this->getDataGenerator()->create_user(['email' => 'employer@example.com']);
        $sub = $this->create_subscription($instance, $subscriber, ['payeremail' => 'employer@example.com']);
        $this->add_payment($sub, 'pay-1');
        $this->queue_event('subscription_preapproval', $sub->preapprovalid);

        $context = \context_course::instance($course->id);
        provider::export_user_data(new approved_contextlist($payer, 'enrol_mercadopagosub', [$context->id]));

        $exported = reset(writer::with_context($context)->get_data($this->export_path())->subscriptions);

        $this->assertSame('employer@example.com', $exported->payeremail);
        $this->assertSame('active', $exported->state);
        $this->assertCount(1, $exported->payments);
        $this->assertObjectNotHasProperty('preapprovalid', $exported);
        $this->assertObjectNotHasProperty('externalreference', $exported);
        $this->assertObjectNotHasProperty('notifications', $exported);
    }

    /**
     * Timestamps that mean "has not happened" are exported as nothing rather
     * than as January 1970.
     *
     * @return void
     */
    public function test_unset_timestamps_are_not_exported_as_the_epoch(): void {
        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $this->create_subscription($instance, $subscriber, [
            'state' => 'pending',
            'timeauthorized' => 0,
            'timeended' => 0,
            'enddate' => null,
            'nextpaymentdate' => 0,
        ]);

        $context = \context_course::instance($course->id);
        provider::export_user_data(new approved_contextlist(
            $subscriber,
            'enrol_mercadopagosub',
            [$context->id]
        ));

        $exported = reset(writer::with_context($context)->get_data($this->export_path())->subscriptions);

        $this->assertNull($exported->timeauthorized);
        $this->assertNull($exported->timeended);
        $this->assertNull($exported->enddate);
        $this->assertNull($exported->nextpaymentdate);
    }

    /**
     * Nothing is exported for a course the request did not approve.
     *
     * @return void
     */
    public function test_only_approved_contexts_are_exported(): void {
        $this->setup_plugin();
        [$first, $firstinstance] = $this->create_instance();
        [$second, $secondinstance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $this->create_subscription($firstinstance, $subscriber);
        $this->create_subscription($secondinstance, $subscriber);

        $firstcontext = \context_course::instance($first->id);
        provider::export_user_data(new approved_contextlist(
            $subscriber,
            'enrol_mercadopagosub',
            [$firstcontext->id]
        ));

        $this->assertTrue(writer::with_context($firstcontext)->has_any_data());
        $this->assertFalse(writer::with_context(\context_course::instance($second->id))->has_any_data());
    }

    /**
     * A subscriber's deletion removes the subscription, its payments, and the
     * notifications that named its preapproval id.
     *
     * @return void
     */
    public function test_deleting_a_subscriber_removes_their_whole_record(): void {
        global $DB;

        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $subscriber);
        $othersub = $this->create_subscription($instance, $other);
        $this->add_payment($sub, 'pay-1');
        $this->add_payment($othersub, 'pay-2');
        $this->queue_event('subscription_preapproval', $sub->preapprovalid);
        $this->queue_event('subscription_preapproval', $othersub->preapprovalid);

        provider::delete_data_for_user(new approved_contextlist(
            $subscriber,
            'enrol_mercadopagosub',
            [\context_course::instance($course->id)->id]
        ));

        $this->assertFalse($DB->record_exists('enrol_mercadopagosub_sub', ['id' => $sub->id]));
        $this->assertFalse($DB->record_exists('enrol_mercadopagosub_payment', ['mppaymentid' => 'pay-1']));
        $this->assertFalse($DB->record_exists('enrol_mercadopagosub_event', ['resourceid' => $sub->preapprovalid]));

        $this->assertTrue($DB->record_exists('enrol_mercadopagosub_sub', ['id' => $othersub->id]));
        $this->assertTrue($DB->record_exists('enrol_mercadopagosub_payment', ['mppaymentid' => 'pay-2']));
        $this->assertTrue($DB->record_exists('enrol_mercadopagosub_event', ['resourceid' => $othersub->preapprovalid]));
    }

    /**
     * A payer's deletion clears their address and leaves the subscription
     * itself standing: it is somebody else's enrolment history.
     *
     * @return void
     */
    public function test_deleting_a_payer_clears_the_address_but_keeps_the_subscription(): void {
        global $DB;

        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $payer = $this->getDataGenerator()->create_user(['email' => 'employer@example.com']);
        $sub = $this->create_subscription($instance, $subscriber, ['payeremail' => 'Employer@Example.com']);
        $this->add_payment($sub, 'pay-1');

        provider::delete_data_for_user(new approved_contextlist(
            $payer,
            'enrol_mercadopagosub',
            [\context_course::instance($course->id)->id]
        ));

        $updated = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $sub->id], '*', MUST_EXIST);
        $this->assertSame('', $updated->payeremail);
        $this->assertSame((int)$subscriber->id, (int)$updated->userid);
        $this->assertTrue($DB->record_exists('enrol_mercadopagosub_payment', ['mppaymentid' => 'pay-1']));
    }

    /**
     * Deleting a course context removes that course's subscriptions and
     * nothing else's.
     *
     * @return void
     */
    public function test_deleting_a_context_removes_only_that_courses_data(): void {
        global $DB;

        $this->setup_plugin();
        [$first, $firstinstance] = $this->create_instance();
        [, $secondinstance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $kept = $this->create_subscription($secondinstance, $subscriber);
        $removed = $this->create_subscription($firstinstance, $subscriber);
        $this->add_payment($removed, 'pay-1');
        $this->queue_event('subscription_preapproval', $removed->preapprovalid);

        provider::delete_data_for_all_users_in_context(\context_course::instance($first->id));

        $this->assertFalse($DB->record_exists('enrol_mercadopagosub_sub', ['id' => $removed->id]));
        $this->assertFalse($DB->record_exists('enrol_mercadopagosub_payment', ['mppaymentid' => 'pay-1']));
        $this->assertFalse($DB->record_exists('enrol_mercadopagosub_event', ['resourceid' => $removed->preapprovalid]));
        $this->assertTrue($DB->record_exists('enrol_mercadopagosub_sub', ['id' => $kept->id]));
    }

    /**
     * A context level this plugin stores nothing at is left alone.
     *
     * @return void
     */
    public function test_deleting_a_non_course_context_does_nothing(): void {
        global $DB;

        $this->setup_plugin();
        [, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $subscriber);

        provider::delete_data_for_all_users_in_context(\context_user::instance($subscriber->id));

        $this->assertTrue($DB->record_exists('enrol_mercadopagosub_sub', ['id' => $sub->id]));
    }

    /**
     * Deleting several users at once follows the same asymmetry: subscribers
     * lose their rows, a payer only loses their address.
     *
     * @return void
     */
    public function test_deleting_several_users_keeps_the_same_asymmetry(): void {
        global $DB;

        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $payer = $this->getDataGenerator()->create_user(['email' => 'employer@example.com']);
        $untouched = $this->getDataGenerator()->create_user();

        $deleted = $this->create_subscription($instance, $subscriber);
        $paidfor = $this->create_subscription($instance, $untouched, ['payeremail' => 'employer@example.com']);

        $userlist = new approved_userlist(
            \context_course::instance($course->id),
            'enrol_mercadopagosub',
            [$subscriber->id, $payer->id]
        );
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists('enrol_mercadopagosub_sub', ['id' => $deleted->id]));
        $kept = $DB->get_record('enrol_mercadopagosub_sub', ['id' => $paidfor->id], '*', MUST_EXIST);
        $this->assertSame('', $kept->payeremail);
        $this->assertSame((int)$untouched->id, (int)$kept->userid);
    }

    /**
     * Notifications that name nobody survive a deletion: a payment
     * notification carries an id in a space this plugin cannot resolve to a
     * subscription, so it identifies no one once the subscription is gone.
     *
     * @return void
     */
    public function test_unattributable_notifications_survive_a_deletion(): void {
        global $DB;

        $this->setup_plugin();
        [$course, $instance] = $this->create_instance();
        $subscriber = $this->getDataGenerator()->create_user();
        $sub = $this->create_subscription($instance, $subscriber);
        $this->queue_event('payment', '123456789');

        provider::delete_data_for_user(new approved_contextlist(
            $subscriber,
            'enrol_mercadopagosub',
            [\context_course::instance($course->id)->id]
        ));

        $this->assertFalse($DB->record_exists('enrol_mercadopagosub_sub', ['id' => $sub->id]));
        $this->assertTrue($DB->record_exists('enrol_mercadopagosub_event', ['resourceid' => '123456789']));
    }

    /**
     * The metadata declares the three tables, the platform it sends data to,
     * and the messaging subsystem it reaches through.
     *
     * @return void
     */
    public function test_metadata_declares_everything_this_plugin_stores(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('enrol_mercadopagosub'));

        $names = array_map(
            static fn($item): string => $item->get_name(),
            $collection->get_collection()
        );

        $this->assertContains('enrol_mercadopagosub_sub', $names);
        $this->assertContains('enrol_mercadopagosub_payment', $names);
        $this->assertContains('enrol_mercadopagosub_event', $names);
        $this->assertContains('mercadopago.com', $names);
        $this->assertContains('core_message', $names);
    }
}

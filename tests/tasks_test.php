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
 * Tests for the scheduled tasks.
 *
 * These are thin wrappers, so what is worth pinning is not their logic but
 * their wiring: that db/tasks.php names classes that exist, that each one is
 * registered with core, that each names itself in a language string rather
 * than a raw identifier, and that none of them does anything on a site where
 * the plugin is disabled — which is the difference between a site that has
 * turned this off and a site still being charged by it.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(task\process_events::class)]
#[CoversClass(task\reconcile_payments::class)]
#[CoversClass(task\process_expirations::class)]
#[CoversClass(task\send_expiry_notifications::class)]
final class tasks_test extends \advanced_testcase {
    use helper_trait;

    /**
     * The four task classes this plugin ships.
     *
     * @return string[]
     */
    private function task_classes(): array {
        return [
            task\process_events::class,
            task\reconcile_payments::class,
            task\process_expirations::class,
            task\send_expiry_notifications::class,
        ];
    }

    /**
     * Every class named in db/tasks.php exists and is a scheduled task. A typo
     * here does not fail at install time — it fails silently at cron.
     *
     * @return void
     */
    public function test_every_registered_task_class_exists(): void {
        global $CFG;

        $tasks = [];
        require($CFG->dirroot . '/enrol/mercadopagosub/db/tasks.php');

        $this->assertCount(4, $tasks);

        foreach ($tasks as $definition) {
            $classname = $definition['classname'];
            $this->assertTrue(class_exists($classname), "{$classname} is registered but does not exist");
            $this->assertInstanceOf(\core\task\scheduled_task::class, new $classname());
        }
    }

    /**
     * Core can find all four, which is what actually decides whether cron runs
     * them.
     *
     * @return void
     */
    public function test_core_knows_about_all_four_tasks(): void {
        $this->resetAfterTest();

        $registered = array_map(
            static fn(\core\task\scheduled_task $task): string => get_class($task),
            \core\task\manager::get_all_scheduled_tasks()
        );

        foreach ($this->task_classes() as $classname) {
            $this->assertContains($classname, $registered, "core does not know about {$classname}");
        }
    }

    /**
     * Each task names itself through a language string, so the scheduled task
     * admin screen does not show a raw identifier.
     *
     * @return void
     */
    public function test_each_task_has_a_translated_name(): void {
        $this->resetAfterTest();

        foreach ($this->task_classes() as $classname) {
            $name = (new $classname())->get_name();
            $this->assertNotEmpty($name);
            $this->assertStringNotContainsString('[[', $name, "{$classname} has no language string");
        }
    }

    /**
     * With the plugin disabled, the queue is left exactly as it was. A site
     * that has turned this enrolment method off must not have its webhook
     * backlog quietly consumed.
     *
     * @return void
     */
    public function test_event_processing_does_nothing_while_disabled(): void {
        global $DB;

        $this->setup_plugin();
        $event = $this->queue_event('payment', '123');

        $enabled = enrol_get_plugins(true);
        unset($enabled['mercadopagosub']);
        set_config('enrol_plugins_enabled', implode(',', array_keys($enabled)));

        ob_start();
        (new task\process_events())->execute();
        ob_end_clean();

        $this->assertSame(
            'queued',
            $DB->get_field('enrol_mercadopagosub_event', 'processstatus', ['id' => $event->id])
        );
    }

    /**
     * Enabled, the same task drains the queue. A payment notification needs no
     * API call, which is what makes this assertable without a network.
     *
     * @return void
     */
    public function test_event_processing_runs_while_enabled(): void {
        global $DB;

        $this->setup_plugin();
        $event = $this->queue_event('payment', '123');

        ob_start();
        (new task\process_events())->execute();
        $output = ob_get_clean();

        $this->assertSame(
            'processed',
            $DB->get_field('enrol_mercadopagosub_event', 'processstatus', ['id' => $event->id])
        );
        $this->assertStringContainsString('processed=1', $output);
    }

    /**
     * Reconciliation reports its counts, and finds nothing to do on a site
     * with no live subscriptions — which is the state every site is in before
     * its first subscriber, and the one where an unguarded sweep would still
     * reach for the network.
     *
     * @return void
     */
    public function test_reconciliation_reports_an_empty_sweep(): void {
        $this->setup_plugin();

        ob_start();
        (new task\reconcile_payments())->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('reconciled=0', $output);
        $this->assertStringContainsString('failed=0', $output);
    }

    /**
     * Reconciliation is silent while the plugin is disabled.
     *
     * @return void
     */
    public function test_reconciliation_does_nothing_while_disabled(): void {
        $this->setup_plugin();

        $enabled = enrol_get_plugins(true);
        unset($enabled['mercadopagosub']);
        set_config('enrol_plugins_enabled', implode(',', array_keys($enabled)));

        ob_start();
        (new task\reconcile_payments())->execute();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    /**
     * The two enrolment-lifecycle tasks delegate to the base class methods,
     * which already implement them. Running them on an empty site must be a
     * no-op rather than a fatal — this is the shape that was wrong once
     * already, when lib.php overrode methods the base class provides.
     *
     * @return void
     */
    public function test_the_lifecycle_tasks_run_cleanly_on_an_empty_site(): void {
        $this->setup_plugin();
        set_config('expirynotifyhour', 0, 'enrol_mercadopagosub');

        ob_start();
        (new task\process_expirations())->execute();
        (new task\send_expiry_notifications())->execute();
        ob_end_clean();

        $this->assertTrue(true, 'both lifecycle tasks completed without raising');
    }
}

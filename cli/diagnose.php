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
 * Diagnoses an installation of the Mercado Pago Subscriptions enrolment method.
 *
 * Answers the question an administrator actually asks — "why can nobody
 * subscribe to this course?" — by checking, in order, the things that silently
 * prevent it: the plugin not being enabled, capabilities missing, the site not
 * being served over HTTPS, credentials absent or belonging to a test account,
 * the scheduled tasks never running, and the enrolment form refusing to save.
 *
 * Deliberately avoids get_string() so that it still produces a useful report
 * when the language cache is the thing that is broken.
 *
 * Usage:
 *   php enrol/mercadopagosub/cli/diagnose.php
 *   php enrol/mercadopagosub/cli/diagnose.php --courseid=12 --username=jperez
 *   php enrol/mercadopagosub/cli/diagnose.php --courseid=12 --tryadd
 *
 * @package   enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/enrol/mercadopagosub/lib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'courseid' => 0,
        'username' => '',
        'tryadd' => false,
        'keep' => false,
        'cost' => '',
        'name' => '',
        'checkaccount' => false,
    ],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error('Unrecognised option: ' . implode(' ', $unrecognised));
}

if ($options['help']) {
    echo <<<EOT
Diagnose the Mercado Pago Subscriptions enrolment method.

Options:
  -h, --help          Print this help.
      --courseid=N    Also check whether the method can be added to course N.
      --username=U    Check the capabilities of this user instead of the admin.
      --tryadd        Actually create a test instance in that course, then remove it.
      --keep          With --tryadd, leave the created instance in place.
      --cost=N        Cost to use for the save test (default 1000).
      --name=TEXT     Instance name to use for the save test.
      --checkaccount  Call the Mercado Pago API to confirm the credentials work.
                      This is the only option that talks to the network.

EOT;
    exit(0);
}

// Number of blocking problems found.
$failures = 0;

// Number of warnings found.
$warnings = 0;

/**
 * Prints one check result and counts it.
 *
 * @param bool|null $ok True to pass, false to fail, null for a warning.
 * @param string $label What was checked.
 * @param string $detail What was found.
 * @param string $fix What to do about it, printed only when not passing.
 * @return void
 */
function enrol_mercadopagosub_report(?bool $ok, string $label, string $detail, string $fix = ''): void {
    global $failures, $warnings;

    if ($ok === true) {
        $mark = '  OK   ';
    } else if ($ok === null) {
        $mark = '  WARN ';
        $warnings++;
    } else {
        $mark = '  FAIL ';
        $failures++;
    }

    echo $mark . str_pad($label, 46) . $detail . PHP_EOL;
    if ($ok !== true && $fix !== '') {
        echo '         -> ' . $fix . PHP_EOL;
    }
}

echo PHP_EOL . '=== enrol_mercadopagosub diagnostics ===' . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------
echo '1. Installation' . PHP_EOL;

$installedversion = get_config('enrol_mercadopagosub', 'version');
enrol_mercadopagosub_report(
    (bool)$installedversion,
    'Plugin installed',
    $installedversion ? 'version ' . $installedversion : 'NOT INSTALLED',
    'Visit Site administration > Notifications to complete the installation.'
);

$plugin = new stdClass();
require($CFG->dirroot . '/enrol/mercadopagosub/version.php');
if ($installedversion && (int)$plugin->version > (int)$installedversion) {
    enrol_mercadopagosub_report(
        false,
        'Plugin up to date',
        'code is ' . $plugin->version . ', database has ' . $installedversion,
        'Visit Site administration > Notifications, or run admin/cli/upgrade.php.'
    );
} else {
    enrol_mercadopagosub_report(true, 'Plugin up to date', 'code and database agree');
}

$dbman = $DB->get_manager();
foreach (['enrol_mercadopagosub_sub', 'enrol_mercadopagosub_payment', 'enrol_mercadopagosub_event'] as $table) {
    $exists = $dbman->table_exists(new xmldb_table($table));
    enrol_mercadopagosub_report(
        $exists,
        'Table ' . $table,
        $exists ? 'present' : 'MISSING',
        'The installation did not complete. Re-run it from Notifications.'
    );
}

$enabled = enrol_get_plugins(true);
$isenabled = array_key_exists('mercadopagosub', $enabled);
enrol_mercadopagosub_report(
    $isenabled,
    'Enrolment method enabled site-wide',
    $isenabled ? 'enabled' : 'DISABLED',
    'Site administration > Plugins > Enrolments > Manage enrol plugins. '
        . 'While disabled, no subscription is processed and no scheduled task does anything.'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '2. Capabilities' . PHP_EOL;

$declared = [];
require($CFG->dirroot . '/enrol/mercadopagosub/db/access.php');
$declared = array_keys($capabilities);

$known = $DB->get_fieldset_select(
    'capabilities',
    'name',
    'component = :component',
    ['component' => 'enrol_mercadopagosub']
);

$missing = array_diff($declared, $known);
enrol_mercadopagosub_report(
    empty($missing),
    'Capabilities registered',
    count($known) . ' of ' . count($declared) . ' found'
        . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''),
    'A capability declared in db/access.php but absent from the database means the '
        . 'install or upgrade did not finish. Re-run it from Notifications.'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '3. Site requirements' . PHP_EOL;

$https = str_starts_with((string)$CFG->wwwroot, 'https://');
enrol_mercadopagosub_report(
    $https,
    'Site served over HTTPS',
    $https ? $CFG->wwwroot : $CFG->wwwroot . ' is NOT https',
    'Mercado Pago refuses the back_url and notification_url of a plain-http site, and '
        . 'this plugin refuses to enable an instance on one. Nothing below can work until '
        . 'this is fixed.'
);

foreach (['curl', 'json'] as $extension) {
    $loaded = extension_loaded($extension);
    enrol_mercadopagosub_report(
        $loaded,
        'PHP extension ' . $extension,
        $loaded ? 'loaded' : 'MISSING',
        'Install it and restart PHP-FPM.'
    );
}

$webhookurl = $CFG->wwwroot . '/enrol/mercadopagosub/webhook.php';
enrol_mercadopagosub_report(
    file_exists($CFG->dirroot . '/enrol/mercadopagosub/webhook.php') ? true : false,
    'Notification endpoint present',
    $webhookurl
);
echo '         Register exactly this URL in Mercado Pago > Your integrations > your ' . PHP_EOL;
echo '         application > Webhooks. It is configured per application, so a site' . PHP_EOL;
echo '         running more than one Mercado Pago plugin needs one application each.' . PHP_EOL;

// ---------------------------------------------------------------------------
echo PHP_EOL . '4. Credentials' . PHP_EOL;

$credentials = \enrol_mercadopagosub\credentials::resolve();

enrol_mercadopagosub_report(
    $credentials->is_complete(),
    'Access token configured',
    $credentials->is_complete()
        ? $credentials->get_redacted_token() . ' (from ' . $credentials->get_source() . ')'
        : 'NOT SET',
    'Set it in the plugin settings, in $CFG->enrol_mercadopagosub in config.php, or in '
        . 'MERCADOPAGOSUB_ACCESS_TOKEN. config.php outranks the environment, which outranks '
        . 'the settings. Note that PHP-FPM does not pass environment variables to its '
        . 'workers, so config.php is the reliable place on a web server.'
);

enrol_mercadopagosub_report(
    $credentials->can_verify_signatures(),
    'Webhook secret configured',
    $credentials->can_verify_signatures() ? 'set (from ' . $credentials->get_source() . ')' : 'NOT SET',
    'Without it every incoming notification is recorded with signaturestatus=absent and '
        . 'is not acted on, so subscriptions never become active from a webhook. The '
        . 'reconciliation task still catches up on its own schedule, but slowly.'
);

if ($credentials->is_test_credential()) {
    enrol_mercadopagosub_report(
        null,
        'Credential type',
        'TEST credentials',
        'A test credential cannot take money from a real buyer. Fine on a development '
            . 'site; on a production one it means every subscription created here is unpayable.'
    );
} else if ($credentials->is_complete()) {
    enrol_mercadopagosub_report(true, 'Credential type', 'production credentials');
}

$currency = \enrol_mercadopagosub\collector::resolve_currency();
enrol_mercadopagosub_report(
    $currency !== '' ? true : null,
    'Currency resolved',
    $currency !== '' ? $currency : 'not determined',
    'Currency follows the collecting account, which is read from the API and cached. '
        . 'Run with --checkaccount to read it now, or set it explicitly in the plugin settings.'
);

if ($options['checkaccount']) {
    if (!$credentials->is_complete()) {
        echo '  SKIP ' . str_pad('Account reachable', 46) . 'no credentials to try' . PHP_EOL;
    } else {
        try {
            $account = (new \enrol_mercadopagosub\api_client($credentials))->get_account();
            enrol_mercadopagosub_report(
                true,
                'Account reachable',
                'id ' . ($account['id'] ?? '?') . ', site ' . ($account['site_id'] ?? '?')
                    . ', country ' . ($account['country_id'] ?? '?')
            );
            \enrol_mercadopagosub\collector::forget();
            $collector = \enrol_mercadopagosub\collector::load(true);
            if ($collector !== null) {
                echo '         nickname ' . $collector->get_nickname()
                    . ', currency ' . ($collector->get_currency() ?: 'unknown for this site')
                    . ', test account: ' . ($collector->is_test_account() ? 'yes' : 'no') . PHP_EOL;
            }
        } catch (\enrol_mercadopagosub\api_exception $e) {
            // A status of 0 means the exchange never completed at all, which is
            // a different problem from the platform refusing the credentials:
            // the first is this site's outgoing network, the second is the
            // token. Measured while writing this script, on a host with no
            // route to api.mercadopago.com — reporting both as "HTTP 0" would
            // have sent an administrator looking in the wrong place.
            if ($e->get_http_status() === 0) {
                enrol_mercadopagosub_report(
                    false,
                    'Account reachable',
                    'could not reach api.mercadopago.com (' . ($e->debuginfo ?: 'no detail') . ')',
                    'The request never completed, so the credentials were not even judged. '
                        . 'Check outgoing HTTPS from this server: a firewall, a proxy this '
                        . 'site does not know about, or DNS.'
                );
            } else {
                enrol_mercadopagosub_report(
                    false,
                    'Account reachable',
                    'HTTP ' . $e->get_http_status() . ' '
                        . ($e->get_api_message() ?: $e->get_api_code() ?: 'no message'),
                    'The platform answered and refused these credentials. Check the access '
                        . 'token, and that it belongs to the application whose notification '
                        . 'URL points at this site.'
                );
            }
        }
    }
} else {
    echo '  SKIP ' . str_pad('Account reachable', 46) . 'pass --checkaccount to call the API' . PHP_EOL;
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '5. Scheduled tasks' . PHP_EOL;

$expectedtasks = [
    '\enrol_mercadopagosub\task\process_events',
    '\enrol_mercadopagosub\task\reconcile_payments',
    '\enrol_mercadopagosub\task\process_expirations',
    '\enrol_mercadopagosub\task\send_expiry_notifications',
];

foreach ($expectedtasks as $classname) {
    $task = \core\task\manager::get_scheduled_task($classname);
    if ($task === false || $task === null) {
        enrol_mercadopagosub_report(
            false,
            trim(substr($classname, strrpos($classname, '\\') + 1)),
            'NOT REGISTERED',
            'Re-run the upgrade so that db/tasks.php is read again.'
        );
        continue;
    }

    $lastrun = (int)$task->get_last_run_time();
    $disabled = $task->get_disabled();
    $detail = $disabled ? 'DISABLED' : 'enabled';
    $detail .= ', last run ' . ($lastrun ? userdate($lastrun) : 'never');

    enrol_mercadopagosub_report(
        $disabled ? false : ($lastrun ? true : null),
        trim(substr($classname, strrpos($classname, '\\') + 1)),
        $detail,
        $disabled
            ? 'Re-enable it in Site administration > Server > Scheduled tasks.'
            : 'A task that has never run usually means cron is not running at all. '
                . 'Check admin/cli/cron.php is scheduled on this server.'
    );
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '6. Current state' . PHP_EOL;

$states = $DB->get_records_sql(
    'SELECT state, COUNT(*) AS total FROM {enrol_mercadopagosub_sub} GROUP BY state ORDER BY state'
);
if (empty($states)) {
    echo '         no subscriptions on this site yet' . PHP_EOL;
} else {
    foreach ($states as $row) {
        echo '         ' . str_pad($row->state, 12) . $row->total . PHP_EOL;
    }
}

$queued = $DB->count_records('enrol_mercadopagosub_event', ['processstatus' => 'queued']);
enrol_mercadopagosub_report(
    $queued < 50,
    'Webhook events waiting',
    $queued . ' queued',
    'A queue that keeps growing means the processing task is not running, or is failing. '
        . 'Run it by hand: php admin/cli/scheduled_task.php '
        . '--execute=\'\\enrol_mercadopagosub\\task\\process_events\''
);

$failed = $DB->count_records('enrol_mercadopagosub_event', ['processstatus' => 'failed']);
enrol_mercadopagosub_report(
    $failed === 0 ? true : null,
    'Webhook events failed',
    $failed . ' failed',
    'Read the lasterror column: it holds the platform\'s own message for each failure.'
);

$unsigned = $DB->count_records_select(
    'enrol_mercadopagosub_event',
    'signaturestatus <> :verified',
    ['verified' => 'verified']
);
enrol_mercadopagosub_report(
    $unsigned === 0 ? true : null,
    'Notifications with an unverified signature',
    $unsigned . ' of ' . $DB->count_records('enrol_mercadopagosub_event'),
    'Unverified notifications are recorded but not acted on. If every one is unverified, '
        . 'the webhook secret configured here is not the one the application sends with.'
);

// ---------------------------------------------------------------------------
$checkuser = null;
if ($options['username'] !== '') {
    $checkuser = $DB->get_record('user', ['username' => $options['username'], 'deleted' => 0]);
    if (!$checkuser) {
        cli_error('No user with username "' . $options['username'] . '".');
    }
}

if (!$options['courseid']) {
    echo PHP_EOL . '7. Course check' . PHP_EOL;
    echo '  SKIP ' . str_pad('not run', 46) . 'pass --courseid=N' . PHP_EOL;
} else {
    $course = $DB->get_record('course', ['id' => (int)$options['courseid']]);
    if (!$course) {
        cli_error('No course with id ' . $options['courseid'] . '.');
    }
    $coursecontext = context_course::instance($course->id);

    // A CLI script starts with no logged-in user, and both can_add_instance()
    // and can_subscribe() read $USER internally. Without this every capability
    // check below would fail regardless of who can really do these things.
    $asuser = $checkuser ?: get_admin();
    \core\session\manager::set_user($asuser);

    echo PHP_EOL . '7. Course ' . $course->id . ' (' . $course->shortname . '), evaluated as '
        . $asuser->username . PHP_EOL;

    if (!$checkuser) {
        echo '         (no --username given, so this is the site admin; pass --username=U to '
            . 'test the person who actually cannot see the method)' . PHP_EOL;
    }

    // Two different people ask two different questions of this plugin, and a
    // learner is *supposed* to fail the first set. Reporting those as failures
    // would make this script exit non-zero for a perfectly healthy site, so a
    // named user's missing configuration capabilities are a warning, while the
    // admin's missing them is a real fault.
    $configseverity = $checkuser ? null : false;

    echo PHP_EOL . '   Managing the method' . PHP_EOL;

    $enrolconfig = has_capability('moodle/course:enrolconfig', $coursecontext);
    enrol_mercadopagosub_report(
        $enrolconfig ?: $configseverity,
        'moodle/course:enrolconfig',
        $enrolconfig ? 'allowed' : 'denied',
        $checkuser
            ? 'Expected for a learner. It only matters for whoever configures the course.'
            : 'Without it no enrolment method can be added to this course at all.'
    );

    $canconfig = has_capability('enrol/mercadopagosub:config', $coursecontext);
    enrol_mercadopagosub_report(
        $canconfig ?: $configseverity,
        'enrol/mercadopagosub:config',
        $canconfig ? 'allowed' : 'denied',
        $checkuser
            ? 'Expected for a learner. This is what decides whether the method appears in '
                . 'the "Add method" dropdown for a teacher or manager.'
            : 'This is the capability that decides whether this particular method appears '
                . 'in the "Add method" dropdown.'
    );

    $enrolplugin = enrol_get_plugin('mercadopagosub');
    $canadd = $enrolplugin->can_add_instance($course->id);
    enrol_mercadopagosub_report(
        $canadd ?: $configseverity,
        'can_add_instance()',
        $canadd ? 'yes' : 'no',
        $checkuser
            ? 'Expected for a learner.'
            : 'This is what the course enrolment methods page itself asks.'
    );

    echo PHP_EOL . '   Subscribing to it' . PHP_EOL;

    $cansubscribecap = has_capability('enrol/mercadopagosub:subscribe', $coursecontext);
    enrol_mercadopagosub_report(
        $cansubscribecap,
        'enrol/mercadopagosub:subscribe',
        $cansubscribecap ? 'allowed' : 'DENIED',
        'Without it the subscribe button is never shown, whatever else is configured. '
            . 'It is granted to the authenticated user role by default, so a denial here '
            . 'means an override somewhere in this course or category.'
    );

    $instances = $DB->get_records('enrol', ['courseid' => $course->id, 'enrol' => 'mercadopagosub']);
    echo '         instances in this course: ' . count($instances) . PHP_EOL;
    foreach ($instances as $existing) {
        echo '           id ' . $existing->id
            . ', status ' . ((int)$existing->status === ENROL_INSTANCE_ENABLED ? 'enabled' : 'disabled')
            . ', cost ' . $existing->cost . ' ' . $existing->currency
            . ', subscribers ' . $DB->count_records_select(
                'enrol_mercadopagosub_sub',
                'enrolid = :enrolid AND state <> :ended',
                ['enrolid' => $existing->id, 'ended' => 'ended']
            ) . PHP_EOL;
    }

    if (!$canadd) {
        echo PHP_EOL . '   Saving the form' . PHP_EOL;
        echo '  SKIP ' . str_pad('edit_instance_validation()', 46)
            . 'this user cannot add the method anyway' . PHP_EOL;
        echo '         -> re-run without --username to test the save as the site admin' . PHP_EOL;
    } else {
        echo PHP_EOL . '   Saving the form' . PHP_EOL;

        // What the browser would post if an administrator opened the form and
        // saved it with the defaults. Numeric widgets post 0 rather than '', and
        // feeding '' here would trip validate_param_types() under PHP 8 and report
        // an error that the real form never produces.
        $data = (array)$enrolplugin->get_instance_defaults();
        $data['name'] = $options['name'] !== '' ? $options['name'] : 'Diagnostic test method';
        $data['cost'] = (string)($options['cost'] !== '' ? $options['cost'] : '1000');
        $data['status'] = ENROL_INSTANCE_ENABLED;
        $data['currency'] = (string)($data['currency'] ?? '') !== ''
            ? $data['currency']
            : (\enrol_mercadopagosub\collector::resolve_currency() ?: 'ARS');

        $studentroles = get_archetype_roles('student');
        $studentrole = $studentroles ? reset($studentroles) : null;
        $data['roleid'] = (int)($data['roleid'] ?? 0) ?: (int)($studentrole->id ?? 0);

        foreach (['customint1', 'customint2', 'customint3', 'customint5', 'customint7'] as $numeric) {
            $data[$numeric] = (int)($data[$numeric] ?? 0);
        }
        $data['customint6'] = (int)($data['customint6'] ?? 1) ?: 1;
        $data['customchar1'] = (string)($data['customchar1'] ?? '') ?: 'months';
        $data['customchar2'] = (string)($data['customchar2'] ?? '') ?: 'days';

        echo '         posting: name="' . $data['name'] . '", cost=' . $data['cost'] . ' '
            . $data['currency'] . ', roleid=' . $data['roleid'] . PHP_EOL;

        $stub = (object)$enrolplugin->get_instance_defaults();
        $stub->id = null;
        $stub->courseid = $course->id;
        $stub->status = ENROL_INSTANCE_ENABLED;

        $errors = $enrolplugin->edit_instance_validation($data, [], $stub, $coursecontext);

        enrol_mercadopagosub_report(
            empty($errors),
            'edit_instance_validation()',
            empty($errors) ? 'no errors' : count($errors) . ' error(s) - THIS is what blocks the save',
            'The browser redisplays the form with these messages attached to their fields. '
                . 'A field inside a collapsed section is easy to miss: expand every section '
                . 'and look for red text.'
        );
        foreach ($errors as $field => $message) {
            echo '           ' . str_pad($field, 22) . $message . PHP_EOL;
        }

        if (!$options['tryadd']) {
            echo '         (add --tryadd to actually create an instance and remove it again)' . PHP_EOL;
        } else if (!empty($errors)) {
            echo '         not attempting add_instance(): validation already failed' . PHP_EOL;
        } else {
            $before = $DB->count_records('enrol', ['courseid' => $course->id, 'enrol' => 'mercadopagosub']);
            try {
                $newid = $enrolplugin->add_instance($course, $data);
                $after = $DB->count_records('enrol', ['courseid' => $course->id, 'enrol' => 'mercadopagosub']);

                enrol_mercadopagosub_report(
                    $after > $before,
                    'add_instance()',
                    $after > $before ? 'created instance ' . $newid : 'reported success but added nothing'
                );

                if ($options['keep']) {
                    echo '         --keep given, leaving instance ' . $newid . ' in place' . PHP_EOL;
                } else {
                    $enrolplugin->delete_instance($DB->get_record('enrol', ['id' => $newid], '*', MUST_EXIST));
                    echo '         test instance removed again' . PHP_EOL;
                }
            } catch (Throwable $e) {
                enrol_mercadopagosub_report(
                    false,
                    'add_instance()',
                    get_class($e) . ': ' . $e->getMessage(),
                    'Validation passed but the save itself failed, which usually means a '
                        . 'database-level problem rather than a configuration one.'
                );
            }
        }
    }

    // The can_subscribe() method is the single authority on whether a learner may
    // start a subscription, so it answers the question this script exists for. An
    // instance created by --tryadd counts: without this, a course with no
    // instance yet would skip the one check most worth seeing.
    $instances = $DB->get_records('enrol', ['courseid' => $course->id, 'enrol' => 'mercadopagosub']);
    if ($instances) {
        $instance = reset($instances);
        $verdict = $enrolplugin->can_subscribe($instance);
        enrol_mercadopagosub_report(
            $verdict === true,
            'can_subscribe() on instance ' . $instance->id,
            $verdict === true ? 'yes' : ($verdict === false ? 'no, and nothing is shown' : (string)$verdict),
            'This is exactly what the course enrolment page asks before showing the '
                . 'subscribe button. A string here is the message the learner sees; false '
                . 'means the method is hidden entirely.'
        );
    } else {
        echo '  SKIP ' . str_pad('can_subscribe()', 46)
            . 'no instance in this course to evaluate' . PHP_EOL;
        echo '         -> add one, or re-run with --tryadd --keep' . PHP_EOL;
    }
}

// ---------------------------------------------------------------------------
echo PHP_EOL;
if ($failures === 0 && $warnings === 0) {
    echo 'No problems found.' . PHP_EOL . PHP_EOL;
    exit(0);
}

echo $failures . ' problem(s), ' . $warnings . ' warning(s).' . PHP_EOL . PHP_EOL;
exit($failures === 0 ? 0 : 1);

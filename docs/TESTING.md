# Testing enrol_mercadopagosub

Everything here belongs on a **throwaway clone**, never on the production
site. The PHPUnit and Behat settings below create and drop database tables and
wipe data directories on demand; on a live site that is a disaster waiting for
a mistyped command. Build the clone from a snapshot, run what you need, and
destroy it.

This file exists because that is exactly what happened once already: the clone
that carried these settings was destroyed and the configuration went with it.
It is versioned here so the next clone is a copy-paste away.

Everything marked **verified** below was checked against Moodle 5.2.2+ source
(`a987843`) rather than recalled. Paths, database names and the domain are
yours to adjust; the setting names and the behaviour are not.

---

## 1. What the clone needs

- The same Moodle release as production, with this plugin in
  `public/enrol/mercadopagosub`.
- PHP 8.3 or 8.4 with `pgsql`/`mysqli`, `curl`, `json`, `intl`, `mbstring`,
  `zip`, `gd`, `soap`, `xml`, and `max_input_vars` of at least 5000. Moodle
  refuses to initialise the PHPUnit environment below that number.
- The `en_AU.UTF-8` locale generated (`sudo locale-gen en_AU.UTF-8`). The
  PHPUnit initialiser stops with *"Required locale 'en_AU.UTF-8' is not
  installed"* without it.
- Composer, to install PHPUnit and Behat (`composer install` in the Moodle
  root).
- **For Behat only**: Node.js as `.nvmrc` requires, a browser, and a
  WebDriver for it (Chrome + chromedriver, or Selenium) — section 1b installs
  all three.
- **For the two HTTPS-tagged Behat scenarios**: the clone served over HTTPS
  with a certificate that validates. Moodle curls `$CFG->behat_wwwroot` from
  the CLI before running, so a self-signed certificate fails there before any
  browser is involved.

Two version pins, both learned the hard way:

- **PHPUnit 11.x, not 12.** PHPUnit 12 makes `TestCase::__construct()` final
  and Moodle 5.2's `basic_testcase` overrides it, so the suite dies before the
  first test.
- **phpcs 3.13.x with `moodlehq/moodle-cs`, not phpcs 4.** phpcs 4 does not
  define the `T_PROPERTY` constant moodle-cs references and exits with a fatal
  error rather than a finding.

---

## 1b. Installing Node.js, Chrome and chromedriver

Written for Debian 13 on EC2, as a non-root user with sudo. Run as an ordinary
user, not as root: Chrome refuses to start as root unless you add
`--no-sandbox`, and turning the sandbox off on a machine that browses anything
is a bad habit to acquire for the sake of a test run.

### Node.js

Moodle pins the version it wants in `.nvmrc` at the root of the source tree —
`lts/jod` for 5.2, which is the Node 22 line (`package.json` says
`>=22.11.0 <23`). Use nvm so that file decides, rather than pinning a version
by hand that drifts at the next Moodle upgrade:

```bash
curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
source ~/.bashrc

cd /path/to/moodle        # the directory holding .nvmrc
nvm install               # reads .nvmrc
nvm use
node --version            # expect v22.x
```

Node is needed for Grunt and for Moodle's own JS build, not by Behat itself.
Behat runs without it; `moodle-plugin-ci grunt` does not.

### Google Chrome

```bash
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://dl.google.com/linux/linux_signing_key.pub \
    | sudo gpg --dearmor -o /etc/apt/keyrings/google-chrome.gpg
echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/google-chrome.gpg] \
https://dl.google.com/linux/chrome/deb/ stable main" \
    | sudo tee /etc/apt/sources.list.d/google-chrome.list

sudo apt update
sudo apt install -y google-chrome-stable
google-chrome --version   # note this number, the driver has to match
```

Debian's own `chromium` package works too, with `chromium-driver` alongside
it, and apt then keeps the two in step for you. The reason to prefer Google's
build is that the matching driver is published for every release, which
matters when Chrome auto-updates and the pair falls out of step.

### chromedriver, matching that Chrome

**The major version of chromedriver must equal the major version of Chrome.**
A mismatch fails at the first scenario with *"This version of ChromeDriver
only supports Chrome version NN"*, which reads like a Behat problem and is
not. Chrome for Testing publishes a driver for every Chrome release:

```bash
CHROME_VERSION=$(google-chrome --version | awk '{print $3}')
curl -fsSL -o /tmp/chromedriver.zip \
    "https://storage.googleapis.com/chrome-for-testing-public/${CHROME_VERSION}/linux64/chromedriver-linux64.zip"

sudo apt install -y unzip
unzip -q -o /tmp/chromedriver.zip -d /tmp
sudo install -m 0755 /tmp/chromedriver-linux64/chromedriver /usr/local/bin/chromedriver
rm -rf /tmp/chromedriver.zip /tmp/chromedriver-linux64

chromedriver --version    # major must match google-chrome --version
```

If that URL 404s, the exact build is not published; take the nearest one for
the same major version from
`https://googlechromelabs.github.io/chrome-for-testing/known-good-versions-with-downloads.json`.
Same major is what matters, not the same patch.

**After every Chrome upgrade, re-run this.** `apt upgrade` moves Chrome and
leaves chromedriver where it was, and the next Behat run fails on a version
message that has nothing to do with the code under test.

### Running the driver

By hand, in its own shell, for a one-off run:

```bash
chromedriver --port=9515
```

Or as a service, which is worth it on a clone you will come back to:

```bash
sudo tee /etc/systemd/system/chromedriver.service >/dev/null <<'UNIT'
[Unit]
Description=chromedriver for Moodle Behat
After=network.target

[Service]
Type=simple
User=admin
ExecStart=/usr/local/bin/chromedriver --port=9515
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable --now chromedriver
```

Change `User=` to whoever owns the Moodle tree. Keep it bound to localhost —
chromedriver's default is local connections only, and it deliberately has no
authentication.

### Checking all three before running Behat

```bash
node --version                                  # v22.x
google-chrome --version                         # e.g. 141.0.7390.x
chromedriver --version                          # same major as Chrome
curl -s http://127.0.0.1:9515/status | head -c 80
```

That last one must answer with JSON saying *"ChromeDriver ready for new
sessions"*. **Verified**: modern chromedriver serves this at `/`, and
`/wd/hub/status` returns 404 — the `/wd/hub` suffix belongs to Selenium, and
putting it in `wd_host` for chromedriver is a slow way to discover that.

---

## 2. config.php additions

Append to the clone's `config.php`, **above** the `require_once(__DIR__ .
'/public/lib/setup.php');` line.

```php
// ---------------------------------------------------------------------------
// TEST CLONE ONLY. Remove all of this before any of it reaches production.
// ---------------------------------------------------------------------------

// PHPUnit. Uses the same database server as the site, with its own prefix and
// its own data directory, so it never touches the site's own tables.
$CFG->phpunit_prefix   = 'phpu_';
$CFG->phpunit_dataroot = '/var/moodledata_phpu';

// Dual-database PHPUnit without a second Moodle. Run the suite twice:
//   vendor/bin/phpunit ...                  -> MariaDB (the site's own engine)
//   PHPUNIT_DB=pgsql vendor/bin/phpunit ... -> PostgreSQL
//
// The data directory switches with the engine, not just the database. Each
// initialised environment keeps its state in its own dataroot, so with two of
// them both environments exist at once and switching engines is only this
// variable. Sharing one directory works too, but then every switch needs
// another init.php run, because the second one overwrites the first's state.
if (getenv('PHPUNIT_DB') === 'pgsql') {
    $CFG->dbtype    = 'pgsql';
    $CFG->dblibrary = 'native';
    $CFG->dbhost    = 'localhost';
    $CFG->dbname    = 'moodle_test_pgsql';
    $CFG->dbuser    = 'moodle';
    $CFG->dbpass    = 'CHANGE_ME';
    $CFG->dboptions = ['dbpersist' => 0, 'dbsocket' => 0, 'dbport' => 5432];

    $CFG->phpunit_dataroot = '/var/moodledata_phpu_pgsql';
}

// Behat. behat_wwwroot must be reachable from this machine and must be https
// for the @enrol_mercadopagosub_https scenarios; the certificate has to
// validate, because Moodle fetches this URL from the CLI before running.
$CFG->behat_wwwroot   = 'https://clone.example.com';
$CFG->behat_prefix    = 'bht_';
$CFG->behat_dataroot  = '/var/moodledata_behat';

// One profile per browser. wd_host is where the driver listens:
//   chromedriver  -> http://127.0.0.1:9515       (no /wd/hub — verified: it 404s)
//   Selenium      -> http://127.0.0.1:4444/wd/hub
// --headless=new is what makes this work on a server with no display. Drop it
// if you ever run this on a desktop and want to watch the browser.
$CFG->behat_profiles = [
    'chrome' => [
        'browser'      => 'chrome',
        'wd_host'      => 'http://127.0.0.1:9515',
        'capabilities' => [
            'extra_capabilities' => [
                'chromeOptions' => [
                    'args' => [
                        '--headless=new',
                        '--disable-gpu',
                        '--disable-dev-shm-usage',
                        '--window-size=1920,1080',
                    ],
                ],
            ],
        ],
    ],
];

// Where a failing scenario leaves its screenshot and HTML dump. Worth setting:
// without it a failure is a stack trace with no picture of the page.
$CFG->behat_faildump_path = '/var/behat_faildumps';
```

### The directories: who creates them, and what must be in them

Short answer: **create the parents, leave the directories themselves empty or
absent, and make sure the user that runs the tests owns them.** All of the
following was checked against the 5.2 source and by running it, not recalled.

```bash
# Only the parents have to pre-exist. /var always does, so with the paths used
# here there is nothing to create at all — but if you nest them deeper, create
# the intermediate levels yourself.
sudo mkdir -p /var/moodledata_phpu /var/moodledata_phpu_pgsql \
              /var/moodledata_behat /var/behat_faildumps

# Whoever runs the tests must own them. On this stack that is www-data.
sudo chown -R www-data:www-data /var/moodledata_phpu /var/moodledata_phpu_pgsql \
                               /var/moodledata_behat /var/behat_faildumps
```

**PHPUnit's dataroot must be empty the first time.** If it does not yet
contain Moodle's own `phpunittestdir.txt` marker, the bootstrap walks the
directory and refuses on the first unexpected entry:

> `$CFG->phpunit_dataroot directory is not empty, can not run tests! Is it
> used for anything else?`

Verified by pointing it at a directory holding a single stray file. Only
`phpunit/`, `.`, `..` and `.DS_Store` are tolerated. So: a fresh empty
directory, or none at all — never a directory you are using for something
else, and never the site's own `dataroot` (there is a separate check for
exactly that).

**PHPUnit creates it, but not its parents.** The `mkdir()` in the bootstrap is
not recursive. Verified: pointing it at `/tmp/pu_created/deep/nested` with no
`/tmp/pu_created` present fails with *"directory can not be created"*. With
`/var/moodledata_phpu` there is nothing to do, since `/var` exists.

**Behat's is different in two ways.** It creates the path recursively, so
missing parents are not a problem; and the value you set is treated as a
*parent*: Moodle appends `behatrun` to it and works inside that. That is why
the `--config` path in section 4 is `<behat_dataroot>/behatrun/behat/behat.yml`
rather than sitting directly in the directory you named. Behat also refuses a
`behat_dataroot` equal to either `dataroot` or `phpunit_dataroot`, which is
another reason the pgsql branch above gets its own.

**Nothing here needs to be preserved.** Both directories are scratch: the init
scripts populate them and the test runs rewrite them. Deleting either one
between runs costs you an `init.php`, nothing more.

### What you do *not* need to add, and why

**Mercado Pago credentials.** Do not put them in `config.php` for either
suite, and do not export `MERCADOPAGOSUB_ACCESS_TOKEN`,
`MERCADOPAGOSUB_PUBLIC_KEY` or `MERCADOPAGOSUB_WEBHOOK_SECRET` in the shell
that runs the tests.

- **Verified**: both harnesses throw away `$CFG->enrol_mercadopagosub`. The
  PHPUnit bootstrap rebuilds `$CFG` from a white list of basic settings plus
  anything prefixed `phpunit_` or `behat_` (`public/lib/phpunit/bootstrap.php`),
  and Behat does the same through `behat_clean_init_config()`
  (`public/lib/behat/lib.php`). So credentials left in `config.php` cannot
  reach a test — they are simply invisible there.
- **Verified**: environment variables are *not* filtered by either, because
  `credentials::from_environment()` reads them with `getenv()`, which no
  bootstrap can clean. The PHPUnit suite defends itself — `helper_trait` and
  `credentials_test` clear all three in `setUp()` — but **Behat has no such
  shield**. A clone with those variables exported will have its Behat
  scenarios talking to whatever account they name.

Both suites set the credentials they need themselves: PHPUnit through
`set_config()`, Behat through *"the following config values are set as
admin"*. Nothing real is ever required, and no test in either suite reaches
the Mercado Pago API.

---

## 3. PHPUnit

```bash
# Once, and again after any change to a db/ file or after switching PHPUNIT_DB.
# Note the public/ prefix: Moodle 5.x keeps tool CLI scripts under public/,
# while the core ones (upgrade.php, cron.php) live in admin/cli at the root.
php public/admin/tool/phpunit/cli/init.php

# The whole plugin suite.
vendor/bin/phpunit --testsuite enrol_mercadopagosub_testsuite

# One file, or one test.
vendor/bin/phpunit public/enrol/mercadopagosub/tests/privacy_provider_test.php
vendor/bin/phpunit --filter test_a_missed_charge_becomes_overdue

# The other engine. Init it once; after that, switching is only the variable,
# because each engine has its own dataroot as well as its own database.
PHPUNIT_DB=pgsql php public/admin/tool/phpunit/cli/init.php
PHPUNIT_DB=pgsql vendor/bin/phpunit --testsuite enrol_mercadopagosub_testsuite
```

Expected: **136 tests, 370 assertions, green**, on both engines. The suite
needs no network and no credentials.

---

## 4. Behat

```bash
# Once, and again whenever a .feature file is added or its tags change.
php public/admin/tool/behat/cli/init.php

# Start the driver in another shell.
chromedriver --port=9515

# Everything except the scenarios that need real HTTPS.
vendor/bin/behat --config /var/moodledata_behat/behatrun/behat/behat.yml \
    --profile chrome \
    --tags='@enrol_mercadopagosub&&~@enrol_mercadopagosub_https'

# Only the HTTPS ones, on a clone that really is served over HTTPS.
vendor/bin/behat --config /var/moodledata_behat/behatrun/behat/behat.yml \
    --profile chrome \
    --tags='@enrol_mercadopagosub&&@enrol_mercadopagosub_https'
```

`init.php` prints the exact `--config` path for this clone; use that rather
than the one above if they differ.

### The tag gotcha, measured on the sibling plugin 2026-09-04

`moodle-plugin-ci behat --tags=X` **replaces** the `@enrol_mercadopagosub` tag
the tool builds by default rather than adding to it. Passing a bare negation
therefore selects Moodle's entire Behat suite — 3150+ steps, still running at
35 minutes. Always put both conditions in one expression, as above.

### Nine scenarios, two of which need HTTPS

Seven run anywhere: adding the method, four validation failures, the
credentials check that fires when the method is enabled, and a manager seeing
the method with a subscriber present. They keep *"Allow new subscriptions"* at
No, which is what avoids the HTTPS guard while still exercising the form.

Two carry `@enrol_mercadopagosub_https` because they enable an instance, and
the plugin refuses to enable one on a site that is not served over HTTPS.
On a plain-http clone they fail by proving that guard works, which is not a
useful signal.

**As of 2026-09-12 this feature file has never been executed.** The first run
is the real test of it; expect it to need corrections.

---

## 5. Static analysis

Run phpcs against the plugin **as installed inside a Moodle tree**, not from
the plugin directory on its own:

```bash
vendor/bin/phpcs --standard=moodle public/enrol/mercadopagosub
vendor/bin/phpcs --standard=moodle-extra public/enrol/mercadopagosub
```

**Verified, and it cost a red CI run to learn**: some moodle-cs sniffs resolve
the Moodle component from the file's path and stay silent otherwise. Run from
inside the plugin directory, `moodle.Files.LangFilesOrdering` reported nothing
at all, while the same tree checked at `public/enrol/mercadopagosub` reported
36 warnings — enough to fail `moodle-plugin-ci phpcs --max-warnings 0`.

Expected: 0 errors, 0 warnings under both standards.

---

## 6. The diagnostics script

Not a test, but the fastest way to confirm a clone is set up correctly:

```bash
php public/enrol/mercadopagosub/cli/diagnose.php
php public/enrol/mercadopagosub/cli/diagnose.php --courseid=N --tryadd
php public/enrol/mercadopagosub/cli/diagnose.php --courseid=N --username=someone
```

It exits 1 only for real failures. `--checkaccount` is the only option that
touches the network, and the only one that needs credentials.

---

## 7. Before destroying the clone

If anything here changed — a path, a driver port, a step that turned out to be
wrong — update this file in the repository first. That is the whole reason it
exists.

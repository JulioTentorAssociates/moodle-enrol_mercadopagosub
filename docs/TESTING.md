# Testing enrol_mercadopagosub

Everything here belongs on a **throwaway clone**, never on the production
site. The PHPUnit and Behat settings below create and drop database tables and
wipe data directories on demand; on a live site that is a disaster waiting for
a mistyped command. Build the clone from a snapshot, run what you need, and
destroy it.

This file exists because that is exactly what happened once already: the clone
that carried these settings was destroyed and the configuration went with it.
It is versioned here so the next clone is a copy-paste away.

**Build the clone from Moodle 5.2.3 or later.** From plugin v1.0.1 that is the
supported release and the only one this plugin is tested against; 5.2.2 is not
supported, on Moodle's own advice to skip it.

Everything marked **verified** below was checked against Moodle 5.2.2+ source
(`a987843`) rather than recalled, in September 2026. 5.2.3 is a point release on
the same branch and none of these mechanisms are expected to have moved, but
that is an expectation rather than a measurement: re-check a *verified* claim
before relying on it against 5.2.3 if it matters. Paths, database names and the
domain are yours to adjust; the setting names and the behaviour are not.

---

## 1. What the clone needs

- The same Moodle release as production — **5.2.3 or later** — with this plugin
  in `public/enrol/mercadopagosub`.
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

### PostgreSQL, if you want the second engine

The site's own database server is whatever production uses — MariaDB here. The
`PHPUNIT_DB=pgsql` branch in section 2 needs a *second* server, with a role and
a database that do not exist until you make them. Nothing in Moodle creates
them, and skipping this step produces an error that looks like something else:

```text
FATAL: password authentication failed for user "moodle"
```

That is not a missing database. PostgreSQL authenticates before it looks the
database up, and under `scram-sha-256` or `md5` it deliberately gives the same
message for *a role that does not exist* as for a wrong password, so nobody can
enumerate roles. A missing database says so plainly — `FATAL: database "x" does
not exist` — and you only ever see it once authentication has passed.

```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE moodle WITH LOGIN PASSWORD 'the same string as $CFG->dbpass';
CREATE DATABASE moodle_test_pgsql OWNER moodle ENCODING 'UTF8' TEMPLATE template0;
SQL
```

**`OWNER moodle` is not decoration.** Since PostgreSQL 15 the `public` schema
belongs to `pg_database_owner` instead of being writable by everyone, so a
database created without an owner gives the role `permission denied for schema
public` the moment `init.php` creates its first table. Measured on PostgreSQL
16.13, both ways.

One database is enough: the test tables all carry `$CFG->phpunit_prefix`, and
this server holds nothing else. Production stays on MariaDB and is never
touched by any of it.

Then verify the credentials the way Moodle will use them — over TCP, because
`$CFG->dbhost = 'localhost'` with `dbsocket => 0` means a network connection,
not the unix socket, and the socket usually authenticates by `peer` instead:

```bash
PGPASSWORD='the same string' \
  psql -h localhost -U moodle -d moodle_test_pgsql -c 'SELECT current_user'
```

If that fails while the socket works, the problem is in `pg_hba.conf`: the
`host` lines for `127.0.0.1/32` and `::1/128` must exist and must name a method
the role's stored password can satisfy — a role created under
`password_encryption = scram-sha-256` cannot authenticate against an `md5`
line. Note which address the error reports; `localhost` resolves to `::1`
first on a dual-stack host, so the IPv6 line is the one that matters.

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

**`User=` must be an ordinary login user with a home directory it can write,
and must not be `root` or `www-data`.** It does *not* have to match the Moodle
tree's owner — chromedriver never reads the Moodle tree, it only answers HTTP
on 9515 and launches browsers — and on this stack matching the web user is
what breaks it, because `www-data`'s home is `/var/www`, which it does not
own. Chrome dies on either count, immediately and without rendering, and it
surfaces two layers up as a Behat message about Selenium. See "Chrome instance
exited" below for both messages and the fix.

Keep it bound to localhost — chromedriver's default is local connections only,
and it deliberately has no authentication.

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

### "The Selenium or WebDriver server is not running" — read past it

That sentence is not a diagnosis and does not mean Behat wants Selenium.
`behat_hooks::before_first_scenario_start_session()` wraps **every**
`DriverException` from the first `@javascript` scenario in that same
hardcoded paragraph. The real message is the line underneath it:

```text
Could not open connection: session not created: Chrome instance exited.
Examine ChromeDriver verbose log to determine the cause.
```

Which says the opposite of the banner: chromedriver **is** running and did
answer — it accepted the new-session command and launched Chrome, and Chrome
died. A chromedriver that was genuinely absent gives a curl connection error
instead, and a version mismatch gives *"This version of ChromeDriver only
supports Chrome version NN"*.

**Chrome runs as whoever chromedriver runs as.** That is the first thing to
check, because Chrome refuses to start as root:

```bash
# Who owns the listening process? That is who Chrome will be.
ps -o user=,pid=,cmd= -p "$(pgrep -x chromedriver | head -1)"

# Then launch Chrome by hand as that user, with the same arguments
# behat_profiles passes. This takes Behat, Moodle and the driver out of it.
sudo -u <that user> google-chrome --headless=new --disable-gpu \
     --disable-dev-shm-usage --window-size=1920,1080 \
     --dump-dom about:blank | head -3
```

A working Chrome prints `<html><head></head><body></body></html>`. Anything
else is the actual fault. Two produce this symptom, and on this stack the
second is the one you will hit.

**The user must have a writable home directory.** Run chromedriver as
`www-data` — whose home is `/var/www`, which it does not own — and Chrome dies
before it renders anything:

```text
mkdir: cannot create directory '/var/www/.local': Permission denied
[ERROR:chrome/app/chrome_main.cc:210] Failed to create headless user data
directory container.
```

Passing `--user-data-dir` does not save it: chromedriver already passes one
under `/tmp`, and headless Chrome still wants its container and its crashpad
database under `$HOME`. In the verbose log the whole failure is one line,
`chrome_crashpad_handler: --database is required`, followed by the session
error — which is why the log is worth reading beside the by-hand launch rather
than instead of it. **Measured on Google Chrome 153.0.8010.36, Debian 13.**

So chromedriver runs as an ordinary login user, whose home it can write. It
never reads the Moodle tree, so there is nothing to gain by matching the web
user, and on a Debian stack matching the web user is exactly what breaks it.
Behat itself still runs as `www-data`, because Behat does need the Moodle
dataroot; the two do not have to agree.

If some constraint forces chromedriver to run as `www-data`, give it a home it
owns rather than loosening `/var/www`:

```bash
sudo install -d -o www-data -g www-data /var/lib/chromedriver
sudo -u www-data env HOME=/var/lib/chromedriver chromedriver --port=9515
```

In the systemd unit that is `Environment=HOME=/var/lib/chromedriver`.

**The other one is root**, which Chrome refuses outright:

```text
Running as root without --no-sandbox is not supported.
```

The fix there is the same `User=` line, not `--no-sandbox` — the flag turns off
the sandbox for a browser that is about to load pages, and the only reason to
want it is a constraint you can remove by changing one word. Measured on
Chromium 1194.

Two things this is *not*, both checked here before the real cause turned up:

- **A version mismatch.** That names itself — *"This version of ChromeDriver
  only supports Chrome version NN"*. `google-chrome --version` and
  `chromedriver --version` matching to the build, as they should after
  section 1b, rules it out.
- **Debian 13's AppArmor restriction on unprivileged user namespaces**, which
  does break Chromium's sandbox on some Debian and Ubuntu builds with exactly
  this symptom and no further detail. Check before believing it:
  `sudo sysctl kernel.apparmor_restrict_unprivileged_userns`. If the file does
  not exist, this kernel does not have the restriction and it is not your
  problem.

The verbose log prints the full Chrome command line it built and Chrome's own
stderr, which is where an unsupported flag or a missing library shows up by
name:

```bash
sudo systemctl stop chromedriver          # or kill the hand-started one
chromedriver --port=9515 --verbose --log-path=/tmp/chromedriver.log
# re-run Behat in another shell, then read what Chrome was actually told:
grep -iE 'launching|chrome|exit|error' /tmp/chromedriver.log | head -40
```

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

// Behat. behat_wwwroot must DIFFER from $CFG->wwwroot — see "The Behat URL"
// below — must be reachable from this machine, and must be https for the
// @enrol_mercadopagosub_https scenarios, with a certificate that validates,
// because Moodle fetches this URL from the CLI before running.
$CFG->behat_wwwroot   = 'https://clone.example.com:8443';
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

### The Behat URL: why it cannot be the site's own

> `Behat config error: $CFG->behat_wwwroot in config.php must be different
> from $CFG->wwwroot`

This is not a formality. The Behat site is a **second site served from the
same code**: same `dirroot`, different database (the `bht_` prefix) and
different dataroot. Nothing is copied. Moodle decides which of the two a
request belongs to by looking at the URL it came in on, so if both had the
same URL there would be no way to tell them apart.

**Verified** in `behat_is_requested_url()` (`public/lib/behat/lib.php`): it
compares **host, port and path**, all three. Making any one of them differ is
enough:

| Approach | `behat_wwwroot` | What it costs |
| --- | --- | --- |
| **Different port** | `https://clone.example.com:8443` | A second vhost on 8443. The existing certificate still validates, because the hostname is unchanged. |
| Different host | `https://behat.clone.example.com` | A DNS record and a certificate covering that name. |
| Different path | `https://clone.example.com/behat` | An alias pointing at the same `public/`, and Moodle then treats it as a subdirectory install. |

**The port is the least work on a clone that already has a certificate**, and
it is what the example above uses. Apache:

```apache
Listen 8443

<VirtualHost *:8443>
    ServerName clone.example.com
    DocumentRoot /path/to/moodle/public

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/clone.example.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/clone.example.com/privkey.pem

    <Directory /path/to/moodle/public>
        Require all granted
        AllowOverride All
    </Directory>
</VirtualHost>
```

nginx, the same idea:

```nginx
server {
    listen 8443 ssl;
    server_name clone.example.com;
    root /path/to/moodle/public;

    ssl_certificate     /etc/letsencrypt/live/clone.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/clone.example.com/privkey.pem;

    location ~ [^/]\.php(/|$) {
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }
}
```

**Keep 8443 closed in the EC2 security group, and make the hostname resolve
locally.** Those two go together, and leaving out the second half is how you
get a `curl` that hangs forever instead of answering.

Everything that talks to the Behat site — the CLI check Moodle makes before
running, chromedriver, and the browser it drives — runs on this same machine.
But the hostname resolves to the instance's *public* IP, so a request to it
leaves the instance, comes back at the public interface, and is dropped by the
security group. Dropped, not refused: the connection hangs until something
times out, which reads like a server problem and is not one.

Point the name at the loopback address in `/etc/hosts`:

```bash
echo '127.0.0.1 clone.example.com' | sudo tee -a /etc/hosts
```

The certificate still validates, because TLS checks the *name* presented in
the request, not the address it resolved to. And the port stays closed to the
internet, which matters for a site with a known admin password whose data
anyone reaching it can reset.

The alternative is to open 8443 in the security group, restricted to your own
address. It works, and it is worse: the site is then reachable by anyone who
finds it.

Then confirm the URL answers, from the clone itself:

```bash
curl -sS https://clone.example.com:8443/
```

**Read the body, not the status code.** Once `$CFG->behat_*` is set, this URL
never serves a normal page, and a bare `%{http_code}` tells you nothing useful.

`setup.php` recognises the request as one aimed at the test site — it compares
scheme, host, port and path against `$CFG->behat_wwwroot` — and stops it,
because a browser that is not Behat has no business on a site whose database
gets dropped between runs. It stops it by printing one line and sending **HTTP
500**. A 500 here is therefore the expected answer, and the line says where in
the setup you are:

| The body says | What it means |
| --- | --- |
| `Install Behat before enabling it, use: php .../init.php` | The vhost, the port, the certificate and `behat_wwwroot` are all correct, and `init.php` has not been run yet. **This is the right answer at this point in the guide** — section 4 runs `init.php`. |
| `Behat is configured but not enabled on this test site.` | `init.php` has been run. This is the steady state between runs: the environment is only enabled while a run is in progress. Nothing to fix. |
| `Behat config error: ... directory is not empty ...` | `$CFG->behat_dataroot` has something else in it. See the directory rules below. |

A normal Moodle page — a 200, or a redirect to the login form — is the one
outcome that is actually *wrong*: it means the request did not match
`$CFG->behat_wwwroot`, so the production site was served over this vhost
instead. Compare the two URLs character for character, including the port and
any subdirectory.

A certificate error, which curl reports before any status code, is the same
error Behat will hit, because Moodle makes this exact request from the CLI
before the first scenario.

**If that command hangs rather than answering or failing**, work through these
in order — they separate the three things that produce the same silence:

```bash
# 1. Is anything listening on 8443 at all?
sudo ss -lntp | grep 8443

# 2. What does the name resolve to, and is that one of this machine's own
#    addresses? If it is the public IP and step 1 found a listener, the
#    security group is eating the packets — add the /etc/hosts line above.
getent hosts clone.example.com
hostname -I

# 3. Force the request to the loopback address, bypassing DNS entirely. If
#    THIS answers at all — including with the 500 described above — the vhost
#    and the certificate are both fine and the problem is only how the name
#    resolves.
curl -sS --resolve clone.example.com:8443:127.0.0.1 \
     https://clone.example.com:8443/
```

Read the three outcomes like this: *connection refused* immediately means
nothing is listening — the vhost is not loaded, so check `apachectl -S` or
`nginx -t` and whether `Listen 8443` was added. A hang means packets are being
dropped, which is the security group. And a certificate error means the vhost
is serving the wrong certificate for this name.

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

### One thing to take *out* of config.php, on PHP 8.4

If the clone prints this on every CLI command and every page:

```text
Deprecated: Constant E_STRICT is deprecated in .../config.php on line N
```

then `config.php` still carries `$CFG->debug = (E_ALL | E_STRICT);`. PHP 8.4
deprecated the constant — it has been a no-op since PHP 8.0, folded into
`E_ALL`. Drop it:

```php
$CFG->debug = E_ALL;
```

Cosmetic for PHPUnit, less so for Behat: a run turns on developer debugging
and installs `behat_error_handler()`, and a notice emitted on every request
is noise across every scenario's output and faildumps.

---

## 3. PHPUnit

```bash
# Once, and again after any change to a db/ file, after adding or removing ANY
# plugin from the tree, after any version.php bump, or after switching
# PHPUNIT_DB. Note the public/ prefix: Moodle 5.x keeps tool CLI scripts under
# public/, while the core ones (upgrade.php, cron.php) live in admin/cli at
# the root. --disable-composer: see below, it is not optional on a clone whose
# web user cannot write to its own home.
php public/admin/tool/phpunit/cli/init.php --disable-composer

# The whole plugin suite.
vendor/bin/phpunit --testsuite enrol_mercadopagosub_testsuite

# One file, or one test. Keep the --testsuite on the filter: see below.
vendor/bin/phpunit public/enrol/mercadopagosub/tests/privacy_provider_test.php
vendor/bin/phpunit --testsuite enrol_mercadopagosub_testsuite \
    --filter test_a_missed_charge_becomes_overdue

# The other engine. Init it once; after that, switching is only the variable,
# because each engine has its own dataroot as well as its own database.
PHPUNIT_DB=pgsql php public/admin/tool/phpunit/cli/init.php --disable-composer
PHPUNIT_DB=pgsql vendor/bin/phpunit --testsuite enrol_mercadopagosub_testsuite
```

Expected: **141 tests, 390 assertions, green**, on both engines — measured
2026-09-13 on MariaDB 12.3.3 (7m02s) and PostgreSQL 17.11 (34s). The suite
needs no network and no credentials.

### A bare `--filter` reports thousands of PHPUnit deprecations

```text
OK, but there were issues!
Tests: 1, Assertions: 5, PHPUnit Deprecations: 4036.
```

Nothing is wrong with the plugin, and the same test inside
`--testsuite enrol_mercadopagosub_testsuite` reports none. `--filter` only
*selects* tests; it does not narrow what gets loaded. Without a `--testsuite`,
PHPUnit builds all 412 suites `init.php` writes into `phpunit.xml`, which means
parsing the metadata of every test class in Moodle. Core still declares
`@covers`, `@dataProvider` and `@group` in docblocks, and PHPUnit 11's
`AnnotationParser` emits one deprecation per class and per method for those
— *"Metadata in doc-comments is deprecated and will no longer be supported in
PHPUnit 12"*. The count is core's docblocks, nothing of ours. The 400 MB peak
and the discovery time have the same cause.

These are PHPUnit deprecations, not `E_DEPRECATED` from the code under test,
so `failOnDeprecation="true"` in `phpunit.xml` does not turn them into a
failure. Scope the run instead — a `--testsuite`, or a file path — and they go
away.

### Always pass `--disable-composer`

`init.php` runs `php composer.phar self-update` before it does anything else,
and on failure it calls `exit()` — the whole initialisation is abandoned
before a single table is touched, and the environment silently stays at
whatever the previous run left it.

It fails on a normal Debian stack the second time you run it. Run the tests as
the web user, as you should, and composer's home is that user's home:

```text
file_put_contents(/var/www/.config/composer/keys.dev.pub):
Failed to open stream: No such file or directory
```

`www-data`'s home is `/var/www` and it does not own it. The *first* run
escapes this: `composer.phar` is not there yet, so `testing_update_composer_
dependencies()` downloads it and forces `$selfupdate = false` — "do not
self-update after installation". Every run after that tries, and dies.

`--disable-composer` turns off both the self-update and the dependency
upgrade. It does **not** disable installing dependencies when `vendor/` is
missing, so a clone that has run `composer install` once needs nothing else.
The `Cannot create cache directory /var/www/.cache/composer` warning from the
first run has the same cause and is harmless — composer says so itself and
proceeds.

If you would rather keep composer working, give it a writable home instead —
`sudo` will not pass the variable through on its own:

```bash
sudo -u www-data env COMPOSER_HOME=/var/moodledata_composer \
     php public/admin/tool/phpunit/cli/init.php
```

### "initialised for different version" means the tree changed

```text
Moodle PHPUnit environment was initialised for different version, please use:
 php public/admin/tool/phpunit/cli/init.php
```

This is not about PHPUnit's version. `testing_util::is_test_data_updated()`
compares `\core\component::get_all_versions_hash()` — a hash over the
`version.php` of *every* component in the tree — against the copy stored in
`$CFG->phpunit_dataroot/phpunit/versionshash.txt` and against the
`phpunittest` row in the test database. Any of the three disagreeing gives
this message.

So **dropping a plugin into the tree invalidates the environment**, exactly as
a version bump does. The plugin list `init.php` prints is the evidence: if
`enrol_mercadopagosub` does not appear between `enrol_mercadopagocpro` and
`enrol_meta`, it was not in the tree when that environment was built, and no
amount of re-running `vendor/bin/phpunit` will change that.

Re-run `init.php` — with `--disable-composer`, or the exit above will make it
look as though you did.

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

`init.php` is also what turns the 500 described in section 2 from *"Install
Behat before enabling it"* into *"Behat is configured but not enabled on this
test site"*. Both are healthy; the second is the steady state from here on.

### The tag gotcha, measured on the sibling plugin 2026-09-04

`moodle-plugin-ci behat --tags=X` **replaces** the `@enrol_mercadopagosub` tag
the tool builds by default rather than adding to it. Passing a bare negation
therefore selects Moodle's entire Behat suite — 3150+ steps, still running at
35 minutes. Always put both conditions in one expression, as above.

### Ten scenarios, three of which need HTTPS

**All ten pass as of 2026-09-13** — 7 non-HTTPS scenarios / 98 steps in 2m32s,
3 HTTPS scenarios / 45 steps in 1m04s, on Moodle 5.2.2 with Chrome and
chromedriver 153.0.8010.36.

Seven run anywhere: adding the method, four validation failures, the
credentials check that fires when the method is enabled, and a manager seeing
the method with a subscriber present. They keep *"Allow new subscriptions"* at
No, which is what avoids the HTTPS guard while still exercising the form.

Three carry `@enrol_mercadopagosub_https` because an enabled instance is
involved, and both `edit_instance_validation()` and `can_subscribe()` refuse
one on a site that is not served over HTTPS. On a plain-http clone they fail by
proving that guard works, which is not a useful signal on every push.

Of those three, one drives the instance form to prove a manager really *can*
enable a method when the site and the credentials allow it — without it only
the refusal was ever tested, and a guard that always said no would pass the
whole file. The other two are about what a learner sees, so they build the
instance with the generator rather than through the form: a scenario that
drives the form on its way to testing something else fails ambiguously, which
is exactly what happened on the first run.

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

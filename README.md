# Mercado Pago Subscriptions — `enrol_mercadopagosub`

A Moodle enrolment method that sells **recurring** access to a course through
Mercado Pago's Subscriptions API. The learner authorises a subscription once;
Mercado Pago charges the card on every cycle and notifies this site, which
grants, keeps or withdraws the enrolment accordingly.

Payment happens entirely on Mercado Pago. This site never sees card data.

- **Component**: `enrol_mercadopagosub`
- **Requires**: Moodle 5.2 or later (`$plugin->requires = 2026042002`)
- **Licence**: [GPL v3 or later](LICENSE)
- **Maintainer**: Julio Tentor & Associates — <https://juliotentor.com>

## Is this the plugin you want?

| | `enrol_mercadopagosub` | `enrol_mercadopagocpro` |
| --- | --- | --- |
| Charges | Every cycle, until cancelled | Once |
| Mercado Pago product | Subscriptions (preapproval) | Checkout Pro |
| Access ends when | Payment stops arriving | Never, or on the course's own end date |
| Good for | Memberships, tuition by the month | A course sold outright |

The two are separate plugins and run side by side on the same site, each with
its own credentials and its own Mercado Pago application.
[`docs/INSTALL.md`](docs/INSTALL.md) covers what running both requires.

## What it does

- **Recurring billing** on a cycle the course sets, in days or months. Mercado
  Pago takes the first payment as soon as the subscription is authorised.
- **Free trial** of any length, during which the subscriber is enrolled and
  pays nothing.
- **A subscription state machine** driven by Mercado Pago's own notifications:
  *waiting for payment → free trial → active → payment overdue → ended*. The
  enrolment follows the state; nobody has to reconcile it by hand.
- **A grace period** for a missed charge, so a card that fails once does not
  cost a learner their access the same morning.
- **Groups**, separately for paying subscribers and for those on trial.
- **A cap** on the number of subscribers a course accepts.
- **Expiry notifications** through Moodle's own message provider.
- **Payment by somebody else** — a parent, an employer — through a payment link
  the learner can pass on. The payer's Mercado Pago account is the one charged.
- **A privacy provider** covering both the subscriber and the payer, including
  the export and deletion paths the GDPR tooling calls.
- **`cli/diagnose.php`**, which checks a site's configuration and tells you
  what is wrong with it rather than making you guess.

Not yet implemented, though the instance form already collects it: sending the
course welcome message. See [`CHANGES.md`](CHANGES.md).

## Requirements

- Moodle 5.2 or later.
- PHP 8.3 or 8.4, with `curl`, `json`, `intl` and `mbstring`.
- **The site must be served over HTTPS.** The plugin refuses to enable an
  enrolment method on a plain-HTTP site, and refuses to start a subscription on
  one. Mercado Pago will not return a subscriber to an `http://` URL.
- A Mercado Pago seller account, and an application registered in *Your
  integrations* with this site's notification URL.
- Cron running. Four scheduled tasks do the work between notifications.

A course can charge only in the currency its Mercado Pago account settles in,
and only an account registered in the same country can pay it. Both follow from
the credentials, not from any setting.

## Installation

```bash
cd /path/to/moodle/public/enrol
git clone https://github.com/jtentor/moodle-enrol_mercadopagosub.git mercadopagosub
php /path/to/moodle/admin/cli/upgrade.php
```

Or install the ZIP from *Site administration → Plugins → Install plugins*. The
directory must be named `mercadopagosub`.

Then configure it — credentials, the Mercado Pago application, the notification
URL, roles — following [`docs/INSTALL.md`](docs/INSTALL.md), and confirm the
result:

```bash
php public/enrol/mercadopagosub/cli/diagnose.php --checkaccount
```

## Documentation

| | |
| --- | --- |
| [`docs/INSTALL.md`](docs/INSTALL.md) | Installation, configuration and the Mercado Pago side of it |
| [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) | Symptoms, causes, fixes |
| [`docs/TESTING.md`](docs/TESTING.md) | Running the PHPUnit and Behat suites on a test clone |
| [`docs/HANDOVER.md`](docs/HANDOVER.md) | Design decisions, measurements and their reasoning |
| [`CHANGES.md`](CHANGES.md) | Release history |

## Tests

| | |
| --- | --- |
| PHPUnit | 141 tests, 390 assertions — green on MariaDB and PostgreSQL |
| Behat | 10 scenarios, 143 steps — green on a real HTTPS site |
| phpcs | clean on `moodle` and `moodle-extra` (CI checks `moodle`) |

Continuous integration runs PHPUnit on PHP 8.3 and 8.4 against PostgreSQL and
MariaDB. **It does not run Behat at all** — three scenarios need a site served
over real HTTPS, which no CI runner provides, and running only the other seven
would report a green Behat badge for a suite whose point is the payment flow.
Run the whole suite by hand before each release;
[`docs/TESTING.md`](docs/TESTING.md) says how.

## Status

Not yet published to the Moodle plugins directory, and not yet running in
production. `$plugin->maturity` says where it actually stands; treat it as the
authority, not this paragraph.

## Licence

Copyright 2026 Julio Tentor & Associates <https://juliotentor.com>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version. See [LICENSE](LICENSE).

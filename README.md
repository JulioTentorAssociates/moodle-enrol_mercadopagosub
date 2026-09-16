# Mercado Pago Subscriptions — `enrol_mercadopagosub`

A Moodle enrolment method that sells **recurring** access to a course through
Mercado Pago's Subscriptions API. The learner authorises a subscription once;
Mercado Pago charges the card on every cycle and notifies this site, which
grants, keeps or withdraws the enrolment accordingly.

Payment happens entirely on Mercado Pago. This site never sees card data.

- **Component**: `enrol_mercadopagosub`
- **Requires**: Moodle **5.2.3** or later — see [Supported Moodle versions](#supported-moodle-versions)
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

## Supported Moodle versions

**Moodle 5.2.3 or later. 5.2.2 is not supported.**

Moodle's own advice is to skip 5.2.2 and upgrade straight to 5.2.3, which fixes
a grade-calculation defect in it. This plugin is developed, tested and verified
against 5.2.3 from v1.0.1 onwards.

`$plugin->requires` currently names the 5.2 branch point rather than the 5.2.3
build, so Moodle will let you install on an earlier 5.2 — that is not a
supported configuration, and tightening it is one of the decisions held open
until this plugin is proposed to the plugins directory
([`docs/HANDOVER.md`](docs/HANDOVER.md)).

## Requirements
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

## AI-Assisted Technology Statement

During the development and documentation of this project, the large language models Claude Opus 5 (Anthropic, 2026), ChatGPT GPT-5.6 Sol (OpenAI, 2026), GitHub Copilot (Microsoft, 2026) and Gemini 3.1 Pro (Google, 2026) were used.

Specifically, these tools were employed as technical assistants for the architecture and code generation of a Moodle enrolment plugin, streamlining scriptwriting, system file structuring, and software debugging. To ensure security, compliance with Moodle development standards, and overall software reliability, all code and logic they generated was thoroughly verified, tested, and critically edited by the author before final implementation. The author maintains full accountability for the functionality, accuracy, and originality of the work presented.

## Licence

Copyright 2026 Julio Tentor & Associates <https://juliotentor.com>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version. See [LICENSE](LICENSE).

## References

The API documentation this plugin was built against, and the Moodle
subsystems it implements:

- [Mercado Pago — Create subscription (preapproval)](https://www.mercadopago.com.ar/developers/en/reference/subscriptions/_preapproval/post) — the *subscription without an associated plan* model this plugin uses
- [Mercado Pago — Webhooks and `x-signature` validation](https://www.mercadopago.com.ar/developers/en/docs/subscriptions/additional-content/your-integrations/notifications/webhooks)
- [Moodle — Enrolment plugins](https://moodledev.io/docs/5.2/apis/plugintypes/enrol)
- [Moodle — Privacy API](https://moodledev.io/docs/5.2/apis/subsystems/privacy)
- [Moodle — Task API](https://moodledev.io/docs/5.2/apis/subsystems/task)

**This plugin does not use the Mercado Pago PHP SDK.** It calls the API
directly over `curl`, which is why there is no `thirdpartylibs.xml` and no
vendored dependency to keep in step with upstream.

The AI tools named in the statement above:

- [Anthropic. Claude Opus 5](https://claude.ai)
- [OpenAI. ChatGPT GPT-5.6 Sol](https://chat.openai.com)
- [Microsoft. GitHub Copilot](https://github.com/features/copilot)
- [Google. Gemini 3.1 pro](https://gemini.google.com/)

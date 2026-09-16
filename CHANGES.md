# Changelog

All notable changes to `enrol_mercadopagosub` are recorded here. Versions follow
[semantic versioning](https://semver.org/); the `$plugin->version` integer in
`version.php` is the date-based number Moodle requires and moves with every
release.

## [Unreleased]

Nothing yet.

## [v1.0.0] — 2026-09-13

First release. Not published to the Moodle plugins directory, and not yet run in
production; `$plugin->maturity` is the authority on how far to trust it.

### Added

- **Recurring enrolment through Mercado Pago's Subscriptions API.** A
  subscription without a plan (*"model C"*): created pending, the learner is sent
  to `init_point` to authorise it, and Mercado Pago charges from there on. The
  site never sees card data.
- **A subscription state machine** — *pending → trialing → active → overdue →
  ended* — driven by Mercado Pago's own notifications, with the Moodle enrolment
  following the state rather than being reconciled by hand.
- **Free trials**, of any length, during which the subscriber is enrolled and
  pays nothing.
- **A grace period** for a failed charge, so one declined card does not cost a
  learner their access immediately.
- **Groups**, configured separately for paying subscribers and for those on
  trial.
- **A cap** on subscribers per course.
- **Payment by a third party** — a parent, an employer — through a payment link,
  with the paying account recorded separately from the subscriber.
- **Expiry notifications** through Moodle's message provider
  (`enrol_mercadopagosub/expiry_notification`).
- **A webhook endpoint** (`webhook.php`) that verifies the `x-signature` HMAC
  and queues notifications rather than acting on them, so that a slow site
  cannot make Mercado Pago retry or give up.
- **Four scheduled tasks**: `process_events` (every 2 minutes),
  `reconcile_payments` (15), `send_expiry_notifications` (10) and
  `process_expirations` (10). Reconciliation is the safety net that makes a
  missed notification an inconvenience rather than a lost enrolment.
- **Seven capabilities**, with the financial ones (`config`,
  `cancelsubscription`) on manager only and `viewsubscriptions` extended to
  editing teachers, on the reasoning that knowing whether a student's access is
  paid up is ordinary teaching information.
- **A privacy provider** covering both people in a subscription — the subscriber
  and the payer — across `metadata\provider`, `request\plugin\provider` and
  `core_userlist_provider`, with the payer's request blanking the paying address
  and nothing more.
- **Credentials from three sources**, in precedence order:
  `$CFG->enrol_mercadopagosub`, the environment, then the plugin settings, so
  production credentials need never reach the database.
- **Currency and country derived from the collecting account**, with an explicit
  setting as the escape hatch for a market this release does not yet recognise.
  Deliberately not a frozen country list — that is what has produced one Mercado
  Pago plugin per country in the plugins directory.
- **`cli/diagnose.php`**, which checks installation, capabilities, site
  requirements, credentials, scheduled tasks, current state and a named course,
  reports a fix beside each failure and redacts credentials. `--checkaccount`
  is the only option that calls the API deliberately, though resolving the
  currency reads the collecting account when the setting is blank and the cache
  is cold.
- **Tests**: 141 PHPUnit tests / 390 assertions, green on MariaDB and
  PostgreSQL; 10 Behat scenarios / 143 steps, green on a real HTTPS site.
- **Continuous integration** running PHPUnit over PHP 8.3 and 8.4 against
  PostgreSQL and MariaDB, plus phpcs on the `moodle` standard. The tree is also
  clean on `moodle-extra`, checked by hand. Behat is deliberately not in CI: the
  scenarios that matter need a site served over real HTTPS.
- **Documentation**: `README.md`, `docs/INSTALL.md`, `docs/TROUBLESHOOTING.md`,
  `docs/TESTING.md` and `docs/HANDOVER.md`.

### Fixed during development

Both of these were fatal on the first screen a customer reaches, both were
invisible to a green 136-test unit suite because no unit test built the instance
form, and both were found by the first runs of the Behat suite. They are
recorded here because the lesson is more useful than the diff.

- **`extend_assignable_roles()` was called but never defined.** `enrol_plugin`
  does not provide it; every plugin whose instance form offers a role selector
  declares its own. Adding the enrolment method to a course died with
  *"Call to undefined method"*, every time.
- **`get_instance_defaults()` was never overridden.** The site defaults lived in
  a private method reached only from `add_instance()`, which runs after
  validation, while `enrol/editinstance.php` builds the add form from the core
  hook. Every field the site settings configure came up blank, and a customer
  who filled in the amount was told *"The billing frequency must be at least 1"*
  about a field they had never been shown a value for.

### Known limitations

- **Trial state detection.** Mercado Pago's `status` field does not distinguish
  *authorised during a trial* from *authorised and paying*, so the plugin infers
  the trial from its own dates rather than from the platform.
- **The subscriber cap counts only `trialing`, `active` and `overdue`.** A
  course can therefore oversell if several people authorise at once. Counting
  `pending` instead would mean an abandoned checkout locks a seat; the choice is
  recorded as an open question in `docs/HANDOVER.md`.
- **No way to cancel a subscription from Moodle.** The
  `enrol/mercadopagosub:cancelsubscription` capability is declared and gates
  nothing. Cancelling the preapproval in the Mercado Pago dashboard works and
  is picked up within 15 minutes, which is why this is a gap and not a blocker.
- **No subscription report.** `enrol/mercadopagosub:viewsubscriptions` today
  only opens the payment link page; there is no screen listing who is
  subscribed, their payments, or the paying account.
- **The course welcome message is collected but never sent.** The instance form
  stores *Send course welcome message* and the body, and no code path in this
  release delivers them. Expiry notifications are a separate mechanism and do
  work.
- **The guest payment path cannot be exercised in a test environment at all.**
  It is covered by neither suite, and no arrangement of test credentials
  changes that.
- **A data deletion request does not cancel the subscription at Mercado Pago.**
  The preapproval must be cancelled in the seller's dashboard by a person. See
  `docs/INSTALL.md`.

[Unreleased]: https://github.com/jtentor/moodle-enrol_mercadopagosub/compare/v1.0.0...HEAD
[v1.0.0]: https://github.com/jtentor/moodle-enrol_mercadopagosub/releases/tag/v1.0.0

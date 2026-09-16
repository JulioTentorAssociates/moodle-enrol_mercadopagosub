# Troubleshooting enrol_mercadopagosub

**Start here:**

```bash
php /path/to/moodle/public/enrol/mercadopagosub/cli/diagnose.php --checkaccount
```

It checks the plugin's installation, capabilities, site requirements,
credentials, scheduled tasks and current state, and prints a fix beside anything
it finds wrong. Most of what follows is the long form of one of its lines.

Run it as the web server user. `--checkaccount` is the only option that calls
Mercado Pago *deliberately* — but a plain run can still reach the API, because
resolving the currency reads the collecting account when the `currency` setting
is blank and the cache is cold. On a site that has never called the API, expect
one request either way.

```bash
# Everything the site can tell you about one course.
php .../cli/diagnose.php --courseid=42

# And as a particular person, which is how you find a capability problem.
php .../cli/diagnose.php --courseid=42 --username=jsmith
```

---

## Nobody can subscribe

### The method is not in the course's *Add method* list

The plugin is installed but not enabled site-wide. *Site administration →
Plugins → Enrolments → Manage enrol plugins*, click the eye beside **Mercado
Pago Subscriptions**. The diagnostics call this *Enrolment method enabled
site-wide*.

### The method cannot be enabled: "no Mercado Pago credentials configured"

No access token is reaching the plugin. Note *reaching* — the settings screen
may show one that something else is overriding. See
[Credentials that look right and are not](#credentials-that-look-right-and-are-not).

### The method cannot be enabled: "cannot be enabled on a site served over plain HTTP"

`$CFG->wwwroot` does not begin with `https://`. This is not a check you can turn
off: Mercado Pago refuses a plain-HTTP `back_url` and notification URL, so an
instance enabled on such a site could never complete a payment.

A site behind a reverse proxy that terminates TLS needs `$CFG->wwwroot` set to
the `https://` address and `$CFG->sslproxy = true`. The plugin reads `wwwroot`,
nothing else.

### The learner sees nothing at all on the course page

In order:

1. **The instance is disabled.** *Allow new subscriptions* is set to No. That
   deliberately leaves existing subscriptions running and only closes the door
   to new ones.
2. **They lack `enrol/mercadopagosub:subscribe`.** It is granted to the
   authenticated user archetype by default, so this means an override.
   `--courseid=N --username=U` says which.
3. **They are not logged in**, in which case they are told to log in rather than
   shown nothing.

`diagnose.php --courseid=N --username=U` answers this directly: it reports whether the
plugin would offer that person a subscription, and if not, why not.

### The learner is told "You already have a subscription in progress or active"

They have a row that is not `ended` — very often a `pending` one from an
abandoned checkout. This is on purpose: two live preapprovals racing for the same
enrolment is worse, and Mercado Pago gives us no way to cancel the first one on
the learner's behalf if they simply walk away.

**This release has no way to cancel a subscription from Moodle.** The
`enrol/mercadopagosub:cancelsubscription` capability is declared but gates
nothing yet.

What works today: cancel the preapproval in the Mercado Pago dashboard. The
next notification — or `reconcile_payments` within 15 minutes — reports it as
`cancelled`, and `event_processor` moves the row to `ended` with
`endreason = cancelled_by_mp`. The learner can then subscribe again. Nothing
has to be edited by hand in the database.

### The learner is told the course has reached its maximum number of subscribers

*Maximum subscribers* on the instance. Only `trialing`, `active` and `overdue`
subscriptions count against it — an abandoned `pending` checkout does **not**
hold a place. Whether that is the right trade-off is an open question recorded
in [`HANDOVER.md`](HANDOVER.md): it means a course can oversell if several
people authorise at once, and the alternative means an abandoned checkout locks
a seat.

A `pending` subscription does block *that same learner* from starting a second
one, which is a different rule — see above.

---

## Payments happen but nothing changes on the site

This is almost always one of three things, in descending order of likelihood.

### Cron is not running

`webhook.php` only **queues** notifications. It does no work, deliberately, so
that a slow site cannot make Mercado Pago retry or give up on it. Everything
after that is `process_events`, every 2 minutes.

If cron is stalled, subscriptions sit in *Waiting for payment* no matter how
well the payment went. The diagnostics report whether each task is enabled and
when it last ran; *Site administration → Server → Scheduled tasks* also shows
the next run.

```bash
php /path/to/moodle/admin/cli/cron.php
```

### The notification URL is wrong, missing, or belongs to another plugin

In *Your integrations*, the application's **Notification URL** must be exactly:

```
https://your-site.example.com/enrol/mercadopagosub/webhook.php
```

There is **one notification URL per application**, so if this site also runs
`enrol_mercadopagocpro` and both point at the same application, one of them is
receiving the other's notifications. Each plugin needs its own application. See
[`INSTALL.md`](INSTALL.md).

Setting `notification_url` on the subscription instead does not help: the API
accepts the field and silently discards it. That was measured, not inferred.

### Nothing was queued at all

If *Webhook events waiting* is 0 and no payment has been recorded, notifications
are not arriving. Check that the URL above is reachable from outside — a
firewall, a WAF rule, or an IP allow-list in front of the site will make Mercado
Pago's delivery fail silently as far as this site is concerned.

`reconcile_payments` is the safety net for exactly this, and it runs every 15
minutes: a missed notification should become an inconvenience rather than a lost
enrolment. If reconciliation is also not correcting things, the problem is the
credentials or the network, not the webhook.

---

## The diagnostics report a problem

### "Notifications with an unverified signature"

Incoming notifications are recorded with `signaturestatus=absent` when no
webhook secret is configured, and `failed` when the one configured does not
match the application sending them. `verified` is the third value; there is no
other.

Absent is a configuration gap. `failed` is worse — it means either the wrong
secret, or traffic that did not come from Mercado Pago. Set the webhook secret
from the same application whose notification URL points here.

**Note that neither value stops the notification being acted on.** Every queued
row is processed regardless of its signature, deliberately: the plugin never
trusts a notification's *body*, only its identifier, and then asks Mercado Pago
what the subscription actually says. The signature status is evidence for you,
not a gate. `classes/event_processor.php` explains the reasoning at the top.

### "Webhook events failed"

`process_events` tried and could not. Each row carries its own `lasterror`. The
usual causes are credentials that stopped working (a rotated token), a
subscription the account no longer owns, or a course whose enrolment instance
was deleted while subscriptions were still live.

Failed events are not retried forever, on purpose. Fix the cause, then re-queue.

### "Account reachable" fails

`--checkaccount` called Mercado Pago and did not get an answer it could use. The
message distinguishes two cases, and they need different fixes:

- **Could not reach `api.mercadopago.com`** — a transport failure. The site has
  no outbound HTTPS, or a proxy is in the way. Moodle's own `curl` settings
  (`$CFG->proxyhost` and friends) apply.
- **An HTTP status** — the API answered and refused. 401 means the access token
  is wrong, revoked, or belongs to a different application than you think.

### "Credential type: TEST credentials"

The token is a Mercado Pago test credential. Correct on a development site,
wrong on a production one: a test credential cannot take money from a real
buyer. Nothing will look broken until a real learner tries to pay.

### "Currency resolved" is blank or wrong

The plugin reads the collecting account's country and derives the currency. If
the account's country is one this release does not recognise — a market opened
since — set the currency explicitly in the plugin settings. That is what the
setting is for; it is not a general override and should stay blank otherwise.

---

## Credentials that look right and are not

The plugin reads credentials from three places, in this order:

1. `$CFG->enrol_mercadopagosub` in `config.php`
2. The environment: `MERCADOPAGOSUB_ACCESS_TOKEN` and friends
3. The plugin settings screen

**The first two silently win over the settings screen.** A site can therefore
display one token in the admin form and use another for every call — which is
the intended behaviour for keeping production credentials out of the database,
and a genuinely confusing hour if you do not know it is happening.

`cli/diagnose.php` reports which source is in effect. Trust it over the form.

One consequence worth knowing on a test clone: PHPUnit and Behat both discard
`$CFG->enrol_mercadopagosub`, so credentials in `config.php` cannot leak into a
test. **Environment variables are not filtered by either.** A clone with those
variables exported will have its Behat scenarios talking to whatever account
they name. See [`TESTING.md`](TESTING.md).

---

## Subscriptions and money

### A subscriber was charged after we deleted them

Deleting the site's record does not cancel anything at Mercado Pago. The
preapproval has to be cancelled in the seller's dashboard, by a person, as part
of handling the request — and *before* deleting the record, or you have thrown
away the reference that identifies which subscription to cancel.

The same applies to uninstalling the plugin: it drops this site's tables and
stops nothing at Mercado Pago.

### The payer's account was refused

A Mercado Pago account can only pay a subscription whose collecting account is
registered in the same country. This is Mercado Pago's rule, not the plugin's,
and there is nothing to configure: selling into another country needs an account
in that country.

### The payer entered the wrong address

The paying account's address cannot be changed after the subscription is
created. Cancel it and start again — which is why the subscribe form says so
before the learner commits.

### A charge failed and the learner still has access

By design, for as long as *Grace period* on the instance allows. A card that fails
once — an expiry, a bank's fraud hold — should not cost somebody their access the
same morning. The subscription moves to *Payment overdue* and the enrolment ends
when the grace period does.

---

## Messages and text

### The course welcome message never arrives

**Because nothing sends it yet.** The instance form collects *Send course
welcome message* and the message body, and stores both, but no code path in
this release sends them. The setting is honest about existing and dishonest
about doing anything.

This is a known gap, not a misconfiguration. Nothing you change on the site
will make the message arrive. Expiry notifications are a separate mechanism and
do work.

### Expiry notifications never arrive

These are real. Check that `send_expiry_notifications` is enabled and running,
that the instance has an **End date** — there is nothing to warn about without
one — and that the recipient has not disabled the
`enrol_mercadopagosub/expiry_notification` provider in their message
preferences.

---

## Development and testing

Running the suites, setting up a test clone, chromedriver, the Behat
configuration and the errors that setup produces are all in
[`TESTING.md`](TESTING.md), which is a longer document than this one and was
written the same way: from what actually went wrong.

Two things from it are worth repeating here, because they cost the most time:

- **The guest payment path cannot be exercised in a test environment at all.**
  Anyone building a test suite should know that before they try.
- Moodle's Behat failure banner about Selenium is printed for *every* driver
  failure and is not a diagnosis. Read the line underneath it.

---

## Reporting a problem

Open an issue at
<https://github.com/jtentor/moodle-enrol_mercadopagosub/issues> with:

- The output of `cli/diagnose.php` — it redacts the credentials themselves.
- Moodle, PHP and database versions.
- What you expected, and what happened instead.

Do not paste an access token, a webhook secret, or a notification body. A
notification body identifies a real payer.

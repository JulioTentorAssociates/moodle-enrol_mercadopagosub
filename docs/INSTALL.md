# Installing and configuring enrol_mercadopagosub

This document covers a production installation. For a throwaway clone that runs
the test suites, see [`TESTING.md`](TESTING.md) instead — it needs settings that
must never reach a live site.

Read [Before you start](#before-you-start) even if you have installed a Moodle
enrolment plugin before. Two of its constraints are not negotiable and are not
obvious, and one of them is decided by your Mercado Pago account rather than by
anything on this site.

---

## Before you start

**The site must be served over HTTPS, with a certificate that validates.** This
is not a recommendation. The plugin refuses to enable an enrolment method on a
plain-HTTP site and refuses to start a subscription on one, because Mercado Pago
will not return a subscriber to an `http://` address and credentials must not
travel over one.

**The currency and the country are decided by your Mercado Pago account, not by
this site.** An account belongs to one Mercado Pago country and settles in that
country's currency. That is what a course can charge in — and it also decides
who can pay: a subscriber whose own Mercado Pago account is registered in a
different country is refused when the subscription is created. If you sell
across borders, you need an account per country, and this plugin cannot paper
over that.

**Each Mercado Pago plugin needs its own Mercado Pago application.** If this
site also runs `enrol_mercadopagocpro`, see
[Running alongside enrol_mercadopagocpro](#running-alongside-enrol_mercadopagocpro).
Everything else about the two coexisting is already handled in code; this one
step is actual configuration.

You will also need:

- Moodle 5.2 or later.
- PHP 8.3 or 8.4 with `curl`, `json`, `intl` and `mbstring`.
- Cron running on a normal schedule. Four scheduled tasks do the work between
  notifications, and a site whose cron is stalled will look like a plugin that
  has stopped working.

---

## 1. Install the plugin

```bash
cd /path/to/moodle/public/enrol
git clone https://github.com/jtentor/moodle-enrol_mercadopagosub.git mercadopagosub
php /path/to/moodle/admin/cli/upgrade.php
```

The directory must be named `mercadopagosub`. Installing from a ZIP through
*Site administration → Plugins → Install plugins* works equally well.

Then enable it: *Site administration → Plugins → Enrolments → Manage enrol
plugins*, and click the eye beside **Mercado Pago Subscriptions**. A plugin that
is installed but not enabled does not appear in a course's *Add method* list,
which is the most common reason for "the plugin is not there".

---

## 2. Create the Mercado Pago application

In the Mercado Pago developer dashboard, under **Your integrations**, create an
application for *this plugin on this site*. Not one shared with another plugin,
another site, or another environment — the reason is in step 3.

From the application, take:

| | Used for |
| --- | --- |
| **Access token** | Every API call this plugin makes. Required. |
| **Public key** | Not used server-side today. Kept for front-end work this design does not yet include. |
| **Webhook secret** | Verifying the `x-signature` header on incoming notifications. |

The access token is the only one the plugin cannot work without. Left without a
webhook secret it can still create and read subscriptions, but `webhook.php` has
nothing to verify signatures against, and will say so in the diagnostics.

---

## 3. Register the notification URL

In the same application, set the **Notification URL** to:

```
https://your-site.example.com/enrol/mercadopagosub/webhook.php
```

Subscribe it to the subscription events (`subscription_preapproval` and
`subscription_authorized_payment`).

**This has to be done by hand, and there is exactly one notification URL per
application.** That is the whole reason a site running more than one Mercado
Pago plugin needs one application per plugin: two plugins cannot share one URL,
and the URL cannot be set per subscription. Passing `notification_url` on the
subscription itself is accepted by the API and then silently discarded —
measured, not assumed.

---

## 4. Enter the credentials

*Site administration → Plugins → Enrolments → Mercado Pago Subscriptions.*

Fill in the access token, the public key and the webhook secret.

### Where credentials really come from

This screen is the **last** of three places the plugin looks, and the only one
you can edit from a browser. In order of precedence:

1. `$CFG->enrol_mercadopagosub` in `config.php`, as an array:

   ```php
   $CFG->enrol_mercadopagosub = [
       'accesstoken'   => '...',
       'publickey'     => '...',
       'webhooksecret' => '...',
   ];
   ```

2. The environment: `MERCADOPAGOSUB_ACCESS_TOKEN`,
   `MERCADOPAGOSUB_PUBLIC_KEY`, `MERCADOPAGOSUB_WEBHOOK_SECRET`.

3. These plugin settings.

A value in `config.php` or the environment silently wins over what this screen
shows. That is deliberate — it keeps production credentials out of the database
and out of backups — but it does mean the settings screen can display one token
while the site uses another. `cli/diagnose.php` reports which source is actually
in effect; trust it over the form.

### Currency

Leave **Currency** blank unless the diagnostics tell you otherwise. The plugin
reads the collecting account's own country and derives the currency from it. The
setting exists for an account whose country the plugin does not recognise — a
market opened after this release — so that such a site can carry on rather than
wait for a code change.

---

## 5. Set up roles

Seven capabilities ship with the plugin. The defaults are deliberate, and one of
them is worth changing on most sites.

| Capability | Default | What it gates **today** |
| --- | --- | --- |
| `enrol/mercadopagosub:config` | Manager | Configuring enrolment instances — the amount, the cycle, the trial |
| `enrol/mercadopagosub:manage` | Manager | Managing enrolled users; also opens the payment link page |
| `enrol/mercadopagosub:unenrol` | Manager | Unenrolling users |
| `enrol/mercadopagosub:unenrolself` | Student | Unenrolling self |
| `enrol/mercadopagosub:subscribe` | Authenticated user | Starting a subscription |
| `enrol/mercadopagosub:viewsubscriptions` | Editing teacher, Manager | Opening the payment link page. **There is no subscription report in this release** |
| `enrol/mercadopagosub:cancelsubscription` | Manager | **Nothing yet.** No cancellation exists in this release |

Two of these are declared ahead of the features they are meant to gate, and the
table says which. Granting them is harmless and forward-looking; expecting a
report or a cancel button from them is not. See [`CHANGES.md`](../CHANGES.md).

**Give the financial capabilities to a dedicated administrative role, and take
them off the teacher role.** Teachers keep `viewsubscriptions` on purpose:
knowing whether a student's access is paid up is ordinary teaching information
and grants no power over the money. `config` is a different matter — it sets
prices — and on most sites it does not belong to whoever writes the course.

---

## 6. Check the scheduled tasks

Four tasks, all under *Site administration → Server → Scheduled tasks*:

| Task | Default | What it does |
| --- | --- | --- |
| `process_events` | every 2 minutes | Acts on notifications the webhook has queued |
| `reconcile_payments` | every 15 minutes | Asks Mercado Pago about charges no notification arrived for |
| `send_expiry_notifications` | every 10 minutes | Warns subscribers whose enrolment is about to end |
| `process_expirations` | every 10 minutes | Applies **Enrolment expiration action** when it does |

`process_events` is the one that matters most: the webhook only *queues*
notifications, deliberately, so that a slow site cannot make Mercado Pago retry
or give up. Nothing reaches a learner's enrolment until this task runs. If cron
is stalled, subscriptions sit in *Waiting for payment* however well the payment
went.

`reconcile_payments` is the safety net for notifications that never arrive, and
it is the reason a missed webhook is an inconvenience rather than a lost
enrolment.

---

## 7. Add the method to a course

*Course → Participants → Enrolment methods → Add method → Mercado Pago
Subscriptions.*

| Field | |
| --- | --- |
| **Custom instance name** | What learners see. Worth setting if a course has more than one |
| **Allow new subscriptions** | Closing it leaves existing subscriptions running; it only stops new ones |
| **Recurring amount** | Charged every cycle. The first charge happens as soon as the subscription is authorised |
| **Currency** | Follows the account; see above |
| **Billing frequency** + **Frequency type** | The cycle, in days or months. Mercado Pago accepts nothing else — a week is 7 days, a year is 12 months |
| **Free trial length** | 0 for none. During a trial the subscriber is enrolled and pays nothing |
| **Group for paying subscribers** / **Group during free trial** | Optional, and separate on purpose |
| **Grace period** | How long a subscriber keeps access after a charge fails |
| **Maximum subscribers** | 0 for no cap |
| **Assign role** | Granted while the subscription is paid up |
| **Start date** / **End date** | Core's enrolment period. Independent of the subscription's own billing cycle |
| **Send course welcome message** | Collected and stored, but **nothing sends it in this release**. See [`CHANGES.md`](../CHANGES.md) |

Everything the site settings configure arrives pre-filled. If a field comes up
blank that you expected a default in, that is a fault — say so, do not work
around it.

**Enabling the method is what triggers the credentials and HTTPS checks.** A
method you cannot enable is the plugin telling you something about the site, not
about the form; the message says which.

---

## 8. Confirm the result

```bash
php /path/to/moodle/public/enrol/mercadopagosub/cli/diagnose.php --checkaccount
```

`--checkaccount` calls Mercado Pago once to confirm the credentials actually
work and to report which account they belong to, which country it is registered
in and which currency it settles in. Run it after any credential change.

It is the only option that calls the API deliberately, but a plain run can still
reach it: resolving the currency reads the collecting account whenever the
`currency` setting is blank and the cache is cold.

The script also reports the installation, the capabilities, the site
requirements, the queued notifications and the scheduled tasks, and exits
non-zero only on a real failure. Add `--courseid=N` for a course's own enrolment
instances, and `--username=U` to evaluate the capabilities as that person rather
than as the admin — without `--courseid` no course is examined at all.

Whatever it says is wrong, [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) has the
fix.

---

## Running alongside `enrol_mercadopagocpro`

Both plugins run on one site with no conflict. Everything that could collide is
component-scoped by construction and was checked rather than assumed: the
credential names, the site settings, the database tables, the capabilities, the
scheduled task classes, the message provider, the webhook URL, the global
helper functions, the plugin name shown in *Add method*, and the enrol instance
rows themselves.

**The one thing you must do is give each plugin its own Mercado Pago
application**, each registered with its own notification URL — because there is
one notification URL per application and it cannot be set per subscription.

The two applications may belong to the same seller account. They do not have to
be different accounts, only different applications.

---

## Handling a data deletion request

When a subscriber asks to be deleted, Moodle's privacy tooling removes this
site's records. **It does not cancel the subscription at Mercado Pago**, which
carries on charging the payer every cycle.

Cancelling the preapproval in the seller's dashboard is part of handling the
request, and it has to be done by a person. Do it *before* deleting the record,
or you will have deleted the reference you need to find the subscription.

Note also that a subscription has **two** people in it: the subscriber, and
whoever pays. They are often the same person and sometimes are not. A deletion
request from the payer blanks the paying address and nothing else — the
subscriber's own enrolment is not theirs to delete. The privacy provider is
documented in [`HANDOVER.md`](HANDOVER.md).

---

## Upgrading

```bash
cd /path/to/moodle/public/enrol/mercadopagosub
git pull
php /path/to/moodle/admin/cli/upgrade.php
```

Read [`CHANGES.md`](../CHANGES.md) first. Then re-run the diagnostics.

## Uninstalling

Uninstalling through *Manage enrol plugins* drops this plugin's three tables and
everything in them: subscriptions, payment history, and the notification queue.

**Cancel every live subscription at Mercado Pago first.** Uninstalling here
stops nothing there; the charges continue, and you will have thrown away the
records that say who is being charged and for what.

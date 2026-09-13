# enrol_mercadopagosub — handover

State as of 2026-08-31, continuously updated since. **The design is frozen at
v1**: what follows is settled and should be implemented, not relitigated.
Reopen an item only if implementation produces evidence against it, and say
which evidence.

Everything asserted here was verified against the live Mercado Pago API or against
Moodle 5.2 source, not recalled.

Companion documents: `API-FINDINGS.md` holds the platform measurements and the
reportable documentation gaps. `probe.php` and `whoami.php` are the throwaway CLIs
that produced them.

## Coexistence with enrol_mercadopagocpro — confirmed 2026-09-12

Julio's decision: this plugin will run on the same Moodle instance as
`enrol_mercadopagocpro`, each with its own credentials and its own Mercado Pago
application. Checked against the actual code, not assumed — every axis that
could collide is already component-scoped, by construction, with nothing new
needed:

- **Credentials.** `MERCADOPAGOSUB_ACCESS_TOKEN`/`_PUBLIC_KEY`/`_WEBHOOK_SECRET`
  (env) and `$CFG->enrol_mercadopagosub` (config.php) — distinct names from
  `mercadopagocpro`'s own `MERCADOPAGOCPRO_*`/`$CFG->enrol_mercadopagocpro`,
  confirmed in `classes/credentials.php`.
- **Site settings.** `get_config('enrol_mercadopagosub', ...)` throughout —
  Moodle's config API is already component-scoped; no shared key is possible.
- **Database tables.** `enrol_mercadopagosub_sub`/`_payment`/`_event` — the
  component prefix is baked into every table name in `db/install.xml`.
- **Capabilities.** Every one is `enrol/mercadopagosub:*` (`db/access.php`).
- **Scheduled tasks.** Every classname is under `enrol_mercadopagosub\task\`
  (`db/tasks.php`) — cannot collide with `enrol_mercadopagocpro\task\*`.
- **Message provider.** `'expiry_notification'` is only ever referred to as
  `enrol_mercadopagosub/expiry_notification` by core, even if
  `enrol_mercadopagocpro` happens to use the same short name for its own —
  full identity always includes the component.
- **Webhook URL.** `/enrol/mercadopagosub/webhook.php`, structurally distinct
  from `/enrol/mercadopagocpro/webhook.php` by directory alone.
- **Global function names.** `webhook.php` declares
  `enrol_mercadopagosub_query_param()` — already prefixed; cannot collide with
  a same-purpose helper in the sibling plugin as long as it follows the same
  convention.
- **`pluginname`.** Already `"Mercado Pago Subscriptions"` — distinct from
  `"Mercado Pago Checkout Pro"`. This one is worth calling out by name: a real
  bug shipped in `enrol_mercadopagocpro` where its own `pluginname` string
  still read a stale, indistinguishable value, which would have shown two
  identical entries in a course's "Add method" dropdown once both plugins
  were installed together. This plugin never had that string wrong, but the
  lesson is exactly why this whole section exists rather than assuming
  coexistence is fine by default.
- **Enrol instance rows.** Each course gets a separate `enrol` table row per
  plugin (`enrol = 'mercadopagosub'` vs `'mercadopagocpro'`), each with its own
  `customint1`-`customint8`/`customchar1`-`customchar3` — Moodle's own
  architecture for multiple enrolment methods per course, not something either
  plugin has to coordinate.

**Confirmed, not just structurally sound but operationally required — each
plugin needs its own Mercado Pago application** for the notification URL to be
distinct (`API-FINDINGS.md`'s own finding that a Notification URL is
application-level, one per application). This was already known before this
session; Julio's decision to run both plugins together is what makes it a live
requirement rather than a hypothetical. **Nothing to build for this — it is a
Mercado Pago dashboard configuration step, not code**, but worth stating
plainly in `docs/INSTALL.md` when that gets written, since installing both
plugins with the same application by mistake would have each plugin's webhook
processor treating the other's notifications as `ignored` (harmless, per
`event_processor`'s own "not ours" branch) but silently losing real events for
whichever plugin's application wasn't actually registered.

## The privacy provider — written 2026-09-12

`classes/privacy/provider.php`, implementing `metadata\provider`,
`request\plugin\provider` and `request\core_userlist_provider`. Every signature
was checked against `MOODLE_502_STABLE` source in this session
(`public/privacy/classes/local/...`), not recalled, and `enrol_paypal`'s own
provider was read as the working precedent for an enrolment plugin that stores
a payer's email address alongside a subscriber's id.

**Two people per subscription, and the asymmetry that follows.** `payeremail`
frequently belongs to someone other than the subscriber — the third-party payer
case this plugin treats as first-class. When that address matches a site
account, both the export and the delete request have to reach it, so every
query here joins `{user} u ON u.id = s.userid OR LOWER(u.email) =
LOWER(s.payeremail)`. The two roles are then treated differently:

- **Export.** A subscriber gets the whole record — subscription, payments, and
  the webhook notifications carrying its preapproval id. A payer gets the
  billing view only: their own address, amount, currency, cadence, state,
  payments. The preapproval id, the external reference and the notification
  trail are withheld, because the subscription's identity is the subscriber's
  personal data, not the payer's.
- **Delete.** A subscriber's request removes the subscription row, its payment
  rows, and the event rows whose `resourceid` is its preapproval id. A payer's
  request only blanks `payeremail` on a subscription that belongs to someone
  else — deleting that row would destroy a third party's enrolment history to
  satisfy a request that was never about them. This is `enrol_paypal`'s own
  shape for `business`/`receiver_email`, arrived at independently here and then
  confirmed against it.

**Judgement call, flagged rather than settled: deleting a subscriber's row does
not stop the subscription at Mercado Pago.** It keeps charging the payer.
Nothing in a privacy deletion can prevent that — a deletion request is not a
place to make network calls that can fail or hang — so cancelling the
preapproval in the seller's dashboard is an operational step that has to
accompany a deletion request for a still-active subscriber. Notifications that
arrive afterwards are harmless: `event_processor` already marks an unknown
external reference `ignored`. **This belongs in `docs/TROUBLESHOOTING.md` and
in whatever the site tells its data protection officer**, and it is worth
Julio's explicit review — the alternative design (anonymise the row instead of
deleting it, keeping the financial record intact) is defensible too, and is
what a site with accounting-retention obligations would probably want.

**Two things deliberately not declared**, with the reasoning in the class
docblock so nobody re-opens it by accident: group membership, because
`groups_add_member()` is called without a component and the rows therefore
belong to `core_group`, which already exports and deletes them; and
`user_enrolments`/`role_assignments`, which belong to `core_enrol` and
`core_role`. `core_message` *is* declared — `enrol_plugin`'s expiry notice is
sent with `component = 'enrol_mercadopagosub'` (verified in
`public/lib/enrollib.php`), so the messaging subsystem holds records attributed
to this plugin.

**Housekeeping columns are not declared either, by decision:**
`sub.timesynced`, `payment.timecreated`/`timemodified`, and
`event.notificationid`/`requestid`/`attempts`/`processedat`/`lasterror`. They
describe the plugin's own bookkeeping, not the person. The omission is listed
in a comment inside `get_metadata()` so it reads as a decision. `dunningstage`
and `dunningsince` went the other way and *are* declared: they record that a
particular person fell behind on a payment. Core's own compliance test only
requires that a table with a `userid` field be covered at all and that every
declared string identifier resolves, both verified.

**Verified this session, not assumed:** all 51 `privacy:*` language strings
exist and none is unused; every declared column exists in `db/install.xml`;
`phpcs --standard=moodle-extra` reports 0 errors and 0 warnings for this file.

## The style pass and CI — 2026-09-12

**phpcs, first run ever against this tree** (phpcs 3.13.6 + `moodlehq/moodle-cs`,
from inside the plugin directory with an explicit `--standard=moodle-extra`):
50 errors and 4 warnings across 22 files. `phpcbf` fixed 49 mechanically —
a blank line after a class's opening brace in nearly every file under
`classes/`, multi-line call formatting in `lib.php` and `paymentlink.php`, a
blank line opening a control structure in `settings.php`. None of them carry
behaviour. **The tree is now at 0/0.**

Five needed a decision rather than a fix, and all five are recorded in the
code itself so they do not resurface:

- `webhook.php` has no login check and must not have one — the caller is
  Mercado Pago, authenticated by HMAC rather than by session. Silenced with
  `phpcs:ignore moodle.Files.RequireLogin.Missing` and the reason.
- `credentials::__debugInfo()` is a real PHP magic method the sniff's own list
  predates. **Gotcha worth keeping:** a `phpcs:ignore` placed between a
  docblock and its function *detaches the two* and trips
  `moodle.Commenting.MissingDocblock` instead; one placed above the docblock
  does not reach the signature. The working form is `phpcs:disable` /
  `phpcs:enable` wrapped around the whole method.
- `lib.php::update_instance()` only called `parent::update_instance()`. The
  sniff's "possible useless method overriding" was right; it was removed.
- Two inline comments reworded to start with a capital letter.

**`phpcs 4.x cannot run moodle-cs at all`** — it references a `T_PROPERTY`
constant 4.x does not define, and dies with a fatal error rather than a
finding. Pin 3.13.x anywhere this runs.

**Running phpcs from inside the plugin directory is not enough, and this cost
a red CI run to learn.** Some moodle-cs sniffs resolve the Moodle component
from the file's path, and simply do not fire when the plugin sits on its own.
`moodle.Files.LangFilesOrdering` is one: run from the plugin directory it
reported nothing, and the same tree checked at
`moodle/public/enrol/mercadopagosub` reported **36 warnings** about language
string ordering — enough to fail `moodle-plugin-ci phpcs --max-warnings 0`,
which is exactly how the first CI run failed. **Check the plugin where it is
installed**, not where it is developed:

    phpcs --standard=moodle /path/to/moodle/public/enrol/mercadopagosub

`phpcbf` fixed all 36 mechanically; the file's content is unchanged, only the
order of the `$string[...]` assignments, verified by comparing the two
files' resulting arrays key by key. Worth knowing for `lang/es` when it is
written: the sniff wants keys in order there too.

**CI: `.github/workflows/ci.yml`**, the same shape as
`enrol_mercadopagocpro`'s — deliberately, so that a difference between the two
files means something instead of being drift. PHP 8.3/8.4 × pgsql/mariadb,
`fail-fast: false`, the eight Marketplace precheck steps each guarded with
`if: ${{ !cancelled() }}`. The PostgreSQL leg matters more here than it did in
the sibling: the privacy provider's queries put `LOWER()` on both sides of an
email comparison and use a subquery inside a delete, which is where engines
diverge.

**First run, 2026-09-12 — one step red, everything else green.** `phpcs
--max-warnings 0` failed on the 36 language-ordering warnings described above
(plus one stale `InlineComment.NotCapital` already fixed locally by then).
`phplint`, `validate`, `savepoints`, `mustache`, `grunt` and `phpdoc` all
passed, and **PHPUnit ran the full suite on the runner: 89 tests, 235
assertions, green on PHP 8.3 + MariaDB 11.8.9**, which is the first
independent confirmation of the suite outside the machine it was written on.
The prediction recorded here before that run — that `phpdoc` and `validate`
were the likely first failures — was wrong; the language file was.

To switch it on: pushing the file is normally enough. If Actions is disabled
for the repository or the organisation, enable it at Settings → Actions →
General → *Allow all actions and reusable workflows*, and check the
organisation's own Actions policy first — an org-level restriction overrides
the repository setting. No secrets are needed; the workflow reads nothing but
the checkout.

## Marketplace readiness checklist — added 2026-09-12

Julio asked that everything learned bringing `enrol_mercadopagocpro` to a
publishable state be incorporated here too. This is that checklist, each item
checked against this plugin's actual current tree, not assumed carried-over.

**Naming — already satisfied.** `mercadopagosub` is 14 characters (limit is 20,
learned the hard way from `mercadopagocheckoutpro` at 22 failing outright under
strict SQL mode) and contains no underscore (`enrol_plugin::get_name()` splits
on `_`, which is what broke `enrol_mp_checkoutpro`). Both constraints were
already respected when this component was named, before this session.

**Not yet done, and larger than one session each:**

- **PHPUnit suite** — 2026-09-12: **127 tests, 345 assertions, green.** See
  "The PHPUnit suite" below. Still uncovered: `curl_transport` (needs a real
  HTTP exchange), `admin_setting_credential` and `form/subscribe_form` (both
  UI, and Behat's job), and `collector`'s own fetch path — see the note there.
- **Behat suite** — written 2026-09-12, **not yet executed**: see "The Behat
  suite" below for what was verified without a browser and what was not. The
  sibling's real-browser, real-HTTPS acceptance tests are
  what actually proved the payment flow works end to end, not just each unit
  in isolation. This plugin has more state machine surface to exercise
  (pending → trialing/active → overdue → ended, in both directions) than a
  single-payment plugin does.
- ~~**`cli/diagnose.php`**~~ — written and exercised 2026-09-12, see "The
  diagnostics script" below. The warning recorded here was right: three of its
  checks were wrong on first run, and only running it found them.
- ~~**phpcs, `moodle-extra` standard**~~ — done 2026-09-12, see "The style
  pass" below. The tree is at 0 errors, 0 warnings.
- ~~**Privacy provider.**~~ Written 2026-09-12 — see the section above.
- ~~**CI**~~ — `.github/workflows/ci.yml` added 2026-09-12, see "CI" below.
  Never executed, so treat its first run as a measurement, not a formality.
- **README, `docs/INSTALL.md`, `docs/TROUBLESHOOTING.md`, `CHANGES.md`.** None
  exist yet (`docs/TESTING.md` does, as of 2026-09-12 — it carries the
  config.php a test clone needs, which was lost once already when the clone
  carrying it was destroyed). `docs/INSTALL.md` already has a running list of notes owed to it,
  below — that list should become the actual document, not stay a scratch pad.
- **Screenshots**, required by the plugins directory listing itself, not by
  the code. Cannot happen before there is a working UI to screenshot, which
  means after the Behat suite is green on a real site, not before.

**Confirmed already in place, no action needed:** GPL v3 headers on every file
written so far; `version.php`'s `$plugin->maturity = MATURITY_ALPHA` and
`$plugin->release = 'v0.1.0'`, both honest about where this actually stands;
no vendored SDK to track against `thirdpartylibs.xml`, since this plugin talks
to the Mercado Pago API directly over `curl_transport` rather than through a
vendored library — a design decision already recorded below, and one that
sidesteps an entire category of the sibling's own maintenance burden (SDK
version drift, composer artefacts accidentally committed).

**Closed 2026-09-12:** `$plugin->requires = 2026042002` is correct. Checked
against `public/version.php` at the tip of `MOODLE_502_STABLE` (commit
`a987843`, "weekly release 5.2.2+"), where `$version = 2026042002.04` — the
integer part is the branching date, which is the value a plugin requires.
`version.php` now records this instead of the VERIFY note.

## Where this stands

Written and reviewed: `version.php`, `db/install.xml`, `db/access.php`,
`db/caches.php`, `db/tasks.php`, `db/messages.php`, `lib.php`, `settings.php`,
`subscribe.php`, `paymentlink.php`, `webhook.php`,
`lang/en/enrol_mercadopagosub.php`, and the service layer under `classes/`:
`util`, `credentials`, `transport`, `curl_transport`, `http_response`,
`api_client`, `api_exception`, `collector`, `admin_setting_credential`,
`subscription_service`, `webhook_signature`, `event_processor`,
`payment_reconciler`, `form/subscribe_form`, `task/send_expiry_notifications`,
`task/process_expirations`, `task/process_events`, `task/reconcile_payments`,
`privacy/provider`.

Not written yet: `cli/diagnose.php`, tests, docs.

**`payment_reconciler` is written — every table and every state transition this
plugin's schema anticipated now has something actually writing to it.**
`enrol_mercadopagosub_payment` (present in `db/install.xml` since before this
session's involvement, untouched until now) gets a row per authorized payment,
upserted by `mppaymentid` so a repeat sweep updates rather than duplicates. It
runs on its own fixed schedule — every 15 minutes — sweeping every row with
`state` in `trialing`/`active`/`overdue`, deliberately not triggered by any
specific webhook. This follows directly from the reasoning already recorded
against `event_processor`: webhook delivery is measurably unreliable
(`version` gaps mean some notifications never arrive), so a subscription this
plugin has not heard from in a while must still get checked on its own.

**`event_processor` and `payment_reconciler` now share one sync path,
deliberately.** `sync_from_response()` and `sync_enrolment()` were made public
on `event_processor` specifically so `payment_reconciler` calls the same
enrolment/group logic after its own independent `GET /preapproval/{id}`,
rather than a second, divergent copy existing. A subscription's access should
never depend on which of the two paths happened to notice a change first.

**Overdue detection follows from what is left after both syncs run**, and
this is a new construction, not something Mercado Pago's own API states
directly: if `nextpaymentdate` (just re-synced from a fresh `GET`) has passed
and no `enrol_mercadopagosub_payment` row with `status = 'processed'` covers
it, local `state` becomes `overdue` — `dunningstage` resets to 0 and
`dunningsince` records the moment. Recovery is symmetric: once a covering
payment is found, `state` returns to `active`. **`'processed'` is the only
authorized-payment status this plugin has ever actually measured for a
completed charge** (`API-FINDINGS.md` §3) — no other status value has been
observed, so this heuristic treats every other status as "not yet paid for
this period," which is conservative rather than confirmed. **To confirm on a
real site:** what an authorized_payment record's `status` reads during an
actual missed or retried charge — this plugin has only ever seen one that
succeeded on the first attempt.

**`periodstart`/`periodend` on each payment row are this plugin's own
bookkeeping, not something the API returns.** Computed as the charge's
`debit_date` through one billing period later (`frequency`/`frequencytype`,
already on the subscription row), matching `API-FINDINGS.md` §3's own framing
that `next_payment_date` can be treated directly as the end of a paid period,
anchored to authorisation rather than creation. Left at 0 for any payment
whose status is not `'processed'`, for the same reason the overdue heuristic
above is conservative rather than assumed.

**Three things from the previous session's judgement calls remain exactly as
flagged, now touched by more code and worth re-reading before sign-off:**
trial detection is still deferred, not implemented; `signaturestatus` still
does not gate whether an event gets acted on; `ended` still only withdraws
group membership, leaving the core enrolment's `timeend` to lapse naturally.
Nothing in this session's reconciliation work resolved any of the three — it
built on top of all of them.

## Julio's review, 2026-09-02 — two of the three flagged judgement calls resolved

**`signaturestatus` now gates action — Julio overruled the previous session's
reasoning explicitly.** `event_processor` no longer dispatches an event unless
`signaturestatus === 'verified'`. `failed` and `absent` rows are still written
(attempts incremented, a `lasterror` set) but never acted on; they need
`requeue_unverified()` called by hand once whatever caused them is fixed —
typically an empty `webhooksecret`. **Operational consequence for testing:**
until the webhook secret is actually set in `settings.php`, every event will
sit as `failed` forever, dispatching nothing. `payment_reconciler` is
unaffected — it never reads webhook bodies at all, verified or not, so
`overdue` detection and payment recording keep working independently of this.

**`ended` after cancellation-with-a-payment-already-taken is confirmed as
designed: access continues until the paid period ends.** Julio resolved the
contradiction this file's own trial/cancellation notes contained (one passage
said continued access, another said immediate suspension) explicitly in favour
of continued access. No code changed for this — `event_processor`'s existing
behaviour (leave `timeend` to lapse via `process_expirations()`) already
matches it.

**Cancelling during an unfinished trial needs no new measurement, by Julio's
own design choice.** Rather than resolve whether Mercado Pago reports
`pending` or `authorized` while a trial is in progress, access during a trial
that gets cancelled before it ends simply continues until the trial's own end
date — the same `nextpaymentdate`-driven `timeend` mechanism already handles
this, since `nextpaymentdate` lands on the trial's end date regardless of
`mpstatus` (API-FINDINGS.md §2). This sidesteps the open trial-detection
question for this specific case; **trial detection itself remains deferred**
for the separate, still-open question of which local state
(`trialing` vs `active`) a currently-authorized subscription should show while
a trial is running.

**No second trial.** `subscription_service::has_had_trial()` checks, per
instance, whether this user has ever had a row with `trialfrequency` set —
including `ended` rows, since rows are never deleted. If so, the new
subscription is created without `free_trial` regardless of the instance's own
trial setting, and its own row correctly records no trial rather than a trial
it never actually got. Scoped to the course/instance, not the whole site,
matching Julio's decision note verbatim ("verificar si el usuario ya ha tenido
un trial en ese curso").

## Julio's testing and review, 2026-09-02 (second round)

**Correction to what this file said above: there was never a missing
self-cancellation flow.** Mercado Pago's own subscriber panel already offers
cancellation — Julio confirmed this from his own testing with a test buyer
account. The "real gap" flagged above was this session misreading the design:
`enrol/mercadopagosub:cancelsubscription` staying `manager`-only was already
correct. What a subscriber cancels through their own Mercado Pago account
reaches this plugin as an ordinary `subscription_preapproval` webhook, which
`event_processor` already handles.

**A different, genuine gap Julio's testing did surface: Moodle's own "Unenrol
me" self-service action did not touch the Mercado Pago subscription at all.**
`enrol/mercadopagosub:unenrolself` is granted to `student` (matching
`enrol_self`'s convention, `db/access.php`), and using it removed the user from
the course while leaving Mercado Pago charging them every cycle — Moodle and
the platform simply disagreed, silently. **Fixed by overriding
`unenrol_user($instance, $userid)` in `lib.php`.** This is the core hook every
unenrolment path routes through — self-service and manager-initiated alike —
so this override closes both at once. It cancels every non-`ended` local row
for that enrolid/userid at Mercado Pago, sets `endreason = 'cancelled_by_admin'`
with certainty (this plugin initiated it, no guessing needed here unlike the
webhook-driven path), and moves the user into the new cancelled group if one is
configured. `parent::unenrol_user()` always runs regardless of whether the
Mercado Pago call succeeded — a network hiccup must not block a Moodle
unenrolment. **Known residual gap, flagged not solved:** a row that fails to
cancel here is left in its current state, and nothing currently retries it.
This is the opposite problem from what `payment_reconciler` watches for (a
charge that should stop but might not, rather than one that should have
arrived and didn't), and is not covered by it.

**The third group Julio asked for is built — `customint8`, "cancelled or ended
subscriptions."** Same shape as `customint1`/`customint2`: a plain
group-picker on the instance form, no site-level meaning attached. Membership
is granted whenever local `state` becomes `ended`, from both places that can
cause that: `event_processor::sync_enrolment()` (a webhook or a reconciliation
sweep observing `mpstatus = cancelled`) and the new `unenrol_user()` override
above. What a site does with this group — content restrictions, nothing at
all — stays entirely outside the plugin, per Julio's own reflection below.

**One `endreason` question remains genuinely open, not resolved this
session:** whether Mercado Pago's `cancelled` status distinguishes a
subscriber cancelling in their own Mercado Pago panel from the platform
cancelling for its own reasons (persistent non-payment, most plausibly).
Nothing measured so far shows a field that would tell them apart — both land
on `endreason = 'cancelled_by_mp'`, the honest generic bucket for "not
something this plugin's own code initiated." Only the `unenrol_user()` path
gets to claim `'cancelled_by_admin'` with real certainty. Worth a targeted
`probe.php` run if this distinction ever needs to be surfaced to a site's own
support process — not attempted here, since Julio explicitly chose to avoid
that round of measurement this session for the trial-cancellation question,
and this is the same underlying gap, not a new one.

## The plugin/business boundary, per Julio's own reflection, 2026-09-02

Not yet acted on in code, recorded here because it should shape what gets built
next: Julio flagged that some of what his own decision notes described
(dedicated trial and cancelled-user groups, their naming, what content they
gate) is his business's own configuration of the plugin's existing generic
mechanism (group membership toggling on state transitions), not something the
plugin itself needs to know about. The plugin's job stops at "this state
transition happened, membership in whatever group this site configured now
matches it" — what a site does with a "cancelled" group, if it configures one
at all, is out of scope. The third group above follows this shape exactly: the
plugin only ever toggles membership, and Julio was explicit that whether any
other site finds it useful "no es algo que me compete" — it is offered as a
generic mechanism, not a business-specific feature.

**Discount/coupon support was raised and deliberately deferred**, as a real
feature for a future iteration, not this one — it touches the amount sent to
Mercado Pago and needs its own validation, which is a large enough change to
warrant its own design pass rather than folding into whatever session it gets
mentioned in.

**`event_processor` is written — this plugin now completes a subscription end
to end for the one notification type that carries usable identity.** It reads
`enrol_mercadopagosub_event` where `processstatus = 'queued'`, and for
`subscription_preapproval` events: re-fetches by `resourceid` (the preapproval
id), reads `external_reference` from that response, finds the local row by it,
syncs `mpstatus`/`nextpaymentdate`/`payerid`/`paymentmethodid`, and — new this
session — actually calls `enrol_user()`/`update_user_enrol()` and adds or
removes the paid/trial group (`customint1`/`customint2`) to match. Before this,
nothing in the plugin ever granted course access; syncing local subscription
state alone was not the same as a subscriber actually getting into the course.

**Trial detection is explicitly deferred, not guessed at.** Mercado Pago's
`status` only reports `pending`/`authorized`/`paused`/`cancelled` — nothing
measured so far distinguishes "authorized, inside its free trial" from
"authorized, paying normally" at the subscription level. Every transition into
`authorized` is treated as local `active`. `event_processor`'s own docblock
says this is unmeasured, not assumed; the `trialing` state and `customint2`
group can exist in the schema and the mform without this session inventing when
they should actually apply. **To confirm on a real site:** what a `GET
/preapproval/{id}` or a webhook reports about a subscription while a
`free_trial` is genuinely in progress.

**`payment`, `subscription_authorized_payment`, and `subscription_preapproval_plan`
events are marked `processed` immediately, doing nothing else.** This is a
deliberate design choice made this session, not previously frozen: rather than
have these notifications "trigger" a per-event reconciliation sweep, the
still-unwritten reconciliation task is meant to run on its own fixed schedule
against every locally active subscription, independent of any specific webhook
arriving. Webhooks are demonstrably unreliable as a trigger signal — `version`
gaps were measured with no data loss, meaning some deliveries never arrive at
all — so a fixed sweep is the only path that does not depend on delivery. This
reasoning is recorded here for review, not settled as frozen design.

**Acting on an event never depends on `signaturestatus`.** This was reasoned
through explicitly this session, using the same fact the schema's own comment
states: `payload` is "never a source of truth." Every action in
`event_processor` re-derives its facts from a fresh, credential-authenticated
API call keyed only by a resource id — never from anything the notification
body claimed. A forged notification therefore cannot make this plugin believe
something Mercado Pago itself did not just confirm; at worst it causes a
harmless, sooner-than-scheduled re-check of one of this site's own
subscriptions. `signaturestatus` is still recorded on every row for audit and
future anomaly detection, just not consulted before acting. Flagged for review,
since it is this session's judgement call and not a prior measurement.

**Withdrawing access on `ended` is limited to group membership, not the core
enrolment.** The enrolment's own `timeend` — already set to `nextpaymentdate`
plus the configured grace period — is left to lapse on its own via
`process_expirations()` rather than being cut short immediately on
cancellation, on the reasoning that a subscriber who cancels has already paid
for the period they are currently in. Also this session's judgement call, not
previously settled, and worth Julio's explicit sign-off given it is a real
product/billing decision, not a technical one.

**`webhook.php` is written and does exactly three things: verify, persist,
answer 200. It makes no business decision and looks up nothing.** This was the
one design question flagged as able to force a real change, and it is now
closed on measurement, not on this session's judgment — see "This settles
webhook.php's handling, definitively" above, from the capture sessions.

**The signature manifest is confirmed, not assumed — it was explicitly left
unverified in an earlier session and that gap is now closed.** `manifest =
"id:{data.id};request-id:{x-request-id};ts:{ts};"`, HMAC-SHA256 with the
webhook secret, hex digest compared against `v1` with `hash_equals()`. Checked
against Mercado Pago's own developer documentation and cross-referenced with
independent third-party implementations, all agreeing. `webhook_signature`
lowercases the id defensively before hashing — one source did this, none of
this plugin's own captures showed it would matter (every id captured was
already lowercase hex), and it costs nothing to keep.

**`data.id` is read from the raw query string, not `$_GET`.** PHP rewrites a
`data.id` query key to `data_id` before user code ever sees it — a genuine gotcha
that would have silently broken every signature check if missed. `webhook.php`
parses `$_SERVER['QUERY_STRING']` itself rather than trusting the superglobal.

**Deliberately not using `ABORT_AFTER_CONFIG`.** `enrol_mercadopagocpro`
shipped exactly the mistake of defining that constant at all — which aborts
setup regardless of its value — while apparently intending the opposite. Rather
than risk a second, different bootstrap-order problem (whether autoloading and
`get_config()` are reliably available in that reduced mode, which this project
has not measured), `webhook.php` takes the full, ordinary bootstrap and
`require_once`s the three class files it needs directly by path, so its
correctness does not depend on how much of `setup.php` ran.

**Signature verification never blocks a response.** A `failed` or `absent`
status is written to the row and the endpoint still answers 200 — enforcement
happens when the processing task decides whether to trust a row, not at the
door. This is what lets the endpoint be genuinely decision-free while still
giving whoever writes that task everything needed to fail closed on it later.

**No deduplication of deliveries.** Mercado Pago is known to resend the same
logical event (`version` gaps observed with no data loss), and this file simply
inserts a new row every time, matching "no decisions in the request" literally.
Idempotency is the processing task's problem to solve by re-fetching current
state via `GET`, not this endpoint's problem to solve by guessing which
deliveries are duplicates.

**`subscription_service` is written, and `subscribe.php` is now functional end
to end.** It builds the request body exactly to the shape `probe.php`'s
`baseline_body()` measured as working — `reason`, `external_reference`,
`payer_email`, `auto_recurring` (`frequency`, `frequency_type`,
`transaction_amount`, `currency_id`, plus `free_trial` when `customint3 > 0`),
`back_url`, `status: "pending"`. `notification_url` is deliberately not sent —
API-FINDINGS.md §1 measured it as silently discarded. `util::make_reference()`
and `util::parse_reference()` already existed from an earlier session and are
used as written, not reimplemented.

`guest_site_mismatch` is caught by `api_exception::get_api_code()` and rethrown
as a learner-facing `moodle_exception` using the `error:mismatchedsite` string
that already existed in the lang file. Every other `api_exception` is left
unwrapped and propagates as-is — wrapping it would throw away the platform's own
error code and body that `api_exception` already carries for whoever catches it
further up.

**`init_point` has no column.** It varies in shape by site and drops its
`&activation=true` once authorised (API-FINDINGS.md §11), so it is not
reconstructable from other columns and is not worth a dedicated one either. It
is stored, verbatim, inside the existing `extras` JSON column — the column the
schema itself describes as "for anything that does not warrant a column."
`paymentlink.php` reads it from there.

**Do not run `subscribe.php` against production credentials until `webhook.php`
exists.** It now makes a real, tested-shape Mercado Pago API call — a
subscriber can genuinely pay — and nothing yet updates the local row when they
do, since that is `webhook.php`'s job.

**`paymentlink.php` reads, never calls the API.** It decodes `initpoint` from
`extras` on the local row and shows it two ways at once — a readonly text field
for copying, and a direct link for opening — deliberately, because the frozen
design says payer and learner are routinely different people and this page
cannot know in advance which one is looking at it. The status line shown is
this plugin's own last-known local `state`, not a live check: nothing exists
yet that would update it after creation, since that is `webhook.php`'s job.

**Access is owner-or-capability, not capability alone.** The subscriber who
owns the row can always see their own payment link; anyone else needs
`enrol/mercadopagosub:viewsubscriptions` or `:manage`. This matters because the
URL carries only a numeric `subid` — nothing else identifies the request, so
the capability check is the only thing stopping one subscriber from viewing
another's live checkout link by guessing or incrementing the id.

**`subscribe_form` asks for one field that matters** — `payeremail` — prefilled
from this user's most recent `enrol_mercadopagosub_sub` row **across all
instances**, not scoped to this course, on the reasoning that what matters is
which address a person tends to pay from, not which course they last paid on.
Falls back to their Moodle account email when they have no prior row. A
`payeremailisthirdparty` checkbox is present and purely informational — nothing
in the code branches on it. It exists because ticking a box that says "someone
else is paying" before typing an address, catches the "typed my own address out
of habit" mistake that `API-FINDINGS.md` §12 establishes cannot be corrected
afterwards (`payer_email` is immutable once the subscription exists). If this
turns out to want real behaviour later (e.g. a separate payer-name field), that
is a genuine scope decision, not an oversight.

**`subscribe.php` calls `can_subscribe()` twice**, once before rendering the
form and once after a valid submission. The second call is not redundant: the
form's own render-to-submit window is exactly when a second tab, or simply time
passing, could put the user in a state `can_subscribe()` would now refuse (most
concretely: they already started a subscription in another tab in the
meantime).

**Correction to what this file said in the previous session:** `expiredaction`
does not need `process_expirations()` overridden in `lib.php`. Confirmed against
current MoodleDev documentation
(moodledev.io/docs/.../apis/plugintypes/enrol): "*Plugins that set `timeend`...
may want to specify expiration action and optional expiration notification
**using** `enrol_plugin::process_expirations()` and
`enrol_plugin::send_expiry_notifications()` methods*" — both already contain
working logic in the base class. What MDL-66786 actually shows is a plugin that
declared the setting but never registered a scheduled task to call the method
that reads it. `db/tasks.php` now registers two such tasks, each a thin wrapper
calling the corresponding inherited method with a `text_progress_trace`. No
override was added to `lib.php`, and per the above, none should be.

**`db/messages.php`** declares one message provider, `expiry_notification` —
the same identifier `enrol_self` and `enrol_manual` use for the same core
method, which is the convention core expects rather than an arbitrary choice.
The four `expirymessage*` strings it sends through were added to the lang file,
reworded for a subscription context: the enrolled-user body explicitly notes
that this concerns the enrolment period, not the Mercado Pago subscription's own
billing, since those two clocks are independent in this design.

**Deliberately not added yet:** a Mercado Pago reconciliation task (sweeping
`authorized_payments/search` for each locally known active subscription,
mirroring `reconcile_payments` in `enrol_mercadopagocpro`). It depends on the
local subscription state table and `subscription_service`, neither of which
exists. Registering a task with nothing real to run would repeat the exact
mistake `expiredaction` made before this session — see `db/tasks.php`'s own
docblock.

**`settings.php` is deliberately narrower than a full site-defaults page.** It
covers credentials (with `admin_setting_credential`, a small subclass that calls
`collector::forget()` when the access token actually changes — the cache has no
other way to notice), `expiredaction`, `expirynotifyhour`, and the currency
override. Instance-level defaults (role, billing frequency, grace period, welcome
message) are not registered as admin settings; `defaults_for_new_instance()`
already falls back to a coded default via `$this->get_config($name, $default)`
when no setting exists, so this is a real gap only once a site needs to change
one of those defaults from the UI, not before.

**`expirynotifylast` was considered and deliberately left out of `settings.php`.**
It does not appear as an `admin_setting` in any enrol plugin checked
(`enrol_manual`, `enrol_self`, `enrol_credit`, `enrol_apply`). Everything found
suggests it is bookkeeping the core expiry-notification method writes to itself
via `set_config()` on every run, not something an administrator sets from a
screen — consistent with `send_expiry_notifications()` now actually running via
`task/send_expiry_notifications.php`.

Nothing in the tree has been run inside Moodle yet. No file has been executed, no
suite exists, and the plugin has not been installed on a site. Treat every class
as reviewed-but-unrun.

## Settled decisions, and why

**Subscription model: plan-less, `status: "pending"`, redirect to `init_point`.**
The alternative with an associated plan requires `card_token_id` when driven from
a server, which would put card handling inside Moodle. Measured, not assumed.

**No vendored SDK.** Four endpoints, all exercised over plain HTTP during design.
`transport` is an interface and `curl_transport` implements it, so the decision is
reversible by writing one class. The interface contract that matters: a transport
must not throw on a 4xx, because the platform's error body is the only diagnostic
it gives.

**The payer address is binding and immutable.** Only an account holding the
declared `payer_email` can authorise the subscription. `PUT` accepts a new address,
returns 200, and discards it without touching `last_modified`. Correction means
cancel and recreate. The plugin must therefore ask for the address, store it in its
own column, and offer a self-service way to change payer.

**Payer and learner are routinely different people.** An employer or client company
pays for someone else's course. Consequences: `init_point` must be presented as a
copyable link, not only as a redirect; email addresses are not redacted, because
they are billing data needed for support; and the plugin communicates only with the
learner. If the payer stops paying, the learner loses access and sorting that out
is the learner's problem, not the site's.

**Currency follows the collecting account, not a country list.** `collector` reads
the account endpoint, caches it, and derives the currency from `site_id`. Freezing
a country list is what has produced one Mercado Pago plugin per country in the
directory. An unrecognised site falls back to a site setting.

**Financial capabilities default to `manager` only**, departing from `enrol_self`,
which grants `:config` to `editingteacher`. Documented in `db/access.php` including
how to revert.

**Local state machine: `pending`, `trialing`, `active`, `overdue`, `ended`.**
`ended` is terminal with an `endreason`; returning means a new row at current
prices. That rule removes the undocumented case of resuming a paused subscription
after `next_payment_date` has passed, because the plugin never resumes.

**Mercado Pago `paused` is not used.** Only `cancelled`. Everything between "still
a subscriber" and "gone" is represented in Moodle.

## The account endpoint, measured 2026-08-31

`GET /users/me` with a test seller's own credentials, HTTP 200. Complete key list:

    id, nickname, registration_date, first_name, last_name, gender, country_id,
    email, identification, address, phone, alternative_phone, user_type, tags,
    logo, points, site_id, permalink, seller_experience, bill_data,
    seller_reputation, buyer_reputation, status, company, credit, context,
    registration_identifiers, test_data

Three things follow, and all three are now reflected in `collector`.

**There is no currency field.** Not at the top level and not nested. The
`SITE_CURRENCY` mapping stays; it cannot be replaced by reading the account. This
closes the open question that stood against it.

**Test accounts identify themselves structurally.** `tags` contains `test_user`,
and a `test_data` object carries `test_user: true`, `is_custom_test_user`,
`client_id` and `user_owner`. `is_test_account()` now reads `test_data.test_user`
first and `tags` second, keeping the `TESTUSER` nickname prefix only as a fallback.
The prefix alone was the wrong test: `is_custom_test_user` exists, so a test
account need not carry a generated nickname. Not yet measured: what a **real**
seller account returns for these keys. Absence is handled — it falls through to
false — so the failure mode is a missed warning, never a false one.

**The record is full of personal data.** `email`, `identification` (DNI number),
`address` and `phone` all come back. `collector::load()` used to cache the response
whole. It now reduces to five fields before caching, and the raw array goes out of
scope immediately.

Also confirmed here, matching `API-FINDINGS.md` §8: `email` is present on
`/users/me` and follows `test_user_<nickname digits>@testuser.com` —
`TESTUSER457645270959939118` → `test_user_457645270959939118@testuser.com`. It
remains unobtainable for a buyer test account, which has no application of its own.

**The real-seller case is now measured, closing the item this file previously
listed as unconfirmed.** `GET /users/me` against Julio's own production credentials
(2026-08-31) returned neither `test_data` nor a `test_user` tag — the key list for
a real account is shorter, missing that object entirely, and `tags` holds only
`normal`, `messages_as_seller`, `user_product_seller`. The nickname (`JTENTOR`)
also does not start with `TESTUSER`. All three signals in `is_test_account()`
agree on `false`, so the fallback chain needs no further change. The real account
also carries two fields the test account did not: `secure_email` and `thumbnail`,
neither of which this plugin has a use for; `reduce()` already discards anything
outside its five kept fields, so no change was needed there either.

## Moodle 5.2 signatures, verified against source

    enrol_page_hook(stdClass $instance)          // #[\Override]; builds output with
                                                 // core_enrol\output\enrol_page and
                                                 // core\output\single_button, named args
    can_self_enrol($instance, $checkuserenrolment = true)  // returns true|string|false
    is_self_enrol_available($instance)                     // same convention
    use_standard_editing_ui()
    can_add_instance($courseid)
    edit_instance_form($instance, MoodleQuickForm $mform, $context)
    edit_instance_validation($data, $files, $instance, $context)
    validate_param_types($data, $rules)
    enrol_user($instance, $userid, $roleid = null, $timestart = 0, $timeend = 0,
               $status = null, $recovergrades = null)
    update_user_enrol($instance, $userid, $status = null, $timestart = null,
                      $timeend = null)
    process_expirations(progress_trace $trace, $courseid = null)
    send_expiry_notifications($trace)
    send_course_welcome_message_to_user(stdClass $instance, int $userid,
        int $sendoption, ?string $message = '', ?int $roleid = null): void

`process_expirations()` and `send_expiry_notifications()` both already contain
working logic in the base `enrol_plugin` class — confirmed against current
MoodleDev docs, see the correction note near the top of this file. What each
needs from the plugin is a setting to read (`expiredaction`, `expirynotifyhour`)
and, critically, a scheduled task that actually calls it — `db/tasks.php` now
does the latter.

Welcome message placeholders substituted by core, confirmed in `enrollib.php`:
`coursename`, `courselink`, `coursestartdate`, `profileurl`, `fullname`, `email`,
`firstname`, `lastname`, `courserole`. Julio's production template uses six of
these and works. `$a` is built by core and knows nothing about subscriptions, so
recurring details go in a separate plugin message rather than into the welcome.

Core welcome strings live in `core_enrol`: `customwelcomemessage`,
`customwelcomemessageplaceholder`, `customwelcomemessage_help`. The textarea needs
a dummy static group to be hideable — MDL-66251.

## Carried over from enrol_mercadopagocpro

The naming constraint that governs this component: the core `enrol.enrol` column is
`char(20)` and Moodle opens its database sessions in strict mode, so a longer name
fails the insert. `mercadopagosub` is 14. The plugin name must also contain no
underscore, because `enrol_plugin::get_name()` takes `explode('_', get_class($this))[1]`.

Two operational rules from that plugin's test cycle apply here unchanged. Server
environment variables outrank site settings in `credentials::resolve()`, so a test
harness has to clear them or it inherits production credentials. And `phpcs`/`phpcbf`
must be run with an explicit `--standard` and a `.phpcs.xml` that excludes any
vendored tree. Run it against the plugin *installed inside a Moodle tree*, not
from the plugin directory alone — see "The style pass and CI" above for the
sniffs that stay silent otherwise.

## Next iteration

**`can_subscribe()` and `enrol_page_hook()` are written.** `can_subscribe()` is
this plugin's own method, not a core override — `enrol_plugin` has no such
method — shaped after the `can_self_enrol()`/`is_self_enrol_available()`
convention: `true`, a string the learner reads, or `false` to show nothing. It
checks, in order: instance enabled, logged in and not a guest, the
`enrol/mercadopagosub:subscribe` capability, credentials and HTTPS still intact
(defence in depth — `edit_instance_validation()` already checked both at
save time), no existing non-`ended` row for this user on this instance, and
`customint5` (max subscribers) against a count of `trialing`/`active`/`overdue`
rows. It is the single authority: the eventual subscriber form must call it too,
rather than re-deriving the same checks, or the two will eventually disagree.

**A signature gap surfaced and was not resolved by guessing.** This file
previously recorded `enrol_page_hook()` as verified to build output with
`core_enrol\output\enrol_page` and `core\output\single_button`. This session
could not re-confirm the constructor of `core_enrol\output\enrol_page` against
actual Moodle 5.2 source — web search surfaced no source for it. Rather than
invent a plausible-looking call, `enrol_page_hook()` as written uses only
`$OUTPUT->box()` and `single_button`, both long-stable core output APIs. **To
confirm on a real site:** whether `core_enrol\output\enrol_page` exists in 5.2
and should replace this — check `public/enrol/self/lib.php`'s own
`enrol_page_hook()` on the actual installed source, which is a better source
than anything found this session.

1. ~~`settings.php`~~ — done.
2. ~~`db/messages.php` and `db/tasks.php`~~ — done, see above. Both scheduled
   tasks are thin wrappers around already-working `enrol_plugin` methods; no
   `lib.php` override was needed after all — see the correction note above.
3. ~~`enrol_page_hook()` and `can_subscribe()`~~ — done, see above. The
   country-restriction and third-party-payer explanation strings mentioned here
   originally belong to the subscriber form (item 4), not this page hook —
   `can_subscribe()` doesn't know the payer's country yet, only the subscriber
   form, where an email address is entered, can check that.
4. ~~The subscriber form~~ — done, see above.
5. ~~`subscription_service`~~ — done, see above.
6. ~~`paymentlink.php`~~ — done, see above.
7. ~~`webhook.php`~~ — done, see above. It only receives and queues.
8. ~~The processing task~~ — done for `subscription_preapproval`, see above.
   Enrolment and group sync are new and load-bearing; review the two judgement
   calls flagged above (`signaturestatus` not gating action, `ended` not
   shortening `timeend`) before treating either as settled.
9. ~~The Mercado Pago reconciliation task~~ — done, see above. Every table and
   transition the schema anticipated now has code behind it. The three
   flagged judgement calls (trial detection, `signaturestatus` not gating
   action, `ended` not shortening `timeend`) are unchanged and worth Julio's
   explicit review before anything here is called settled.
10. ~~The privacy provider~~ — done 2026-09-12, see "The privacy provider"
    above. One judgement call there is flagged and not settled: a subscriber's
    deletion removes the local row but cannot stop the subscription charging at
    Mercado Pago, and anonymising the row instead is a defensible alternative
    that a site with accounting-retention obligations would likely prefer.
11. Next: run the Behat feature on the HTTPS development site and fix what it
    finds, then the README, `docs/INSTALL.md`, `docs/TROUBLESHOOTING.md` and
    `CHANGES.md`, then the screenshots the plugins directory listing
    requires.

## The PHPUnit suite — started 2026-09-12

**127 tests, 345 assertions, green**, and actually executed rather than only
written: Moodle 5.2.2+ (commit `a987843`) on PHP 8.4.21 against PostgreSQL
16.13, and independently on the CI runner on PHP 8.3 against MariaDB 11.8.9.
One file per unit under test:

| File | What it pins down |
| --- | --- |
| `util_test` | redaction, the truncation cap, reference minting and parsing, timestamp parsing |
| `webhook_signature_test` | the documented manifest, tamper rejection, header parsing |
| `credentials_test` | config.php > environment > settings precedence, and that no token reaches a diagnostic in full |
| `event_processor_test` | enrolment on authorisation, idempotent replays, cancellation, foreign references, failure recording, group movement |
| `payment_reconciler_test` | payment upsert, the overdue rule and its recovery, sweep ordering and limit |
| `privacy_provider_test` | the subscriber/payer asymmetry in both export and deletion |
| `plugin_test` | `can_subscribe()` in every branch, instance defaults |
| `subscription_service_test` | the request body, free trials, reference minting, the site-mismatch error |
| `api_client_test` | endpoint paths, idempotency keys, error-body preservation, redaction |
| `collector_test` | currency per marketplace site, the cache and its record version |
| `tasks_test` | that db/tasks.php names classes that exist, and that nothing runs while the plugin is disabled |

Plus `tests/helper_trait.php` (site setup, instance/subscription/event
factories) and `tests/fixtures/mock_transport.php`, a scripted `transport`
implementation. Mocking at the `transport` seam rather than at `api_client`
is deliberate: it exercises `api_client`'s own error handling — the branch
that carries the platform's error body into the exception — instead of
stubbing it away.

**Conventions worth keeping.** `#[CoversClass(...)]` attributes, not `@covers`
docblocks: PHPUnit 11 emits a deprecation for metadata in doc-comments and
PHPUnit 12 drops it. `print_r()` is a forbidden function under moodle-cs, so
`credentials_test` checks the debug representation through `__debugInfo()`
directly. Moodle returns ids from the database as strings, so identity
comparisons against integer ids need an explicit cast — two privacy tests
failed on exactly that before being fixed.

**A second finding, about testability rather than behaviour.**
`collector::load()` builds its own `api_client` internally instead of
accepting one, which makes it the only service in this plugin whose network
path cannot be scripted from a test — `event_processor`,
`payment_reconciler`, `subscription_service` and `api_client` all take their
collaborator as an optional constructor argument. `collector_test` therefore
works from a seeded cache entry and from the paths that never call the API,
which leaves the fetch, the reduction of the account record and the
test-account detection uncovered. Giving `load()` an optional `?api_client`
parameter would close that, and changes nothing for existing callers — but it
is a change to working production code for the benefit of tests, so it is
recorded here rather than made unilaterally.

**One finding worth Julio's decision, recorded rather than fixed.** The
subscriber cap (`customint5`) counts only `trialing`/`active`/`overdue`, so a
`pending` subscription — a checkout in flight — does not hold a seat. It
blocks the person who owns it from starting a second one, but not anybody
else, so a course capped at one subscriber can have two checkouts running at
once and whichever authorises second gets a seat that was supposed to be
taken. `plugin_test::test_a_pending_subscription_counts_against_the_cap`
records the current behaviour and says so in a comment; if the cap is meant to
hold a seat during checkout, that test is the one to change first.

**How the suite was run, in case the numbers ever need reproducing.** It was
executed in a sandbox with no access to packagist, so `vendor/` was assembled
by hand from GitHub: PHPUnit 11.5.56 plus its dependency tree, with a
generated PSR-4 autoloader and two small shims for the `Composer\` classes
Moodle's own bootstrap probes for. Nothing in the plugin or in the tests knows
about any of that, and on a normal site `moodle-plugin-ci` or a plain
`composer install` is what should provide PHPUnit. **PHPUnit 12 cannot run
Moodle 5.2** — `PHPUnit\Framework\TestCase::__construct()` is final there and
`basic_testcase` overrides it — so pin 11.x.

## The diagnostics script — 2026-09-12

`cli/diagnose.php`, shaped after the sibling's but answering this plugin's own
question: *why can nobody subscribe to this course?* Seven sections —
installation, capabilities, site requirements, credentials, scheduled tasks,
current state, and an optional per-course check — each ending in OK, WARN or
FAIL with the fix spelled out. Exit status is 1 only when something is
actually broken; warnings alone exit 0, so it is safe to run from a monitoring
job.

**It was exercised against a real installed Moodle**, not only linted: a 5.2.2+
site installed in the sandbox with the plugin enabled, a course, a learner
account, and both a working and a deliberately broken configuration. That is
what found the following, all of which read as correct code until run:

- **Three false failures for a learner.** With `--username=alumno`, the
  configuration capabilities (`moodle/course:enrolconfig`,
  `enrol/mercadopagosub:config`, `can_add_instance()`) were reported FAIL —
  but a learner is *supposed* to lack them, and the script exited 1 on a
  perfectly healthy site. They are now warnings when a username was given and
  failures only when evaluating as the admin, and the course section is split
  into "Managing the method" and "Subscribing to it".
- **The capability that actually matters to a learner was not checked at all.**
  `enrol/mercadopagosub:subscribe` is what decides whether the button is ever
  shown; it is now the first check in the subscriber block.
- **The save simulation ran for users who cannot save.** Posting a form as a
  learner tells nobody anything; it is skipped with a note to re-run as admin.
- **`can_subscribe()` was skipped exactly when it was most wanted.** The
  instance list was read before `--tryadd` created one, so a course with no
  instance yet showed nothing. It is re-read afterwards.
- **A transport failure was reported as `HTTP 0`.** Measured on a host with no
  route to api.mercadopago.com: an exchange that never completed and a
  platform that refused the credentials are different problems — outgoing
  network versus the token — and reporting both the same way sends an
  administrator looking in the wrong place. They are now separate branches,
  the first carrying the curl error.

**Known limits.** `--checkaccount` is the only option that touches the
network, and it is opt-in for that reason. The script cannot tell whether the
notification URL is actually registered at Mercado Pago — that is dashboard
configuration with no API to read it back — so it prints the URL and says to
register it. And the verdicts it gives for a named user are that user's own:
run it twice, once as the teacher who cannot add the method and once as the
learner who cannot subscribe, because they fail for different reasons.

## The Behat suite — written 2026-09-12, NOT YET RUN

`tests/behat/enrol_mercadopagosub.feature`, nine scenarios, plus the data
generator they need. **Nothing here has been executed**: Behat needs a browser
driving a served site, and the scenarios that matter need real HTTPS, which
the sandbox this was written in cannot provide and `moodle-plugin-ci` cannot
either. Treat the first run on the HTTPS development site as the real test of
this file, and expect it to find something.

**Seven scenarios do not need HTTPS** — adding the method, the four validation
failures (amount not positive, amount not numeric, frequency below one,
negative trial), the credentials check that fires on enabling, and a manager
seeing the method with a subscriber present. They all keep "Allow new
subscriptions" at No, because that is what avoids the HTTPS guard while still
exercising the rest of the form.

**Two are tagged `@enrol_mercadopagosub_https`** and will only pass on a site
served over HTTPS with a certificate that validates, since Moodle curls
`$CFG->behat_wwwroot` from the CLI before running: the subscribe button
appearing for a learner, and a learner who already has a subscription being
told so instead. Run them with both conditions in one expression —
`--tags='@enrol_mercadopagosub&&~@enrol_mercadopagosub_https'` to skip them,
never a bare negation, which replaces the plugin tag rather than adding to it
and runs the whole of Moodle's own suite.

**The data generator is the part that could be verified, and was.**
`tests/generator/lib.php` creates subscriptions, payments and notifications
directly, because none of the states worth testing — active, overdue, ended —
can be reached through the interface without the platform charging somebody,
and without it a feature can only ever test the empty case.
`tests/generator_test.php` exercises it exactly as a feature would: 9 tests,
green, including that every generated state is internally consistent (an
`overdue` row really does have a past due date), that generated references
parse back to their instance and user, and that a generated notification is
one `event_processor` can actually resolve and act on. So if Behat fails on
the first run, the generator is not where to look first.

**What was checked without a browser:** every string the feature asserts on
exists in `lang/en` — which caught one wrong assertion, "You already have a
subscription to this course" against an actual message of "You already have a
subscription in progress or active for this course" — and every form label
used matches a real label from `edit_instance_form()`. What remains unchecked
is everything only a browser can tell: whether the steps resolve, whether the
selectors match, and whether the pages render what these scenarios assume.

**An HTTP 500 from the Behat vhost is the healthy answer, and `docs/TESTING.md`
said otherwise until 2026-09-13.** Once `$CFG->behat_*` is configured,
`public/lib/setup.php` matches any web request against `$CFG->behat_wwwroot`
(`behat_is_requested_url()`, on scheme, host, port and path) and refuses to
serve it: `behat_error()` → `testing_error()` echoes one line and, when
`$_SERVER['REMOTE_ADDR']` is set, sends `HTTP/1.1 500`. Before
`admin/tool/behat/cli/init.php` has run, that line is *"Install Behat before
enabling it"*; after it, and between runs, it is *"Behat is configured but not
enabled on this test site"*, because `test_environment_enabled.txt` only exists
while a run is in progress. The document told the reader to check the URL with
`curl -o /dev/null -w '%{http_code}'` and to expect a 200 — discarding the only
part of the response that carries the answer, and calling the wrong outcome
success. A 200 there actually means the request *missed* `behat_wwwroot` and
the production site answered. Corrected in section 2, with the three bodies
tabulated. Verified against 5.2 source, not recalled.

**`init.php` abandons everything if composer's self-update fails, and says
nothing about it — 2026-09-13.** `admin/tool/phpunit/cli/init.php` calls
`testing_update_composer_dependencies()` as its first act, which runs `php
composer.phar self-update` and, on a non-zero exit, `exit($code)`. No table is
touched; the environment silently stays where the previous run left it. On a
Debian stack with the tests run as `www-data`, composer's home is `/var/www`,
which `www-data` does not own, and the self-update dies on
`/var/www/.config/composer/keys.dev.pub`. The *first* run escapes it because
`composer.phar` does not exist yet, so the function downloads it and forces
`$selfupdate = false`; every run after that fails. **Always pass
`--disable-composer`** — it stops the self-update and the dependency upgrade,
but not the install when `vendor/` is missing.

**"initialised for different version" is about the tree, not about PHPUnit.**
`testing_util::is_test_data_updated()` compares
`\core\component::get_all_versions_hash()` — over every component's
`version.php` — against `$CFG->phpunit_dataroot/phpunit/versionshash.txt` and
the `phpunittest` config row. Dropping this plugin into the tree invalidates
the environment exactly as a version bump would. `init.php`'s own plugin list
is the diagnostic: `enrol_mercadopagosub` must appear between
`enrol_mercadopagocpro` and `enrol_meta`, and its absence there is proof the
environment predates the plugin. Both gotchas are now in `docs/TESTING.md`
section 3, along with the `E_STRICT` deprecation PHP 8.4 raises for
`$CFG->debug = (E_ALL | E_STRICT)`.

## To confirm on a real site, at first install

Three assertions in the tree are marked and unverified. None blocks writing code;
all three have to be settled before anything is called a release.

- ~~**`$plugin->requires = 2026042002`**~~ — verified 2026-09-12 against
  `MOODLE_502_STABLE` source. Closed.
- **`PUT` in `curl_transport`** is issued as a `POST` with `CURLOPT_CUSTOMREQUEST`,
  because core's `curl::put()` is shaped for file uploads and its handling of a raw
  JSON body is not confirmed for this release. The probe performed these `PUT`s with
  plain curl and they behaved as expected; the wrapper path has not been exercised.
- ~~**The account endpoint on a real seller account**~~ — measured 2026-08-31,
  see above. Closed.

## Still unmeasured

- **What the subscription webhooks carry — measured 2026-08-31, closed.**
  Every `subscription_preapproval` notification has this shape:

      {
        "action": "created" | "updated",
        "application_id": <int>,
        "data": {"id": "<preapproval_id>"},
        "date": "...",
        "entity": "preapproval",
        "id": <event id>,
        "type": "subscription_preapproval",
        "version": <int>
      }

  A second, distinct notification type exists and was not anticipated at design
  time: `type: subscription_authorized_payment`, `entity: authorized_payment`,
  same envelope shape, firing alongside a plain `type: payment` notification for
  every completed recurring charge. Both carry an id in a space this plugin does
  not otherwise use — neither is the preapproval id.

  **`external_reference` is absent from every webhook body**, of all three types.
  It does exist, but only inside the record returned by
  `GET /authorized_payments/search?preapproval_id={id}`, alongside `preapproval_id`
  itself, a nested `payment` object (`id`, `status`, `status_detail` — the same id
  the plain `payment` webhook carries), and two previously undocumented fields:
  `retry_attempt` and `next_retry_date`, meaning the platform runs its own retry
  cycle on a failed recurring charge independently of whatever this plugin's
  `expiredaction`/notice mechanism does. Worth a line in `docs/INSTALL.md`; does
  not change the frozen design.

  **This settles `webhook.php`'s handling, definitively:**

  - `subscription_preapproval` is the only notification type whose `data.id` is
    directly usable: it *is* the `preapproval_id` this plugin already stores.
    Handling it is a `GET /preapproval/{id}`, read `external_reference`, locate
    the local row, sync `status` and `next_payment_date`. No search needed.
  - `payment` and `subscription_authorized_payment` carry no field this plugin
    can search on without already knowing the preapproval id they belong to.
    Treat both as reconciliation triggers, not as carriers of identity: on
    receipt, do not attempt to resolve which subscription they belong to from
    the body. Let the scheduled reconciliation task sweep
    `authorized_payments/search?preapproval_id=X` for each locally known active
    subscription on its own cycle, mirroring `reconcile_payments` in
    `enrol_mercadopagocpro`. No design alternative was found that extracts
    identity from either notification more directly, and none is needed.

  `x-signature` arrives as `ts=<epoch>,v1=<hex>` on all three notification types,
  consistent with the published Checkout Pro shape. The exact manifest string
  (`id:...;request-id:...;ts:...;`) is what the reference documents; verify it
  against current docs before writing the verifier, not from memory.

  `version` is not a reliable ordering signal: gaps were observed (`0` then `2`,
  never `1`) with no corresponding loss of information — a `GET` is always the
  source of truth regardless of which versions were or weren't delivered.

  Also confirmed: `payer_id` is assigned once checkout starts, before
  authorisation completes — it was present while `status` was still `pending`.
  Do not treat a populated `payer_id` as evidence of authorisation.

  A `subscription_preapproval` "updated" notification was observed firing
  multiple times (`version 2`, then `version 3` roughly forty seconds later) for
  the same authorisation event, alongside the `subscription_authorized_payment`
  pair. An idempotent handler is not optional.

- **Resuming a paused subscription after `next_payment_date` has passed.** Out of
  scope by design, but worth knowing. Compressible with a 1-day frequency.

## Notes owed to `docs/INSTALL.md`

- **Handling a data deletion request for an active subscriber**: deleting the
  site's record does not cancel the subscription at Mercado Pago, which keeps
  charging the payer. The preapproval has to be cancelled in the seller's
  dashboard as part of handling the request. See "The privacy provider" above.

- **Running alongside `enrol_mercadopagocpro`**: each plugin needs its own
  Mercado Pago application in *Your integrations*, registered with its own
  Notification URL. See "Coexistence with enrol_mercadopagocpro" above for the
  full reasoning; this is the one step that is actual configuration, not code.
- The notification URL must be registered by hand in *Your integrations*. There is
  one per application, so a site running more than one Mercado Pago plugin needs
  one application per plugin. `notification_url` on the subscription is accepted
  and silently discarded.
- The multilang filter must be enabled, or the supplied welcome template renders
  both languages one after the other.
- Currency and country both follow from the collecting account: it decides what a
  course can charge and which country a subscriber's own account must belong to.
- A dedicated administrative role should hold the financial capabilities, and the
  teacher role should not.
- The guest payment path cannot be exercised in a test environment at all. Anyone
  building a test suite should know that before they try.

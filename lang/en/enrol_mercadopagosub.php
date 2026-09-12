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
 * English strings for enrol_mercadopagosub.
 *
 * This file is the source of truth. lang/es is derived from it, never the reverse.
 * Mercado Pago field names, event names and status values stay verbatim.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['assignrole'] = 'Assign role';
$string['cost'] = 'Recurring amount';
$string['cost_help'] = 'The amount charged on every billing cycle. Mercado Pago takes the first payment as soon as the subscription is authorised, so a subscriber who completes the checkout has already paid for the first period.';
$string['currency'] = 'Currency';
$string['currency_help'] = 'A Mercado Pago account belongs to one country and settles in that country\'s currency, so this follows from the credentials rather than being a choice the course makes. It also decides who can subscribe: a subscriber paying with a Mercado Pago account registered in a different country is refused when the subscription is created.

If your account settles in a currency this plugin does not recognise, set it in the plugin settings and it will be offered here. Nothing restricts this plugin to any particular list of countries.';
$string['currency:unknown'] = 'Not determined yet — check the credentials';
$string['enrolenddate'] = 'End date';
$string['enrolstartdate'] = 'Start date';
$string['error:apifailed'] = 'Mercado Pago rejected the request.';
$string['error:alreadysubscribed'] = 'You already have a subscription in progress or active for this course.';
$string['error:costnotnumeric'] = 'The amount must be a number.';
$string['error:costnotpositive'] = 'The amount must be greater than zero.';
$string['error:enrolenddate'] = 'The end date cannot be earlier than the start date.';
$string['error:frequencynotpositive'] = 'The billing frequency must be at least 1.';
$string['error:gracenegative'] = 'The grace period cannot be negative.';
$string['error:httpsrequired'] = 'This enrolment method cannot be enabled on a site served over plain HTTP. Mercado Pago requires HTTPS.';
$string['error:mismatchedsite'] = 'That email address belongs to a Mercado Pago account registered in another country. Use an address from an account in the same country as the course provider, or choose the single-payment option instead.';
$string['error:maxenrolledreached'] = 'This course has reached its maximum number of subscribers.';
$string['error:mustbeloggedin'] = 'You need an account on this site, and to be logged in, before you can start a subscription.';
$string['error:nocredentials'] = 'This enrolment method has no Mercado Pago credentials configured.';
$string['error:transport'] = 'Mercado Pago could not be reached.';
$string['error:trialnegative'] = 'The trial length cannot be negative.';
$string['error:unavailable'] = 'New subscriptions cannot be started right now. Contact the course administrator.';
$string['expirymessageenrollerbody'] = 'A subscription in the course \'{$a->course}\' will reach its enrolment end date within the next {$a->threshold} for the following users:

{$a->users}

To extend their enrolment, go to {$a->extendurl}';
$string['expirymessageenrollersubject'] = 'Mercado Pago subscription enrolment expiry notification';
$string['expirymessageenrolledbody'] = 'Dear {$a->user},

This is a notification that your enrolment in the course \'{$a->course}\' is due to reach its end date on {$a->timeend}.

This concerns the course enrolment period, not your Mercado Pago subscription itself: if your subscription is still being charged successfully, contact {$a->enroller} about extending your access.';
$string['expirymessageenrolledsubject'] = 'Mercado Pago subscription enrolment expiry notification';
$string['expiredaction'] = 'Enrolment expiration action';
$string['expiredaction_help'] = 'Action to carry out when an enrolment created by this method reaches its end date. This governs the core enrolment period (start/end date), not the subscription\'s own billing cycle or grace period, which this plugin manages separately through the subscription status.';
$string['frequency'] = 'Billing frequency';
$string['frequency_help'] = 'How many units pass between charges. Combined with the frequency type: 1 month, 7 days, 12 months.';
$string['frequencytype'] = 'Frequency type';
$string['frequencytype_help'] = 'Mercado Pago accepts days and months only. Express a weekly subscription as 7 days and a yearly one as 12 months.';
$string['frequencytype:days'] = 'Days';
$string['frequencytype:months'] = 'Months';
$string['gracedays'] = 'Grace period';
$string['gracedays_help'] = 'Extra days of access after a payment fails to arrive, before the enrolment is suspended. Set to 0 to end access as soon as the paid period does.';
$string['settings:accesstoken'] = 'Access token';
$string['settings:accesstoken_desc'] = 'Bearer credential used to call the Mercado Pago API. Overridden if $CFG->enrol_mercadopagosub or the MERCADOPAGOSUB_ACCESS_TOKEN environment variable is set — see credentials::resolve().';
$string['settings:credentials'] = 'Mercado Pago credentials';
$string['settings:credentials_desc'] = 'These are the last of three places this plugin looks for credentials, and the only one editable from this screen. config.php and the server environment both take precedence — see the class-level documentation in classes/credentials.php for why.';
$string['settings:expiry'] = 'Enrolment expiry';
$string['settings:publickey'] = 'Public key';
$string['settings:publickey_desc'] = 'Not used server side by this plugin today; kept for front-end work this design does not yet include.';
$string['settings:webhooksecret'] = 'Webhook secret';
$string['settings:webhooksecret_desc'] = 'Used to verify the x-signature header on incoming notifications. Left blank, this plugin can still create and read subscriptions, but webhook.php has nothing to verify signatures against.';
$string['maxenrolled'] = 'Maximum subscribers';
$string['maxenrolled_help'] = 'The largest number of people who may hold an active subscription through this method at once. Set to 0 for no limit.';
$string['messageprovider:expiry_notification'] = 'Mercado Pago Subscriptions enrolment expiry notifications';
$string['paidgroup'] = 'Group for paying subscribers';
$string['paidgroup_help'] = 'Subscribers join this group once a payment has been taken. Use it with activity restrictions to decide what paid access opens up. Leave as none if the course does not distinguish.';
$string['mercadopagosub:cancelsubscription'] = 'Cancel a subscriber\'s Mercado Pago subscription';
$string['mercadopagosub:config'] = 'Configure Mercado Pago subscription enrolment instances';
$string['mercadopagosub:manage'] = 'Manage enrolled users';
$string['mercadopagosub:subscribe'] = 'Start a Mercado Pago subscription';
$string['mercadopagosub:unenrol'] = 'Unenrol users from the course';
$string['mercadopagosub:unenrolself'] = 'Unenrol self from the course';
$string['mercadopagosub:viewsubscriptions'] = 'View subscriptions and payments';
$string['payeremail'] = 'Mercado Pago email address for payment';
$string['payeremail_help'] = 'The email address of the Mercado Pago account that will pay for this subscription. It does not have to be your own: an employer or a company can pay on your behalf, in which case enter their address here.

The account must be registered in the same country as the course provider. Only that account will be able to pay this subscription, and the address cannot be changed afterwards — if it is wrong, the subscription has to be cancelled and started again.';
$string['payeremailisthirdparty'] = 'Someone else is paying for me';
$string['paymentlink'] = 'Payment link';
$string['paymentlink_backtocourse'] = 'Back to course';
$string['paymentlink_help'] = 'Send this link to whoever is paying. They open it, sign in to the Mercado Pago account whose address you entered, and authorise the subscription. Your enrolment starts once the first payment goes through.';
$string['paymentlink_open'] = 'Open payment page';
$string['paymentlink_status'] = 'Current status: {$a}';
$string['pluginname'] = 'Mercado Pago Subscriptions';
$string['pluginname_desc'] = 'Recurring enrolment paid through Mercado Pago subscriptions. Payment is handled entirely on Mercado Pago; this site never sees card data.';
$string['privacy:path:subscriptions'] = 'Mercado Pago subscriptions';
$string['privacy:metadata:core_message'] = 'Enrolment expiry notices are sent through the messaging system, which keeps its own record of what was sent to whom.';
$string['privacy:metadata:mercadopago'] = 'Subscribing sends the data Mercado Pago needs to create the subscription and to identify the payment as belonging to this course. Payment itself happens entirely on Mercado Pago: card details are entered there and never reach this site.';
$string['privacy:metadata:mercadopago:payer_email'] = 'The address of the Mercado Pago account that will be charged. It is the only account allowed to pay this subscription.';
$string['privacy:metadata:mercadopago:external_reference'] = 'A reference minted by this site, identifying the subscription it belongs to. It contains no personal data itself.';
$string['privacy:metadata:mercadopago:reason'] = 'The description shown to the payer, which names the course being subscribed to.';
$string['privacy:metadata:mercadopago:back_url'] = 'The address on this site the payer returns to once the subscription is authorised.';
$string['privacy:metadata:enrol_mercadopagosub_sub'] = 'One row per subscription: who subscribed, which Mercado Pago account pays for it, and what state it is in. The subscriber and the payer are frequently different people.';
$string['privacy:metadata:enrol_mercadopagosub_sub:userid'] = 'The subscriber, who is enrolled in the course while the subscription is paid.';
$string['privacy:metadata:enrol_mercadopagosub_sub:enrolid'] = 'The enrolment method, and through it the course, the subscription belongs to.';
$string['privacy:metadata:enrol_mercadopagosub_sub:preapprovalid'] = 'The subscription identifier assigned by Mercado Pago.';
$string['privacy:metadata:enrol_mercadopagosub_sub:externalreference'] = 'The reference this site sent to Mercado Pago to recognise its own subscriptions.';
$string['privacy:metadata:enrol_mercadopagosub_sub:payeremail'] = 'The address of the Mercado Pago account paying for the subscription, which may belong to someone other than the subscriber.';
$string['privacy:metadata:enrol_mercadopagosub_sub:payerid'] = 'The payer identifier Mercado Pago assigns once checkout starts.';
$string['privacy:metadata:enrol_mercadopagosub_sub:paymentmethodid'] = 'Which payment method Mercado Pago charges. No card details are stored.';
$string['privacy:metadata:enrol_mercadopagosub_sub:state'] = 'Where the subscription stands on this site: waiting for payment, in a free trial, active, overdue or ended.';
$string['privacy:metadata:enrol_mercadopagosub_sub:mpstatus'] = 'The status Mercado Pago itself reports for the subscription.';
$string['privacy:metadata:enrol_mercadopagosub_sub:endreason'] = 'Why the subscription ended, when it has.';
$string['privacy:metadata:enrol_mercadopagosub_sub:amount'] = 'The amount charged on each billing cycle.';
$string['privacy:metadata:enrol_mercadopagosub_sub:currency'] = 'The currency the subscription is charged in.';
$string['privacy:metadata:enrol_mercadopagosub_sub:frequency'] = 'How often the subscription is charged.';
$string['privacy:metadata:enrol_mercadopagosub_sub:frequencytype'] = 'The unit the billing frequency is counted in.';
$string['privacy:metadata:enrol_mercadopagosub_sub:trialfrequency'] = 'How long the free trial lasted, when the subscription started with one.';
$string['privacy:metadata:enrol_mercadopagosub_sub:trialfrequencytype'] = 'The unit the free trial length is counted in.';
$string['privacy:metadata:enrol_mercadopagosub_sub:nextpaymentdate'] = 'When the next charge is due.';
$string['privacy:metadata:enrol_mercadopagosub_sub:enddate'] = 'When the subscription is set to stop, when an end date applies.';
$string['privacy:metadata:enrol_mercadopagosub_sub:dunningstage'] = 'How far a subscription with an unpaid charge has gone through the overdue sequence.';
$string['privacy:metadata:enrol_mercadopagosub_sub:dunningsince'] = 'When the subscription was first found to have an unpaid charge.';
$string['privacy:metadata:enrol_mercadopagosub_sub:timecreated'] = 'When the subscription was started.';
$string['privacy:metadata:enrol_mercadopagosub_sub:timemodified'] = 'When the subscription was last changed.';
$string['privacy:metadata:enrol_mercadopagosub_sub:timeauthorized'] = 'When the payer authorised the subscription at Mercado Pago.';
$string['privacy:metadata:enrol_mercadopagosub_sub:timeended'] = 'When the subscription ended.';
$string['privacy:metadata:enrol_mercadopagosub_sub:extras'] = 'Additional data returned when the subscription was created, such as the payment page address.';
$string['privacy:metadata:enrol_mercadopagosub_payment'] = 'One row per charge Mercado Pago reports against a subscription, used to decide which billing periods have been paid for.';
$string['privacy:metadata:enrol_mercadopagosub_payment:subid'] = 'The subscription the charge belongs to.';
$string['privacy:metadata:enrol_mercadopagosub_payment:mppaymentid'] = 'The payment identifier assigned by Mercado Pago.';
$string['privacy:metadata:enrol_mercadopagosub_payment:status'] = 'Whether the charge went through.';
$string['privacy:metadata:enrol_mercadopagosub_payment:statusdetail'] = 'Mercado Pago\'s own explanation of that status, which is what an administrator reads when a charge is rejected.';
$string['privacy:metadata:enrol_mercadopagosub_payment:amount'] = 'The amount charged.';
$string['privacy:metadata:enrol_mercadopagosub_payment:currency'] = 'The currency charged in.';
$string['privacy:metadata:enrol_mercadopagosub_payment:debitdate'] = 'When Mercado Pago took the charge.';
$string['privacy:metadata:enrol_mercadopagosub_payment:periodstart'] = 'The start of the period this charge paid for, worked out by this site rather than reported by Mercado Pago.';
$string['privacy:metadata:enrol_mercadopagosub_payment:periodend'] = 'The end of the period this charge paid for, worked out by this site rather than reported by Mercado Pago.';
$string['privacy:metadata:enrol_mercadopagosub_payment:payload'] = 'The record Mercado Pago returned for the charge, kept for diagnosis. Card, identification and name fields are removed before it is stored; the payer\'s address is not, because it is what an administrator needs to trace a rejected payment.';
$string['privacy:metadata:enrol_mercadopagosub_event'] = 'One row per notification Mercado Pago sends this site. The notifications carry identifiers rather than personal details, but a subscription notification names the subscription it is about.';
$string['privacy:metadata:enrol_mercadopagosub_event:topic'] = 'What kind of notification arrived.';
$string['privacy:metadata:enrol_mercadopagosub_event:resourceid'] = 'The Mercado Pago identifier the notification is about. For a subscription notification this is the subscription itself.';
$string['privacy:metadata:enrol_mercadopagosub_event:signaturestatus'] = 'Whether the notification\'s signature could be verified as genuinely coming from Mercado Pago.';
$string['privacy:metadata:enrol_mercadopagosub_event:processstatus'] = 'Whether the notification has been acted on yet.';
$string['privacy:metadata:enrol_mercadopagosub_event:receivedat'] = 'When the notification arrived.';
$string['privacy:metadata:enrol_mercadopagosub_event:payload'] = 'The notification as received, kept so that a delivery can be traced afterwards. Card, identification and name fields are removed before it is stored.';
$string['processeventstask'] = 'Process Mercado Pago subscription webhook events';
$string['processexpirationstask'] = 'Process subscription enrolment expirations';
$string['reconcilepaymentstask'] = 'Reconcile Mercado Pago subscription payments';
$string['sendexpirynotificationstask'] = 'Send subscription enrolment expiry notifications';
$string['state:pending'] = 'Waiting for payment';
$string['state:trialing'] = 'Free trial';
$string['state:active'] = 'Active';
$string['state:overdue'] = 'Payment overdue';
$string['state:ended'] = 'Ended';
$string['sendcoursewelcomemessage'] = 'Send course welcome message';
$string['sendcoursewelcomemessage_help'] = 'Sent once, when the enrolment first becomes active. A subscription still waiting for its first payment does not trigger it.';
$string['status'] = 'Allow new subscriptions';
$string['status_help'] = 'Whether people can subscribe through this method. Turning it off leaves existing subscriptions running: it only closes the door to new ones.';
$string['subscribe'] = 'Subscribe';
$string['subscriptionreason'] = 'Subscription: {$a}';
$string['triallength'] = 'Free trial length';
$string['triallength_help'] = 'How long before the first charge. Set to 0 for no trial. During a trial the subscriber is enrolled and pays nothing, so the enrolment must be able to end cleanly if the payment never arrives.';
$string['trialgroup'] = 'Group during free trial';
$string['trialgroup_help'] = 'Subscribers join this group for the length of the trial and move to the paying group once a payment is taken. What a trial group can see is decided by the course, through activity restrictions, not by this plugin.';
$string['trialtype'] = 'Trial length units';

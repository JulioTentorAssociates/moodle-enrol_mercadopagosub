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
 * Receives Mercado Pago subscription webhooks.
 *
 * Authenticates, persists, returns 200. Nothing else. No lookup of the local
 * subscription row happens here, no state changes, no decision about whether
 * this notification matters — that is entirely the job of the (not yet
 * written) processing task that sweeps enrol_mercadopagosub_event on its own
 * schedule. Every design conclusion this split rests on is measured, not
 * assumed — see HANDOVER.md, "This settles webhook.php's handling,
 * definitively":
 *
 *   - subscription_preapproval is the only notification type whose data.id is
 *     directly usable (it is the preapproval id this plugin already stores).
 *   - payment and subscription_authorized_payment carry no id this plugin can
 *     search on without already knowing the preapproval id they belong to.
 *   - external_reference is absent from every notification body, of all three
 *     types, without exception. A GET is always required afterwards.
 *   - version is not a reliable ordering signal; gaps were observed with no
 *     corresponding loss of information.
 *
 * None of that reasoning needs to run here. It belongs to the processing task,
 * which reads the queue this file writes to and does the actual GET calls.
 * This file's only job is to not lose a delivery and to always answer 200, so
 * Mercado Pago never has reason to retry because of anything on this end.
 *
 * Deliberately NOT using ABORT_AFTER_CONFIG. A sibling plugin
 * (enrol_mercadopagocpro) shipped exactly that mistake: defining the constant
 * at all aborts setup regardless of its value, which is not what "defined it
 * as false" was meant to do. Rather than repeat that defect, or introduce a
 * new one by relying on autoloading and get_config() being available in a
 * bootstrap mode this project has not measured, this file takes the full,
 * ordinary Moodle bootstrap and pays whatever overhead that costs. Class files
 * this endpoint needs are also require_once'd directly by path below, rather
 * than trusted to autoload, so correctness here does not depend on how much of
 * setup.php actually ran.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This endpoint is called by Mercado Pago, not by a browser session, and it
// authenticates the caller by HMAC signature rather than by Moodle login —
// see webhook_signature and the signaturestatus column. A login check here
// would reject every real notification.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require('../../config.php');

require_once(__DIR__ . '/classes/util.php');
require_once(__DIR__ . '/classes/credentials.php');
require_once(__DIR__ . '/classes/webhook_signature.php');

use enrol_mercadopagosub\credentials;
use enrol_mercadopagosub\util;
use enrol_mercadopagosub\webhook_signature;

/**
 * Reads one parameter from a raw query string, without PHP's superglobal
 * handling of it.
 *
 * PHP silently rewrites a top-level query key containing a dot — "data.id"
 * becomes $_GET['data_id'] — before user code ever sees it. Mercado Pago's own
 * documentation specifies the signature manifest's id as coming from the
 * "data.id" query parameter by that exact literal name, so this reads
 * $_SERVER['QUERY_STRING'] directly rather than through $_GET.
 *
 * @param string $querystring Raw, undecoded query string.
 * @param string $name Literal parameter name to find, dots and all.
 * @return string The decoded value, or an empty string if absent.
 */
function enrol_mercadopagosub_query_param(string $querystring, string $name): string {
    if ($querystring === '') {
        return '';
    }

    foreach (explode('&', $querystring) as $pair) {
        $keyvalue = explode('=', $pair, 2);
        if (count($keyvalue) === 2 && urldecode($keyvalue[0]) === $name) {
            return urldecode($keyvalue[1]);
        }
    }

    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$rawbody = file_get_contents('php://input');
$decoded = json_decode((string)$rawbody, true);
$decoded = is_array($decoded) ? $decoded : [];

$querystring = (string)($_SERVER['QUERY_STRING'] ?? '');
$dataid = enrol_mercadopagosub_query_param($querystring, 'data.id');
if ($dataid === '') {
    // Not measured to happen in this plugin's own captures — every delivery so
    // far carried it in both places — but the body is the documented fallback
    // if the query string ever does not.
    $dataid = (string)($decoded['data']['id'] ?? '');
}

$topic = (string)($decoded['type'] ?? '');
if ($topic === '') {
    $topic = enrol_mercadopagosub_query_param($querystring, 'type');
}

$requestid = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
$signatureheader = (string)($_SERVER['HTTP_X_SIGNATURE'] ?? '');
$notificationid = (string)($decoded['id'] ?? '');

$secret = credentials::resolve()->get_webhook_secret();

if ($secret === '' || $signatureheader === '' || webhook_signature::parse($signatureheader) === null) {
    // Absent covers two distinct situations the schema deliberately does not
    // distinguish further: no secret configured yet, or a request that simply
    // did not carry a usable signature at all. Both mean the same thing to
    // whatever processes this queue next — there is nothing to trust here —
    // and neither is grounds for anything other than logging it and moving on.
    $signaturestatus = 'absent';
} else if (webhook_signature::verify($signatureheader, $requestid, $dataid, $secret)) {
    $signaturestatus = 'verified';
} else {
    $signaturestatus = 'failed';
}

$payloadsource = $decoded !== [] ? util::redact($decoded) : ['_unparsed_raw' => (string)$rawbody];

$event = new stdClass();
$event->topic = $topic;
$event->resourceid = $dataid;
$event->notificationid = $notificationid !== '' ? $notificationid : null;
$event->requestid = $requestid !== '' ? $requestid : null;
$event->signaturestatus = $signaturestatus;
$event->processstatus = 'queued';
$event->attempts = 0;
$event->receivedat = time();
$event->processedat = 0;
$event->lasterror = null;
$event->payload = util::encode_for_storage($payloadsource);

$DB->insert_record('enrol_mercadopagosub_event', $event);

http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';

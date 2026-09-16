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
 * Version metadata for the Mercado Pago Subscriptions enrolment plugin.
 *
 * @package    enrol_mercadopagosub
 * @copyright 2026 Julio Tentor & Associates <https://juliotentor.com>
 * @author    Julio Tentor <jtentor@juliotentor.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'enrol_mercadopagosub';
$plugin->version = 2026091600;

// Verified 2026-09-12 against public/version.php at the tip of MOODLE_502_STABLE
// (commit a987843, "weekly release 5.2.2+"), where $version = 2026042002.04 —
// 2026042002 is that release's branching date, which is what a plugin requires.
//
// DELIBERATELY NOT TIGHTENED, and one of the two decisions held open until this
// plugin is proposed to the Moodle plugins directory. From v1.0.1 the supported
// release is 5.2.3 or later: Moodle's own advice is to skip 5.2.2, which has a
// grade-calculation defect 5.2.3 fixes. Naming the branch point here therefore
// lets Moodle install this plugin on 5.2.0–5.2.2, which is not a configuration
// anyone should run and not one this plugin is tested against.
//
// Tightening it means naming the exact build rather than the branch point —
// requires accepts the decimal part, so 5.2.3's own $version value is what
// would go here. Left as is until the release is being prepared, because the
// value has to be read off the tag being targeted, not off a memory of it.
// See "Decisions held open for the plugins directory" in docs/HANDOVER.md.
$plugin->requires = 2026042002;

// Also held open, and the other half of the same decision: ALPHA alongside a
// v1.0.x release string is a contradiction the plugins directory will notice.
// See the same section of docs/HANDOVER.md.
$plugin->maturity = MATURITY_ALPHA;

$plugin->release = 'v1.0.1';

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

namespace enrol_mercadopagosub\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Asks for the Mercado Pago address that will pay this subscription.
 *
 * One field that matters, deliberately: this plugin cannot know which Mercado
 * Pago account, if any, a person holds, and API-FINDINGS.md §7 and §12 establish
 * that the declared address is what the checkout enforces and cannot be changed
 * afterwards. Getting this typed correctly the first time is the whole job of
 * this form; nothing else here is worth the same care.
 *
 * @package    enrol_mercadopagosub
 * @copyright  2026 Julio Tentor
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subscribe_form extends \moodleform {
    /**
     * Builds the form.
     *
     * Expects $this->_customdata to carry 'instance' (the enrol instance record)
     * and 'prefill' (a string email address, possibly empty).
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $instance = $this->_customdata['instance'];
        $prefill = $this->_customdata['prefill'];

        $mform->addElement('hidden', 'id', $instance->id);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'payeremail', get_string('payeremail', 'enrol_mercadopagosub'), ['size' => 40]);
        $mform->setType('payeremail', PARAM_EMAIL);
        $mform->addRule('payeremail', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('payeremail', 'payeremail', 'enrol_mercadopagosub');
        if ($prefill !== '') {
            $mform->setDefault('payeremail', $prefill);
        }

        // Purely informational — nothing branches on this. The help text on the
        // field above already covers paying on someone else's behalf; this
        // exists because ticking a box that says so, before typing an address
        // that is not the subscriber's own, catches the "typed my own address by
        // habit" mistake this design has no way to detect afterwards (payer_email
        // is immutable once the subscription is created — API-FINDINGS.md §12).
        $mform->addElement(
            'advcheckbox',
            'payeremailisthirdparty',
            '',
            get_string('payeremailisthirdparty', 'enrol_mercadopagosub')
        );
        $mform->setType('payeremailisthirdparty', PARAM_BOOL);

        $this->add_action_buttons(true, get_string('subscribe', 'enrol_mercadopagosub'));
    }

    /**
     * Server-side validation.
     *
     * Syntax only. Whether the address belongs to an account, and whether that
     * account is on the right site, cannot be known here — API-FINDINGS.md §7
     * establishes that Mercado Pago itself does not check existence at creation,
     * only site membership, and only for an address that does resolve to an
     * account. That check happens when subscription_service calls the API, not
     * in this form.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!validate_email($data['payeremail'])) {
            $errors['payeremail'] = get_string('invalidemail');
        }

        return $errors;
    }
}

<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The main overflow configuration form.
 *
 * It uses the standard core Moodle formslib. For more info about them, please
 * visit: http://docs.moodle.org/en/Development:lib/formslib.php
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_overflow\anonymous;
use local_overflow\review;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');
/**
 * Module instance settings form.
 *
 * @package    local_overflow
 * @copyright  2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_overflow_admin_form extends moodleform {

    /**
     * Defines forms elements.
     */
    public function definition() {
        global $CFG, $PAGE;

        // Define the modform.
        $mform = $this->_form;

        // Add hidden fields
        $mform->addElement('hidden', 'sesskey', sesskey());
        $mform->setType('sesskey', PARAM_RAW);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'action', 'add');
        $mform->setType('action', PARAM_RAW);
    

        //$PAGE->requires->js_call_amd('local_overflow/warnmodechange', 'init',
        //    $mform['forcesubscribe']);

        // Adding the "general" fieldset, where all the common settings are showed.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('overflowname', 'local_overflow'), ['size' => '64']);
        if (!empty(get_config('local_overflow', 'formatstringstriptags'))) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $currentsetting = isset($this->customdata['anonymous']) ? $this->customdata['anonymous'] : 0;
        $possiblesettings = [
                anonymous::EVERYTHING_ANONYMOUS => get_string('anonymous:everything', 'local_overflow'),
        ];

        if ($currentsetting <= anonymous::QUESTION_ANONYMOUS) {
            $possiblesettings[anonymous::QUESTION_ANONYMOUS] = get_string('anonymous:only_questions', 'local_overflow');
        }

        if ($currentsetting == anonymous::NOT_ANONYMOUS) {
            $possiblesettings[anonymous::NOT_ANONYMOUS] = get_string('no');
        }

        if (get_config('local_overflow', 'allowanonymous') == '1') {
            $mform->addElement('select', 'anonymous', get_string('anonymous', 'local_overflow'), $possiblesettings);
            $mform->addHelpButton('anonymous', 'anonymous', 'local_overflow');
            $mform->setDefault('anonymous', anonymous::NOT_ANONYMOUS);
        }

        if (get_config('local_overflow', 'allowreview') == 1) {
            $possiblesettings = [
                    review::NOTHING => get_string('nothing', 'local_overflow'),
                    review::QUESTIONS => get_string('questions', 'local_overflow'),
                    review::EVERYTHING => get_string('questions_and_posts', 'local_overflow'),
            ];

            $mform->addElement('select', 'needsreview', get_string('review', 'local_overflow'), $possiblesettings);
            $mform->addHelpButton('needsreview', 'review', 'local_overflow');
            $mform->setDefault('needsreview', review::NOTHING);
        }

        // Attachments.
        $mform->addElement('header', 'attachmentshdr', get_string('attachments', 'local_overflow'));

        $choices = get_max_upload_sizes($CFG->maxbytes, $CFG->maxbytes, 0, get_config('local_overflow', 'maxbytes'));
        $choices[1] = get_string('uploadnotallowed');
        $mform->addElement('select', 'maxbytes', get_string('maxattachmentsize', 'local_overflow'), $choices);
        $mform->addHelpButton('maxbytes', 'maxattachmentsize', 'local_overflow');
        $mform->setDefault('maxbytes', get_config('local_overflow', 'maxbytes'));

        $choices = [
            0 => 0,
            1 => 1,
            2 => 2,
            3 => 3,
            4 => 4,
            5 => 5,
            6 => 6,
            7 => 7,
            8 => 8,
            9 => 9,
            10 => 10,
            20 => 20,
            50 => 50,
            100 => 100,
        ];
        $mform->addElement('select', 'maxattachments', get_string('maxattachments', 'local_overflow'), $choices);
        $mform->addHelpButton('maxattachments', 'maxattachments', 'local_overflow');
        $mform->setDefault('maxattachments', get_config('local_overflow', 'maxattachments'));

        // Subscription Handling.
        $mform->addElement('header', 'subscriptiontrackingheader', get_string('subscriptiontrackingheader', 'local_overflow'));

        // Prepare the array with options for the subscription state.
        $options = [];
        $options[OVERFLOW_CHOOSESUBSCRIBE] = get_string('subscriptionoptional', 'local_overflow');
        $options[OVERFLOW_FORCESUBSCRIBE] = get_string('subscriptionforced', 'local_overflow');
        $options[OVERFLOW_INITIALSUBSCRIBE] = get_string('subscriptionauto', 'local_overflow');
        $options[OVERFLOW_DISALLOWSUBSCRIBE] = get_string('subscriptiondisabled', 'local_overflow');

        // Create the option to set the subscription state.
        $mform->addElement('select', 'forcesubscribe', get_string('subscriptionmode', 'local_overflow'), $options);
        $mform->addHelpButton('forcesubscribe', 'subscriptionmode', 'local_overflow');

        // Set the options for the default readtracking.
        $options = [];
        $options[OVERFLOW_TRACKING_OPTIONAL] = get_string('trackingoptional', 'local_overflow');
        $options[OVERFLOW_TRACKING_OFF] = get_string('trackingoff', 'local_overflow');
        if (get_config('local_overflow', 'allowforcedreadtracking')) {
            $options[OVERFLOW_TRACKING_FORCED] = get_string('trackingon', 'local_overflow');
        }

        // Create the option to set the readtracking state.
        $mform->addElement('select', 'trackingtype', get_string('trackingtype', 'local_overflow'), $options);
        $mform->addHelpButton('trackingtype', 'trackingtype', 'local_overflow');
        
        // Choose the default tracking type.
        $default = get_config('local_overflow', 'trackingtype');
        if ((!get_config('local_overflow', 'allowforcedreadtracking')) && ($default == OVERFLOW_TRACKING_FORCED)) {
            $default = OVERFLOW_TRACKING_OPTIONAL;
        }
        $mform->setDefault('trackingtype', $default);

        // Grade options.
        $mform->addElement('header', 'gradeheading',
            $CFG->branch >= 311 ? get_string('gradenoun') : get_string('grade'));

        $mform->addElement('text', 'grademaxgrade', get_string('modgrademaxgrade', 'grades'));
        $mform->setType('grademaxgrade', PARAM_INT);
        $mform->addRule('grademaxgrade', get_string('grademaxgradeerror', 'local_overflow'), 'regex', '/^[0-9]+$/', 'client');

        $mform->addElement('text', 'gradescalefactor', get_string('scalefactor', 'local_overflow'));
        $mform->addHelpButton('gradescalefactor', 'scalefactor', 'local_overflow');
        $mform->setType('gradescalefactor', PARAM_INT);
        $mform->addRule('gradescalefactor', get_string('scalefactorerror', 'local_overflow'), 'regex', '/^[0-9]+$/', 'client');

        if (isset($this->customdata['gradecat'])) {
            $mform->addElement(
                'select', 'gradecat',
                get_string('gradecategoryonmodform', 'grades'),
                grade_get_categories_menu(1, $this->customdata['outcomesused'])
            );
            $mform->addHelpButton('gradecat', 'gradecategoryonmodform', 'grades');
        }

        // Rating options.
        $mform->addElement('header', 'ratingheading', get_string('ratingheading', 'local_overflow'));

        // Which rating is more important?
        $options = [];
        $options[OVERFLOW_PREFERENCE_STARTER] = get_string('starterrating', 'local_overflow');
        $options[OVERFLOW_PREFERENCE_TEACHER] = get_string('teacherrating', 'local_overflow');
        $mform->addElement('select', 'ratingpreference', get_string('ratingpreference', 'local_overflow'), $options);
        $mform->addHelpButton('ratingpreference', 'ratingpreference', 'local_overflow');
        $mform->setDefault('ratingpreference', OVERFLOW_PREFERENCE_STARTER);

        if (get_config('local_overflow', 'allowdisablerating') == 1) {
            // Allow Rating.
            $mform->addElement('selectyesno', 'allowrating', get_string('allowrating', 'local_overflow'));
            $mform->addHelpButton('allowrating', 'allowrating', 'local_overflow');
            $mform->setDefault('allowrating', OVERFLOW_RATING_ALLOW);

            // Allow Reputation.
            $mform->addElement('selectyesno', 'allowreputation', get_string('allowreputation', 'local_overflow'));
            $mform->addHelpButton('allowreputation', 'allowreputation', 'local_overflow');
            $mform->setDefault('allowreputation', OVERFLOW_REPUTATION_ALLOW);
        }
        // Ưide reputation?
        $mform->addElement('selectyesno', 'widereputation', get_string('widereputation', 'local_overflow'));
        $mform->addHelpButton('widereputation', 'widereputation', 'local_overflow');
        $mform->setDefault('widereputation', OVERFLOW_REPUTATION);
        $mform->hideIf('widereputation', 'anonymous', 'gt', 0);

        // Allow negative reputations?
        $mform->addElement('selectyesno', 'allownegativereputation', get_string('allownegativereputation', 'local_overflow'));
        $mform->addHelpButton('allownegativereputation', 'allownegativereputation', 'local_overflow');
        $mform->setDefault('allownegativereputation', OVERFLOW_REPUTATION_NEGATIVE);

        // Allow multiple marks of helpful/solved.
        $mform->addElement('advcheckbox', 'allowmultiplemarks', get_string('allowmultiplemarks', 'local_overflow'));
        $mform->addHelpButton('allowmultiplemarks', 'allowmultiplemarks', 'local_overflow');
        $mform->setDefault('allowmultiplemarks', 0);

        $mform->disabledIf('completionusegrade', 'grademaxgrade', 'in', [0, '']);
        $mform->disabledIf('completionusegrade', 'gradescalefactor', 'in', [0, '']);

        // Add standard buttons, common to all modules.
        $this->add_action_buttons();
    }

    /**
     * Handles data postprocessing.
     *
     * @param array $data data from the form.
     */
    public function data_postprocessing($data) {
        if (isset($data->anonymous) && $data->anonymous != anonymous::NOT_ANONYMOUS) {
            $data->widereputation = false;
        }
    }
}

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
 * File containing the form definition to post in a overflow.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/repository/lib.php');

/**
 * Class to post in a overflow.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_overflow_post_form extends moodleform {

    /**
     * Form definition.
     *
     * @return void
     */
    public function definition() {

        $mform        =& $this->_form;
        $post = $this->_customdata['post'];
        $context = $this->_customdata['context'];
        $overflow = $this->_customdata['overflow'];

        // Fill in the data depending on page params later using set_data.
        $mform->addElement('header', 'general', '');

        // The subject.
        $mform->addElement('text', 'subject', get_string('subject', 'local_overflow'), 'size="48"');
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', get_string('required'), 'required', null, 'client');
        $mform->addRule('subject', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // The message.
        $mform->addElement('editor', 'message', get_string('message', 'local_overflow'), null,
                        self::editor_options($context, (empty($post->id) ? null : $post->id)));

        $mform->setType('message', PARAM_RAW);
        $mform->addRule('message', get_string('required'), 'required', null, 'client');

        if (overflow_can_create_attachment($overflow, $context)) {
            $mform->addElement('filemanager', 'attachments',
                get_string('attachment', 'local_overflow'),
                null, self::attachment_options($overflow));
            $mform->addHelpButton('attachments', 'attachment', 'local_overflow');
        }

        // Submit buttons.
        if (isset($post->edit)) {
            $strsubmit = get_string('savechanges');
        } else {
            $strsubmit = get_string('posttooverflow', 'local_overflow');
        }
        $this->add_action_buttons(true, $strsubmit);


        // The overflow instance.
        $mform->addElement('hidden', 'overflow');
        $mform->setType('overflow', PARAM_INT);

        // The discussion.
        $mform->addElement('hidden', 'discussion');
        $mform->setType('discussion', PARAM_INT);

        // The parent post.
        $mform->addElement('hidden', 'parent');
        $mform->setType('parent', PARAM_INT);

        // Are we editing a post?
        $mform->addElement('hidden', 'edit');
        $mform->setType('edit', PARAM_INT);

        // Is it a reply?
        $mform->addElement('hidden', 'reply');
        $mform->setType('reply', PARAM_INT);
    }

    /**
     * Form validation.
     *
     * @param array $data  data from the form.
     * @param array $files files uplaoded.
     *
     * @return array of errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['message']['text'])) {
            $errors['message'] = get_string('erroremptymessage', 'local_overflow');
        }
        if (empty($data['subject'])) {
            $errors['subject'] = get_string('erroremptysubject', 'local_overflow');
        }

        return $errors;
    }

    /**
     * Returns the options array to use in filemanager for overflow attachments
     *
     * @param stdClass $overflow
     *
     * @return array
     */
    public static function attachment_options($overflow) {
        global $COURSE, $PAGE, $CFG;
        $maxbytes = get_user_max_upload_file_size($PAGE->context, $CFG->maxbytes, $COURSE->maxbytes, $overflow->maxbytes);

        return [
            'subdirs' => 0,
            'maxbytes' => $maxbytes,
            'maxfiles' => $overflow->maxattachments,
            'accepted_types' => '*',
            'return_types' => FILE_INTERNAL | FILE_CONTROLLED_LINK,
        ];
    }

    /**
     * Returns the options array to use in forum text editor
     *
     * @param context_system $context
     * @param int $postid post id, use null when adding new post
     * @return array
     */
    public static function editor_options(context_system $context, $postid) {
        global $COURSE, $PAGE, $CFG;
        $maxbytes = get_user_max_upload_file_size($PAGE->context, $CFG->maxbytes, $COURSE->maxbytes);
        return [
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'maxbytes' => $maxbytes,
            'trusttext' => true,
            'return_types' => FILE_INTERNAL | FILE_EXTERNAL,
            'subdirs' => file_area_contains_subdirs($context, 'local_overflow', 'post', $postid),
        ];
    }
}








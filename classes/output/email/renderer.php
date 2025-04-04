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
 * overflow post renderable.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_overflow\output\email;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../renderer.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * overflow post renderable.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \local_overflow_renderer {

    /**
     * The template name for this renderer.
     *
     * @return string
     */
    public function overflow_email_template() {
        return 'email_html';
    }

    /**
     * The HTML version of the e-mail message.
     *
     * @param \stdClass $post
     *
     * @return string
     */
    public function format_message_text($post) {
        // Convert the message.
        $message = file_rewrite_pluginfile_urls(
            $post->message, 'pluginfile.php',
            \context_system::instance()->id,
            'local_overflow', 'post', $post->id);

        // Initiate some options.
        $options = new \stdClass();
        $options->para = true;

        // Return the message in html.
        return format_text($message, $post->messageformat, $options);
    }
}

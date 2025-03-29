<?php
// This file is part of a plugin for Moodle - http://moodle.org/
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
 * Renderer definition.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/weblib.php');

/**
 * Class for rendering overflow.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_overflow_renderer extends plugin_renderer_base {

    /**
     * Display the discussion list for the view.php.
     *
     * @param object $data The prepared variables.
     *
     * @return string
     */
    public function render_discussion_list($data) {
        return $this->render_from_template('local_overflow/discussions', $data);
    }

    /**
     * Display the forum list in the view.php if a discussion needs to be moved to another forum.
     *
     * @param object $data The prepared variables.
     *
     * @return string
     */
    public function render_forum_list($data) {
        return $this->render_from_template('local_overflow/forum_list', $data);
    }

    /**
     * Renders a dummy post for users that cannot see the post.
     *
     * @param object $data The submitted variables.
     *
     * @return bool|string
     */
    public function render_post_dummy_cantsee($data) {
        return $this->render_from_template('local_overflow/post_dummy_cantsee', $data);
    }

    /**
     * Renders any post.
     *
     * @param object $data The submitted variables.
     *
     * @return bool|string
     */
    public function render_post($data) {
        return $this->render_from_template('local_overflow/post', $data);
    }

    /**
     * Display a overflow post in the relevant context.
     *
     * @param \local_overflow\output\overflow_email $post The post to display.
     *
     * @return string
     */
    public function render_overflow_email(\local_overflow\output\overflow_email $post) {
        $data = $post->export_for_template($this, $this->target === RENDERER_TARGET_TEXTEMAIL);

        return $this->render_from_template('local_overflow/' . $this->overflow_email_template(), $data);
    }

    /**
     * The template name for this renderer.
     * This method will be overwritten by other classes.
     *
     * @return string
     */
    public function overflow_email_template() {
        return null;
    }
}

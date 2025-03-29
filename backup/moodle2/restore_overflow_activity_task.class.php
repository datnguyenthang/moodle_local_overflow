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
 * Provides the restore activity task class
 *
 * @package   local_overflow
 * @category  backup
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/overflow/backup/moodle2/restore_overflow_stepslib.php');

/**
 * Restore task for the overflow activity module
 *
 * Provides all the settings and steps to perform complete restore of the activity.
 *
 * @package   local_overflow
 * @category  backup
 * @copyright 2016 Your Name <your@email.address>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_overflow_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // We have just one structure step here.
        $this->add_step(new restore_overflow_activity_structure_step('overflow_structure', 'overflow.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    public static function define_decode_contents() {
        $contents = [];

        $contents[] = new restore_decode_content('overflow', ['intro'], 'overflow');
        $contents[] = new restore_decode_content('overflow_posts', ['message'], 'overflow_post');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule('overflowVIEWBYID', '/local/overflow/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('overflowINDEX', '/local/overflow/index.php?id=$1', 'course');

        $rules[] = new restore_decode_rule('overflowVIEWBYF', '/local/overflow/view.php?f=$1', 'overflow');
        // Link to forum discussion.
        $rules[] = new restore_decode_rule('overflowDISCUSSIONVIEW',
            '/local/overflow/discussion.php?d=$1',
            'overflow_discussion');
        // Link to discussion with parent and with anchor posts.
        $rules[] = new restore_decode_rule('overflowDISCUSSIONVIEWPARENT',
            '/local/overflow/discussion.php?d=$1&parent=$2',
            ['overflow_discussion', 'overflow_post']);
        $rules[] = new restore_decode_rule('overflowDISCUSSIONVIEWINSIDE', '/local/overflow/discussion.php?d=$1#$2',
            ['overflow_discussion', 'overflow_post']);

        return $rules;

    }

    /**
     * Define the restore log rules that will be applied
     * by the {restore_logs_processor} when restoring
     * overflow logs. It must return one array
     * of { restore_log_rule} objects
     */
    public static function define_restore_log_rules() {
        $rules = [];

        $rules[] = new restore_log_rule('overflow', 'add',
            'view.php?id={course_module}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'update',
            'view.php?id={course_module}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'view',
            'view.php?id={course_module}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'view overflow',
            'view.php?id={course_module}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'mark read',
            'view.php?f={overflow}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'start tracking',
            'view.php?f={overflow}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'stop tracking',
            'view.php?f={moodloeoverflow}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'subscribe',
            'view.php?f={overflow}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'unsubscribe',
            'view.php?f={overflow}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'subscriber',
            'subscribers.php?id={overflow}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'subscribers',
            'subscribers.php?id={overflow}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'view subscribers',
            'subscribers.php?id={overflow}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'add discussion',
            'discussion.php?d={overflow_discussion}', '{overflow_discussion}');
        $rules[] = new restore_log_rule('overflow', 'view discussion',
            'discussion.php?d={overflow_discussion}', '{overflow_discussion}');
        $rules[] = new restore_log_rule('overflow', 'move discussion',
            'discussion.php?d={overflow_discussion}', '{overflow_discussion}');
        $rules[] = new restore_log_rule('overflow', 'delete discussi',
            'view.php?id={course_module}', '{overflow}',
            null, 'delete discussion');
        $rules[] = new restore_log_rule('overflow', 'delete discussion',
            'view.php?id={course_module}', '{overflow}');
        $rules[] = new restore_log_rule('overflow', 'add post',
            'discussion.php?d={overflow_discussion}&parent={overflow_post}', '{overflow_post}');
        $rules[] = new restore_log_rule('overflow', 'update post',
            'discussion.php?d={overflow_discussion}&parent={overflow_post}', '{overflow_post}');
        $rules[] = new restore_log_rule('overflow', 'prune post',
            'discussion.php?d={overflow_discussion}', '{overflow_post}');
        $rules[] = new restore_log_rule('overflow', 'delete post',
            'discussion.php?d={overflow_discussion}', '[post]');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the { restore_logs_processor} when restoring
     * course logs. It must return one array
     * of { restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    public static function define_restore_log_rules_for_course() {
        $rules = [];

        $rules[] = new restore_log_rule('overflow', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}

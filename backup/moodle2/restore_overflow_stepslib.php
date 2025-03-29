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
 * Define all the restore steps that will be used by the restore_overflow_activity_task
 *
 * @package   local_overflow
 * @category  backup
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one overflow activity
 *
 * @package   local_overflow
 * @category  backup
 * @copyright 2016 Your Name <your@email.address>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_overflow_activity_structure_step extends restore_activity_structure_step {

    /**
     * Defines structure of path elements to be processed during the restore.
     *
     * @return array of {restore_path_element}
     */
    protected function define_structure() {

        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('overflow', '/activity/overflow');
        if ($userinfo) {
            $paths[] = new restore_path_element('overflow_discussion',
                '/activity/overflow/discussions/discussion');
            $paths[] = new restore_path_element('overflow_post',
                '/activity/overflow/discussions/discussion/posts/post');
            $paths[] = new restore_path_element('overflow_discuss_sub',
                '/activity/overflow/discussions/discussion/discuss_subs/discuss_sub');
            $paths[] = new restore_path_element('overflow_rating',
                '/activity/overflow/discussions/discussion/posts/post/ratings/rating');
            $paths[] = new restore_path_element('overflow_subscription',
                '/activity/overflow/subscriptions/subscription');
            $paths[] = new restore_path_element('overflow_read',
                '/activity/overflow/readposts/read');
            $paths[] = new restore_path_element('overflow_track',
                '/activity/overflow/tracking/track');
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process the given restore path element data
     *
     * @param array $data parsed element data
     */
    protected function process_overflow($data) {
        global $DB;

        $data = (object) $data;

        if (empty($data->timecreated)) {
            $data->timecreated = time();
        }

        if (empty($data->timemodified)) {
            $data->timemodified = time();
        }

        // Create the overflow instance.
        $newitemid = $DB->insert_record('overflow', $data);
        $this->apply_activity_instance($newitemid);

        // Add current enrolled user subscriptions if necessary.
    }

    /**
     * Restores a overflow discussion from element data.
     *
     * @param array $data element data
     */
    protected function process_overflow_discussion($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->overflow = $this->get_new_parentid('overflow');
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->usermodified = $this->get_mappingid('user', $data->usermodified);

        $newitemid = $DB->insert_record('overflow_discussions', $data);
        $this->set_mapping('overflow_discussion', $oldid, $newitemid);
    }

    /**
     * Resotres a mooodleoverflow post from element data.
     *
     * @param array $data element data
     */
    protected function process_overflow_post($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->discussion = $this->get_new_parentid('overflow_discussion');
        $data->created = $this->apply_date_offset($data->created);
        $data->modified = $this->apply_date_offset($data->modified);
        $data->userid = $this->get_mappingid('user', $data->userid);
        // If post has parent, map it (it has been already restored).
        if (!empty($data->parent)) {
            $data->parent = $this->get_mappingid('overflow_post', $data->parent);
        }

        $newitemid = $DB->insert_record('overflow_posts', $data);
        $this->set_mapping('overflow_post', $oldid, $newitemid, true);

        // If !post->parent, it's the 1st post. Set it in discussion.
        if (empty($data->parent)) {
            $DB->set_field('overflow_discussions', 'firstpost', $newitemid, ['id' => $data->discussion]);
        }
    }

    /**
     * Restores rating from element data.
     *
     * @param array $data element data
     */
    protected function process_overflow_rating($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->postid = $this->get_new_parentid('overflow_post');
        $data->discussionid = $this->get_new_parentid('overflow_discussion');
        $data->overflowid = $this->get_new_parentid('overflow');

        $newitemid = $DB->insert_record('overflow_ratings', $data);
        $this->set_mapping('overflow_rating', $oldid, $newitemid, true);
    }

    /**
     * Restores overflow subscriptions from element data.
     *
     * @param array $data element data
     */
    protected function process_overflow_subscription($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->overflow = $this->get_new_parentid('overflow');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('overflow_subscriptions', $data);
        $this->set_mapping('overflow_subscription', $oldid, $newitemid, true);

    }

    /**
     * Restores overflow disussion subscriptions from element data.
     *
     * @param array $data element data
     */
    protected function process_overflow_discuss_sub($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->discussion = $this->get_new_parentid('overflow_discussion');
        $data->overflow = $this->get_new_parentid('overflow');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('overflow_discuss_subs', $data);
        $this->set_mapping('overflow_discuss_sub', $oldid, $newitemid, true);
    }

    /**
     * Restores overflow read records from element data.
     *
     * @param array $data element data
     */
    protected function process_overflow_read($data) {
        global $DB;

        $data = (object) $data;

        $data->overflowid = $this->get_new_parentid('overflow');
        $data->discussionid = $this->get_mappingid('overflow_discussion', $data->discussionid);
        $data->postid = $this->get_mappingid('overflow_post', $data->postid);
        $data->userid = $this->get_mappingid('user', $data->userid);

        $DB->insert_record('overflow_read', $data);
    }

    /**
     * Restores tracking records from element data.
     *
     * @param array $data element data
     */
    protected function process_overflow_track($data) {
        global $DB;

        $data = (object) $data;

        $data->overflowid = $this->get_new_parentid('overflow');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $DB->insert_record('overflow_tracking', $data);
    }

    /**
     * Post-execution actions
     */
    protected function after_execute() {
        // Add overflow related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('local_overflow', 'intro', null);
        $this->add_related_files('local_overflow', 'post', 'overflow_post');
        $this->add_related_files('local_overflow', 'attachment', 'overflow_post');
    }
}

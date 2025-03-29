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
 * overflow anonymous related class.
 *
 * @package   local_overflow
 * @copyright 2021 Justus Dieckmann WWU
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_overflow;

/**
 * Class for overflow anonymity
 *
 * @package   local_overflow
 * @copyright 2021 Justus Dieckmann WWU
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class anonymous {

    /**
     * Used if nothing is anonymous.
     */
    const NOT_ANONYMOUS = 0;
    /**
     * Used if question is anonymous.
     */
    const QUESTION_ANONYMOUS = 1;
    /**
     * Used if whole post is anonymous.
     */
    const EVERYTHING_ANONYMOUS = 2;

    /**
     * Checks if post is anonymous.
     *
     * @param object $discussion              overflow discussion
     * @param object $overflow
     * @param int $postinguserid        user id of posting user
     *
     * @return bool true if user is not logged in, everything is marked anonymous
     * and if the question is anonymous and there are no answers yet, else false
     */
    public static function is_post_anonymous($discussion, $overflow, $postinguserid): bool {
        if ($postinguserid == 0) {
            return true;
        }

        if ($overflow->anonymous == self::EVERYTHING_ANONYMOUS) {
            return true;
        }

        if ($overflow->anonymous == self::QUESTION_ANONYMOUS) {
            return $discussion->userid == $postinguserid;
        }

        return false;
    }

    /**
     * Returns a usermapping for the overflow, where each anonymized userid is replaced by an int, to form the
     * new name, e.g. Answerer #4.
     *
     * @param \stdClass $overflow
     * @param int $discussionid
     */
    public static function get_userid_mapping($overflow, $discussionid) {
        global $DB;
        if ($overflow->anonymous == self::NOT_ANONYMOUS) {
            return [];
        }
        if ($overflow->anonymous == self::QUESTION_ANONYMOUS) {
            return [
                $DB->get_field('overflow_posts', 'userid',
                    ['parent' => 0, 'discussion' => $discussionid]) => get_string('questioner', 'local_overflow'),
            ];
        }

        $userids = $DB->get_records_sql(
            'SELECT userid ' .
            'FROM {overflow_posts} ' .
            'WHERE discussion = :discussion ' .
            'GROUP BY userid ' .
            'ORDER BY MIN(created) ASC;', ['discussion' => $discussionid]);

        $mapping = [];
        $questioner = array_shift($userids);
        $mapping[$questioner->userid] = get_string('questioner', 'local_overflow');
        $i = 1;
        foreach ($userids as $user) {
            $mapping[$user->userid] = get_string('answerer', 'local_overflow', $i);
            $i++;
        }
        return $mapping;
    }

}

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
class review {

    /**
     * Nothing has to be reviewed.
     */
    const NOTHING = 0;
    /**
     * New questions (= discussions) have to be reviewed.
     */
    const QUESTIONS = 1;
    /**
     * Everything has to be reviewed.
     */
    const EVERYTHING = 2;

    /**
     * Returns the review level of the given overflow instance, considering the global allowreview setting.
     * @param object $overflow
     * @return int
     */
    public static function get_review_level(object $overflow): int {
        if (get_config('local_overflow', 'allowreview') == '1') {
            return $overflow->needsreview;
        } else {
            return self::NOTHING;
        }
    }

    /**
     * Returns a short review info for the discussion.
     * @param int $discussionid The discussionid.
     * @return object {"count": amount needing review (int) , "first": first postid needing review (int)}
     */
    public static function get_short_review_info_for_discussion(int $discussionid) {
        global $DB;

        return $DB->get_record_sql(
                'SELECT COUNT(*) as count, MIN(id) AS first ' .
                'FROM {overflow_posts} ' .
                'WHERE discussion = :discussionid AND reviewed = 0 AND created < :cutofftime', [
                        'discussionid' => $discussionid,
                        'cutofftime' => time() - get_config('local_overflow', 'reviewpossibleaftertime'),
                ]
        );
    }

    /**
     * Get a review post to review.
     *
     * @param int $overflowid ID of overflow to look in.
     * @param int $afterpostid ID of post after which to look for the first post to review.
     * @return string|null
     */
    public static function get_first_review_post($overflowid, $afterpostid = null) {
        global $DB;

        $params = [
                'overflowid' => $overflowid,
                'reviewtime' => time() - get_config('local_overflow', 'reviewpossibleaftertime'),
        ];
        $orderby = '';
        $addwhere = '';

        if ($afterpostid) {
            $afterdiscussionid = $DB->get_field('overflow_posts', 'discussion', ['id' => $afterpostid],
                MUST_EXIST);

            $orderby = 'CASE WHEN (p.discussion > :afterdiscussionid OR (p.discussion = :afterdiscussionid2 AND p.id
             > :afterpostid)) THEN 0 ELSE 1 END, ';
            $params['afterdiscussionid'] = $afterdiscussionid;
            $params['afterdiscussionid2'] = $afterdiscussionid;
            $params['afterpostid'] = $afterpostid;

            $addwhere = ' AND p.id != :notpostid ';
            $params['notpostid'] = $afterpostid;
        }
        $record = $DB->get_record_sql(
            'SELECT p.id as postid, p.discussion as discussionid FROM {overflow_posts} p ' .
            'JOIN {overflow_discussions} d ON d.id = p.discussion ' .
            "WHERE p.reviewed = 0 AND d.overflow = :overflowid AND p.created < :reviewtime $addwhere " .
            "ORDER BY $orderby p.discussion, p.id " .
            'LIMIT 1',
            $params
        );
        if ($record) {
            return (new \moodle_url('/local/overflow/discussion.php', [
                'd' => $record->discussionid,
            ], 'p' . $record->postid))->out(false);
        } else {
            return null;
        }
    }

    /**
     * Return if the post does need/needed a review with the current overflow settings.
     * @param object $post
     * @param object $overflow
     * @return bool
     */
    public static function should_post_be_reviewed($post, $overflow): bool {
        $reviewlevel = self::get_review_level($overflow);
        if ($post->parent) {
            return $reviewlevel == self::EVERYTHING;
        } else {
            return $reviewlevel >= self::QUESTIONS;
        }
    }

    /**
     * Returns whether a post is reviewable depending on its review state and review period.
     * @param object $post
     * @return bool
     */
    public static function is_post_in_review_period($post): bool {
        return time() - $post->created > get_config('local_overflow', 'reviewpossibleaftertime');
    }

    /**
     * Count outstanding reviews in the overflow.
     *
     * @param int $overflowid
     * @return int
     */
    public static function count_outstanding_reviews_in_overflow($overflowid): int {
        global $DB;
        return $DB->count_records_sql(
                'SELECT COUNT(*) ' .
                'FROM {overflow_posts} p ' .
                'JOIN {overflow_discussions} d ON d.id = p.discussion ' .
                'WHERE d.overflow = :overflowid AND p.created < :cutofftime AND reviewed = 0', [
                        'overflowid' => $overflowid,
                        'cutofftime' => time() - get_config('local_overflow', 'reviewpossibleaftertime'),
                ]
        );
    }


}

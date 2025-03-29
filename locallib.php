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
 * Internal library of functions for module overflow
 *
 * All the overflow specific functions, needed to implement the module
 * logic, should go here. Never include this file from your lib.php!
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use local_overflow\anonymous;
use local_overflow\capabilities;
use local_overflow\event\post_deleted;
use local_overflow\readtracking;
use local_overflow\review;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(dirname(__FILE__) . '/lib.php');

/**
 * Get all discussions in a overflow instance.
 *
 * @param object $overflow
 * @param int $page
 * @param int $perpage
 *
 * @return array
 */
function overflow_get_discussions($overflow, $page = -1, $perpage = 0) {
    global $DB, $CFG, $USER;

    // User must have the permission to view the discussions.
    $context = context_system::instance(); ;
    if (!capabilities::has(capabilities::VIEW_DISCUSSION, $context)) {
        return [];
    }

    // Filter some defaults.
    if ($perpage <= 0) {
        $limitfrom = 0;
        $limitamount = $perpage;
    } else if ($page != -1) {
        $limitfrom = $page * $perpage;
        $limitamount = $perpage;
    } else {
        $limitfrom = 0;
        $limitamount = 0;
    }

    // Get all name fields as sql string snippet.
    if ($CFG->branch >= 311) {
        $allnames = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    } else {
        $allnames = get_all_user_name_fields(true, 'u');
    }
    $postdata = 'p.id, p.modified, p.discussion, p.userid, p.reviewed';
    $discussiondata = 'd.name, d.timemodified, d.timestart, d.usermodified, d.firstpost';
    $userdata = 'u.email, u.picture, u.imagealt';

    if ($CFG->branch >= 311) {
        $usermodifiedfields = \core_user\fields::for_name()->get_sql('um', false, 'um',
                '', false)->selects .
            ', um.email AS umemail, um.picture AS umpicture, um.imagealt AS umimagealt';
    } else {
        $usermodifiedfields = get_all_user_name_fields(true, 'um', null, 'um') .
            ', um.email AS umemail, um.picture AS umpicture, um.imagealt AS umimagealt';
    }

    $params = [$overflow->id];
    $whereconditions = ['d.overflow = ?', 'p.parent = 0'];

    if (!capabilities::has(capabilities::REVIEW_POST, $context)) {
        $whereconditions[] = '(p.reviewed = 1 OR p.userid = ?)';
        $params[] = $USER->id;
    }

    $wheresql = join(' AND ', $whereconditions);

    // Retrieve and return all discussions from the database.
    $sql = "SELECT $postdata, $discussiondata, $allnames, $userdata, $usermodifiedfields
              FROM {overflow_discussions} d
                   JOIN {overflow_posts} p ON p.discussion = d.id
                   LEFT JOIN {user} u ON p.userid = u.id
                   LEFT JOIN {user} um ON d.usermodified = um.id
              WHERE $wheresql
           ORDER BY d.timestart DESC, d.id DESC";

    return $DB->get_records_sql($sql, $params, $limitfrom, $limitamount);
}

/**
 * Prints latest overflow discussions.
 *
 * @param object $overflow overflow to be printed.
 * @param int    $page           Page mode, page to display (optional).
 * @param int    $perpage        The maximum number of discussions per page (optional).
 */
function overflow_print_latest_discussions($overflow,  $page = -1, $perpage = 25) {
    global $CFG, $USER, $OUTPUT, $PAGE;

    // Set the context.
    $context = context_system::instance(); ;

    // If the perpage value is invalid, deactivate paging.
    if ($perpage <= 0) {
        $perpage = 0;
        $page = -1;
    }

    // Check some capabilities.
    $canstartdiscussion = overflow_user_can_post_discussion($overflow, $context);
    $canviewdiscussions = has_capability('local/overflow:viewdiscussion', $context);
    $canreviewposts = has_capability('local/overflow:reviewpost', $context);
    $canseeuserstats = has_capability('local/overflow:viewanyrating', $context);

    // Print a button if the user is capable of starting
    // a new discussion or if the selfenrol is aviable.
    if ($canstartdiscussion) {
        $buttontext = get_string('addanewdiscussion', 'local_overflow');
        $buttonurl = new moodle_url('/local/overflow/post.php', ['overflow' => $overflow->id]);
        $button = new single_button($buttonurl, $buttontext, 'get');
        $button->class = 'singlebutton align-middle m-2';
        $button->formid = 'newdiscussionform';
        echo $OUTPUT->render($button);
    }

    // Print a button if the user is capable of seeing the user stats.
    if ($canseeuserstats && get_config('local_overflow', 'showuserstats')) {
        $userstatsbuttontext = get_string('seeuserstats', 'local_overflow');
        $userstatsbuttonurl = new moodle_url('/local/overflow/userstats.php', [
                                                                            'mid' => $overflow->id, ]);
        $userstatsbutton = new single_button($userstatsbuttonurl, $userstatsbuttontext, 'get');
        $userstatsbutton->class = 'singlebutton align-middle m-2';
        echo $OUTPUT->render($userstatsbutton);
    }

    // Get all the recent discussions the user is allowed to see.
    $discussions = overflow_get_discussions($overflow, $page, $perpage);

    // Get the number of replies for each discussion.
    $replies = overflow_count_discussion_replies($overflow);

    // Check whether the overflow instance can be tracked and is tracked.
    if ($cantrack = readtracking::overflow_can_track_overflows($overflow)) {
        $istracked = readtracking::overflow_is_tracked($overflow);
    } else {
        $istracked = false;
    }

    // Get an array of unread messages for the current user if the overflow instance is tracked.
    if ($istracked) {
        $unreads = overflow_get_discussions_unread($overflow);
        $markallread = $CFG->wwwroot . '/local/overflow/markposts.php?m=' . $overflow->id;
    } else {
        $unreads = [];
        $markallread = null;
    }

    if ($markallread && $unreads) {
        echo html_writer::link(new moodle_url($markallread),
            get_string('markallread_forum', 'local_overflow'),
            ['class' => 'btn btn-secondary my-2']
        );
    }

    // Get all the recent discussions the user is allowed to see.
    $discussions = overflow_get_discussions($overflow, $page, $perpage);

    // If we want paging.
    if ($page != -1) {

        // Get the number of discussions.
        $numberofdiscussions = overflow_get_discussions_count($overflow);

        // Show the paging bar.
        echo $OUTPUT->paging_bar($numberofdiscussions, $page, $perpage, "view.php?id=$overflow->id");
    }

    // Get the number of replies for each discussion.
    $replies = overflow_count_discussion_replies($overflow);

    // Check whether the user can subscribe to the discussion.
    $cansubtodiscussion = false;

    if ((!isguestuser() && isloggedin()) && has_capability('local/overflow:viewdiscussion', $context)
                                    && \local_overflow\subscriptions::is_subscribable($overflow, $context)) {
        $cansubtodiscussion = true;
    }

    // Check wether the user can move a topic.
    $canmovetopic = false;
    if ((!isguestuser() && isloggedin()) && has_capability('local/overflow:movetopic', $context)) {
        $canmovetopic = true;
    }


    // Iterate through every visible discussion.
    $i = 0;
    $preparedarray = [];
    foreach ($discussions as $discussion) {
        $preparedarray[$i] = [];

        // Handle anonymized discussions.
        if ($discussion->userid == 0) {
            $discussion->name = get_string('privacy:anonym_discussion_name', 'local_overflow');
        }

        // Set the amount of replies for every discussion.
        if (!empty($replies[$discussion->discussion])) {
            $discussion->replies = $replies[$discussion->discussion]->replies;
            $discussion->lastpostid = $replies[$discussion->discussion]->lastpostid;
        } else {
            $discussion->replies = 0;
        }

        // Set the right text.
        $preparedarray[$i]['answertext'] = ($discussion->replies == 1) ? 'answer' : 'answers';

        // Set the amount of unread messages for each discussion.
        if (!$istracked) {
            $discussion->unread = '-';
        } else if (empty($USER)) {
            $discussion->unread = 0;
        } else {
            if (empty($unreads[$discussion->discussion])) {
                $discussion->unread = 0;
            } else {
                $discussion->unread = $unreads[$discussion->discussion];
            }
        }

        // Check if the question owner marked the question as helpful.
        $markedhelpful = \local_overflow\ratings::overflow_discussion_is_solved($discussion->discussion, false);
        $preparedarray[$i]['starterlink'] = null;
        if ($markedhelpful) {
            $link = '/local/overflow/discussion.php?d=';
            $markedhelpful = $markedhelpful[array_key_first($markedhelpful)];

            $preparedarray[$i]['starterlink'] = new moodle_url($link .
                $markedhelpful->discussionid . '#p' . $markedhelpful->postid);
        }

        // Check if a teacher marked a post as solved.
        $markedsolution = \local_overflow\ratings::overflow_discussion_is_solved($discussion->discussion, true);
        $preparedarray[$i]['teacherlink'] = null;
        if ($markedsolution) {
            $link = '/local/overflow/discussion.php?d=';
            $markedsolution = $markedsolution[array_key_first($markedsolution)];

            $preparedarray[$i]['teacherlink'] = new moodle_url($link .
                $markedsolution->discussionid . '#p' . $markedsolution->postid);
        }

        // Check if a single post was marked by the question owner and a teacher.
        $statusboth = false;
        if ($markedhelpful && $markedsolution) {
            if ($markedhelpful->postid == $markedsolution->postid) {
                $statusboth = true;
            }
        }

        // Get the amount of votes for the discussion.
        $votes = \local_overflow\ratings::overflow_get_ratings_by_discussion($discussion->discussion, $discussion->id);
        $votes = $votes->upvotes - $votes->downvotes;
        $preparedarray[$i]['votetext'] = ($votes == 1) ? 'vote' : 'votes';

        // Use the discussions name instead of the subject of the first post.
        $discussion->subject = $discussion->name;

        // Format the subjectname and the link to the topic.
        $preparedarray[$i]['subjecttext'] = format_string($discussion->subject);
        $preparedarray[$i]['subjectlink'] = $CFG->wwwroot . '/local/overflow/discussion.php?d=' . $discussion->discussion;

        // Get information about the user who started the discussion.
        $startuser = new stdClass();
        if ($CFG->branch >= 311) {
            $startuserfields = \core_user\fields::get_picture_fields();
        } else {
            $startuserfields = explode(',', user_picture::fields());
        }

        $startuser = username_load_fields_from_object($startuser, $discussion, null, $startuserfields);
        $startuser->id = $discussion->userid;

        // Discussion was anonymized.
        if ($startuser->id == 0 || $overflow->anonymous != anonymous::NOT_ANONYMOUS) {
            // Get his picture, his name and the link to his profile.
            if ($startuser->id == $USER->id) {
                $preparedarray[$i]['username'] = get_string('anonym_you', 'local_overflow');
                // Needs to be included for reputation to update properly.
                $preparedarray[$i]['userlink'] = $CFG->wwwroot . '/user/view.php?id=' .
                    $discussion->userid;

            } else {
                $preparedarray[$i]['username'] = get_string('privacy:anonym_user_name', 'local_overflow');
                $preparedarray[$i]['userlink'] = null;
            }
        } else {
            // Get his picture, his name and the link to his profile.
            $preparedarray[$i]['picture'] = $OUTPUT->user_picture($startuser, ['link' => false, ]);
            $preparedarray[$i]['username'] = fullname($startuser, has_capability('moodle/site:viewfullnames', $context));
            $preparedarray[$i]['userlink'] = $CFG->wwwroot . '/user/view.php?id=' .
                $discussion->userid . '&overflowid=' . $overflow->id;
        }

        // Get the amount of replies and the link to the discussion.
        $preparedarray[$i]['replyamount'] = $discussion->replies;
        $preparedarray[$i]['questionunderreview'] = $discussion->reviewed == 0;

        // Are there unread messages? Create a link to them.
        $preparedarray[$i]['unreadamount'] = $discussion->unread;
        $preparedarray[$i]['unread'] = ($preparedarray[$i]['unreadamount'] > 0) ? true : false;
        $preparedarray[$i]['unreadlink'] = $CFG->wwwroot .
            '/local/overflow/discussion.php?d=' . $discussion->discussion . '#unread';
        $link = '/local/overflow/markposts.php?m=';
        $preparedarray[$i]['markreadlink'] = $CFG->wwwroot . $link . $overflow->id . '&d=' . $discussion->discussion;

        // Check the date of the latest post. Just in case the database is not consistent.
        $usedate = (empty($discussion->timemodified)) ? $discussion->modified : $discussion->timemodified;

        // Get the name and the link to the profile of the user, that is related to the latest post.
        $usermodified = new stdClass();
        $usermodified->id = $discussion->usermodified;

        if ($usermodified->id == 0 || $overflow->anonymous) {
            if ($usermodified->id == $USER->id) {
                $preparedarray[$i]['lastpostusername'] = null;
                $preparedarray[$i]['lastpostuserlink'] = null;
            } else {
                $preparedarray[$i]['lastpostusername'] = null;
                $preparedarray[$i]['lastpostuserlink'] = null;
            }
        } else {
            $usermodified = username_load_fields_from_object($usermodified, $discussion, 'um');
            $preparedarray[$i]['lastpostusername'] = fullname($usermodified);
            $preparedarray[$i]['lastpostuserlink'] = $CFG->wwwroot . '/user/view.php?id=' .
                $discussion->usermodified . '&overflow=' . $overflow->id;
        }

        // Get the date of the latest post of the discussion.
        $parenturl = (empty($discussion->lastpostid)) ? '' : '&parent=' . $discussion->lastpostid;
        $preparedarray[$i]['lastpostdate'] = userdate($usedate, get_string('strftimerecentfull'));
        $preparedarray[$i]['lastpostlink'] = $preparedarray[$i]['subjectlink'] . $parenturl;

        // Check whether the discussion is subscribed.
        $preparedarray[$i]['discussionsubicon'] = false;
        if ((!isguestuser() && isloggedin()) && has_capability('local/overflow:viewdiscussion', $context)) {
            // Discussion subscription.
            if (\local_overflow\subscriptions::is_subscribable($overflow, $context)) {
                $preparedarray[$i]['discussionsubicon'] = \local_overflow\subscriptions::get_discussion_subscription_icon(
                    $overflow, $context, $discussion->discussion);
            }
        }

        if ($canreviewposts) {
            $reviewinfo = review::get_short_review_info_for_discussion($discussion->discussion);
            $preparedarray[$i]['needreview'] = $reviewinfo->count;
            $preparedarray[$i]['reviewlink'] = (new moodle_url('/local/overflow/discussion.php', [
                'd' => $discussion->discussion,
            ], 'p' . $reviewinfo->first))->out(false);
        }

        // Build linktopopup to move a topic.
        $linktopopup = $CFG->wwwroot . '/local/overflow/view.php?id=' . $overflow->id . '&movetopopup=' . $discussion->discussion;
        $preparedarray[$i]['linktopopup'] = $linktopopup;

        // Add all created data to an array.
        $preparedarray[$i]['markedhelpful'] = $markedhelpful;
        $preparedarray[$i]['markedsolution'] = $markedsolution;
        $preparedarray[$i]['statusboth'] = $statusboth;
        $preparedarray[$i]['votes'] = $votes;

        // Did the user rated this post?
        $rating = \local_overflow\ratings::overflow_user_rated($discussion->firstpost);

        $firstpost = overflow_get_post_full($discussion->firstpost);

        $preparedarray[$i]['userupvoted'] = ($rating->rating ?? null) == RATING_UPVOTE;
        $preparedarray[$i]['userdownvoted'] = ($rating->rating ?? null) == RATING_DOWNVOTE;
        $preparedarray[$i]['canchange'] = \local_overflow\ratings::overflow_user_can_rate($firstpost, $context) &&
                $startuser->id != $USER->id;
        $preparedarray[$i]['postid'] = $discussion->firstpost;

        // Go to the next discussion.
        $i++;
    }

    // Include the renderer.
    $renderer = $PAGE->get_renderer('local_overflow');

    // Collect the needed data being submitted to the template.
    $mustachedata = new stdClass();
    $mustachedata->cantrack = $cantrack;
    $mustachedata->canviewdiscussions = $canviewdiscussions;
    $mustachedata->canreview = $canreviewposts;
    $mustachedata->discussions = $preparedarray;
    $mustachedata->hasdiscussions = (count($discussions) >= 0) ? true : false;
    $mustachedata->istracked = $istracked;
    $mustachedata->markallread = $markallread;
    $mustachedata->cansubtodiscussion = $cansubtodiscussion;
    $mustachedata->canmovetopic = $canmovetopic;
    $mustachedata->cannormoveorsub = ((!$canmovetopic) && (!$cansubtodiscussion));
    // Print the template.
    echo $renderer->render_discussion_list($mustachedata);

    // Show the paging bar if paging is activated.
    if ($page != -1) {
        echo $OUTPUT->paging_bar($numberofdiscussions, $page, $perpage, "view.php?id=$overflow->id");
    }
}

/**
 * Prints a popup with a menu of other overflow in the page.
 * Menu to move a topic to another overflow forum.
 *
 * @param int $movetopopup forum where forum list is being printed.
 */
function overflow_print_forum_list($movetopopup) {
    global $CFG, $DB, $PAGE;
    $forumarray = [[]];
    $currentforum = $DB->get_record('overflow_discussions', ['id' => $movetopopup], 'overflow');
    $currentdiscussion = $DB->get_record('overflow_discussions', ['id' => $movetopopup], 'name');

    // If the currentforum is anonymous, only show forums that have a higher anonymous setting.
    $anonymoussetting = $DB->get_field('overflow', 'anonymous', ['id' => $currentforum->overflow]);
    if ($anonymoussetting == anonymous::QUESTION_ANONYMOUS || $anonymoussetting == anonymous::EVERYTHING_ANONYMOUS) {
        $params = ['anonymous' => $anonymoussetting,
                   'currentforumid' => $currentforum->overflow ];
        $sql = "SELECT *
               FROM {overflow}
               WHERE 1=1
                 AND anonymous >= :anonymous
                 AND id != :currentforumid";
        $forums = $DB->get_records_sql($sql, $params);
    } else {
        $forums = $DB->get_records('overflow', ['id' => $currentforum->overflow]);
    }

    $amountforums = count($forums);

    if ($amountforums >= 1) {
        // Write the overflow-names in an array.
        $i = 0;
        foreach ($forums as $forum) {
            if ($forum->id == $currentforum->overflow) {
                continue;
            } else {
                $forumarray[$i]['name'] = $forum->name;
                $movetoforum = $CFG->wwwroot . '/local/overflow/view.php?overflowid=' . $currentforum->overflow . '&movetopopup='
                                             . $movetopopup . '&movetoforum=' . $forum->id;
                $forumarray[$i]['movetoforum'] = $movetoforum;
            }
            $i++;
        }
        $amountforums = true;
    } else {
        $amountforums = false;
    }

    // Build popup.
    $renderer = $PAGE->get_renderer('local_overflow');
    $mustachedata = new stdClass();
    $mustachedata->hasforums = $amountforums;
    $mustachedata->forums = $forumarray;
    $mustachedata->currentdiscussion = $currentdiscussion->name;
    echo $renderer->render_forum_list($mustachedata);
}


/**
 * Returns an array of counts of replies for each discussion.
 *
 * @param object $overflow overflow
 *
 * @return array
 */
function overflow_count_discussion_replies($overflow) {
    global $DB, $USER;

    $context = context_system::instance();

    $params = [$overflow->id];
    $whereconditions = ['d.overflow = ?', 'p.parent > 0'];

    if (!has_capability('local/overflow:reviewpost', $context)) {
        $whereconditions[] = '(p.reviewed = 1 OR p.userid = ?)';
        $params[] = $USER->id;
    }

    $wheresql = join(' AND ', $whereconditions);

    $sql = "SELECT p.discussion, COUNT(p.id) AS replies, MAX(p.id) AS lastpostid
              FROM {overflow_posts} p
                   JOIN {overflow_discussions} d ON p.discussion = d.id
             WHERE $wheresql
          GROUP BY p.discussion";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Check if the user is capable of starting a new discussion.
 *
 * @param object $overflow
 * @param object $context
 *
 * @return bool
 */
function overflow_user_can_post_discussion($overflow, $context = null) {

    // Guests an not-logged-in users can not psot.
    if (isguestuser() || !isloggedin()) {
        return false;
    }

    // Get the context if not set in the parameters.
    if (!$context) {
        $context = context_system::instance(); ;
    }

    // Check the capability.
    if (has_capability('local/overflow:startdiscussion', $context)) {
        return true;
    } else {
        return false;
    }
}

/**
 * Returns the amount of discussions of the given context module.
 *
 * @param object $overflow
 *
 * @return array
 */
function overflow_get_discussions_count($overflow) {
    global $DB, $USER;

    $context = context_system::instance();

    $params = [$overflow->id];
    $whereconditions = ['d.overflow = ?', 'p.parent = 0'];

    if (!has_capability('local/overflow:reviewpost', $context)) {
        $whereconditions[] = '(p.reviewed = 1 OR p.userid = ?)';
        $params[] = $USER->id;
    }

    $wheresql = join(' AND ', $whereconditions);

    $sql = 'SELECT COUNT(d.id)
            FROM {overflow_discussions} d
                    JOIN {overflow_posts} p ON p.discussion = d.id
            WHERE ' . $wheresql;

    return $DB->get_field_sql($sql, $params);
}

/**
 * Returns an array of unread messages for the current user.
 *
 * @param object $overflow
 *
 * @return array
 */
function overflow_get_discussions_unread($overflow) {
    global $DB, $USER;

    // Get the current timestamp and the oldpost-timestamp.
    $now = round(time(), -2);
    $cutoffdate = $now - (get_config('local_overflow', 'oldpostdays') * 24 * 60 * 60);

    $context = context_system::instance();

    $whereconditions = ['d.overflow = :overflowid', 'p.modified >= :cutoffdate', 'r.id is NULL'];
    $params = [
            'userid' => $USER->id,
            'overflowid' => $overflow->id,
            'cutoffdate' => $cutoffdate,
    ];

    if (!has_capability('local/overflow:reviewpost', $context)) {
        $whereconditions[] = '(p.reviewed = 1 OR p.userid = :userid2)';
        $params['userid2'] = $USER->id;
    }

    $wheresql = join(' AND ', $whereconditions);

    // Define the sql-query.
    $sql = "SELECT d.id, COUNT(p.id) AS unread
            FROM {overflow_discussions} d
                JOIN {overflow_posts} p ON p.discussion = d.id
                LEFT JOIN {overflow_read} r ON (r.postid = p.id AND r.userid = :userid)
            WHERE $wheresql
            GROUP BY d.id";

    // Return the unread messages as an array.
    if ($unreads = $DB->get_records_sql($sql, $params)) {
        $returnarray = [];
        foreach ($unreads as $unread) {
            $returnarray[$unread->id] = $unread->unread;
        }
        return $returnarray;
    } else {

        // If there are no unread messages, return an empty array.
        return [];
    }
}

/**
 * Gets a post with all info ready for overflow_print_post.
 * Most of these joins are just to get the forum id.
 *
 * @param int $postid
 *
 * @return mixed array of posts or false
 */
function overflow_get_post_full($postid) {
    global $DB, $CFG;

    if ($CFG->branch >= 311) {
        $allnames = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    } else {
        $allnames = get_all_user_name_fields(true, 'u');
    }
    $sql = "SELECT p.*, d.overflow, $allnames, u.email, u.picture, u.imagealt
              FROM {overflow_posts} p
                   JOIN {overflow_discussions} d ON p.discussion = d.id
              LEFT JOIN {user} u ON p.userid = u.id
                  WHERE p.id = :postid";
    $params = [];
    $params['postid'] = $postid;

    $post = $DB->get_record_sql($sql, $params);
    if ($post->userid === 0) {
        $post->message = get_string('privacy:anonym_post_message', 'local_overflow');
    }

    return $post;
}

/**
 * Checks if a user can see a specific post.
 *
 * @param object $overflow
 * @param object $discussion
 * @param object $post
 *
 * @return bool
 */
function overflow_user_can_see_post($overflow, $discussion, $post) {
    global $USER, $DB;

    // Retrieve the context.
    $context = context_system::instance();

    // Fetch the overflow instance object.
    if (is_numeric($overflow)) {
        debugging('missing full overflow', DEBUG_DEVELOPER);
        if (!$overflow = $DB->get_record('overflow', ['id' => $overflow])) {
            return false;
        }
    }

    // Fetch the discussion object.
    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('overflow_discussions', ['id' => $discussion])) {
            return false;
        }
    }

    // Fetch the post object.
    if (is_numeric($post)) {
        debugging('missing full post', DEBUG_DEVELOPER);
        if (!$post = $DB->get_record('overflow_posts', ['id' => $post])) {
            return false;
        }
    }

    // Get the postid if not set.
    if (!isset($post->id) && isset($post->parent)) {
        $post->id = $post->parent;
    }

    // Check if the user can view the discussion.
    if (!capabilities::has(capabilities::VIEW_DISCUSSION, $context)) {
        return false;
    }

    if (!($post->reviewed == 1 || $post->userid == $USER->id ||
        capabilities::has(capabilities::REVIEW_POST, $context))) {
        return false;
    }

    // The user has the capability to see the discussion.
    //return \core_availability\info_module::is_user_visible($overflow, $USER->id, false);
    return true;
}

/**
 * Check if a user can see a specific discussion.
 *
 * @param object $overflow
 * @param object $discussion
 * @param context $context
 *
 * @return bool
 */
function overflow_user_can_see_discussion($overflow, $discussion, $context) {
    global $DB;

    // Retrieve the overflow object.
    if (is_numeric($overflow)) {
        debugging('missing full overflow', DEBUG_DEVELOPER);
        if (!$overflow = $DB->get_record('overflow', ['id' => $overflow])) {
            return false;
        }
    }

    // Retrieve the discussion object.
    if (is_numeric($discussion)) {
        debugging('missing full discussion', DEBUG_DEVELOPER);
        if (!$discussion = $DB->get_record('overflow_discussions', ['id' => $discussion])) {
            return false;
        }
    }

    return capabilities::has(capabilities::VIEW_DISCUSSION, $context);
}

/**
 * Creates a new overflow discussion.
 *
 * @param stdClass $discussion The discussion object
 * @param context_system $context
 * @param int      $userid     The user ID
 *
 * @return bool|int The id of the created discussion
 */
function overflow_add_discussion($discussion, $userid = null) {
    global $DB, $USER;

    // Get the current time.
    $timenow = time();

    // Get the current user.
    if (is_null($userid)) {
        $userid = $USER->id;
    }

    // The first post of the discussion is stored
    // as a real post. The discussion links to it.

    // Retrieve the module instance.
    if (!$overflow = $DB->get_record('overflow', ['id' => $discussion->overflow])) {
        return false;
    }

    $context = context_system::instance();

    // Create the post-object.
    $post = new stdClass();
    $post->discussion = 0;
    $post->parent = 0;
    $post->userid = $userid;
    $post->created = $timenow;
    $post->modified = $timenow;
    $post->message = $discussion->message;
    $post->attachments = $discussion->attachments;
    $post->overflow = $overflow->id;

    // Set to not reviewed, if questions should be reviewed, and user is not a reviewer themselves.
    if (review::get_review_level($overflow) >= review::QUESTIONS &&
            !capabilities::has(capabilities::REVIEW_POST, $context, $userid)) {
        $post->reviewed = 0;
    }

    // Submit the post to the database and get its id.
    $post->id = $DB->insert_record('overflow_posts', $post);
    // Save draft files to permanent file area.
    $post->message = file_save_draft_area_files($discussion->draftideditor, $context->id, 'local_overflow', 'post',
            $post->id, null, $post->message);
    $DB->set_field('overflow_posts', 'message', $post->message, ['id' => $post->id]);

    // Create the discussion object.
    $discussionobject = new stdClass();
    $discussionobject->overflow = $discussion->overflow;
    $discussionobject->name = $discussion->name;
    $discussionobject->firstpost = $post->id;
    $discussionobject->userid = $post->userid;
    $discussionobject->timemodified = $timenow;
    $discussionobject->timestart = $timenow;
    $discussionobject->usermodified = $post->userid;

    // Submit the discussion to the database and get its id.
    $post->discussion = $DB->insert_record('overflow_discussions', $discussionobject);

    // Link the post to the discussion.
    $DB->set_field('overflow_posts', 'discussion', $post->discussion, ['id' => $post->id]);

    overflow_add_attachment($post, $overflow, );

    // Mark the created post as read.
    $cantrack = readtracking::overflow_can_track_overflows($overflow);
    $istracked = readtracking::overflow_is_tracked($overflow);
    if ($cantrack && $istracked) {
        readtracking::overflow_mark_post_read($post->userid, $post);
    }

    // Trigger event.
    $params = [
        'context' => $context,
        'objectid' => $post->discussion,
    ];

    $event = \local_overflow\event\discussion_viewed::create($params);
    $event->trigger();

    // Return the id of the discussion.
    return $post->discussion;
}

/**
 * Modifies the session to return back to where the user is coming from.
 *
 * @param object $default
 *
 * @return mixed
 */
function overflow_go_back_to($default) {
    global $SESSION;

    if (!empty($SESSION->fromdiscussion)) {
        $returnto = $SESSION->fromdiscussion;
        unset($SESSION->fromdiscussion);

        return $returnto;
    } else {
        return $default;
    }
}

/**
 * Checks whether the user can reply to posts in a discussion.
 *
 * @param object $context
 * @param object $posttoreplyto
 * @param bool $considerreviewstatus
 * @param int $userid
 * @return bool Whether the user can reply
 * @throws coding_exception
 */
function overflow_user_can_post($context, $posttoreplyto, $considerreviewstatus = true, $userid = null) {
    global $USER;

    // If not user is submitted, use the current one.
    if (empty($userid)) {
        $userid = $USER->id;
    }

    // Check the users capability.
    if (!has_capability('local/overflow:replypost', $context, $userid)) {
        return false;
    }
    return !$considerreviewstatus || $posttoreplyto->reviewed == 1;
}

/**
 * Prints a overflow discussion.
 *
 * @param stdClass $overflow The overflow object
 * @param stdClass $discussion     The discussion object
 * @param stdClass $post           The post object
 * @param bool     $multiplemarks  The setting of multiplemarks (default: multiplemarks are not allowed)
 */
function overflow_print_discussion($overflow, $discussion, $post, $multiplemarks = false) {
    global $USER;
    // Check if the current is the starter of the discussion.
    $ownpost = (isloggedin() && ($USER->id == $post->userid));

    // Fetch the system context.
    $context = context_system::instance();

    // Is the forum tracked?
    $istracked = readtracking::overflow_is_tracked($overflow);

    // Retrieve all posts of the discussion.
    $posts = overflow_get_all_discussion_posts($discussion->id, $istracked, $context);
    $usermapping = anonymous::get_userid_mapping($overflow, $discussion->id);

    // Start with the parent post.
    $post = $posts[$post->id];

    $answercount = 0;

    // Lets clear all posts above level 2.
    // Check if there are answers.
    if (isset($post->children)) {

        // Itereate through all answers.
        foreach ($post->children as $aid => $a) {
            $answercount += 1;

            // Check for each answer if they have children as well.
            if (isset($post->children[$aid]->children)) {

                // Iterate through all comments.
                foreach ($post->children[$aid]->children as $cid => $c) {

                    // Delete the children of the comments.
                    if (isset($post->children[$aid]->children[$cid]->children)) {
                        unset($post->children[$aid]->children[$cid]->children);
                    }
                }
            }
        }
    }

    // Format the subject.
    $post->overflow = $overflow->id;
    $post->subject = format_string($post->subject);

    // Check if the post was read.
    $postread = !empty($post->postread);

    // Print the starting post.
    echo overflow_print_post($post, $discussion, $overflow,
        $ownpost, false, '', '', $postread, true, $istracked, 0, $usermapping, 0, $multiplemarks);

    // Print answer divider.
    if ($answercount == 1) {
        $answerstring = get_string('answer', 'local_overflow', $answercount);
    } else {
        $answerstring = get_string('answers', 'local_overflow', $answercount);
    }
    echo "<br><h2>$answerstring</h2>";

    echo '<div id="overflow-posts">';

    // Print the other posts.
    echo overflow_print_posts_nested($overflow, $discussion, $post, $istracked, $posts,
        null, $usermapping, $multiplemarks);

    echo '</div>';
}

/**
 * Get all posts in discussion including the starting post.
 *
 * @param int     $discussionid The ID of the discussion
 * @param boolean $tracking     Whether tracking is activated
 * @param context_system $context Context of the context
 *
 * @return array
 */
function overflow_get_all_discussion_posts($discussionid, $tracking, $context) {
    global $DB, $USER, $CFG;

    // Initiate tracking settings.
    $params = [];
    $trackingselector = "";
    $trackingjoin = "";
    $params = [];

    // If tracking is enabled, another join is needed.
    if ($tracking) {
        $trackingselector = ", mr.id AS postread";
        $trackingjoin = "LEFT JOIN {overflow_read} mr ON (mr.postid = p.id AND mr.userid = :userid)";
        $params['userid'] = $USER->id;
    }

    // Get all username fields.
    if ($CFG->branch >= 311) {
        $allnames = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    } else {
        $allnames = get_all_user_name_fields(true, 'u');
    }

    $additionalwhere = '';

    if (!has_capability('local/overflow:reviewpost', $context)) {
        $additionalwhere = ' AND (p.reviewed = 1 OR p.userid = :userid2) ';
        $params['userid2'] = $USER->id;
    }

    // Create the sql array.
    $sql = "SELECT p.*, m.ratingpreference, $allnames, d.name as subject, u.email, u.picture, u.imagealt $trackingselector
              FROM {overflow_posts} p
                   LEFT JOIN {user} u ON p.userid = u.id
                   LEFT JOIN {overflow_discussions} d ON d.id = p.discussion
                   LEFT JOIN {overflow} m on m.id = d.overflow
                   $trackingjoin
             WHERE p.discussion = :discussion $additionalwhere
          ORDER BY p.created ASC";
    $params['discussion'] = $discussionid;

    // Return an empty array, if there are no posts.
    if (!$posts = $DB->get_records_sql($sql, $params)) {
        return [];
    }

    // Load all ratings.
    $discussionratings = \local_overflow\ratings::overflow_get_ratings_by_discussion($discussionid);

    // Assign ratings to the posts.
    foreach ($posts as $postid => $post) {

        // Assign the ratings to the matching posts.
        $posts[$postid]->upvotes = $discussionratings[$post->id]->upvotes;
        $posts[$postid]->downvotes = $discussionratings[$post->id]->downvotes;
        $posts[$postid]->votesdifference = $posts[$postid]->upvotes - $posts[$postid]->downvotes;
        $posts[$postid]->markedhelpful = $discussionratings[$post->id]->ishelpful;
        $posts[$postid]->markedsolution = $discussionratings[$post->id]->issolved;
    }

    // Order the answers by their ratings.
    $posts = \local_overflow\ratings::overflow_sort_answers_by_ratings($posts);

    // Find all children of this post.
    foreach ($posts as $postid => $post) {

        // Is it an old post?
        if ($tracking) {
            if (readtracking::overflow_is_old_post($post)) {
                $posts[$postid]->postread = true;
            }
        }

        // Don't iterate through the parent post.
        if (!$post->parent) {
            $posts[$postid]->level = 0;
            continue;
        }

        // If the parent post does not exist.
        if (!isset($posts[$post->parent])) {
            continue;
        }

        // Create the children array.
        if (!isset($posts[$post->parent]->children)) {
            $posts[$post->parent]->children = [];
        }

        // Increase the level of the current post.
        $posts[$post->parent]->children[$postid] =& $posts[$postid];
    }

    // Return the object.
    return $posts;
}


/**
 * Prints a overflow post.
 * @param object $post
 * @param object $discussion
 * @param object $overflow
 * @param bool $ownpost
 * @param bool $link
 * @param string $footer
 * @param string $highlight
 * @param bool $postisread
 * @param bool $dummyifcantsee
 * @param bool $istracked
 * @param bool $iscomment
 * @param array $usermapping
 * @param int $level
 * @param bool $multiplemarks setting of multiplemarks
 * @return void|null
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function overflow_print_post($post, $discussion, $overflow,
                                $ownpost = false, $link = false,
                                $footer = '', $highlight = '', $postisread = null,
                                $dummyifcantsee = true, $istracked = false,
                                $iscomment = false, $usermapping = [], $level = 0, $multiplemarks = false) {
    global $USER, $CFG, $OUTPUT, $PAGE;

    // Require the filelib.
    require_once($CFG->libdir . '/filelib.php');

    // String cache.
    static $str;

    // Print the 'unread' only on time.
    static $firstunreadanchorprinted = false;

    // Declare the context.
    $context = context_system::instance();

    // Post was anonymized.
    if ($post->userid == 0) {
        $post->message = get_string('privacy:anonym_post_message', 'local_overflow');
    }

    // Add some informationto the post.
    $post->overflow = $overflow->id;
    $mcid = $context->id;

    // Check if the user has the capability to see posts.
    if (!overflow_user_can_see_post($overflow, $discussion, $post)) {

        // No dummy message is requested.
        if (!$dummyifcantsee) {
            echo '';

            return;
        }

        // Include the renderer to display the dummy content.
        $renderer = $PAGE->get_renderer('local_overflow');

        // Collect the needed data being submitted to the template.
        $mustachedata = new stdClass();

        // Print the template.
        return $renderer->render_post_dummy_cantsee($mustachedata);
    }

    // Check if the strings have been cached.
    if (empty($str)) {
        $str = new stdClass();
        $str->edit = get_string('edit', 'local_overflow');
        $str->delete = get_string('delete', 'local_overflow');
        $str->reply = get_string('reply', 'local_overflow');
        $str->replyfirst = get_string('replyfirst', 'local_overflow');
        $str->parent = get_string('parent', 'local_overflow');
        $str->markread = get_string('markread', 'local_overflow');
        $str->markunread = get_string('markunread', 'local_overflow');
        $str->marksolved = get_string('marksolved', 'local_overflow');
        $str->alsomarksolved = get_string('alsomarksolved', 'local_overflow');
        $str->marknotsolved = get_string('marknotsolved', 'local_overflow');
        $str->markhelpful = get_string('markhelpful', 'local_overflow');
        $str->alsomarkhelpful = get_string('alsomarkhelpful', 'local_overflow');
        $str->marknothelpful = get_string('marknothelpful', 'local_overflow');
    }

    // Get the current link without unnecessary parameters.
    $discussionlink = new moodle_url('/local/overflow/discussion.php', ['d' => $post->discussion]);

    // Build the object that represents the posting user.
    $postinguser = new stdClass();
    if ($CFG->branch >= 311) {
        $postinguserfields = \core_user\fields::get_picture_fields();
    } else {
        $postinguserfields = explode(',', user_picture::fields());
    }
    $postinguser = username_load_fields_from_object($postinguser, $post, null, $postinguserfields);

    // Post was anonymized.
    if (anonymous::is_post_anonymous($discussion, $overflow, $post->userid)) {
        $postinguser->id = null;
        if ($post->userid == $USER->id) {
            $postinguser->fullname = get_string('anonym_you', 'local_overflow');
            $postinguser->profilelink = new moodle_url('/user/view.php', ['id' => $post->userid]);
        } else {
            $postinguser->fullname = $usermapping[(int) $post->userid];
            $postinguser->profilelink = null;
        }
    } else {
        $postinguser->fullname = fullname($postinguser, capabilities::has('moodle/site:viewfullnames', $context));
        $postinguser->profilelink = new moodle_url('/user/view.php', ['id' => $post->userid]);
        $postinguser->id = $post->userid;
    }

    // Prepare an array of commands.
    $commands = [];

    // Create a permalink.
    $permalink = new moodle_url($discussionlink);
    $permalink->set_anchor('p' . $post->id);

    // Check if multiplemarks are allowed, if so, check if there are already marked posts.
    $helpfulposts = false;
    $solvedposts = false;
    if ($multiplemarks) {
        $helpfulposts = \local_overflow\ratings::overflow_discussion_is_solved($discussion->id, false);
        $solvedposts = \local_overflow\ratings::overflow_discussion_is_solved($discussion->id, true);
    }

    // If the user has started the discussion, he can mark the answer as helpful.
    $canmarkhelpful = (($USER->id == $discussion->userid) && ($USER->id != $post->userid) &&
        ($iscomment != $post->parent) && !empty($post->parent));
    if ($canmarkhelpful) {
        // When the post is already marked, remove the mark instead.
        $link = '/local/overflow/discussion.php';
        if ($post->markedhelpful) {
            $commands[] = html_writer::tag('a', $str->marknothelpful,
                    ['class' => 'markhelpful onlyifreviewed', 'role' => 'button', 'data-overflow-action' => 'helpful']);
        } else {
            // If there are already marked posts, change the string of the button.
            if ($helpfulposts) {
                $commands[] = html_writer::tag('a', $str->alsomarkhelpful,
                    ['class' => 'markhelpful onlyifreviewed', 'role' => 'button', 'data-overflow-action' => 'helpful']);
            } else {
                $commands[] = html_writer::tag('a', $str->markhelpful,
                    ['class' => 'markhelpful onlyifreviewed', 'role' => 'button', 'data-overflow-action' => 'helpful']);
            }
        }
    }

    // A teacher can mark an answer as solved.
    $canmarksolved = (($iscomment != $post->parent) && !empty($post->parent)
                                                    && capabilities::has(capabilities::MARK_SOLVED, $context));
    if ($canmarksolved) {

        // When the post is already marked, remove the mark instead.
        $link = '/local/overflow/discussion.php';
        if ($post->markedsolution) {
            $commands[] = html_writer::tag('a', $str->marknotsolved,
                    ['class' => 'marksolved onlyifreviewed', 'role' => 'button', 'data-overflow-action' => 'solved']);
        } else {
            // If there are already marked posts, change the string of the button.
            if ($solvedposts) {
                $commands[] = html_writer::tag('a', $str->alsomarksolved,
                    ['class' => 'marksolved onlyifreviewed', 'role' => 'button', 'data-overflow-action' => 'solved']);
            } else {
                $commands[] = html_writer::tag('a', $str->marksolved,
                    ['class' => 'marksolved onlyifreviewed', 'role' => 'button', 'data-overflow-action' => 'solved']);
            }
        }
    }

    // Calculate the age of the post.
    $age = time() - $post->created;
//var_dump($age, get_config('local_overflow', 'maxeditingtime'));
    // Make a link to edit your own post within the given time and not already reviewed.
    if (($ownpost && ($age < get_config('local_overflow', 'maxeditingtime')) &&
                    (!review::should_post_be_reviewed($post, $overflow) || !$post->reviewed))
        || capabilities::has(capabilities::EDIT_ANY_POST, $context)
    ) {
        $editurl = new moodle_url('/local/overflow/post.php', ['edit' => $post->id]);
        $commands[] = ['url' => $editurl, 'text' => $str->edit];
    }

    // Give the option to delete a post.
    $notold = ($age < get_config('local_overflow', 'maxeditingtime'));
    if (($ownpost && $notold && capabilities::has(capabilities::DELETE_OWN_POST, $context)) ||
        capabilities::has(capabilities::DELETE_ANY_POST, $context)) {

        $link = '/local/overflow/post.php';
        $commands[] = ['url' => new moodle_url($link, ['delete' => $post->id]), 'text' => $str->delete];
    }

    // Give the option to reply to a post.
    if (overflow_user_can_post($context, $post, false)) {

        $attributes = [
                'class' => 'onlyifreviewed',
        ];

        // Answer to the parent post.
        if (empty($post->parent)) {
            $replyurl = new moodle_url('/local/overflow/post.php#mformoverflow', ['reply' => $post->id]);
            $commands[] = ['url' => $replyurl, 'text' => $str->replyfirst, 'attributes' => $attributes];

            // If the post is a comment, answer to the parent post.
        } else if (!$iscomment) {
            $replyurl = new moodle_url('/local/overflow/post.php#mformoverflow', ['reply' => $post->id]);
            $commands[] = ['url' => $replyurl, 'text' => $str->reply, 'attributes' => $attributes];

            // Else simple respond to the answer.
        } else {
            $replyurl = new moodle_url('/local/overflow/post.php#mformoverflow', ['reply' => $iscomment]);
            $commands[] = ['url' => $replyurl, 'text' => $str->reply, 'attributes' => $attributes];
        }
    }

    // Initiate the output variables.
    $mustachedata = new stdClass();
    $mustachedata->istracked = $istracked;
    $mustachedata->isread = false;
    $mustachedata->isfirstunread = false;
    $mustachedata->isfirstpost = false;
    $mustachedata->iscomment = (!empty($post->parent) && ($iscomment == $post->parent));
    $mustachedata->permalink = $permalink;

    // Get the ratings.
    $mustachedata->votes = $post->upvotes - $post->downvotes;

    // Check if the post is marked.
    $mustachedata->markedhelpful = $post->markedhelpful;
    $mustachedata->markedsolution = $post->markedsolution;

    // Did the user rated this post?
    $rating = \local_overflow\ratings::overflow_user_rated($post->id);

    // Initiate the variables.
    $mustachedata->userupvoted = false;
    $mustachedata->userdownvoted = false;
    $mustachedata->canchange = $USER->id != $post->userid;

    // Check the actual rating.
    if ($rating) {

        // Convert the object.
        $rating = $rating->rating;

        // Did the user upvoted or downvoted this post?
        // The user upvoted the post.
        if ($rating == 1) {
            $mustachedata->userdownvoted = true;
        } else if ($rating == 2) {
            $mustachedata->userupvoted = true;
        }
    }

    // Check the reading status of the post.
    $postclass = '';
    if ($istracked) {
        if ($postisread) {
            $postclass .= ' read';
            $mustachedata->isread = true;
        } else {
            $postclass .= ' unread';

            // Anchor the first unread post of a discussion.
            if (!$firstunreadanchorprinted) {
                $mustachedata->isfirstunread = true;
                $firstunreadanchorprinted = true;
            }
        }
    }
    if ($post->markedhelpful) {
        $postclass .= ' markedhelpful';
    }
    if ($post->markedsolution) {
        $postclass .= ' markedsolution';
    }
    $mustachedata->postclass = $postclass;

    // Is this the firstpost?
    if (empty($post->parent)) {
        $mustachedata->isfirstpost = true;
    }

    // Create an element for the user which posted the post.
    $postbyuser = new stdClass();
    $postbyuser->post = $post->subject;

    // Anonymization already handled in $postinguser->fullname.
    $postbyuser->user = $postinguser->fullname;

    $mustachedata->discussionby = get_string('postbyuser', 'local_overflow', $postbyuser);

    // Set basic variables of the post.
    $mustachedata->postid = $post->id;
    $mustachedata->subject = format_string($post->subject);

    // Post was anonymized.
    if (!anonymous::is_post_anonymous($discussion, $overflow, $post->userid)) {
        // User picture.
        $mustachedata->picture = $OUTPUT->user_picture($postinguser);
    }

    // The rating of the user.
    if (anonymous::is_post_anonymous($discussion, $overflow, $post->userid)) {
        $postuserrating = null;
    } else {
        $postuserrating = \local_overflow\ratings::overflow_get_reputation($overflow->id, $postinguser->id);
    }

    // The name of the user and the date modified.
    $mustachedata->bydate = userdate($post->modified);
    $mustachedata->byshortdate = userdate($post->modified, get_string('strftimedatetimeshort', 'core_langconfig'));
    $mustachedata->byname = $postinguser->profilelink ?
        html_writer::link($postinguser->profilelink, $postinguser->fullname)
        : $postinguser->fullname;
    $mustachedata->byrating = $postuserrating;
    $mustachedata->byuserid = $postinguser->id;
    $mustachedata->showrating = $postuserrating !== null;
    if (get_config('local_overflow', 'allowdisablerating') == 1) {
        $mustachedata->showvotes = $overflow->allowrating;
        $mustachedata->showreputation = $overflow->allowreputation;
    } else {
        $mustachedata->showvotes = OVERFLOW_RATING_ALLOW;
        $mustachedata->showreputation = OVERFLOW_REPUTATION_ALLOW;
    }
    $mustachedata->questioner = $post->userid == $discussion->userid ? 'questioner' : '';

    $reviewdelay = get_config('local_overflow', 'reviewpossibleaftertime');
    $mustachedata->reviewdelay = format_time($reviewdelay);
    $mustachedata->needsreview = !$post->reviewed;
    $reviewable = time() - $post->created > $reviewdelay;
    $mustachedata->canreview = capabilities::has(capabilities::REVIEW_POST, $context);
    $mustachedata->withinreviewperiod = $reviewable;

    // Prepare the post.
    $post->message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $mcid, 'local_overflow',
                                        'post', $post->id);
    $options = new stdClass();
    $options->para = false;
    $options->newlines = true;
    $options->filter = true;
    $options->noclean = false;
    $options->overflowdiv = false;
    $options->context = $context;
    $mustachedata->postcontent = format_text($post->message, $post->messageformat, $options);

    // Load the attachments.
    $mustachedata->attachments = get_attachments($post);

    // Output the commands.
    $commandhtml = [];
    foreach ($commands as $command) {
        if (is_array($command)) {
            $commandhtml[] = html_writer::link($command['url'], $command['text'], $command['attributes'] ?? null);
        } else {
            $commandhtml[] = $command;
        }
    }
    $mustachedata->commands = implode('', $commandhtml);

    // Print a footer if requested.
    $mustachedata->footer = $footer;

    // Mark the forum post as read.
    if ($istracked && !$postisread) {
        readtracking::overflow_mark_post_read($USER->id, $post);
    }

    $mustachedata->iscomment = $level == 2;

    // Include the renderer to display the dummy content.
    $renderer = $PAGE->get_renderer('local_overflow');

    // Render the different elements.
    return $renderer->render_post($mustachedata);
}


/**
 * Prints all posts of the discussion in a nested form.
 *
 * @param object $overflow The overflow object
 * @param object $discussion     The discussion object
 * @param object $parent         The object of the parent post
 * @param bool   $istracked      Whether the user tracks the discussion
 * @param array  $posts          Array of posts within the discussion
 * @param bool   $iscomment      Whether the current post is a comment
 * @param array $usermapping
 * @param bool  $multiplemarks
 * @return string
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function overflow_print_posts_nested($overflow, $discussion, $parent,
                                    $istracked, $posts, $iscomment = null, $usermapping = [], $multiplemarks = false) {
    global $USER;

    // Prepare the output.
    $output = '';

    // If there are answers.
    if (!empty($posts[$parent->id]->children)) {

        // We do not need the other parts of this variable anymore.
        $posts = $posts[$parent->id]->children;

        // Iterate through all answers.
        foreach ($posts as $post) {

            // Answers should be seperated from each other.
            // While comments should be indented.
            if (!isset($iscomment)) {
                $output .= "<div class='tmargin'>";
                $level = 1;
                $parentid = $post->id;
            } else {
                $output .= "<div class='indent'>";
                $level = 2;
                $parentid = $iscomment;
            }

            // Has the current user written the answer?
            if (!isloggedin()) {
                $ownpost = false;
            } else {
                $ownpost = ($USER->id == $post->userid);
            }

            // Format the subject.
            $post->subject = format_string($post->subject);

            // Determine whether the post has been read by the current user.
            $postread = !empty($post->postread);

            // Print the answer.
            $output .= overflow_print_post($post, $discussion, $overflow,
                $ownpost, false, '', '', $postread, true, $istracked, $parentid, $usermapping, $level, $multiplemarks);

            // Print its children.
            $output .= overflow_print_posts_nested($overflow,
                $discussion, $post, $istracked, $posts, $parentid, $usermapping, $multiplemarks);

            // End the div.
            $output .= "</div>\n";
        }
    }

    // Return the output.
    return $output;
}

/**
 * Returns attachments with information for the template
 *
 * @param object $post
 *
 * @return array
 */
function get_attachments($post) {
    global $OUTPUT;
    $attachments = [];

    if (empty($post->attachment)) {
        return [];
    }

    if (!$context = context_system::instance()) {
        return [];
    }

    $fs = get_file_storage();

    // We retrieve all files according to the time that they were created.  In the case that several files were uploaded
    // at the sametime (e.g. in the case of drag/drop upload) we revert to using the filename.
    $files = $fs->get_area_files($context->id, 'local_overflow', 'attachment', $post->id, "filename", false);
    if ($files) {
        $i = 0;
        foreach ($files as $file) {
            $attachments[$i] = [];
            $attachments[$i]['filename'] = $file->get_filename();

            $mimetype = $file->get_mimetype();
            $iconimage = $OUTPUT->pix_icon(file_file_icon($file),
                get_mimetype_description($file), 'moodle',
                ['class' => 'icon']);
            $path = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(),
                                                     $file->get_itemid(), $file->get_filepath(), $file->get_filename());

            $attachments[$i]['icon'] = $iconimage;
            $attachments[$i]['filepath'] = $path;

            if (in_array($mimetype, ['image/gif', 'image/jpeg', 'image/png'])) {
                // Image attachments don't get printed as links.
                $attachments[$i]['image'] = true;
            } else {
                $attachments[$i]['image'] = false;
            }
            $i += 1;
        }
    }
    return $attachments;
}

/**
 * If successful, this function returns the name of the file
 *
 * @param object $post is a full post record, including forum
 * @param object $forum
 *
 * @return bool
 */
function overflow_add_attachment($post, $forum) {
    global $DB;

    if (empty($post->attachments)) {
        return true;   // Nothing to do.
    }

    $context = context_system::instance();

    $info = file_get_draft_area_info($post->attachments);
    $present = ($info['filecount'] > 0) ? '1' : '';
    file_save_draft_area_files($post->attachments, $context->id, 'local_overflow', 'attachment', $post->id,
        local_overflow_post_form::attachment_options($forum));

    $DB->set_field('overflow_posts', 'attachment', $present, ['id' => $post->id]);

    return true;
}

/**
 * Adds a new post in an existing discussion.
 * @param object $post The post object
 * @return bool|int The Id of the post if operation was successful
 * @throws coding_exception
 * @throws dml_exception
 */
function overflow_add_new_post($post) {
    global $USER, $DB;

    // We do not check if these variables exist because this function
    // is just called from one function which checks all these variables.
    $discussion = $DB->get_record('overflow_discussions', ['id' => $post->discussion]);
    $overflow = $DB->get_record('overflow', ['id' => $discussion->overflow]);
    $context = context_system::instance();

    // Add some variables to the post.
    $post->created = $post->modified = time();
    $post->userid = $USER->id;
    if (!isset($post->totalscore)) {
        $post->totalscore = 0;
    }

    // Set to not reviewed, if posts should be reviewed, and user is not a reviewer themselves.
    if (review::get_review_level($overflow) == review::EVERYTHING &&
            !has_capability('local/overflow:reviewpost', $context)) {
        $post->reviewed = 0;
    } else {
        $post->reviewed = 1;
    }

    // Add the post to the database.
    $post->id = $DB->insert_record('overflow_posts', $post);
    // Save draft files to permanent file area.
    $post->message = file_save_draft_area_files($post->draftideditor, $context->id, 'local_overflow', 'post',
            $post->id, null, $post->message);
    $DB->set_field('overflow_posts', 'message', $post->message, ['id' => $post->id]);
    overflow_add_attachment($post, $overflow);

    if ($post->reviewed) {
        // Update the discussion.
        $DB->set_field('overflow_discussions', 'timemodified', $post->modified, ['id' => $post->discussion]);
        $DB->set_field('overflow_discussions', 'usermodified', $post->userid, ['id' => $post->discussion]);
    }

    // Mark the created post as read if the user is tracking the discussion.
    $cantrack = readtracking::overflow_can_track_overflows($overflow);
    $istracked = readtracking::overflow_is_tracked($overflow);
    if ($cantrack && $istracked) {
        readtracking::overflow_mark_post_read($post->userid, $post);
    }

    // Return the id of the created post.
    return $post->id;
}

/**
 * Updates a specific post.
 *
 * Capabilities are not checked, because this is happening in the post.php.
 *
 * @param object $newpost The new post object
 *
 * @return bool Whether the update was successful
 */
function overflow_update_post($newpost) {
    global $DB, $USER;

    // Retrieve not submitted variables.
    $post = $DB->get_record('overflow_posts', ['id' => $newpost->id]);
    $discussion = $DB->get_record('overflow_discussions', ['id' => $post->discussion]);
    $overflow = $DB->get_record('overflow', ['id' => $discussion->overflow]);
    $context = context_system::instance();

    // Allowed modifiable fields.
    $modifiablefields = [
        'message',
        'messageformat',
    ];

    // Iteratate through all modifiable fields and update the values.
    foreach ($modifiablefields as $field) {
        if (isset($newpost->{$field})) {
            $post->{$field} = $newpost->{$field};
        }
    }

    $post->modified = time();
    if ($newpost->reviewed ?? $post->reviewed) {
        // Update the date and the user of the post and the discussion.
        $discussion->timemodified = $post->modified;
        $discussion->usermodified = $post->userid;
    }

    // When editing the starting post of a discussion.
    if (!$post->parent) {
        $discussion->name = $newpost->subject;
    }

    // Save draft files to permanent file area.
    $post->message = file_save_draft_area_files($newpost->draftideditor, $context->id, 'local_overflow', 'post',
            $post->id, null, $post->message);

    // Update the post and the corresponding discussion.
    $DB->update_record('overflow_posts', $post);
    $DB->update_record('overflow_discussions', $discussion);

    overflow_add_attachment($newpost, $overflow);

    // Mark the edited post as read.
    $cantrack = readtracking::overflow_can_track_overflows($overflow);
    $istracked = readtracking::overflow_is_tracked($overflow);
    if ($cantrack && $istracked) {
        readtracking::overflow_mark_post_read($USER->id, $post);
    }

    // The post has been edited successfully.
    return true;
}

/**
 * Count all replies of a post.
 *
 * @param object $post The post object
 * @param bool $onlyreviewed Whether to count only reviewed posts.
 *
 * @return int Amount of replies
 */
function overflow_count_replies($post, $onlyreviewed) {
    global $DB;

    $conditions = ['parent' => $post->id];

    if ($onlyreviewed) {
        $conditions['reviewed'] = '1';
    }

    // Return the amount of replies.
    return $DB->count_records('overflow_posts', $conditions);
}

/**
 * Deletes a discussion and handles all associated cleanups.
 *
 * @param object $discussion     The discussion object
 * @param object $overflow The overflow object
 *
 * @return bool Whether the deletion was successful.
 */
function overflow_delete_discussion($discussion, $overflow) {
    global $DB;

    // Initiate a pointer.
    $result = true;

    // Get all posts related to the discussion.
    if ($posts = $DB->get_records('overflow_posts', ['discussion' => $discussion->id])) {

        // Iterate through them and delete each one.
        foreach ($posts as $post) {
            $post->overflow = $discussion->overflow;
            if (!overflow_delete_post($post, 'ignore', $overflow)) {

                // If the deletion failed, change the pointer.
                $result = false;
            }
        }
    }

    // Delete the read-records for the discussion.
    readtracking::overflow_delete_read_records(-1, -1, $discussion->id);

    // Remove the subscriptions for this discussion.
    $DB->delete_records('overflow_discuss_subs', ['discussion' => $discussion->id]);
    if (!$DB->delete_records('overflow_discussions', ['id' => $discussion->id])) {
        $result = false;
    }

    // Return if there deletion was successful.
    return $result;
}

/**
 * Deletes a single overflow post.
 *
 * @param object $post                  The post
 * @param bool   $deletechildren        The child posts
 * @param object $overflow        The overflow
 *
 * @return bool Whether the deletion was successful
 */
function overflow_delete_post($post, $deletechildren, $overflow) {
    global $DB, $USER;

    // Iterate through all children and delete them.
    // In case something does not work we throw the error as it should be known that something went ... terribly wrong.
    // All DB transactions are rolled back.
    try {
        $transaction = $DB->start_delegated_transaction();

        $childposts = $DB->get_records('overflow_posts', ['parent' => $post->id]);
        if ($deletechildren && $childposts) {
            foreach ($childposts as $childpost) {
                overflow_delete_post($childpost, true, $overflow);
            }
        }

        // Delete the ratings.
        $DB->delete_records('overflow_ratings', ['postid' => $post->id]);

        // Delete the post.
        if ($DB->delete_records('overflow_posts', ['id' => $post->id])) {
            // Delete the read records.
            readtracking::overflow_delete_read_records(-1, $post->id);

            // Delete the attachments.
            // First delete the actual files on the disk.
            $fs = get_file_storage();
            $context = context_system::instance();
            $attachments = $fs->get_area_files($context->id, 'local_overflow', 'attachment',
                $post->id, "filename", true);
            foreach ($attachments as $attachment) {
                // Get file.
                $file = $fs->get_file($context->id, 'local_overflow', 'attachment', $post->id,
                    $attachment->get_filepath(), $attachment->get_filename());
                // Delete it if it exists.
                if ($file) {
                    $file->delete();
                }
            }

            // Just in case, check for the new last post of the discussion.
            overflow_discussion_update_last_post($post->discussion);

            // Trigger the post deletion event.
            $params = [
                'context' => $context,
                'objectid' => $post->id,
                'other' => [
                    'discussionid' => $post->discussion,
                    'overflowid' => $overflow->id,
                ],
            ];
            if ($post->userid !== $USER->id) {
                $params['relateduserid'] = $post->userid;
            }
            $event = post_deleted::create($params);
            $event->trigger();

            // The post has been deleted.
            $transaction->allow_commit();
            return true;
        }
    } catch (Exception $e) {
        $transaction->rollback($e);
    }

    // Deleting the post failed.
    return false;
}

/**
 * Sets the last post for a given discussion.
 *
 * @param int $discussionid The discussion ID
 *
 * @return bool Whether the last post needs to be updated
 */
function overflow_discussion_update_last_post($discussionid) {
    global $DB;

    // Check if the given discussion exists.
    if (!$DB->record_exists('overflow_discussions', ['id' => $discussionid])) {
        return false;
    }

    // Find the last reviewed post of the discussion. (even if user has review capability, because it is written to DB).
    $sql = "SELECT id, userid, modified
              FROM {overflow_posts}
             WHERE discussion = ?
               AND reviewed = 1
          ORDER BY modified DESC";

    // Find the new last post of the discussion.
    if (($lastposts = $DB->get_records_sql($sql, [$discussionid], 0, 1))) {
        $lastpost = reset($lastposts);

        // Create an discussion object.
        $discussionobject = new stdClass();
        $discussionobject->id = $discussionid;
        $discussionobject->usermodified = $lastpost->userid;
        $discussionobject->timemodified = $lastpost->modified;

        // Update the discussion.
        $DB->update_record('overflow_discussions', $discussionobject);

        return $lastpost->id;
    }

    // Just in case, return false.
    return false;
}

/**
 * Save the referer for later redirection.
 */
function overflow_set_return() {
    global $CFG, $SESSION;

    // Get the referer.
    if (!isset($SESSION->fromdiscussion)) {
        $referer = get_local_referer(false);

        // If the referer is not a login screen, save it.
        if (!strncasecmp("$CFG->wwwroot/login", $referer, 300)) {
            $SESSION->fromdiscussion = $referer;
        }
    }
}

/**
 * Count the amount of discussions per overflow.
 *
 * @param object $overflow
 *
 * @return int|mixed
 */
function overflow_count_discussions($overflow) {
    global $CFG, $DB;

    // Create a cache.
    static $cache = [];

    // Initiate variables.
    $params = [$overflow->id];

    // Check whether the cache for the overflow is set.
    if (!isset($cache[$overflow->id])) {

        // Count the number of discussions.
        $sql = "SELECT m.id, COUNT(d.id) as dcount
                  FROM {overflow} m
                  JOIN {overflow_discussions} d on d.overflow = m.id
                 WHERE m.id = ?
              GROUP BY m.id";
        $counts = $DB->get_records_sql($sql, $params);

        // Check whether there are discussions.
        if ($counts) {

            // Loop through all records.
            foreach ($counts as $count) {
                $counts[$count->id] = $count->dcount;
            }

            // Cache the overflow.
            $cache[$overflow->id] = $counts;

        } else {
            // There are no records.

            // Save the result into the cache.
            $cache[$overflow->id] = [];
        }
    }

    // Check whether there are discussions.
    if (empty($cache[$overflow->id])) {
        return 0;
    }

    // Count the discussions.
    $sql = "SELECT COUNT(d.id)
            FROM {overflow_discussions} d
            WHERE d.overflow = ?";
    $amount = $DB->get_field_sql($sql, [$overflow->id]);

    // Return the amount.
    return $amount;
}

/**
 * Updates user grade.
 *
 * @param object $overflow
 * @param int $postuserrating
 * @param object $postinguser
 *
 */
function overflow_update_user_grade($overflow, $postuserrating, $postinguser) {

    // Check whether overflow object has the added params.
    if ($overflow->grademaxgrade > 0 && $overflow->gradescalefactor > 0) {
        overflow_update_user_grade_on_db($overflow, $postuserrating, $postinguser);
    }
}

/**
 * Updates user grade in database.
 *
 * @param object $overflow
 * @param int $postuserrating
 * @param int $userid
 *
 */
function overflow_update_user_grade_on_db($overflow, $postuserrating, $userid) {
    global $DB;

    // Calculate the posting user's updated grade.
    $grade = $postuserrating / $overflow->gradescalefactor;

    if ($grade > $overflow->grademaxgrade) {

        $grade = $overflow->grademaxgrade;
    }

    // Save updated grade on local table.
    if ($DB->record_exists('overflow_grades', ['userid' => $userid, 'overflowid' => $overflow->id])) {

        $DB->set_field('overflow_grades', 'grade', $grade, ['userid' => $userid,
            'overflowid' => $overflow->id, ]);

    } else {

        $gradedataobject = new stdClass();
        $gradedataobject->overflowid = $overflow->id;
        $gradedataobject->userid = $userid;
        $gradedataobject->grade = $grade;
        $DB->insert_record('overflow_grades', $gradedataobject, false);
    }

    // Update gradebook.
    overflow_update_grades($overflow, $userid);
}

/**
 * Updates all grades for context module.
 *
 * @param int $overflowid
 *
 */
function overflow_update_all_grades_for_cm($overflowid) {
    global $DB;

    $overflow = $DB->get_record('overflow', ['id' => $overflowid]);

    // Check whether overflow object has the added params.
    if ($overflow->grademaxgrade > 0 && $overflow->gradescalefactor > 0) {

        // Get all users id.
        $params = ['overflowid' => $overflowid, 'overflowid2' => $overflowid];
        $sql = 'SELECT DISTINCT u.userid FROM (
                    SELECT p.userid as userid
                    FROM {overflow_discussions} d, {overflow_posts} p
                    WHERE d.id = p.discussion AND d.overflow = :overflowid
                    UNION
                    SELECT r.userid as userid
                    FROM {overflow_ratings} r
                    WHERE r.overflowid = :overflowid2
                ) as u';
        $userids = $DB->get_fieldset_sql($sql, $params);

        // Iterate all users.
        foreach ($userids as $userid) {
            if ($userid == 0) {
                continue;
            }

            // Get user reputation.
            $userrating = \local_overflow\ratings::overflow_get_reputation($overflow->id, $userid, true);

            // Calculate the posting user's updated grade.
            overflow_update_user_grade_on_db($overflow, $userrating, $userid);
        }
    }
}

/**
 * Updates all grades.
 */
function overflow_update_all_grades() {
    global $DB;
    $overflows = $DB->get_records_select('overflow', null, null, 'id');
    foreach ($overflows as $overflow) {
        overflow_update_all_grades_for_cm($overflow->id);
    }
}


/**
 * Function to sort an array with a quicksort algorithm. This function is a recursive function that needs to
 * be called from outside.
 *
 * @param array $array The array to be sorted. It is passed by reference.
 * @param int $low The lowest index of the array. The first call should set it to 0.
 * @param int $high The highest index of the array. The first call should set it to the length of the array - 1.
 *
 * @param string $key The key/attribute after what the algorithm sorts. The key should be an comparable integer.
 * @param string $order The order of the sorting. It can be 'asc' or 'desc'.
 * @return void
 */
function overflow_quick_array_sort(&$array, $low, $high, $key, $order) {
    if ($low >= $high) {
        return;
    }
    $left = $low;
    $right = $high;
    $pivot = $array[intval(($low + $high) / 2)]->$key;

    $compare = function($a, $b) use ($order) {
        if ($order == 'asc') {
            return $a < $b;
        } else {
            return $a > $b;
        }
    };

    do {
        while ($compare($array[$left]->$key, $pivot)) {
            $left++;
        }
        while ($compare($pivot, $array[$right]->$key)) {
            $right--;
        }
        if ($left <= $right) {
            $temp = $array[$right];
            $array[$right] = $array[$left];
            $array[$left] = $temp;
            $right--;
            $left++;
        }
    } while ($left <= $right);
    if ($low < $right) {
        overflow_quick_array_sort($array, $low, $right, $key, $order);
    }
    if ($high > $left) {
        overflow_quick_array_sort($array, $left, $high, $key, $order);
    }
}

/**
 * Function to get a record from the database and throw an exception, if the record is not available. The error string is
 * retrieved from overflow but can be retrieved from the core too.
 * @param string $table                 The table to get the record from
 * @param array $options                Conditions for the record
 * @param string $exceptionstring       Name of the overflow exception that should be thrown in case there is no record.
 * @param string $fields                Optional fields that are retrieved from the found record.
 * @param bool $coreexception           Optional param if exception is from the core exceptions.
 * @return mixed $record                The found record
 */
function overflow_get_record_or_exception($table, $options, $exceptionstring, $fields = '*', $coreexception = false) {
    global $DB;
    if (!$record = $DB->get_record($table, $options, $fields)) {
        if ($coreexception) {
            throw new moodle_exception($exceptionstring);
        } else {
            throw new moodle_exception($exceptionstring, 'local_overflow');
        }
    }
    return $record;
}

/**
 * Function to retrieve a config and throw an exception, if the config is not found.
 * @param string $plugin            Plugin that has the configuration
 * @param string $configname        Name of configuration
 * @param string $errorcode         Error code/name of the exception
 * @param string $exceptionmodule   Module that has the exception.
 * @return mixed $config
 */
function overflow_get_config_or_exception($plugin, $configname, $errorcode, $exceptionmodule) {
    if (!$config = get_config($plugin, $configname)) {
        throw new moodle_exception($errorcode, $exceptionmodule);
    }
    return $config;
}

/**
 * Function that throws an exception if a given check is true.
 * @param bool $check               The result of a boolean check.
 * @param string $errorcode         Error code/name of the exception
 * @param string $coreexception     Optional param if exception is from the core exceptions and not overflow.
 * @return void
 */
function overflow_throw_exception_with_check($check, $errorcode, $coreexception = false) {
    if ($check) {
        if ($coreexception) {
            throw new moodle_exception($errorcode);
        } else {
            throw new moodle_exception($errorcode, 'local_overflow');
        }
    }
}

/**
 * Function that catches unenrolled users and redirects them to the enrolment page.
 * @param string $returnurl         The url to return to after the user has been enrolled.
 * @return void
 */
function overflow_catch_unenrolled_user($context, $courseid, $returnurl) {
    global $SESSION;
    if (!isguestuser() && !is_enrolled($context)) {
        if (enrol_selfenrol_available($courseid)) {
            $SESSION->wantsurl = qualified_me();
            $SESSION->enrolcancel = get_local_referer(false);
            redirect(new \moodle_url('/enrol/index.php', [
                'id' => $courseid,
                'returnurl' => $returnurl,
            ]), get_string('youneedtoenrol'));
        }
    }
}

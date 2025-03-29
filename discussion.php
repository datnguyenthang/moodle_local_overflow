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
 * File to display a overflow discussion.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Include config and locallib.
require_once('../../config.php');
global $CFG, $DB, $PAGE, $USER, $SESSION, $OUTPUT;
require_once($CFG->dirroot . '/local/overflow/locallib.php');

// Declare optional parameters.
$d = required_param('d', PARAM_INT); // The ID of the discussion.
$sesskey = optional_param('sesskey', null, PARAM_TEXT);
$ratingid = optional_param('r', 0, PARAM_INT);
$ratedpost = optional_param('rp', 0, PARAM_INT);

// Set the URL that should be used to return to this page.
$PAGE->set_url('/local/overflow/discussion.php', ['d' => $d]);

// The page should not be large, only pages containing broad tables are usually.
$PAGE->add_body_class('limitedwidth');

// Check if the discussion is valid.
$discussion = overflow_get_record_or_exception('overflow_discussions', ['id' => $d], 'invaliddiscussionid');

// Check if the related overflow instance is valid.
$overflow = overflow_get_record_or_exception('overflow', ['id' => $discussion->overflow],
                                            'invalidoverflowid');

// Save the allowmultiplemarks setting.
$marksetting = $DB->get_record('overflow', ['id' => $overflow->id], 'allowmultiplemarks');
$multiplemarks = false;
if ($marksetting->allowmultiplemarks == 1) {
    $multiplemarks = true;
}


// Set the modulecontext.
$context = context_system::instance();
$PAGE->set_context($context);

// A user must be logged in and enrolled to system.
require_login();

// Check if the user has the capability to view discussions.
$canviewdiscussion = has_capability('local/overflow:viewdiscussion', $context);
if (!$canviewdiscussion) {
    notice(get_string('noviewdiscussionspermission', 'local_overflow'));
}

// Has a request to rate a post (as solved or helpful) or to remove rating been submitted?
if ($ratingid) {
    require_sesskey();

    if (in_array($ratingid, [RATING_SOLVED, RATING_REMOVE_SOLVED, RATING_HELPFUL, RATING_REMOVE_HELPFUL])) {
        // Rate the post.
        if (!\local_overflow\ratings::overflow_add_rating($overflow, $ratedpost, $ratingid, $USER->id)) {
            throw new moodle_exception('ratingfailed', 'local_overflow');
        }

        // Return to the discussion.
        $returnto = new moodle_url('/local/overflow/discussion.php?d=' . $discussion->id);
        redirect($returnto);
    }
}

// Trigger the discussion viewed event.
$params = [
    'context' => $context,
    'objectid' => $discussion->id,
];
$event = \local_overflow\event\discussion_viewed::create($params);
$event->trigger();

// Unset where the user is coming from.
// Allows to calculate the correct return url later.
unset($SESSION->fromdiscussion);

// Get the parent post.
$parent = $discussion->firstpost;
if (!$post = overflow_get_post_full($parent)) {
    throw new moodle_exception("notexists", 'local_overflow', "$CFG->wwwroot/local/overflow/view.php?m=$overflow->id");
}

// Has the user the capability to view the post?
if (!overflow_user_can_see_post($overflow, $discussion, $post)) {
    throw new moodle_exception('noviewdiscussionspermission', 'local_overflow',
        "$CFG->wwwroot/local/overflow/view.php?m=$overflow->id");
}

// Append the discussion name to the navigation.
$forumnode = $PAGE->navigation->find($overflow->id, navigation_node::TYPE_ACTIVITY);
if (empty($forumnode)) {
    $forumnode = $PAGE->navbar;
} else {
    $forumnode->make_active();
}

if ($discussion->userid === '0') {
    $discussion->name = get_string('privacy:anonym_discussion_name', 'local_overflow');
}

$node = $forumnode->add(format_string($discussion->name),
    new moodle_url('/local/overflow/discussion.php', ['d' => $discussion->id]));
$node->display = false;
if ($node && ($post->id != $discussion->firstpost)) {
    $node->add(format_string($post->subject), $PAGE->url);
}

$PAGE->requires->js_call_amd('local_overflow/reviewing', 'init');

$PAGE->requires->js_call_amd('local_overflow/rating', 'init', [$USER->id, $multiplemarks]);

// Initiate the page.
$PAGE->set_title($overflow->name . ': ' . format_string($discussion->name));
$PAGE->set_heading($overflow->name);

// Include the renderer.
$renderer = $PAGE->get_renderer('local_overflow');

// Start the side-output.
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($discussion->name), 1, 'discussionname');

// Guests and users can not subscribe to a discussion.
if ((!isguestuser() && isloggedin() && $canviewdiscussion)) {
    echo '';
}

echo "<br>";

echo '<div id="overflow-posts"><div id="overflow-root">';

overflow_print_discussion($overflow, $discussion, $post, $multiplemarks);
echo '</div></div>';

echo $OUTPUT->footer();

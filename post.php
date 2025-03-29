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
 * The file to manage posts.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Include config and locallib.
use local_overflow\anonymous;
use local_overflow\review;

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
global $CFG, $USER, $DB, $PAGE, $SESSION, $OUTPUT;
require_once(dirname(__FILE__) . '/locallib.php');
require_once($CFG->libdir . '/completionlib.php');

// Declare optional parameters.
$overflow = optional_param('overflow', 0, PARAM_INT);
$reply = optional_param('reply', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$count = 0;
$count += $overflow ? 1 : 0;
$count += $reply ? 1 : 0;
$count += $edit ? 1 : 0;
$count += $delete ? 1 : 0;

if ($count !== 1) {
    throw new coding_exception('Exactly one parameter should be specified!');
}

// Set the URL that should be used to return to this page.
$PAGE->set_url('/local/overflow/post.php', [
    'overflow' => $overflow,
    'reply' => $reply,
    'edit' => $edit,
    'delete' => $delete,
    'confirm' => $confirm,
]);

// These params will be passed as hidden variables later in the form.
$pageparams = ['overflow' => $overflow, 'reply' => $reply, 'edit' => $edit];

// Get the system context instance.
$context = context_system::instance();
$PAGE->set_context($context);

// Catch guests.
if (!isloggedin() || isguestuser()) {

    // The user is starting a new discussion in a overflow instance.
    if (!empty($overflow)) {

        // Check the overflow instance is valid.
        if (!$overflow = $DB->get_record('overflow', ['id' => $overflow])) {
            throw new moodle_exception('invalidoverflowid', 'local_overflow');
        }

        // The user is replying to an existing overflow discussion.
    } else if (!empty($reply)) {

        // Check if the related post exists.
        if (!$parent = overflow_get_post_full($reply)) {
            throw new moodle_exception('invalidparentpostid', 'local_overflow');
        }

        // Check if the post is part of a valid discussion.
        if (!$discussion = $DB->get_record('overflow_discussions', ['id' => $parent->discussion])) {
            throw new moodle_exception('notpartofdiscussion', 'local_overflow');
        }

        // Check if the post is related to a valid overflow instance.
        if (!$overflow = $DB->get_record('overflow', ['id' => $discussion->overflow])) {
            throw new moodle_exception('invalidoverflowid', 'local_overflow');
        }
    }

    // Get the context of the module.
    $context = context_system::instance();

    // Set parameters for the page.
    $PAGE->set_context($context);
    $PAGE->set_title($overflow->name);
    $PAGE->set_heading($overflow->name);

    // The page should not be large, only pages containing broad tables are usually.
    $PAGE->add_body_class('limitedwidth');

    // The guest needs to login.
    echo $OUTPUT->header();
    $strlogin = get_string('noguestpost', 'forum') . '<br /><br />' . get_string('liketologin');
    echo $OUTPUT->confirm($strlogin, get_login_url(), $CFG->wwwroot . '/local/overflow/view.php?m=' . $overflow->id);
    echo $OUTPUT->footer();
    exit;
}

// First step: A general login is needed to post something.
require_login(0, false);

// First possibility: User is starting a new discussion in a overflow instance.
if (!empty($overflow)) {

    // Check the overflow instance is valid.
    if (!$overflow = $DB->get_record('overflow', ['id' => $overflow])) {
        throw new moodle_exception('invalidoverflowid', 'local_overflow');
    }

    // Retrieve the contexts.
    $context = context_system::instance();

    // Check if the user can start a new discussion.
    if (!overflow_user_can_post_discussion($overflow, $context)) {
        // Notify the user, that he can not post a new discussion.
        throw new moodle_exception('nopostoverflow', 'local_overflow');
    }

    // Where is the user coming from?
    $SESSION->fromurl = get_local_referer(false);

    // Load all the $post variables.
    $post = new stdClass();
    $post->overflow = $overflow->id;
    $post->discussion = 0;
    $post->parent = 0;
    $post->subject = '';
    $post->userid = $USER->id;
    $post->message = '';

    // Unset where the user is coming from.
    // Allows to calculate the correct return url later.
    unset($SESSION->fromdiscussion);

} else if (!empty($reply)) {
    // Second possibility: The user is writing a new reply.

    // Check if the post exists.
    if (!$parent = overflow_get_post_full($reply)) {
        throw new moodle_exception('invalidparentpostid', 'local_overflow');
    }

    // Check if the post is part of a discussion.
    if (!$discussion = $DB->get_record('overflow_discussions', ['id' => $parent->discussion])) {
        throw new moodle_exception('notpartofdiscussion', 'local_overflow');
    }

    // Check if the discussion is part of a overflow instance.
    if (!$overflow = $DB->get_record('overflow', ['id' => $discussion->overflow])) {
        throw new moodle_exception('invalidoverflowid', 'local_overflow');
    }

    // Check whether the user is allowed to post.
    if (!overflow_user_can_post($context, $parent)) {


        // Print the error message.
        throw new moodle_exception('nopostoverflow', 'local_overflow');
    }

    // Load the $post variable.
    $post = new stdClass();
    $post->overflow = $overflow->id;
    $post->discussion = $parent->discussion;
    $post->parent = $parent->id;
    $post->subject = $discussion->name;
    $post->userid = $USER->id;
    $post->message = '';

    // Append 'RE: ' to the discussions subject.
    $strre = get_string('re', 'local_overflow');
    if (!(substr($post->subject, 0, strlen($strre)) == $strre)) {
        $post->subject = $strre . ' ' . $post->subject;
    }

    // Unset where the user is coming from.
    // Allows to calculate the correct return url later.
    unset($SESSION->fromdiscussion);


} else if (!empty($edit)) {
    // Third possibility: The user is editing his own post.

    // Check if the submitted post exists.
    if (!$post = overflow_get_post_full($edit)) {
        throw new moodle_exception('invalidpostid', 'local_overflow');
    }

    // Get the parent post of this post if it is not the starting post of the discussion.
    if ($post->parent) {
        if (!$parent = overflow_get_post_full($post->parent)) {
            throw new moodle_exception('invalidparentpostid', 'local_overflow');
        }
    }

    // Check if the post refers to a valid discussion.
    if (!$discussion = $DB->get_record('overflow_discussions', ['id' => $post->discussion])) {
        throw new moodle_exception('notpartofdiscussion', 'local_overflow');
    }

    // Check if the post refers to a valid overflow instance.
    if (!$overflow = $DB->get_record('overflow', ['id' => $discussion->overflow])) {
        throw new moodle_exception('invalidoverflowid', 'local_overflow');
    }

    // Check if the post can be edited.
    $beyondtime = ((time() - $post->created) > get_config('local_overflow', 'maxeditingtime'));
    $alreadyreviewed = review::should_post_be_reviewed($post, $overflow) && $post->reviewed;
    if (($beyondtime || $alreadyreviewed) && !has_capability('local/overflow:editanypost', $context)) {
        throw new moodle_exception('maxtimehaspassed', 'local_overflow', '',
            format_time(get_config('local_overflow', 'maxeditingtime')));
    }



    // If the current user is not the one who posted this post.
    if ($post->userid <> $USER->id) {

        // Check if the current user has not the capability to edit any post.
        if (!has_capability('local/overflow:editanypost', $context)) {

            // Display the error. Capabilities are missing.
            throw new moodle_exception('cannoteditposts', 'local_overflow');
        }
    }

    // Load the $post variable.
    $post->edit = $edit;
    $post->overflow = $overflow->id;

    // Unset where the user is coming from.
    // Allows to calculate the correct return url later.
    unset($SESSION->fromdiscussion);

} else if (!empty($delete)) {
    // Fourth possibility: The user is deleting a post.
    // Check if the post is existing.
    if (!$post = overflow_get_post_full($delete)) {
        throw new moodle_exception('invalidpostid', 'local_overflow');
    }

    // Get the related discussion.
    if (!$discussion = $DB->get_record('overflow_discussions', ['id' => $post->discussion])) {
        throw new moodle_exception('notpartofdiscussion', 'local_overflow');
    }

    // Get the related overflow instance.
    if (!$overflow = $DB->get_record('overflow', ['id' => $discussion->overflow])) {
        throw new moodle_exception('invalidoverflowid', 'local_overflow');
    }

    // Require a login and retrieve.
    $context = context_system::instance();

    // Check some capabilities.
    $deleteownpost = has_capability('local/overflow:deleteownpost', $context);
    $deleteanypost = has_capability('local/overflow:deleteanypost', $context);
    if (!(($post->userid == $USER->id && $deleteownpost) || $deleteanypost)) {
        throw new moodle_exception('cannotdeletepost', 'local_overflow');
    }

    // Count all replies of this post.
    $replycount = overflow_count_replies($post, false);

    // Has the user confirmed the deletion?
    if (!empty($confirm) && confirm_sesskey()) {

        // Check if the user has the capability to delete the post.
        $timepassed = time() - $post->created;
        if (($timepassed > get_config('local_overflow', 'maxeditingtime')) && !$deleteanypost) {
            $url = new moodle_url('/local/overflow/discussion.php', ['d' => $post->discussion]);
            throw new moodle_exception('cannotdeletepost', 'local_overflow', overflow_go_back_to($url));
        }

        // A normal user cannot delete his post if there are direct replies.
        if ($replycount && !$deleteanypost) {
            $url = new moodle_url('/local/overflow/discussion.php', ['d' => $post->discussion]);
            throw new moodle_exception('couldnotdeletereplies', 'local_overflow', overflow_go_back_to($url));
        } else {
            // Delete the post.

            // The post is the starting post of a discussion. Delete the topic as well.
            if (!$post->parent) {
                overflow_delete_discussion($discussion, $overflow);

                // Trigger the discussion deleted event.
                $params = [
                    'objectid' => $discussion->id,
                    'context' => $context,
                ];

                $event = \local_overflow\event\discussion_deleted::create($params);
                $event->trigger();

                // Redirect the user back to start page of the overflow instance.
                redirect("view.php?m=$discussion->overflow");
                exit;

            } else if (overflow_delete_post($post, $deleteanypost, $overflow)) {
                // Delete a single post.
                // Redirect back to the discussion.
                $discussionurl = new moodle_url('/local/overflow/discussion.php', ['d' => $discussion->id]);
                redirect(overflow_go_back_to($discussionurl));
                exit;

            } else {
                // Something went wrong.
                throw new moodle_exception('errorwhiledelete', 'local_overflow');
            }
        }
    } else {
        // Deletion needs to be confirmed.

        overflow_set_return();
        $PAGE->navbar->add(get_string('delete', 'local_overflow'));
        $PAGE->set_title($overflow->name);
        $PAGE->set_heading($overflow->name);

        // The page should not be large, only pages containing broad tables are usually.
        $PAGE->add_body_class('limitedwidth');

        // Check if there are replies for the post.
        if ($replycount) {

            // Check if the user has capabilities to delete more than one post.
            if (!$deleteanypost) {
                throw new moodle_exception('couldnotdeletereplies', 'local_overflow',
                    overflow_go_back_to(new moodle_url('/local/overflow/discussion.php',
                        ['d' => $post->discussion, 'p' . $post->id])));
            }

            // Request a confirmation to delete the post.
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string("deletesureplural", "local_overflow", $replycount + 1),
                "post.php?delete=$delete&confirm=$delete", $CFG->wwwroot . '/local/overflow/discussion.php?d=' .
                $post->discussion . '#p' . $post->id);

        } else {
            // Delete a single post.

            // Print a confirmation message.
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string("deletesure", "local_overflow", $replycount),
                "post.php?delete=$delete&confirm=$delete",
                $CFG->wwwroot . '/local/overflow/discussion.php?d=' . $post->discussion . '#p' . $post->id);
        }
    }
    echo $OUTPUT->footer();
    exit;

} else {
    // Last posibility: the action is not known.

    throw new moodle_exception('unknownaction');
}

// Second step: The user must be logged on properly. 
require_login();

// Get the contexts.
$context = context_system::instance();

// Get the subject.
if ($edit) {
    $subject = $discussion->name;
} else if ($reply) {
    $subject = $post->subject;
} else if ($overflow) {
    $subject = $post->subject;
}
require_once($CFG->dirroot.'/lib/filelib.php');

// Get attachments.
$postid = empty($post->id) ? null : $post->id;
$draftitemid = file_get_submitted_draft_itemid('attachments');
file_prepare_draft_area($draftitemid,
    $context->id,
    'local_overflow',
    'attachment',
    $postid,
    local_overflow_post_form::attachment_options($overflow));

if ($draftitemid && $edit && anonymous::is_post_anonymous($discussion, $overflow, $post->userid)
    && $post->userid != $USER->id) {

    $usercontext = context_user::instance($USER->id);
    $anonymousstr = get_string('anonymous', 'local_overflow');
    foreach (get_file_storage()->get_area_files($usercontext->id, 'user', 'draft', $draftitemid) as $file) {
        $file->set_author($anonymousstr);
    }
}

$draftideditor = file_get_submitted_draft_itemid('message');
$currenttext = file_prepare_draft_area($draftideditor, $context->id, 'local_overflow', 'post', $postid,
        local_overflow_post_form::editor_options($context, $postid), $post->message);

// Prepare the form.
$formarray = [
    'context' => $context,
    'overflow' => $overflow,
    'post' => $post,
    'edit' => $edit,
];
$mformpost = new local_overflow_post_form('post.php', $formarray, 'post', '', ['id' => 'mformoverflow']);

// The current user is not the original author.
// Append the message to the end of the message.
if ($USER->id != $post->userid) {

    // Create a temporary object.
    $data = new stdClass();
    $data->date = userdate($post->modified);
    $post->messageformat = editors_get_preferred_format();

    // Append the message depending on the messages format.
    if ($post->messageformat == FORMAT_HTML) {
        $data->name = '<a href="' . $CFG->wwwroot . '/user/view.php?id' . $USER->id .
            '">' . fullname($USER) . '</a>';
        $post->message .= '<p><span class="edited">(' . get_string('editedby', 'local_overflow', $data) . ')</span></p>';
    } else {
        $data->name = fullname($USER);
        $post->message .= "\n\n(" . get_string('editedby', 'local_overflow', $data) . ')';
    }

    // Delete the temporary object.
    unset($data);
}

// Define the heading for the form.
$formheading = '';
if (!empty($parent)) {
    $heading = get_string('yourreply', 'local_overflow');
    $formheading = get_string('reply', 'local_overflow');
} else {
    $heading = get_string('yournewtopic', 'local_overflow');
}

// Set data for the form.
// TODO Refactor.
$param1 = (isset($discussion->id) ? [$discussion->id] : []);
$param2 = (isset($post->format) ? ['format' => $post->format] : []);
$param3 = (isset($discussion->timestart) ? ['timestart' => $discussion->timestart] : []);
$param4 = (isset($discussion->timeend) ? ['timeend' => $discussion->timeend] : []);
$param5 = (isset($discussion->id) ? ['discussion' => $discussion->id] : []);
$mformpost->set_data([
        'attachments' => $draftitemid,
        'general' => $heading,
        'subject' => $subject,
        'message' => [
            'text' => $currenttext,
            'format' => editors_get_preferred_format(),
            'itemid' => $draftideditor,
        ],
        'userid' => $post->userid,
        'parent' => $post->parent,
        'discussion' => $post->discussion,
    ] + $pageparams + $param1 + $param2 + $param3 + $param4 + $param5);

// Is it canceled?
if ($mformpost->is_cancelled()) {

    // Redirect the user back.
    if (!isset($discussion->id)) {
        redirect(new moodle_url('/local/overflow/view.php', ['m' => $overflow->id]));
    } else {
        redirect(new moodle_url('/local/overflow/discussion.php', ['d' => $discussion->id]));
    }

    // Cancel.
    exit();
}

// Is it submitted?
if ($fromform = $mformpost->get_data()) {

    // Redirect url in case of occuring errors.
    if (empty($SESSION->fromurl)) {
        $errordestination = "$CFG->wwwroot/local/overflow/view.php?m=$overflow->id";
    } else {
        $errordestination = $SESSION->fromurl;
    }

    // Format the submitted data.
    $fromform->messageformat = $fromform->message['format'];
    $fromform->draftideditor = $fromform->message['itemid'];
    $fromform->message = $fromform->message['text'];
    $fromform->messagetrust = trusttext_trusted($context);

    // If we are updating a post.
    if ($fromform->edit) {

        // Initiate some variables.
        unset($fromform->groupid);
        $fromform->id = $fromform->edit;
        $message = '';

        // The FORUM-Plugin had an bug: https://tracker.moodle.org/browse/MDL-4314
        // This is a fix for it.
        if (!$realpost = $DB->get_record('overflow_posts', ['id' => $fromform->id])) {
            $realpost = new stdClass();
            $realpost->userid = -1;
        }

        // Check the capabilities of the user.
        // He may proceed if he can edit any post or if he has the startnewdiscussion
        // capability or the capability to reply and is editing his own post.
        $editanypost = has_capability('local/overflow:editanypost', $context);
        $replypost = has_capability('local/overflow:replypost', $context);
        $startdiscussion = has_capability('local/overflow:startdiscussion', $context);
        $ownpost = ($realpost->userid == $USER->id);
        if (!(($ownpost && ($replypost || $startdiscussion)) || $editanypost)) {
            throw new moodle_exception('cannotupdatepost', 'local_overflow');
        }

        // Update the post or print an error message.
        $updatepost = $fromform;
        $updatepost->overflow = $overflow->id;
        if (!overflow_update_post($updatepost, $mformpost)) {
            throw new moodle_exception('couldnotupdate', 'local_overflow', $errordestination);
        }

        // Create a success-message.
        if ($realpost->userid == $USER->id) {
            $message .= get_string('postupdated', 'local_overflow');
        } else {
            if (anonymous::is_post_anonymous($discussion, $overflow, $realpost->userid)) {
                $name = get_string('anonymous', 'local_overflow');
            } else {
                $realuser = $DB->get_record('user', ['id' => $realpost->userid]);
                $name = fullname($realuser);
            }
            $message .= get_string('editedpostupdated', 'local_overflow', $name);
        }

        // Create a link to go back to the discussion.
        $discussionurl = new moodle_url('/local/overflow/discussion.php', ['d' => $discussion->id], 'p' . $fromform->id);

        // Set some parameters.
        $params = [
            'context' => $context,
            'objectid' => $fromform->id,
            'other' => [
                'discussionid' => $discussion->id,
                'overflowid' => $overflow->id,
            ], ];

        // If the editing user is not the original author, add the original author to the params.
        if ($realpost->userid != $USER->id) {
            $params['relateduserid'] = $realpost->userid;
        }

        // Trigger post updated event.
        $event = \local_overflow\event\post_updated::create($params);
        $event->trigger();

        // Redirect back to the discussion.
        redirect(overflow_go_back_to($discussionurl), $message, null, \core\output\notification::NOTIFY_SUCCESS);

        // Cancel.
        exit;

    } else if ($fromform->discussion) {
        // Add a new post to an existing discussion.

        // Set some basic variables.
        unset($fromform->groupid);
        $message = '';
        $addpost = $fromform;
        $addpost->overflow = $overflow->id;

        // Create the new post.
        if ($fromform->id = overflow_add_new_post($addpost)) {

            // Subscribe to this thread.
            $discussion = new \stdClass();
            $discussion->id = $fromform->discussion;
            $discussion->overflow = $overflow->id;
            \local_overflow\subscriptions::overflow_post_subscription($overflow, $discussion, $context);

            // Print a success-message.
            $message .= '<p>' . get_string("postaddedsuccess", "local_overflow") . '</p>';
            $message .= '<p>' . get_string("postaddedtimeleft", "local_overflow",
                    format_time(get_config('local_overflow', 'maxeditingtime'))) . '</p>';

            // Set the URL that links back to the discussion.
            $link = '/local/overflow/discussion.php';
            $discussionurl = new moodle_url($link, ['d' => $discussion->id], 'p' . $fromform->id);

            // Trigger post created event.
            $params = [
                'context' => $context,
                'objectid' => $fromform->id,
                'other' => [
                    'discussionid' => $discussion->id,
                    'overflowid' => $overflow->id,
                ], ];
            $event = \local_overflow\event\post_created::create($params);
            $event->trigger();
            redirect(
                overflow_go_back_to($discussionurl),
                $message,
                \core\output\notification::NOTIFY_SUCCESS
            );

            // Print an error if the answer could not be added.
        } else {
            throw new moodle_exception('couldnotadd', 'local_overflow', $errordestination);
        }

        // The post has been added.
        exit;

    } else {
        // Add a new discussion.

        // The location to redirect the user after successfully posting.
        $redirectto = new moodle_url('view.php', ['m' => $fromform->overflow]);

        $discussion = $fromform;
        $discussion->name = $fromform->subject;

        // Check if the user is allowed to post here.
        if (!overflow_user_can_post_discussion($overflow)) {
            throw new moodle_exception('cannotcreatediscussion', 'local_overflow');
        }

        // Check if the creation of the new discussion failed.
        if (!$discussion->id = overflow_add_discussion($discussion)) {

            throw new moodle_exception('couldnotadd', 'local_overflow', $errordestination);

        } else {    // The creation of the new discussion was successful.

            $params = [
                'context' => $context,
                'objectid' => $discussion->id,
                'other' => [
                    'overflowid' => $overflow->id,
                ],
            ];

            $message = '<p>' . get_string("postaddedsuccess", "local_overflow") . '</p>';

            // Trigger the discussion created event.
            $params = [
                'context' => $context,
                'objectid' => $discussion->id,
            ];
            $event = \local_overflow\event\discussion_created::create($params);
            $event->trigger();
            // Subscribe to this thread.
            $discussion->overflow = $overflow->id;
            \local_overflow\subscriptions::overflow_post_subscription($overflow, $discussion, $context);
        }

        // Redirect back to te discussion.
        redirect(overflow_go_back_to($redirectto->out()), $message, null, \core\output\notification::NOTIFY_SUCCESS);

        // Do not continue.
        exit;
    }
}

// If the script gets to this point, nothing has been submitted.
// We have to display the form.
// $discussion is only used for replying and editing.

// Define the message to be displayed above the form.
$toppost = new stdClass();
$toppost->subject = get_string("addanewdiscussion", "local_overflow");

// Initiate the page.
$PAGE->set_title("$overflow->name: $overflow->name " . format_string($toppost->subject));
$PAGE->set_heading($overflow->name);

// The page should not be large, only pages containing broad tables are usually.
$PAGE->add_body_class('limitedwidth');

// Display the header.
echo $OUTPUT->header();

// Display the form.
$mformpost->display();

// Display the footer.
echo $OUTPUT->footer();

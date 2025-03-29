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
 * Prints a particular instance of overflow
 *
 * You can have a rather longer description of the file as well,
 * if you like, and it can span multiple lines.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Include config and locallib.
require_once(__DIR__.'/../../config.php');
global $CFG, $PAGE, $DB, $OUTPUT, $SESSION, $USER;
require_once($CFG->dirroot.'/local/overflow/locallib.php');

// Declare optional parameters.
$id = optional_param('id', 0, PARAM_INT); // 
$m = optional_param('m', 0, PARAM_INT);   // overflow ID.
$page = optional_param('page', 0, PARAM_INT);     // Which page to show.
$movetopopup = optional_param('movetopopup', 0, PARAM_INT);     // Which Topic to move.
$linktoforum = optional_param('movetoforum', 0, PARAM_INT);     // Forum to which it is moved.

// Set the parameters.
$params = [];
if ($id) {
    $params['id'] = $id;
} else {
    $params['m'] = $m;
}
if ($page) {
    $params['page'] = $page;
}
$PAGE->set_url('/local/overflow/view.php', $params);

// Check for the overflow.
if ($id) {
    $overflow = $DB->get_record('overflow', ['id' =>$id], '*', MUST_EXIST);
} else if ($m) {
    $overflow = $DB->get_record('overflow', ['id' => $m], '*', MUST_EXIST);
} else {
    throw new moodle_exception('missingparameter');
}

// Save the allowmultiplemarks setting.
$marksetting = $DB->get_record('overflow', ['id' => $overflow->id], 'allowmultiplemarks');

// Require a login.
require_login();

// Set the context.
$context = context_system::instance(); 
$PAGE->set_context($context);
//var_dump($context);exit;
// Check some capabilities.
if (!has_capability('local/overflow:viewdiscussion', $context)) {
    notice(get_string('noviewdiscussionspermission', 'local_overflow'));
}

// Print the page header.
$PAGE->set_url('/local/overflow/view.php', ['id' => $overflow->id]);
$PAGE->set_title(format_string($overflow->name));
$PAGE->set_heading(format_string($overflow->name));

$PAGE->requires->js_call_amd('local_overflow/rating', 'init', [$USER->id, $marksetting->allowmultiplemarks]);

// The page should not be large, only pages containing broad tables are usually.
$PAGE->add_body_class('limitedwidth');

// If a topic is to be moved, do it.
if ($linktoforum && $movetopopup && has_capability('local/overflow:movetopic', $context)) {
    // Take the $movetopopup-id and the $linktoforum-id and move the discussion to the forum.
    $topic = $DB->get_record('overflow_discussions', ['id' => $movetopopup]);
    $topic->overflow = $linktoforum;
    $DB->update_record('overflow_discussions', $topic);
    redirect($CFG->wwwroot . '/local/overflow/view.php?id=' . $m);
}

// Output starts here.
echo $OUTPUT->header();

if ($overflow->anonymous > 0) {
    $strkeys = [
            \local_overflow\anonymous::QUESTION_ANONYMOUS => 'desc:only_questions',
            \local_overflow\anonymous::EVERYTHING_ANONYMOUS => 'desc:anonymous',
    ];
    echo html_writer::tag('p', get_string($strkeys[$overflow->anonymous], 'local_overflow'));
}

$reviewlevel = \local_overflow\review::get_review_level($overflow);
if ($reviewlevel > 0) {
    $strkeys = [
        \local_overflow\review::QUESTIONS => 'desc:review_questions',
        \local_overflow\review::EVERYTHING => 'desc:review_everything',
    ];
    echo html_writer::tag('p', get_string($strkeys[$reviewlevel], 'local_overflow'));
}

echo '<div id="overflow-root">';

if (has_capability('local/overflow:reviewpost', $context)) {
    $reviewpost = \local_overflow\review::get_first_review_post($overflow->id);

    if ($reviewpost) {
        echo html_writer::link($reviewpost, get_string('review_needed', 'local_overflow'),
                ['class' => 'btn btn-danger my-2']);
    }
}

if ($movetopopup && has_capability('local/overflow:movetopic', $context)) {
    overflow_print_forum_list($movetopopup);
}

// Return here after posting, etc.
$SESSION->fromdiscussion = qualified_me();

// Print the discussions.
overflow_print_latest_discussions($overflow, $page, get_config('local_overflow', 'manydiscussions'));

echo '</div>';

// Finish the page.
echo $OUTPUT->footer();

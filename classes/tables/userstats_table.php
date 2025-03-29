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
 * Class needed in userstats.php
 *
 * @package   local_overflow
 * @copyright 2023 Tamaro Walter
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_overflow\tables;

defined('MOODLE_INTERNAL') || die();

use local_overflow\ratings;
global $CFG;
require_once($CFG->dirroot . '/local/overflow/lib.php');
require_once($CFG->dirroot . '/local/overflow/locallib.php');
require_once($CFG->libdir . '/tablelib.php');

/**
 * Table listing all user statistics 
 *
 * @package   local_overflow
 * @copyright 2023 Tamaro Walter
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userstats_table extends \flexible_table
{

    /** @var int overflow that started the printing of statistics*/
    private $overflowid;

    /** @var array table that will have objects with every user and his statistics. */
    private $userstatsdata = [];

    /** @var \stdClass Help icon for amountofactivity-column.*/
    private $helpactivity;

    /**
     * Constructor for workflow_table.
     *
     * @param int $uniqueid Unique id of this table.
     * @param int $overflow ID if the overflow
     * @param string $url The url of the table
     */
    public function __construct($uniqueid, $overflow, $url)
    {
        global $PAGE;
        parent::__construct($uniqueid);
        $PAGE->requires->js_call_amd('local_overflow/activityhelp', 'init');

        $this->overflowid = $overflow;
        $this->set_helpactivity();

        $this->set_attribute('class', 'overflow-statistics-table');
        $this->set_attribute('id', $uniqueid);
        $this->define_columns([
            'username',
            'receivedupvotes',
            'receiveddownvotes',
            'forumactivity',
            'activity',
            'forumreputation',
            'reputation',
        ]);
        $this->define_baseurl($url);
        $this->define_headers([
            get_string('fullnameuser'),
            get_string('userstatsupvotes', 'local_overflow'),
            get_string('userstatsdownvotes', 'local_overflow'),
            (get_string('userstatsforumactivity', 'local_overflow') . $this->helpactivity->object),
            (get_string('userstatsactivity', 'local_overflow') . $this->helpactivity->object),
            get_string('userstatsforumreputation', 'local_overflow'),
            get_string('userstatsreputation', 'local_overflow'),
        ]);
        $this->get_table_data();
        $this->sortable(true, '', SORT_DESC);
        $this->no_sorting('username');
        $this->setup();
    }

    /**
     * Method to display the table.
     * @return void
     */
    public function out()
    {
        $this->start_output();
        $this->sort_table_data($this->get_sort_order());
        $this->format_and_add_array_of_rows($this->userstatsdata, true);
        $this->text_sorting('reputation');
        $this->finish_output();
    }

    /**
     * Method to collect all the data.
     * Method will collect all users from the given system and will determine the user statistics
     * Builds an 2d-array with user statistic
     */
    public function get_table_data()
    {

        $context = \context_system::instance();
        $users = get_enrolled_users($context, '', 0, 'u.id, u.firstname, u.lastname');

        // Step 1.0: Build the datatable with all relevant information.
        $ratingdata = $this->get_rating_data();

        // Step 2.0: Now collect the data for every user.
        foreach ($users as $user) {
            $student = $this->createstudent($user);

            foreach ($ratingdata as $row) {
                // Did the student receive an up- or downvote?
                if ($row->postuserid == $student->id) {
                    $this->process_received_votes($student, $row);
                }

                // Did a student submit a rating?
                if ($row->rateuserid == $student->id) {
                    $this->process_submitted_ratings($student, $row);
                }

                // Did the student write a post?
                if ($row->postuserid == $student->id) {
                    $this->process_written_posts($student, $row);
                }

            }
            // Get the user reputation.
            $student->forumreputation = ratings::overflow_get_reputation_instance($this->overflowid, $student->id);
            $student->reputation = ratings::overflow_get_reputation($student->id);
            $this->userstatsdata[] = $student;
        }
    }

    /**
     * Return the userstatsdata-table.
     */
    public function get_usertable()
    {
        return $this->userstatsdata;
    }

    /**
     * Setup the help icon for amount of activity
     */
    public function set_helpactivity()
    {
        global $CFG;
        $this->helpactivity = new \stdClass();
        $this->helpactivity->iconurl = $CFG->wwwroot . '/pix/a/help.png';
        $this->helpactivity->icon = \html_writer::img(
            $this->helpactivity->iconurl,
            get_string('helpamountofactivity', 'local_overflow')
        );
        $this->helpactivity->class = 'helpactivityclass btn btn-link';
        $this->helpactivity->iconattributes = [
            'role' => 'button',
            'data-container' => 'body',
            'data-toggle' => 'popover',
            'data-placement' => 'right',
            'data-action' => 'showhelpicon',
            'data-html' => 'true',
            'data-trigger' => 'focus',
            'tabindex' => '0',
            'data-content' => '<div class=&quot;no-overflow&quot;><p>' .
                get_string('helpamountofactivity', 'local_overflow') .
                '</p> </div>',
        ];

        $this->helpactivity->object = \html_writer::span(
            $this->helpactivity->icon,
            $this->helpactivity->class,
            $this->helpactivity->iconattributes
        );
    }

    // Functions that show the data.

    /**
     * username column
     * @param object $row
     * @return string
     */
    public function col_username($row)
    {
        return $row->link;
    }

    /**
     * upvotes column
     * @param object $row
     * @return string
     */
    public function col_receivedupvotes($row)
    {
        return $this->badge_render($row->receivedupvotes);
    }

    /**
     * downvotes column
     * @param object $row
     * @return string
     */
    public function col_receiveddownvotes($row)
    {
        return $this->badge_render($row->receiveddownvotes);
    }

    /**
     * Forum activity column
     * @param object $row
     * @return string
     */
    public function col_forumactivity($row)
    {
        return $this->badge_render($row->forumactivity);
    }

    /**
     * Forum reputation column
     * @param object $row
     * @return string
     */
    public function col_forumreputation($row)
    {
        return $this->badge_render($row->forumreputation);
    }

    /**
     * @param object $row
     * @return string
     */
    public function col_activity($row)
    {
        return $this->badge_render($row->activity);
    }

    /**
     * @param object $row
     * @return string
     */
    public function col_reputation($row)
    {
        return $this->badge_render($row->reputation);
    }

    /**
     * Depending on the value display success or warning badge.
     * @param int $number
     * @return string
     */
    private function badge_render($number)
    {
        if ($number > 0) {
            return \html_writer::tag('h5', \html_writer::start_span('badge bg-success') .
                $number . \html_writer::end_span());
        } else {
            return \html_writer::tag('h5', \html_writer::start_span('badge bg-warning') .
                $number . \html_writer::end_span());
        }
    }

    /**
     * error handling
     * @param object $colname
     * @param int    $attempt
     * @return null
     */
    public function other_cols($colname, $attempt)
    {
        return null;
    }

    // Helper functions.

    /**
     * Return a student object.
     * @param \stdClass $user
     * @return object
     */
    private function createstudent($user)
    {
        $student = new \stdClass();
        $student->id = $user->id;
        $student->name = $user->firstname . ' ' . $user->lastname;
        $linktostudent = new \moodle_url('/user/view.php', ['id' => $student->id]);
        $student->link = \html_writer::link($linktostudent->out(), $student->name);
        $student->submittedposts = [];      // Posts written by the student. Key = postid, Value = postid.
        $student->ratedposts = [];          // Posts that the student rated. Key = rateid, Value = rateid.
        $student->receivedupvotes = 0;
        $student->receiveddownvotes = 0;
        $student->forumactivity = 0;        // Number of written posts and submitted ratings in the current overflow.
        $student->activity = 0;       // Number of written posts and submitted ratings.
        $student->forumreputation = 0;      // Reputation in the current overflow.
        $student->reputation = 0;     // Reputation.
        return $student;
    }

    /**
     * All ratings upvotes downbotes activity etc. from the current.
     * @return array
     * @throws \dml_exception
     */
    private function get_rating_data()
    {
        global $DB;
        $sqlquery = 'SELECT (ROW_NUMBER() OVER (ORDER BY ratings.id)) AS row_num,
                            discuss.id AS discussid,
                            discuss.userid AS discussuserid,
                            posts.id AS postid,
                            posts.userid AS postuserid,
                            ratings.id AS rateid,
                            ratings.rating AS rating,
                            ratings.userid AS rateuserid,
                            ratings.postid AS ratepostid,
                            overflow.anonymous AS anonymoussetting,
                            overflow.id AS overflowid
                      FROM {overflow_discussions} discuss
                      LEFT JOIN {overflow_posts} posts ON discuss.id = posts.discussion
                      LEFT JOIN {overflow_ratings} ratings ON posts.id = ratings.postid
                      LEFT JOIN {overflow} overflow ON discuss.overflow = overflow.id;';
        return $DB->get_records_sql($sqlquery);
    }

    /**
     * Process the received votes for a student.
     * @param $student
     * @param $row
     * @return void
     */
    private function process_received_votes(&$student, $row)
    {
        // Only count received votes if the post is not anonymous (no anonymous setting or only questioner anonymous discussion).
        if ((($row->anonymoussetting == 0) || ($row->anonymoussetting == 1 && $row->postuserid != $row->discussuserid))) {
            if ($row->rating == RATING_UPVOTE) {
                $student->receivedupvotes += 1;
            } else if ($row->rating == RATING_DOWNVOTE) {
                $student->receiveddownvotes += 1;
            }
        }
    }

    /**
     * Process the submitted ratings from a student.
     * @param $student
     * @param $row
     * @return void
     */
    private function process_submitted_ratings(&$student, $row)
    {
        // For solution marks: only count a solution if the discussion is not completely anonymous.
        // For helpful marks: only count helpful marks if the discussion is not any kind of anonymous.
        // Up and downvotes are always counted.
        $solvedcheck = ($row->rating == RATING_SOLVED && $row->anonymoussetting != 2);
        $helpfulcheck = ($row->rating == RATING_HELPFUL && $row->anonymoussetting == 0);
        $isvote = ($row->rating == RATING_UPVOTE || $row->rating == RATING_DOWNVOTE);

        if (!array_key_exists($row->rateid, $student->ratedposts)) {
            if ($solvedcheck || $helpfulcheck || $isvote) {
                $this->increment_forumactivity($student, $row);
                $student->activity++;
                $student->ratedposts[$row->rateid] = $row->rateid;
            }
        }
    }

    /**
     * Process the written posts from a student for the activity.
     * @param $student
     * @param $row
     * @return void
     */
    private function process_written_posts(&$student, $row)
    {
        // Only count a written post if: the post is not in an anonymous discussion:
        // or the post is in a partial anonymous discussion and the user is not the starter of the discussion.
        if (
            !array_key_exists($row->postid, $student->submittedposts) &&
            ($row->anonymoussetting == 0 || ($row->anonymoussetting == 1 && $row->postuserid != $row->discussuserid))
        ) {

            $this->increment_forumactivity($student, $row);
            $student->activity += 1;
            $student->submittedposts[$row->postid] = $row->postid;
        }
    }

    /**
     * Increments the forum activity of a student.
     * @param $row
     * @param $overflowid
     * @param $forumactivity
     * @return void
     */
    private function increment_forumactivity(&$student, $row)
    {
        if ($row->overflowid == $this->overflowid) {
            $student->forumactivity++;
        }
    }

    // Sort function.

    /**
     * Method to sort the userstatsdata-table.
     * @param array $sortorder The sort order array.
     * @return void
     */
    private function sort_table_data($sortorder)
    {
        $key = $sortorder['sortby'];
        // The index of each object in usertable is it's value of $key.
        $length = count($this->userstatsdata);
        if ($sortorder['sortorder'] == 4) {
            // 4 means sort in ascending order.
            overflow_quick_array_sort($this->userstatsdata, 0, $length - 1, $key, 'asc');
        } else if ($sortorder['sortorder'] == 3) {
            // 3 means sort in descending order.
            overflow_quick_array_sort($this->userstatsdata, 0, $length - 1, $key, 'desc');
        }
    }
}

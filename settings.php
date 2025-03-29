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
 * File for the settings of overflow.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once(dirname(__FILE__) . '/lib.php');

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_overflow', get_string('pluginname', 'local_overflow'));

    // Number of discussions per page.
    $settings->add(new admin_setting_configtext('local_overflow/manydiscussions', get_string('manydiscussions', 'local_overflow'),
        get_string('configmanydiscussions', 'local_overflow'), 10, PARAM_INT));

    if (isset($CFG->maxbytes)) {
        $maxbytes = 0;
        if (get_config('local_overflow', 'maxbytes')) {
            $maxbytes = get_config('local_overflow', 'maxbytes');
        }
        $settings->add(new admin_setting_configselect('local_overflow/maxbytes', get_string('maxattachmentsize', 'local_overflow'),
            get_string('configmaxbytes', 'local_overflow'), 512000, get_max_upload_sizes($CFG->maxbytes, 0, 0, $maxbytes)));
    }

    // Default number of attachments allowed per post in all moodlevoerflows.
    $settings->add(new admin_setting_configtext('local_overflow/maxattachments', get_string('maxattachments', 'local_overflow'),
        get_string('configmaxattachments', 'local_overflow'), 9, PARAM_INT));

    $settings->add(new admin_setting_configtext('local_overflow/maxeditingtime', get_string('maxeditingtime', 'local_overflow'),
        get_string('configmaxeditingtime', 'local_overflow'), 3600, PARAM_INT));

    // Default read tracking settings.
    $options = [];
    $options[OVERFLOW_TRACKING_OPTIONAL] = get_string('trackingoptional', 'local_overflow');
    $options[OVERFLOW_TRACKING_OFF] = get_string('trackingoff', 'local_overflow');
    $options[OVERFLOW_TRACKING_FORCED] = get_string('trackingon', 'local_overflow');
    $settings->add(new admin_setting_configselect('local_overflow/trackingtype', get_string('trackingtype', 'local_overflow'),
        get_string('configtrackingtype', 'local_overflow'), OVERFLOW_TRACKING_OPTIONAL, $options));

    // Should unread posts be tracked for each user?
    $settings->add(new admin_setting_configcheckbox('local_overflow/trackreadposts',
        get_string('trackoverflow', 'local_overflow'), get_string('configtrackoverflow', 'local_overflow'), 1));

    // Allow overflows to be set to forced read tracking.
    $settings->add(new admin_setting_configcheckbox('local_overflow/allowforcedreadtracking',
        get_string('forcedreadtracking', 'local_overflow'), get_string('configforcedreadtracking', 'local_overflow'), 0));

    // Default number of days that a post is considered old.
    $settings->add(new admin_setting_configtext('local_overflow/oldpostdays', get_string('oldpostdays', 'local_overflow'),
        get_string('configoldpostdays', 'local_overflow'), 14, PARAM_INT));

    // Default time (hour) to execute 'clean_read_records' cron.
    $options = [];
    for ($i = 0; $i < 24; $i++) {
        $options[$i] = sprintf("%02d", $i);
    }
    $settings->add(new admin_setting_configselect('local_overflow/cleanreadtime', get_string('cleanreadtime', 'local_overflow'),
        get_string('configcleanreadtime', 'local_overflow'), 2, $options));

    $url = new moodle_url('/local/local_overflow/resetanonymous.php');

    $settings->add(new admin_setting_configcheckbox('local_overflow/allowanonymous',
        get_string('allowanonymous', 'local_overflow'),
        get_string('allowanonymous_desc', 'local_overflow', $url->out(false)),
        1
    ));

    // Allow teachers to disable ratings/reputation.
    $settings->add(new admin_setting_configcheckbox('local_overflow/allowdisablerating',
        get_string('allowdisablerating', 'local_overflow'), get_string('configallowdisablerating', 'local_overflow'), 1));

    // Allow users to change their votes?
    $settings->add(new admin_setting_configcheckbox('local_overflow/allowratingchange',
        get_string('allowratingchange', 'local_overflow'), get_string('configallowratingchange', 'local_overflow'), 1));

    // Allow teachers to enable review before publish.
    $settings->add(new admin_setting_configcheckbox('local_overflow/allowreview',
        get_string('allowreview', 'local_overflow'), get_string('allowreview_desc', 'local_overflow'), 1));

    $settings->add(new admin_setting_configtext('local_overflow/reviewpossibleaftertime',
        get_string('reviewpossibleaftertime', 'local_overflow'),
        get_string('reviewpossibleaftertime_desc', 'local_overflow'), 1800, PARAM_INT));

    // Set scales for the reputation.
    $votesettings = [];

    // Votescale: How much reputation gives a vote for another post?
    $settings->add($votesettings[] = new admin_setting_configtext('local_overflow/votescalevote',
        get_string('votescalevote', 'local_overflow'),
        get_string('configvotescalevote', 'local_overflow'), 1, PARAM_INT));

    // Votescale: How much reputation gives a post that has been downvoted?
    $settings->add($votesettings[] = new admin_setting_configtext('local_overflow/votescaledownvote',
        get_string('votescaledownvote', 'local_overflow'), get_string('configvotescaledownvote', 'local_overflow'), -5, PARAM_INT));

    // Votescale: How much reputation gives a post that has been upvoted?
    $settings->add($votesettings[] = new admin_setting_configtext('local_overflow/votescaleupvote',
        get_string('votescaleupvote', 'local_overflow'),
        get_string('configvotescaleupvote', 'local_overflow'), 5, PARAM_INT));

    // Votescale: How much reputation gives a post that is marked as solved.
    $settings->add($votesettings[] = new admin_setting_configtext('local_overflow/votescalesolved',
        get_string('votescalesolved', 'local_overflow'),
        get_string('configvotescalesolved', 'local_overflow'), 30, PARAM_INT));

    // Votescale: How much reputation gives a post that is marked as helpful.
    $settings->add($votesettings[] = new admin_setting_configtext('local_overflow/votescalehelpful',
        get_string('votescalehelpful', 'local_overflow'),
        get_string('configvotescalehelpful', 'local_overflow'), 15, PARAM_INT));

    // Number of discussions per page.
    $settings->add($votesettings[] = new admin_setting_configtext('local_overflow/maxmailingtime',
        get_string('maxmailingtime', 'local_overflow'),
        get_string('configmaxmailingtime', 'local_overflow'), 48, PARAM_INT));

    foreach ($votesettings as $setting) {
        $setting->set_updatedcallback('overflow_update_all_grades');
    }

    // Allow teachers to see cumulative userstats.
    $settings->add(new admin_setting_configcheckbox('local_overflow/showuserstats',
        get_string('showuserstats', 'local_overflow'), get_string('configshowuserstats', 'local_overflow'), 0));

    $ADMIN->add('localplugins', $settings);
}
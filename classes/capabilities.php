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
 * Class for easily caching capabilities.
 *
 * @package   local_overflow
 * @copyright 2022 Justus Dieckmann WWU
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_overflow;

use context;

/**
 * Class for easily caching capabilities.
 *
 * @package   local_overflow
 * @copyright 2022 Justus Dieckmann WWU
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class capabilities {

    /** capability add instance */
    const ADD_INSTANCE = 'local/overflow:addinstance';

    /** capability view discussions*/
    const VIEW_DISCUSSION = 'local/overflow:viewdiscussion';

    /** capability reply in discussions*/
    const REPLY_POST = 'local/overflow:replypost';

    /** capability start discussions*/
    const START_DISCUSSION = 'local/overflow:startdiscussion';

    /** capability edit post from other participants*/
    const EDIT_ANY_POST = 'local/overflow:editanypost';

    /** capability delete your post*/
    const DELETE_OWN_POST = 'local/overflow:deleteownpost';

    /** capability delete post from any participant*/
    const DELETE_ANY_POST = 'local/overflow:deleteanypost';

    /** capability rate a post*/
    const RATE_POST = 'local/overflow:ratepost';

    /** capability mark a post as a solution for a questions*/
    const MARK_SOLVED = 'local/overflow:marksolved';

    /** capability manage the subscription of a overflow instance */
    const MANAGE_SUBSCRIPTIONS = 'local/overflow:managesubscriptions';

    /** capability force the subscription of participants */
    const ALLOW_FORCE_SUBSCRIBE = 'local/overflow:allowforcesubscribe';

    /** capability attach files to posts */
    const CREATE_ATTACHMENT = 'local/overflow:createattachment';

    /** capability review post to be published*/
    const REVIEW_POST = 'local/overflow:reviewpost';

    /** @var array cache capabilities*/
    private static $cache = [];

    /**
     * Saves the cache from has_capability.
     *
     * @param string            $capability The capability that is being checked.
     * @param context           $context    The context.
     * @param int|null          $userid     The user ID.
     *
     * @return bool true or false
     */
    public static function has(string $capability, context $context, $userid = null): bool {
        global $USER;
        if (!$userid) {
            $userid = $USER->id;
        }

        $key = "$userid:$context->id:$capability";

        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = has_capability($capability, $context, $userid);
        }

        return self::$cache[$key];
    }

}

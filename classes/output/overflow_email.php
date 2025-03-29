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
 * overflow post renderable for e-mail.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_overflow\output;

use local_overflow\anonymous;

/**
 * overflow email renderable for use in e-mail.
 *
 * @package   local_overflow
 * @copyright 2017 Kennet Winter <k_wint10@uni-muenster.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overflow_email implements \renderable, \templatable {

    /**
     * The overflow that the post is in.
     *
     * @var object $overflow
     */
    protected $overflow = null;

    /**
     * The discussion that the overflow post is in.
     *
     * @var object $discussion
     */
    protected $discussion = null;

    /**
     * The overflow post being displayed.
     *
     * @var object $post
     */
    protected $post = null;

    /**
     * Whether the user can reply to this post.
     *
     * @var bool $canreply
     */
    protected $canreply = false;

    /**
     * Whether to override forum display when displaying usernames.
     * @var bool $viewfullnames
     */
    protected $viewfullnames = false;

    /**
     * The user that is reading the post.
     *
     * @var object $userto
     */
    protected $userto = null;

    /**
     * The user that wrote the post.
     *
     * @var object $author
     */
    protected $author = null;

    /**
     * An associative array indicating which keys on this object should be writeable.
     *
     * @var array $writablekeys
     */
    protected $writablekeys = [
        'viewfullnames' => true,
    ];

    /**
     * Builds a renderable overflow mail.
     *
     * @param object $overflow The overflow of the post
     * @param object $discussion     Discussion thread in which the post appears
     * @param object $post           The post
     * @param object $author         Author of the post
     * @param object $recipient      Recipient of the email
     * @param bool   $canreply       whether the user can reply to the post
     */
    public function __construct($overflow, $discussion, $post, $author, $recipient, $canreply) {
        $this->overflow = $overflow;
        $this->discussion = $discussion;
        $this->post = $post;
        $this->author = $author;
        $this->userto = $recipient;
        $this->canreply = $canreply;
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param \renderer_base $renderer  The render to be used for formatting the message
     * @param bool                         $plaintext Whether the target is a plaintext target
     *
     * @return mixed Data ready for use in a mustache template
     */
    public function export_for_template(\renderer_base $renderer, $plaintext = false) {
        if ($plaintext) {
            return $this->export_for_template_text($renderer);
        } else {
            return $this->export_for_template_html($renderer);
        }
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param \local_overflow_renderer $renderer The render to be used for formatting the message
     *
     * @return array Data ready for use in a mustache template
     */
    protected function export_for_template_text(\local_overflow_renderer $renderer) {

        return [
            'id' => html_entity_decode($this->post->id, ENT_COMPAT),
            'name' => html_entity_decode($this->get_overflowname(), ENT_COMPAT),
            'showdiscussionname' => html_entity_decode($this->has_showdiscussionname(), ENT_COMPAT),
            'discussionname' => html_entity_decode($this->get_discussionname(), ENT_COMPAT),
            'subject' => html_entity_decode($this->get_subject(), ENT_COMPAT),
            'authorfullname' => html_entity_decode($this->get_author_fullname(), ENT_COMPAT),
            'postdate' => html_entity_decode($this->get_postdate(), ENT_COMPAT),
            'firstpost' => $this->is_firstpost(),
            'canreply' => $this->canreply,
            'permalink' => $this->get_permalink(),
            'overflowindexlink' => $this->get_overflowindexlink(),
            'replylink' => $this->get_replylink(),
            'authorpicture' => $this->get_author_picture(),
            'unsubscribeoverflowlink' => $this->get_unsubscribeoverflowlink(),
            'parentpostlink' => $this->get_parentpostlink(),
            'unsubscribediscussionlink' => $this->get_unsubscribediscussionlink(),
            'overflowviewlink' => $this->get_overflowviewlink(),
            'discussionlink' => $this->get_discussionlink(),
            'authorlink' => $this->get_authorlink(),
            'grouppicture' => $this->get_group_picture(),

            // Format some components according to the renderer.
            'message' => html_entity_decode($renderer->format_message_text($this->post), ENT_COMPAT),
        ];
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param \local_overflow_renderer $renderer The render to be used for formatting the message and attachments
     *
     * @return stdClass Data ready for use in a mustache template
     */
    protected function export_for_template_html(\local_overflow_renderer $renderer) {
        return [
            'id' => $this->post->id,
            'name' => $this->get_overflowname(),
            'showdiscussionname' => $this->has_showdiscussionname(),
            'discussionname' => $this->get_discussionname(),
            'subject' => $this->get_subject(),
            'authorfullname' => $this->get_author_fullname(),
            'postdate' => $this->get_postdate(),
            'canreply' => $this->canreply,
            'permalink' => $this->get_permalink(),
            'firstpost' => $this->is_firstpost(),
            'replylink' => $this->get_replylink(),
            'unsubscribediscussionlink' => $this->get_unsubscribediscussionlink(),
            'unsubscribeoverflowlink' => $this->get_unsubscribeoverflowlink(),
            'parentpostlink' => $this->get_parentpostlink(),
            'overflowindexlink' => $this->get_overflowindexlink(),
            'overflowviewlink' => $this->get_overflowviewlink(),
            'discussionlink' => $this->get_discussionlink(),
            'authorlink' => $this->get_authorlink(),
            'authorpicture' => $this->get_author_picture(),
            'grouppicture' => $this->get_group_picture(),

            // Format some components according to the renderer.
            'message' => $renderer->format_message_text($this->overflow, $this->post),
        ];
    }

    /**
     * Magically sets a property against this object.
     *
     * @param string $name
     * @param mixed  $value
     */
    public function __set($name, $value) {

        // First attempt to use the setter function.
        $methodname = 'set_' . $name;
        if (method_exists($this, $methodname)) {
            return $this->{$methodname}($value);
        }

        // Fall back to the writable keys list.
        if (isset($this->writablekeys[$name]) && $this->writablekeys[$name]) {
            return $this->{$name} = $value;
        }

        // Throw an error rather than fail silently.
        throw new \coding_exception('Tried to set unknown property "' . $name . '"');
    }

    /**
     * Get the link to unsubscribe from the discussion.
     *
     * @return null|string
     */
    public function get_unsubscribediscussionlink() {

        // Check whether the overflow is subscribable.
        $subscribable = \local_overflow\subscriptions::is_subscribable($this->overflow,
                \context_system::instance());
        if (!$subscribable) {
            return null;
        }

        // Prepare information.
        $id = $this->overflow->id;
        $d = $this->discussion->id;
        $url = '/local/overflow/subscribe.php';

        // Generate a link to unsubscribe from the discussion.
        $link = new \moodle_url($url, ['id' => $id, 'd' => $d]);

        return $link->out(false);
    }

    /**
     * The formatted subject for the current post.
     *
     * @return string
     */
    public function get_subject() {
        return format_string($this->discussion->name, true);
    }

    /**
     * The name of the overflow.
     *
     * @return string
     */
    public function get_overflowname() {
        return format_string($this->overflow->name, true);
    }

    /**
     * Whether to show the discussion name.
     * If the overflow name matches the discussion name, the discussion name is not typically displayed.
     *
     * @return boolean
     */
    public function has_showdiscussionname() {
        return ($this->overflow->name !== $this->discussion->name);
    }

    /**
     * The name of the current discussion.
     *
     * @return string
     */
    public function get_discussionname() {
        return format_string($this->discussion->name, true);
    }

    /**
     * The fullname of the post author.
     *
     * @return string
     */
    public function get_author_fullname() {
        if (anonymous::is_post_anonymous($this->discussion, $this->overflow, $this->author->id)) {
            return get_string('privacy:anonym_user_name', 'local_overflow');
        } else {
            return fullname($this->author, $this->viewfullnames);
        }
    }

    /**
     * The date of the post, formatted according to the postto user's preferences.
     *
     * @return string.
     */
    public function get_postdate() {

        // Get the date.
        $postmodified = $this->post->modified;

        return userdate($postmodified, "", \core_date::get_user_timezone($this->get_postto()));
    }

    /**
     * The recipient of the post.
     *
     * @return string
     */
    protected function get_postto() {
        global $USER;
        if (null === $this->userto) {
            return $USER;
        }

        return $this->userto;
    }

    /**
     * Get the link to the current post, including post anchor.
     *
     * @return string
     */
    public function get_permalink() {
        $link = $this->get_discussionurl();
        $link->set_anchor($this->get_postanchor());

        return $link->out(false);
    }

    /**
     * Whether this is the first post.
     *
     * @return boolean
     */
    public function is_firstpost() {
        return empty($this->post->parent);
    }

    /**
     * Get the link to reply to the current post.
     *
     * @return string
     */
    public function get_replylink() {
        return new \moodle_url(
            '/local/overflow/post.php', [
                'reply' => $this->post->id,
            ]
        );
    }

    /**
     * Get the link to unsubscribe from the overflow.
     *
     * @return string
     */
    public function get_unsubscribeoverflowlink() {
        if (!\local_overflow\subscriptions::is_subscribable($this->overflow,
                \context_system::instance())) {
            return null;
        }
        $link = new \moodle_url(
            '/local/overflow/subscribe.php', [
                'id' => $this->overflow->id,
            ]
        );

        return $link->out(false);
    }

    /**
     * Get the link to the parent post.
     *
     * @return string
     */
    public function get_parentpostlink() {
        $link = $this->get_discussionurl();
        $link->param('parent', $this->post->parent);

        return $link->out(false);
    }

    /**
     * Get the link to the current discussion.
     *
     * @return string
     */
    protected function get_discussionurl() {
        return new \moodle_url(
        // Posts are viewed on the topic.
            '/local/overflow/discussion.php', [
                // Within a discussion.
                'd' => $this->discussion->id,
            ]
        );
    }

    /**
     * Get the link to the current discussion.
     *
     * @return string
     */
    public function get_discussionlink() {
        $link = $this->get_discussionurl();

        return $link->out(false);
    }

    /**
     * Get the link to the overflow index.
     *
     * @return string
     */
    public function get_overflowindexlink() {
        $link = new \moodle_url(
        // Posts are viewed on the topic.
            '/local/overflow/index.php', [
                'id' => $this->overflow->id,
            ]
        );

        return $link->out(false);
    }

    /**
     * Get the link to the view page for this overflow.
     *
     * @return string
     */
    public function get_overflowviewlink() {
        $link = new \moodle_url(
        // Posts are viewed on the topic.
            '/local/overflow/view.php', [
                'm' => $this->overflow->id,
            ]
        );

        return $link->out(false);
    }

    /**
     * Get the link to the author's profile page.
     *
     * @return string
     */
    public function get_authorlink() {
        if (anonymous::is_post_anonymous($this->discussion, $this->overflow, $this->author->id)) {
            return null;
        }

        $link = new \moodle_url(
            '/user/view.php', [
                'id' => $this->post->userid,
            ]
        );

        return $link->out(false);
    }

    /**
     * The HTML for the author's user picture.
     *
     * @return string
     */
    public function get_author_picture() {
        global $OUTPUT;
        if (anonymous::is_post_anonymous($this->discussion, $this->overflow, $this->author->id)) {
            return '';
        }

        return $OUTPUT->user_picture($this->author, []);
    }

    /**
     * The HTML for a group picture.
     *
     * @return string
     */
    public function get_group_picture() {
        if (anonymous::is_post_anonymous($this->discussion, $this->overflow, $this->author->id)) {
            return '';
        }
        return '';
    }

    /**
     * The plaintext anchor id for the current post.
     *
     * @return string
     */
    public function get_postanchor() {
        return 'p' . $this->post->id;
    }
}

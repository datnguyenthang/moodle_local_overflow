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
 * Implements reviewing functionality
 *
 * @module     local_overflow/reviewing
 * @copyright  2022 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';
import Prefetch from 'core/prefetch';
import Templates from 'core/templates';
import {get_string as getString} from 'core/str';

/**
 * Init function.
 */
export function init() {
    Prefetch.prefetchTemplates(['local_overflow/reject_post_form', 'local_overflow/review_buttons']);
    Prefetch.prefetchStrings('local_overflow',
        ['post_was_approved', 'jump_to_next_post_needing_review', 'there_are_no_posts_needing_review', 'post_was_rejected']);

    const root = document.getElementById('overflow-posts');
    root.onclick = async(e) => {
        const action = e.target.getAttribute('data-overflow-action');

        if (!action) {
            return;
        }

        const post = e.target.closest('*[data-overflow-postid]');
        const reviewRow = e.target.closest('.reviewrow');
        const postID = post.getAttribute('data-overflow-postid');

        if (action === 'approve') {
            reviewRow.innerHTML = '.';
            const nextPostURL = await Ajax.call([{
                methodname: 'local_overflow_review_approve_post',
                args: {
                    postid: postID,
                }
            }])[0];

            let message = await getString('post_was_approved', 'local_overflow') + ' ';
            if (nextPostURL) {
                message += `<a href="${nextPostURL}">`
                    + await getString('jump_to_next_post_needing_review', 'local_overflow')
                    + "</a>";
            } else {
                message += await getString('there_are_no_posts_needing_review', 'local_overflow');
            }
            reviewRow.innerHTML = message;
            post.classList.remove("pendingreview");
        } else if (action === 'reject') {
            reviewRow.innerHTML = '.';
            reviewRow.innerHTML = await Templates.render('local_overflow/reject_post_form', {});
        } else if (action === 'reject-submit') {
            const rejectMessage = post.querySelector('textarea.reject-reason').value.toString().trim();
            reviewRow.innerHTML = '.';
            const args = {
                postid: postID,
                reason: rejectMessage ? rejectMessage : null
            };
            const nextPostURL = await Ajax.call([{
                methodname: 'local_overflow_review_reject_post',
                args: args
            }])[0];

            let message = await getString('post_was_rejected', 'local_overflow') + ' ';
            if (nextPostURL) {
                message += `<a href="${nextPostURL}">`
                    + await getString('jump_to_next_post_needing_review', 'local_overflow')
                    + "</a>";
            } else {
                message += await getString('there_are_no_posts_needing_review', 'local_overflow');
            }
            reviewRow.innerHTML = message;
        } else if (action === 'reject-cancel') {
            reviewRow.innerHTML = '.';
            reviewRow.innerHTML = await Templates.render('local_overflow/review_buttons', {});
        }
    };
}
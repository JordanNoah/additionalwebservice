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
 * External functions and service definitions.
 *
 * @package    local_additional_web_service
 * @copyright  2022 Michael Alejandro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_additional_web_service;


use core_user;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use invalid_parameter_exception;
use local_additional_web_service\exceptions\SentNotificationError;
use local_additional_web_service\exceptions\UserNotFound;
use local_additional_web_service\output\Notification;


/**
 * @Class external_notification
 * @author 2022 Michael Alejandro
 * @package additional_web_service
 */
class external_notification extends external_api
{

    /**
     * Returns description of sent_notification_parameters() parameters
     * @return external_function_parameters
     */
    public static function sent_notification_parameters()
    {
        // The external_function_parameters constructor expects an array of external_description.
        return new external_function_parameters(
            [
                'userid_to' => new external_value(PARAM_INT, 'user who receives the message'),
                'subject' => new external_single_structure(
                    [
                        'component' => new external_value(PARAM_TEXT, 'component or plugins of moodle'),
                        'lang_key' => new external_value(PARAM_TEXT, 'name key of translations')
                    ]
                ),
                'message' => new external_single_structure(
                    [
                        'component' => new external_value(PARAM_TEXT, 'component or plugins of moodle'),
                        'lang_key' => new external_value(PARAM_TEXT, 'name key of translations'),
                        'additional_content' => new external_value(PARAM_RAW, 'additional content to be added to the message explicitly.', VALUE_DEFAULT, ""),
                    ]
                ),
                'url' => new external_value(PARAM_URL, 'url to which is redirected when the notification is clicked', VALUE_DEFAULT, ""),
            ]
        );
    }

    /**
     * Returns description of sent_notification_returns() result value
     * @return external_single_structure
     */
    public static function sent_notification_returns()
    {
        return new external_single_structure(
            [
                'message_id' => new external_value(PARAM_INT, 'message id'),
                'user_to' => new external_value(PARAM_TEXT, 'user recipient'),
                'subject' => new external_value(PARAM_TEXT, 'subject message'),
                'message_html' => new external_value(PARAM_RAW, 'message in format html'),

            ]
        );
    }

    /**
     * @param $messageContent
     * @return array
     * @throws invalid_parameter_exception
     * @throws \coding_exception
     * @throws UserNotFound
     */
    public static function sent_notification($useridTo, $subject, $messageContent, $url)
    {
        global $CFG;

        require_once("{$CFG->dirroot}/local/additional_web_service/functions.php");

        $params = self::validate_parameters(self::sent_notification_parameters(),
            [
                'userid_to' => $useridTo,
                'subject' => $subject,
                'message' => $messageContent,
                'url' => $url
            ]);

        $user = core_user::get_user($useridTo);

        if(!$user) throw new UserNotFound("user not found");

        $subjectText = getString($subject['component'], $subject['lang_key'], $user->lang);
        $messageHtml = Notification::output_template((object)$params , $user);
        $message = new \core\message\message();
        $message->component = 'local_additional_web_service'; // Your plugin's name
        $message->name = 'notificationapi'; // Your notification name from message.php
        $message->userfrom = \core_user::get_noreply_user(); // If the message is 'from' a specific user you can set them here
        $message->userto = $user;
        $message->subject = $subjectText;
        $message->fullmessage = $messageHtml;
        $message->fullmessageformat = FORMAT_MARKDOWN;
        $message->fullmessagehtml = $messageHtml;
        $message->smallmessage = '';
        $message->notification = 1; // Because this is a notification generated from Moodle, not a user-to-user message
        $message->contexturl = $url; // A relevant URL for the notification
        $messageid = message_send($message);

        if(!$messageid) throw new SentNotificationError("Error sending notification. verify SMTP settings");

        return [
            'message_id' => $messageid,
            'user_to' => $message->userto->username,
            'subject' => $message->subject,
            'message_html' => $message->fullmessagehtml
        ];
    }


}

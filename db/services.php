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

$functions = [
    'local_additional_web_service_get_groups_by_idnumber' => [
        'classname' => 'local_additional_web_service\external',
        'methodname' => 'get_groups_by_idnumber',
        'description' => 'Returns groups by course id and id number',
        'type' => 'read',
        'loginrequired' => false,
        'ajax' => true,
    ],
    'local_additional_web_service_sent_notification' => [
        'classname' => 'local_additional_web_service\external_notification',
        'methodname' => 'sent_notification',
        'description' => 'Send a notification via email to a user',
        'type' => 'write',
        'loginrequired' => false,
        'ajax' => true,
    ],
    'local_additional_web_service_save_grade' => [
        'classname' => 'local_additional_web_service\external_assign_grades',
        'methodname' => 'save_grade',
        'description' => 'Save a grade update for a single student.',
        'type' => 'write',
        'loginrequired' => false,
        'ajax' => true,
    ],
    'local_additional_web_service_get_users_courses' => [
        'classname' => 'local_additional_web_service\external_courses',
        'methodname' => 'get_users_courses',
        'loginrequired' => false,
        'ajax' => true,
        'description' => 'Get the list of courses where a user is enrolled in',
        'type' => 'read',
        'capabilities' => 'moodle/course:viewparticipants',
    ],
    'local_additional_web_service_subscribe_to_announcement_forum' => [
        'classname' => 'local_additional_web_service\external_subscribe_to_announcement_forum',
        'methodname' => 'subscribe_to_announcement_forum',
        'description' => 'Suscribe an user to the announcement forum',
        'type' => 'write',
        'loginrequired' => false,
        'ajax' => true,
    ],
    'local_additional_web_service_unsubscribe_to_forum' => [
        'classname' => 'local_additional_web_service\external_unsubscribe_to_forum',
        'methodname' => 'unsubscribe_to_forum',
        'description' => 'unsubscribe an user to the announcement forum',
        'type' => 'write',
        'loginrequired' => false,
        'ajax' => true,
    ],
    'local_additional_web_service_conversation_messages' => [
        'classname' => 'local_additional_web_service\external_conversation_messages',
        'methodname' => 'get_conversation_messages',
        'description' => 'returns moodle messages without any html attributes',
        'type' => 'read',
        'loginrequired' => false,
        'ajax' => true
    ]
];

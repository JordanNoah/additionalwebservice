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

namespace local_additional_web_service;

use coding_exception;
use context_course;
use dml_exception;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use external_api;
use invalid_parameter_exception;
use mod_forum\subscriptions;
use moodle_exception;
use required_capability_exception;


defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');
require_once ($CFG->dirroot . '/mod/forum/externallib.php');

/**
 * Class external_subscribe_to_announcement_forum
 * @package local_additional_web_service
 */
class external_subscribe_to_announcement_forum extends external_api
{
    /**
     * @return external_function_parameters
     */
    public static function subscribe_to_announcement_forum_parameters(): external_function_parameters
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'user id'),
                'courseid' => new external_value(PARAM_INT, 'course  id'),
                'subscribe' => new external_value(PARAM_BOOL, 'true to subscribe, false to unsubscribe')
            )
        );
    }

    /**
     * @param $courseid
     * @param $userid
     * @param $subscribe
     * @return true[]
     * @throws coding_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     * @throws required_capability_exception
     */
    public static function subscribe_to_announcement_forum($userid, $courseid, $subscribe): array
    {

        global $DB;

        $params = self::validate_parameters(self::subscribe_to_announcement_forum_parameters(), array(
            'userid' => $userid,
            'courseid' => $courseid,
            'subscribe' => $subscribe,
        ));

        $course = $DB->get_record('course', array('id' => $params['courseid']), '*', MUST_EXIST);
        $context = context_course::instance($course->id);

        $forum = $DB->get_record('forum', array('course' => $course->id, 'type' => 'news'), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $course->id, false, MUST_EXIST);

        $forum->cmidnumber = $cm->id;
        $forum->modcontext = $context;

        $subscribed = \mod_forum\subscriptions::is_subscribed($params['userid'], $forum,'', $cm);

        if ($subscribed && !$params['subscribe']) {
            \mod_forum\subscriptions::unsubscribe_user($params['userid'], $forum, $cm, $course);
            $tracked = 'trackremoved';
        } else if (!$subscribed && $params['subscribe']) {
            \mod_forum\subscriptions::subscribe_user($params['userid'], $forum, $cm, $course);
            if (forum_tp_start_tracking($forum->id)) {
                $eventparams = [
                    'context' => \context_module::instance($cm->id),
                    'relateduserid' => $userid,
                    'other' => ['forumid' => $forum->id],
                ];
                $event = \mod_forum\event\readtracking_enabled::create($eventparams);
                $event->trigger();
                $tracked = 'tracking';
            } else {
                $tracked = 'cannottrack';
            }
        }
        return [
            'userid' => $params['userid'],
            'courseid' => $params['courseid'],
            'subscribe' => $params['subscribe'],
            'tracking_status' => $tracked,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function subscribe_to_announcement_forum_returns(): external_single_structure

    {


        return new external_single_structure(array (
                'userid' => new external_value(PARAM_INT, 'user id'),
                'courseid' => new external_value(PARAM_INT, 'course  id'),
                'subscribe' => new external_value(PARAM_BOOL, 'true to subscribe, false to unsubscribe')
            )
        );

    }
}


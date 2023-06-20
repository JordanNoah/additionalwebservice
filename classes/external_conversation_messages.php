<?php

namespace local_additional_web_service;

use context_system;
use core_message\api;
use core_message\helper;
use core_reportbuilder\local\aggregation\count;
use external_api;
use external_multiple_structure;
use external_single_structure;
use external_function_parameters;
use external_value;
use external_format_value;
use invalid_parameter_exception;
use local_emoji\models\emoji;
use Matrix\Exception;
use moodle_exception;
use restricted_context_exception;

class external_conversation_messages extends external_api
{
    private static $contextrestriction;
    public static function get_conversation_messages_parameters(){
        return new external_function_parameters(
            array(
                'currentuserid' => new external_value(PARAM_INT, 'The current user\'s id'),
                'convid' => new external_value(PARAM_INT, 'The conversation id'),
                'limitfrom' => new external_value(PARAM_INT, 'Limit from', VALUE_DEFAULT, 0),
                'limitnum' => new external_value(PARAM_INT, 'Limit number', VALUE_DEFAULT, 0),
                'newest' => new external_value(PARAM_BOOL, 'Newest first?', VALUE_DEFAULT, false),
                'timefrom' => new external_value(PARAM_INT,
                    'The timestamp from which the messages were created', VALUE_DEFAULT, 0),
            )
        );
    }

    public static function get_conversation_messages(int $currentuserid, int $convid, int $limitfrom = 0, int $limitnum = 0, bool $newest = false, int $timefrom = 0){
            global $CFG, $USER;

            // Check if messaging is enabled.
            if (empty($CFG->messaging)) {
                throw new moodle_exception('disabled', 'message');
            }

            $systemcontext = context_system::instance();

            $params = array(
                'currentuserid' => $currentuserid,
                'convid' => $convid,
                'limitfrom' => $limitfrom,
                'limitnum' => $limitnum,
                'newest' => $newest,
                'timefrom' => $timefrom,
            );

            $params = self::validate_parameters(self::get_conversation_messages_parameters(), $params);

            self::validate_context($systemcontext);

            $sort = $newest ? 'timecreated DESC' : 'timecreated ASC';

            $timeto = empty($params['timefrom']) ? 0 : time() - 1;


            if ($params['timefrom'] == time()) {
                $messages = [];
            } else {
                $messages = self::get_conversation_messages_api(
                    $params['currentuserid'],
                    $params['convid'],
                    $params['limitfrom'],
                    $params['limitnum'],
                    $sort,
                    $params['timefrom'],
                    $timeto);
            }

            return $messages;
    }

    public static function validate_context($context) {
        global $CFG, $PAGE;

        if (empty($context)) {
            throw new invalid_parameter_exception('Context does not exist');
        }
        if (empty(self::$contextrestriction)) {
            self::$contextrestriction = context_system::instance();
        }
        $rcontext = self::$contextrestriction;

        if ($rcontext->contextlevel == $context->contextlevel) {
            if ($rcontext->id != $context->id) {
                throw new restricted_context_exception();
            }
        } else if ($rcontext->contextlevel > $context->contextlevel) {
            throw new restricted_context_exception();
        } else {
            $parents = $context->get_parent_context_ids();
            if (!in_array($rcontext->id, $parents)) {
                throw new restricted_context_exception();
            }
        }

        $PAGE->reset_theme_and_output();
        list($unused, $course, $cm) = get_context_info_array($context->id);
        require_login($course, false, $cm, false, true);
        $PAGE->set_context($context);
    }

    public static function get_conversation_messages_api(int $userid, int $convid, int $limitfrom = 0, int $limitnum = 0,
                                                     string $sort = 'timecreated ASC', int $timefrom = 0, int $timeto = 0)
    {
        if (!empty($timefrom)) {
            // Check the cache to see if we even need to do a DB query.
            $cache = \cache::make('core', 'message_time_last_message_between_users');
            $key = helper::get_last_message_time_created_cache_key($convid);
            $lastcreated = $cache->get($key);

            // The last known message time is earlier than the one being requested so we can
            // just return an empty result set rather than having to query the DB.
            if ($lastcreated && $lastcreated < $timefrom) {
                return helper::format_conversation_messages($userid, $convid, []);
            }
        }

        $messages = helper::get_conversation_messages($userid, $convid, 0, $limitfrom, $limitnum, $sort, $timefrom, $timeto);

        return static::format_conversation_messages_api($userid, $convid, $messages);
    }

    public static function format_conversation_messages_api(int $userid, int $convid, array $messages) : array {
        global $USER;

        // Create the conversation array.
        $conversation = array(
            'id' => $convid,
        );

        // Store the messages.
        $arrmessages = array();

        foreach ($messages as $message) {

            $emoji = new Emoji($message->fullmessage);

            // Store the message information.
            $msg = new \stdClass();
            $msg->id = $message->id;
            $msg->useridfrom = $message->useridfrom;
            if(count($emoji->detect_emoji($message->fullmessage))>0){
                $msg->text = $emoji->replace_emoji($message->fullmessage,":",":");
            }else{
                $msg->text = $message->fullmessage;
            }

            $msg->timecreated = $message->timecreated;
            $arrmessages[] = $msg;
        }
        // Add the messages to the conversation.
        $conversation['messages'] = $arrmessages;

        // Get the users who have sent any of the $messages.
        $memberids = array_unique(array_map(function($message) {
            return $message->useridfrom;
        }, $messages));

        if (!empty($memberids)) {
            // Get members information.
            $conversation['members'] = self::get_member_info($userid, $memberids);
        } else {
            $conversation['members'] = array();
        }

        return $conversation;
    }

    public static function get_member_info(int $referenceuserid, array $userids, bool $includecontactrequests = false,
                                           bool $includeprivacyinfo = false) : array {
        global $DB, $PAGE;

        // Prevent exception being thrown when array is empty.
        if (empty($userids)) {
            return [];
        }

        list($useridsql, $usersparams) = $DB->get_in_or_equal($userids);
        $userfieldsapi = \core_user\fields::for_userpic()->including('lastaccess');
        $userfields = $userfieldsapi->get_sql('u', false, '', '', false)->selects;
        $userssql = "SELECT $userfields, u.deleted, mc.id AS contactid, mub.id AS blockedid
                       FROM {user} u
                  LEFT JOIN {message_contacts} mc
                         ON ((mc.userid = ? AND mc.contactid = u.id) OR (mc.userid = u.id AND mc.contactid = ?))
                  LEFT JOIN {message_users_blocked} mub
                         ON (mub.userid = ? AND mub.blockeduserid = u.id)
                      WHERE u.id $useridsql";
        $usersparams = array_merge([$referenceuserid, $referenceuserid, $referenceuserid], $usersparams);
        $otherusers = $DB->get_records_sql($userssql, $usersparams);

        $members = [];
        foreach ($otherusers as $member) {
            // Set basic data.
            $data = new \stdClass();
            $data->id = $member->id;
            $data->fullname = fullname($member);

            // Create the URL for their profile.
            $profileurl = new \moodle_url('/user/profile.php', ['id' => $member->id]);
            $data->profileurl = $profileurl->out(false);

            // Set the user picture data.
            $userpicture = new \user_picture($member);
            $userpicture->size = 1; // Size f1.
            $data->profileimageurl = $userpicture->get_url($PAGE)->out(false);
            $userpicture->size = 0; // Size f2.
            $data->profileimageurlsmall = $userpicture->get_url($PAGE)->out(false);

            // Set online status indicators.
            $data->isonline = false;
            $data->showonlinestatus = false;
            if (!$member->deleted) {
                $data->isonline = self::show_online_status($member) ? self::is_online($member->lastaccess) : null;
                $data->showonlinestatus = is_null($data->isonline) ? false : true;
            }

            // Set contact and blocked status indicators.
            $data->iscontact = ($member->contactid) ? true : false;

            // We don't want that a user has been blocked if they can message the user anyways.
            $canmessageifblocked = api::can_send_message($referenceuserid, $member->id, true);
            $data->isblocked = ($member->blockedid && !$canmessageifblocked) ? true : false;

            $data->isdeleted = ($member->deleted) ? true : false;

            $data->requirescontact = null;
            $data->canmessage = null;
            $data->canmessageevenifblocked = null;
            if ($includeprivacyinfo) {
                $privacysetting = api::get_user_privacy_messaging_preference($member->id);
                $data->requirescontact = $privacysetting == api::MESSAGE_PRIVACY_ONLYCONTACTS;

                // Here we check that if the sender wanted to block the recipient, the
                // recipient would still be able to message them regardless.
                $data->canmessageevenifblocked = !$data->isdeleted && $canmessageifblocked;
                $data->canmessage = !$data->isdeleted && api::can_send_message($member->id, $referenceuserid);
            }

            // Populate the contact requests, even if we don't need them.
            $data->contactrequests = [];

            $members[$data->id] = $data;
        }

        // Check if we want to include contact requests as well.
        if (!empty($members) && $includecontactrequests) {
            list($useridsql, $usersparams) = $DB->get_in_or_equal($userids);

            $wheresql = "(userid $useridsql AND requesteduserid = ?) OR (userid = ? AND requesteduserid $useridsql)";
            $params = array_merge($usersparams, [$referenceuserid, $referenceuserid], $usersparams);
            if ($contactrequests = $DB->get_records_select('message_contact_requests', $wheresql, $params,
                'timecreated ASC, id ASC')) {
                foreach ($contactrequests as $contactrequest) {
                    if (isset($members[$contactrequest->userid])) {
                        $members[$contactrequest->userid]->contactrequests[] = $contactrequest;
                    }
                    if (isset($members[$contactrequest->requesteduserid])) {
                        $members[$contactrequest->requesteduserid]->contactrequests[] = $contactrequest;
                    }
                }
            }
        }

        // Remove any userids not in $members. This can happen in the case of a user who has been deleted
        // from the Moodle database table (which can happen in earlier versions of Moodle).
        $userids = array_filter($userids, function($userid) use ($members) {
            return isset($members[$userid]);
        });

        // Return member information in the same order as the userids originally provided.
        $members = array_replace(array_flip($userids), $members);

        return $members;
    }

    public static function is_online($lastaccess) {
        global $CFG;

        // Variable to check if we consider this user online or not.
        $timetoshowusers = 300; // Seconds default.
        if (isset($CFG->block_online_users_timetosee)) {
            $timetoshowusers = $CFG->block_online_users_timetosee * 60;
        }
        $time = time() - $timetoshowusers;

        return $lastaccess >= $time;
    }

    public static function show_online_status($user) {
        global $CFG;

        require_once($CFG->dirroot . '/user/lib.php');

        if ($lastaccess = user_get_user_details($user, null, array('lastaccess'))) {
            if (isset($lastaccess['lastaccess'])) {
                return true;
            }
        }

        return false;
    }

    public static function get_conversation_messages_returns(){
        return new external_single_structure(
            array(
                'id' => new external_value(PARAM_INT, 'The conversation id'),
                'members' => new external_multiple_structure(
                    self::get_conversation_member_structure()
                ),
                'messages' => new external_multiple_structure(
                    self::get_conversation_message_structure()
                ),
            )
        );
    }

    private static function get_conversation_member_structure() {
        $result = [
            'id' => new external_value(PARAM_INT, 'The user id'),
            'fullname' => new external_value(PARAM_NOTAGS, 'The user\'s name'),
            'profileurl' => new external_value(PARAM_URL, 'The link to the user\'s profile page'),
            'profileimageurl' => new external_value(PARAM_URL, 'User picture URL'),
            'profileimageurlsmall' => new external_value(PARAM_URL, 'Small user picture URL'),
            'isonline' => new external_value(PARAM_BOOL, 'The user\'s online status'),
            'showonlinestatus' => new external_value(PARAM_BOOL, 'Show the user\'s online status?'),
            'isblocked' => new external_value(PARAM_BOOL, 'If the user has been blocked'),
            'iscontact' => new external_value(PARAM_BOOL, 'Is the user a contact?'),
            'isdeleted' => new external_value(PARAM_BOOL, 'Is the user deleted?'),
            'canmessageevenifblocked' => new external_value(PARAM_BOOL,
                'If the user can still message even if they get blocked'),
            'canmessage' => new external_value(PARAM_BOOL, 'If the user can be messaged'),
            'requirescontact' => new external_value(PARAM_BOOL, 'If the user requires to be contacts'),
        ];

        $result['contactrequests'] = new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, 'The id of the contact request'),
                    'userid' => new external_value(PARAM_INT, 'The id of the user who created the contact request'),
                    'requesteduserid' => new external_value(PARAM_INT, 'The id of the user confirming the request'),
                    'timecreated' => new external_value(PARAM_INT, 'The timecreated timestamp for the contact request'),
                ]
            ), 'The contact requests', VALUE_OPTIONAL
        );

        $result['conversations'] = new external_multiple_structure(new external_single_structure(
            array(
                'id' => new external_value(PARAM_INT, 'Conversations id'),
                'type' => new external_value(PARAM_INT, 'Conversation type: private or public'),
                'name' => new external_value(PARAM_RAW, 'Multilang compatible conversation name'. VALUE_OPTIONAL),
                'timecreated' => new external_value(PARAM_INT, 'The timecreated timestamp for the conversation'),
            ), 'information about conversation', VALUE_OPTIONAL),
            'Conversations between users', VALUE_OPTIONAL
        );

        return new external_single_structure(
            $result
        );
    }

    private static function get_conversation_message_structure() {
        return new external_single_structure(
            array(
                'id' => new external_value(PARAM_INT, 'The id of the message'),
                'useridfrom' => new external_value(PARAM_INT, 'The id of the user who sent the message'),
                'text' => new external_value(PARAM_TEXT, 'The text of the message'),
                'timecreated' => new external_value(PARAM_INT, 'The timecreated timestamp for the message'),
            )
        );
    }
}
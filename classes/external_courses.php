<?php


namespace local_additional_web_service;


use completion_info;
use context_course;
use core_course_list_element;
use external_api;
use external_files;
use external_format_value;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use moodle_url;

defined('MOODLE_INTERNAL') || die;


/**
 *
 */
class external_courses extends external_api
{
    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     */
    public static function get_users_courses_parameters()
    {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'user id'),
                'returnusercount' => new external_value(PARAM_BOOL,
                    'Include count of enrolled users for each course? This can add several seconds to the response time'
                    . ' if a user is on several large courses, so set this to false if the value will not be used to'
                    . ' improve performance.',
                    VALUE_DEFAULT, false),
                'onlyactive' => new external_value(PARAM_BOOL,
                    'Does not include courses with hidden visibility where the user is enrolled',
                    VALUE_DEFAULT, false),
            )
        );
    }

    /**
     * Get list of courses user is enrolled in (only active enrolments are returned).
     * Please note the current user must be able to access the course, otherwise the course is not included.
     *
     * @param int $userid
     * @param bool $returnusercount
     * @return array of courses
     */
    public static function get_users_courses($userid, $returnusercount = false, $onlyactive = false)
    {
        global $CFG, $USER, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/completionlib.php');

        // Do basic automatic PARAM checks on incoming data, using params description
        // If any problems are found then exceptions are thrown with helpful error messages
        $params = self::validate_parameters(self::get_users_courses_parameters(),
            ['userid' => $userid, 'returnusercount' => $returnusercount]);
        $userid = $params['userid'];
        $returnusercount = $params['returnusercount'];

        $courses = enrol_get_users_courses($userid, $onlyactive, '*');

        $result = array();

        // Get user data including last access to courses.
        $user = get_complete_user_data('id', $userid);
        $sameuser = $USER->id == $userid;

        // Retrieve favourited courses (starred).
        $favouritecourseids = array();
        if ($sameuser) {
            $ufservice = \core_favourites\service_factory::get_service_for_user_context(\context_user::instance($userid));
            $favourites = $ufservice->find_favourites_by_type('core_course', 'courses');

            if ($favourites) {
                $favouritecourseids = array_flip(array_map(
                    function ($favourite) {
                        return $favourite->itemid;
                    }, $favourites));
            }
        }

        foreach ($courses as $course) {
            $context = context_course::instance($course->id, IGNORE_MISSING);
            try {
                self::validate_context($context);
            } catch (\Exception $e) {
                // current user can not access this course, sorry we can not disclose who is enrolled in this course!
                continue;
            }

            // If viewing details of another user, then we must be able to view participants as well as profile of that user.
            if (!$sameuser && (!course_can_view_participants($context) || !user_can_view_profile($user, $course))) {
                continue;
            }

            if ($returnusercount) {
                list($enrolledsqlselect, $enrolledparams) = get_enrolled_sql($context);
                $enrolledsql = "SELECT COUNT('x') FROM ($enrolledsqlselect) enrolleduserids";
                $enrolledusercount = $DB->count_records_sql($enrolledsql, $enrolledparams);
            }

            $displayname = external_format_string(get_course_display_name_for_list($course), $context->id);
            list($course->summary, $course->summaryformat) =
                external_format_text($course->summary, $course->summaryformat, $context->id, 'course', 'summary', null);
            $course->fullname = external_format_string($course->fullname, $context->id);
            $course->shortname = external_format_string($course->shortname, $context->id);

            $progress = null;
            $completed = null;
            $completionhascriteria = false;
            $completionusertracked = false;

            // Return only private information if the user should be able to see it.
            if ($sameuser || completion_can_view_data($userid, $course)) {
                if ($course->enablecompletion) {
                    $completion = new completion_info($course);
                    $completed = $completion->is_course_complete($userid);
                    $completionhascriteria = $completion->has_criteria();
                    $completionusertracked = $completion->is_tracked_user($userid);
                    $progress = \core_completion\progress::get_course_progress_percentage($course, $userid);
                }
            }

            $lastaccess = null;
            // Check if last access is a hidden field.
            $hiddenfields = array_flip(explode(',', $CFG->hiddenuserfields));
            $canviewlastaccess = $sameuser || !isset($hiddenfields['lastaccess']);
            if (!$canviewlastaccess) {
                $canviewlastaccess = has_capability('moodle/course:viewhiddenuserfields', $context);
            }

            if ($canviewlastaccess && isset($user->lastcourseaccess[$course->id])) {
                $lastaccess = $user->lastcourseaccess[$course->id];
            }

            $hidden = false;
            if ($sameuser) {
                $hidden = boolval(get_user_preferences('block_myoverview_hidden_course_' . $course->id, 0));
            }

            // Retrieve course overview used files.
            $courselist = new core_course_list_element($course);
            $overviewfiles = array();
            foreach ($courselist->get_course_overviewfiles() as $file) {
                $fileurl = moodle_url::make_webservice_pluginfile_url($file->get_contextid(), $file->get_component(),
                    $file->get_filearea(), null, $file->get_filepath(),
                    $file->get_filename())->out(false);
                $overviewfiles[] = array(
                    'filename' => $file->get_filename(),
                    'fileurl' => $fileurl,
                    'filesize' => $file->get_filesize(),
                    'filepath' => $file->get_filepath(),
                    'mimetype' => $file->get_mimetype(),
                    'timemodified' => $file->get_timemodified(),
                );
            }

            $courseresult = [
                'id' => $course->id,
                'shortname' => $course->shortname,
                'fullname' => $course->fullname,
                'displayname' => $displayname,
                'idnumber' => $course->idnumber,
                'visible' => $course->visible,
                'summary' => $course->summary,
                'summaryformat' => $course->summaryformat,
                'format' => $course->format,
                'showgrades' => $course->showgrades,
                'lang' => clean_param($course->lang, PARAM_LANG),
                'enablecompletion' => $course->enablecompletion,
                'completionhascriteria' => $completionhascriteria,
                'completionusertracked' => $completionusertracked,
                'category' => $course->category,
                'progress' => $progress,
                'completed' => $completed,
                'startdate' => $course->startdate,
                'enddate' => $course->enddate,
                'marker' => $course->marker,
                'lastaccess' => $lastaccess,
                'isfavourite' => isset($favouritecourseids[$course->id]),
                'hidden' => $hidden,
                'overviewfiles' => $overviewfiles,
                'showactivitydates' => $course->showactivitydates,
                'showcompletionconditions' => $course->showcompletionconditions,
                'timemodified' => $course->timemodified,
            ];
            if ($returnusercount) {
                $courseresult['enrolledusercount'] = $enrolledusercount;
            }
            $result[] = $courseresult;
        }

        return $result;
    }

    /**
     * Returns description of method result value
     *
     * @return external_multiple_structure
     */
    public static function get_users_courses_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'id of course'),
                    'shortname' => new external_value(PARAM_RAW, 'short name of course'),
                    'fullname' => new external_value(PARAM_RAW, 'long name of course'),
                    'displayname' => new external_value(PARAM_RAW, 'course display name for lists.', VALUE_OPTIONAL),
                    'enrolledusercount' => new external_value(PARAM_INT, 'Number of enrolled users in this course',
                        VALUE_OPTIONAL),
                    'idnumber' => new external_value(PARAM_RAW, 'id number of course'),
                    'visible' => new external_value(PARAM_INT, '1 means visible, 0 means not yet visible course'),
                    'summary' => new external_value(PARAM_RAW, 'summary', VALUE_OPTIONAL),
                    'summaryformat' => new external_format_value('summary', VALUE_OPTIONAL),
                    'format' => new external_value(PARAM_PLUGIN, 'course format: weeks, topics, social, site', VALUE_OPTIONAL),
                    'showgrades' => new external_value(PARAM_BOOL, 'true if grades are shown, otherwise false', VALUE_OPTIONAL),
                    'lang' => new external_value(PARAM_LANG, 'forced course language', VALUE_OPTIONAL),
                    'enablecompletion' => new external_value(PARAM_BOOL, 'true if completion is enabled, otherwise false',
                        VALUE_OPTIONAL),
                    'completionhascriteria' => new external_value(PARAM_BOOL, 'If completion criteria is set.', VALUE_OPTIONAL),
                    'completionusertracked' => new external_value(PARAM_BOOL, 'If the user is completion tracked.', VALUE_OPTIONAL),
                    'category' => new external_value(PARAM_INT, 'course category id', VALUE_OPTIONAL),
                    'progress' => new external_value(PARAM_FLOAT, 'Progress percentage', VALUE_OPTIONAL),
                    'completed' => new external_value(PARAM_BOOL, 'Whether the course is completed.', VALUE_OPTIONAL),
                    'startdate' => new external_value(PARAM_INT, 'Timestamp when the course start', VALUE_OPTIONAL),
                    'enddate' => new external_value(PARAM_INT, 'Timestamp when the course end', VALUE_OPTIONAL),
                    'marker' => new external_value(PARAM_INT, 'Course section marker.', VALUE_OPTIONAL),
                    'lastaccess' => new external_value(PARAM_INT, 'Last access to the course (timestamp).', VALUE_OPTIONAL),
                    'isfavourite' => new external_value(PARAM_BOOL, 'If the user marked this course a favourite.', VALUE_OPTIONAL),
                    'hidden' => new external_value(PARAM_BOOL, 'If the user hide the course from the dashboard.', VALUE_OPTIONAL),
                    'overviewfiles' => new external_files('Overview files attached to this course.', VALUE_OPTIONAL),
                    'showactivitydates' => new external_value(PARAM_BOOL, 'Whether the activity dates are shown or not'),
                    'showcompletionconditions' => new external_value(PARAM_BOOL, 'Whether the activity completion conditions are shown or not'),
                    'timemodified' => new external_value(PARAM_INT, 'Last time course settings were updated (timestamp).',
                        VALUE_OPTIONAL),
                )
            )
        );
    }


}
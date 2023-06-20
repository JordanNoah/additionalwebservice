<?php


namespace local_additional_web_service;

use assign;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use grading_manager;
use moodle_exception;

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->dirroot/mod/assign/locallib.php");


class external_assign_grades extends \mod_assign\external\external_api
{

    /**
     * Describes the parameters for save_grade
     * @return external_function_parameters
     * @since  Moodle 2.6
     */
    public static function save_grade_parameters()
    {
        global $CFG;
        require_once("$CFG->dirroot/grade/grading/lib.php");
        $instance = new assign(null, null, null);
        $pluginfeedbackparams = array();

        foreach ($instance->get_feedback_plugins() as $plugin) {
            if ($plugin->is_visible()) {
                $pluginparams = $plugin->get_external_parameters();
                if (!empty($pluginparams)) {
                    $pluginfeedbackparams = array_merge($pluginfeedbackparams, $pluginparams);
                }
            }
        }

        $advancedgradingdata = array();
        $methods = array_keys(grading_manager::available_methods(false));
        foreach ($methods as $method) {
            require_once($CFG->dirroot . '/grade/grading/form/' . $method . '/lib.php');
            $details = call_user_func('gradingform_' . $method . '_controller::get_external_instance_filling_details');
            if (!empty($details)) {
                $items = array();
                foreach ($details as $key => $value) {
                    $value->required = VALUE_OPTIONAL;
                    unset($value->content->keys['id']);
                    $items[$key] = new external_multiple_structure (new external_single_structure(
                        array(
                            'criterionid' => new external_value(PARAM_INT, 'criterion id'),
                            'fillings' => $value
                        )
                    ));
                }
                $advancedgradingdata[$method] = new external_single_structure($items, 'items', VALUE_OPTIONAL);
            }
        }

        return new external_function_parameters(
            array(
                'assignmentid' => new external_value(PARAM_INT, 'The assignment id to operate on'),
                'userid' => new external_value(PARAM_INT, 'The student id to operate on'),
                'grade' => new external_value(PARAM_TEXT, 'The new grade for this user. Ignored if advanced grading used'),
                'attemptnumber' => new external_value(PARAM_INT, 'The attempt number (-1 means latest attempt)'),
                'addattempt' => new external_value(PARAM_BOOL, 'Allow another attempt if the attempt reopen method is manual'),
                'workflowstate' => new external_value(PARAM_ALPHA, 'The next marking workflow state'),
                'applytoall' => new external_value(PARAM_BOOL, 'If true, this grade will be applied ' .
                    'to all members ' .
                    'of the group (for group assignments).'),
                'plugindata' => new external_single_structure($pluginfeedbackparams, 'plugin data', VALUE_DEFAULT, array()),
                'advancedgradingdata' => new external_single_structure($advancedgradingdata, 'advanced grading data',
                    VALUE_DEFAULT, array())
            )
        );
    }

    /**
     * Save a student grade for a single assignment.
     *
     * @param int $assignmentid The id of the assignment
     * @param int $userid The id of the user
     * @param float $grade The grade (ignored if the assignment uses advanced grading)
     * @param int $attemptnumber The attempt number
     * @param bool $addattempt Allow another attempt
     * @param string $workflowstate New workflow state
     * @param bool $applytoall Apply the grade to all members of the group
     * @param array $plugindata Custom data used by plugins
     * @param array $advancedgradingdata Advanced grading data
     * @return null
     * @since Moodle 2.6
     */
    public static function save_grade($assignmentid,
                                      $userid,
                                      $grade,
                                      $attemptnumber,
                                      $addattempt,
                                      $workflowstate,
                                      $applytoall,
                                      $plugindata = array(),
                                      $advancedgradingdata = array())
    {
        global $CFG, $USER;

        $params = self::validate_parameters(self::save_grade_parameters(),
            array('assignmentid' => $assignmentid,
                'userid' => $userid,
                'grade' => $grade,
                'attemptnumber' => $attemptnumber,
                'workflowstate' => $workflowstate,
                'addattempt' => $addattempt,
                'applytoall' => $applytoall,
                'plugindata' => $plugindata,
                'advancedgradingdata' => $advancedgradingdata));

        /**
         * @var assign $assignment
         */
        list($assignment, $course, $cm, $context) = self::validate_assign($params['assignmentid']);

        $gradedata = (object)$params['plugindata'];
        $gradedata->addattempt = $params['addattempt'];
        $gradedata->attemptnumber = $params['attemptnumber'];
        $gradedata->workflowstate = $params['workflowstate'];
        $gradedata->applytoall = $params['applytoall'];
        $gradedata->grade = unformat_float($params['grade']);

        static::check_grades($gradedata->grade, $userid, $assignmentid);

        if (!empty($params['advancedgradingdata'])) {
            $advancedgrading = array();
            $criteria = reset($params['advancedgradingdata']);
            foreach ($criteria as $key => $criterion) {
                $details = array();
                foreach ($criterion as $value) {
                    foreach ($value['fillings'] as $filling) {
                        $details[$value['criterionid']] = $filling;
                    }
                }
                $advancedgrading[$key] = $details;
            }
            $gradedata->advancedgrading = $advancedgrading;
        }
        $assignment->save_grade($params['userid'], $gradedata);

        return null;
    }

    /**
     * Describes the return value for save_grade
     *
     * @return external_single_structure
     * @since Moodle 2.6
     */
    public static function save_grade_returns()
    {
        return null;
    }

    private static function check_grades($grade, $userid, $assignmentid)
    {
        $grade = grade_floatval($grade);
        $maxgrade = static::get_max_grade($userid, $assignmentid);

        if ($grade > $maxgrade) {
            // The grade is greater than the maximum possible value.
            throw new moodle_exception('error:notinrange', 'core_grading', '', (object)[
                'maxgrade' => $maxgrade,
                'grade' => $grade,
            ]);
        } else if ($grade < 0) {
            // Negative grades are not supported.
            throw new moodle_exception('error:notinrange', 'core_grading', '', (object)[
                'maxgrade' => $maxgrade,
                'grade' => $grade,
            ]);
        }

    }

    private static function get_max_grade($userid, $assignmentid)
    {
        global $DB;

        $sql = "SELECT m.grademax
                FROM {grade_items} m 
                where m.itemmodule = 'assign'
                and m.itemtype = 'mod'
                and m.iteminstance = :assignmentid";

        $grade = $DB->get_record_sql($sql, [
            'userid' => $userid,
            'assignmentid' => $assignmentid,
        ]);

        return grade_floatval(unformat_float($grade->grademax));
    }

}
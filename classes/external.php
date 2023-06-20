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


use external_api;
use external_format_value;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use invalid_parameter_exception;


/**
 * Class external
 * @package local_group
 */
class external extends external_api
{

    /**
     * Returns description of get_course_module_idnumber() parameters
     * @return external_function_parameters
     */
    public static function get_groups_by_idnumber_parameters()
    {
        // The external_function_parameters constructor expects an array of external_description.
        return new external_function_parameters(
        // a external_description can be: external_value, external_single_structure or external_multiple structure
            [
                'id_number' => new external_value(PARAM_TEXT, 'references the id number the group'),
                'course_id' => new external_value(PARAM_INT, 'references the id course in table course',VALUE_DEFAULT)
            ]
        );
    }

    /**
     * Returns description of get_course_module_idnumber() result value
     * @return external_multiple_structure
     */
    public static function get_groups_by_idnumber_returns()
    {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'id' => new external_value(PARAM_INT, 'group record id'),
                    'courseid' => new external_value(PARAM_INT, 'id of course'),
                    'name' => new external_value(PARAM_TEXT, 'multilang compatible name, course unique'),
                    'description' => new external_value(PARAM_RAW, 'group description text'),
                    'descriptionformat' => new external_format_value('description'),
                    'enrolmentkey' => new external_value(PARAM_RAW, 'group enrol secret phrase'),
                    'idnumber' => new external_value(PARAM_RAW, 'id number')
                ]
            ));
    }

    /**
     * @param $id
     * @return false|mixed
     * @throws \dml_exception
     * @throws invalid_parameter_exception
     */
    public static function get_groups_by_idnumber($idNumber, $courseId=null)
    {
        $params = self::validate_parameters(self::get_groups_by_idnumber_parameters(), ['id_number' => $idNumber, 'course_id' => $courseId]);
        $context = \context_system::instance();
        $gs = static::get_groups_by_id_number( $params['id_number'],$params['course_id']??null);
        $groups = array();
        foreach ($gs as $group) {
            list($group->description, $group->descriptionformat) =
                external_format_text($group->description, $group->descriptionformat,
                    $context->id, 'group', 'description', $group->id);
            $groups[] = (array)$group;
        }
        return $groups;
    }

    /**
     * return information of group by id number and course or only by id number
     *
     * @param string $idNumber
     * @param int $courseId default null
     * @return array
     * @throws \dml_exception
     */
    private static function get_groups_by_id_number($idNumber,$courseId=null)
    {
        global $DB;

        $fields = 'g.id, g.courseid, g.name, g.idnumber, g.description, g.descriptionformat, g.enrolmentkey';
        $whereCourse = $courseId == null ? '': 'and g.courseid = ?';
        $params = $courseId == null ? [$idNumber] : [$idNumber,$courseId ];
        return $DB->get_records_sql("
            SELECT $fields
              FROM {groups} g
             WHERE  g.idnumber = ?
                 $whereCourse
          ORDER BY g.name ASC",$params);
    }

}

<?php

namespace local_additional_web_service\exceptions;


use moodle_exception;

/**
 * Exception indicating malformed user not found problem.
 * This exception is not supposed to be thrown when processing
 * user submitted data in forms. It is more suitable
 * for WS and other low level stuff.
 *
 * @package    local_additional_web_service
 * @copyright  2022 Michael Alejandro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class SentNotificationError extends moodle_exception
{
    /**
     * Constructor
     * @param string $debuginfo some detailed information
     */
    function __construct($debuginfo = null)
    {
        parent::__construct('sentnotificationerror', 'debug', '', null, $debuginfo);
    }
}

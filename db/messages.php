<?php

/**
 * External functions and service definitions.
 *
 * @package    local_additional_web_service
 * @copyright  2022 Michael Alejandro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

$messageproviders = array(
    'notificationapi' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED
        ],
    ],
);

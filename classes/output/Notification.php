<?php

namespace local_additional_web_service\output;


/**
 * Output buttons.
 */
class Notification
{
    /**
     * Output notification buttons to page.
     *
     * @param object $parameters
     * @return string
     * @throws \coding_exception
     */
    public static function output_template($parameters, $user)
    {
        global $OUTPUT, $CFG;

        require_once("{$CFG->dirroot}/local/additional_web_service/functions.php");

        $message = $parameters->message;


        $payload = [
            "greeting" => getString('local_additional_web_service', 'greeting', $user->lang, $user->firstname),
            "message" => getString($message['component'], $message['lang_key'], $user->lang,json_decode($message['additional_content'])),
        ];

        return $OUTPUT->render_from_template('local_additional_web_service/notification', $payload);
    }


}

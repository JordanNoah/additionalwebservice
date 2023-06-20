<?php

function getString($component, $langKey, $language, $additionalData=null)
{

    return get_string_manager()->get_string($langKey, $component, $additionalData, $language);
}

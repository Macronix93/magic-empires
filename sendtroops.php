<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_SAWMILL);

/*
 * HTML Content Part
 */
$view .= "Truppen verschicken:";


/*
 * HTML Section
 */
$title = "Truppen schicken";
$header = "Truppen schicken";
$script_files = [];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include('layout/base.php');
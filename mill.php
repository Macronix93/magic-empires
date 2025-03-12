<?php
require_once("includes/core.php");

check_user_login($user);

$current_kingdom = $user->get_current_kingdom();
$building = fetch_kingdom_building($current_kingdom, BuildingTypes::BUILDING_MILL);
$building_name = $building->get_building_name();

if (!$building->is_built()) {
    change_location("towncenter.php");
    exit;
}

$kingdom = new Kingdoms($db_instance);
$kingdom->get_kingdom_info($current_kingdom);

/*
 * HTML Content Part
 */
$view .= "<b>Nahrungsertrag pro Stunde:</b> " . fnum($kingdom->get_kingdom_food_per_hour());


/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = [];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include('layout/base.php');
<?php
require_once("includes/core.php");

if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$current_kingdom = $user->get_current_kingdom();
$building = fetch_kingdom_building($current_kingdom, BuildingTypes::BUILDING_SAWMILL);
$building_name = $building->get_building_name();
$kingdom = new Kingdoms($db_instance);
$kingdom->get_kingdom_info($current_kingdom);

if (!$building->is_built()) {
    change_location("towncenter.php");
    exit;
}

/*
 * HTML Content Part
 */
$view .= "<b>Holzertrag pro Stunde:</b> " . fnum($kingdom->get_kingdom_wood_per_hour());


/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = [];

if (!empty($error)) {
    $view = "<div class='info-box'>" . $error . "</div>" . $view;
}

include('layout/base.php');
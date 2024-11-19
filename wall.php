<?php
require_once("includes/core.php");

if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$current_kingdom = $user->get_current_kingdom();
$building = fetch_kingdom_building($current_kingdom, BuildingTypes::BUILDING_WALL);
$building_name = $building->get_building_name();

if (!$building->is_built()) {
    change_location("towncenter.php");
    exit;
}

$level = $building->get_building_level() * DEFAULT_WALL_HP;

/*
 * HTML Content Part
 */
$view .= "<b>Verteidigungswert:</b> " . fnum($level);

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
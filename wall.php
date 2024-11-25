<?php
require_once("includes/core.php");

$result = checkUserLoginAndKingdom($user, $db_instance, BuildingTypes::BUILDING_WALL);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

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
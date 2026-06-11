<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_EMBASSY);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

/*
 * HTML Content Part
 */
$view .= "<h3>Diplomatie & Gildenwesen</h3>";
$view .= "<p>Willkommen in der Botschaft von <b>" . e($kingdom->get_kingdom_name()) . "</b>.</p>";
$view .= "
<div class='info-box' style='justify-content: center; margin-top: 30px;'>
    <span>Hier kannst du bald Allianzen schmieden und deine Gilde verwalten.</span>
</div>";

/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = [];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
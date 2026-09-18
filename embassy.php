<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, BuildingTypes::BUILDING_EMBASSY);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

/*
 * HTML Content Part
 */
$view .= "<h3 style='margin: 0;'>Gilden-Zentrum & Diplomatie</h3>";
$view .= "<p>Willkommen in der Botschaft von <b>" . e($kingdom->get_kingdom_name()) . "</b>.</p><hr>";
$view .= "
<div class='info-box' style='justify-content: center; flex-direction: column; margin: 0;'>
    <span>Die Botschaft ist das diplomatische Herz deines Reiches.</span>
    <ul style='text-align: left; display: inline-block; margin-top: 10px; max-width: 550px;'>
        <li>Ermöglicht die Gründung einer <a href='guild.php'>Gilde</a>.</li>
        <li>Die Gilde verwaltet Bündnisse und Mitglieder.</li>
        <li>Die Gilde besitzt einen Zugang zu einer Gilden-Schatzkammer und Gilden-Forschungen.</li>
    </ul>
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
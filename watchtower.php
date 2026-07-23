<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_WATCHTOWER);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

$level = $building->get_building_level();
$current_visibility = $level * WATCHTOWER_DETECTION_PER_LEVEL;
$next_visibility = ($level + 1) * WATCHTOWER_DETECTION_PER_LEVEL;

/*
 * HTML Content Part
 */
$view .= "<div style='margin: auto; width: 350px;'>
        <div class='split-content'>
            <div><b>Aktuelle Sichtweite:</b></div>
            <div class='passed'>" . convert_sec_to_str($current_visibility) . "</div>
        </div>";

if ($level < MAX_BUILDING_LEVEL) {
    $view .= "<div class='split-content'>
                <div><b>Nächste Stufe:</b></div>
                <div>" . convert_sec_to_str($next_visibility) . "</div>
            </div>";
}

$view .= "<p style='font-size: 14px; opacity: 0.8; text-align: center;'>
            <i>Der Wachturm ermöglicht es, herannahende Feinde frühzeitig zu entdecken. 
            Je höher der Turm, desto eher wird die Stadtwache Alarm schlagen.</i>
        </p>
</div>";

/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $level . ")";
$script_files = [];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
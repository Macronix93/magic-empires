<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, BuildingTypes::BUILDING_STORAGE);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

$level = $building->get_building_level();
$secure_percent = $level * STORAGE_SECURE_PERCENT_STEP;
$display_percent = $secure_percent * 100;
$example_secure_units = floor($kingdom->get_kingdom_max_food() * $secure_percent);

$next_level_text = "";
if ($level < MAX_BUILDING_LEVEL) {
    $next_level = $level + 1;
    $next_max = (int)round(STORAGE_STARTING_VALUE * pow(STORAGE_INC_FACTOR, $next_level - 1));
    $next_display_percent = fdec($next_level * STORAGE_SECURE_PERCENT_STEP * 100);

    $next_level_text = "
        <div class='split-content' style='margin: 10px auto; width: fit-content; gap: 5px;'>
            <div><b>Nächste Stufe:</b></div>
            <div class='passed'>" . fnum($next_max) . " Kapazität ($next_display_percent% geschützt)</div>
        </div>";
}


/*
 * HTML Content Part
 */
$view .= "<div class='storage-listing' style='margin: auto;'>
        <div class='split-content' style='gap: 5px;'>
            <div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . fnum($kingdom->get_kingdom_food()) . "</div>
            <div>von " . fnum($kingdom->get_kingdom_max_food()) . "</div>
        </div>
        <div class='split-content' style='gap: 5px;'>
            <div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . fnum($kingdom->get_kingdom_wood()) . "</div>
            <div>von " . fnum($kingdom->get_kingdom_max_wood()) . "</div>
        </div>
        <div class='split-content' style='gap: 5px;'>
            <div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . fnum($kingdom->get_kingdom_stone()) . "</div>
            <div>von " . fnum($kingdom->get_kingdom_max_stone()) . "</div>
        </div>
        <div class='split-content' style='gap: 5px;'>
            <div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . fnum($kingdom->get_kingdom_gold()) . "</div>
            <div>von " . fnum($kingdom->get_kingdom_max_gold()) . "</div>
        </div>
</div>";
$view .= "
    <div class='info-box event-passed' style='margin-top: 20px; flex-direction: column; padding: 15px; max-width: 550px;'>
        <span style='font-weight: bold; font-size: 22px;'>🛡️ Sichere Ressourcen</span>
        <span style='font-size: 0.9em; opacity: 0.9; margin-top: 5px;'>
            Durch die Bauweise deines Lagers sind <b>" . fdec($display_percent) . " %</b> deiner maximalen Lagerkapazität 
            vor Diebstahl geschützt.
        </span>
        <span style='color: var(--link-color); font-weight: bold; margin-top: 8px;'>
            Aktuell gesichert: " . fnum($example_secure_units) . " Einheiten pro Ressource
        </span>
    </div>
";
$view .= $next_level_text;

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
<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_ESTATE);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];


$level = $building->get_building_level();
$next_level_text = "";

if ($level < MAX_BUILDING_LEVEL) {
    $next_level = $level + 1;
    $next_increase = (int)round(ESTATE_VILLAGER_BASE_INC + pow($next_level, 1.25) * ESTATE_VILLAGER_BASE_STEP);

    $next_level_text = "
        <div class='split-content'>
            <div><b>Nächste Stufe:</b></div>
            <div class='passed'>+" . fnum($next_increase) . " Bewohner</div>
        </div>";
}

/*
 * HTML Content Part
 */
$view .= "<div style='margin: auto; width: 350px;'>
        <div class='split-content'>
            <div><b>Dorfbewohner Zuwachs:</b></div>
            <div>" . fnum($kingdom->get_kingdom_villager_per_hour()) . " / Std.</div>
        </div>
        <div class='split-content'>
            <div><b>Wohnraum Kapazität:</b></div>
            <div>" . fnum($kingdom->get_kingdom_max_villager()) . " Bewohner</div>
        </div>
        $next_level_text
        <p style='font-size: 14px; opacity: 0.8; margin-top: 20px;'>
            <i>Das Anwesen bietet deinen Untertanen Raum zum Leben. 
            Alle " . ESTATE_VILLAGER_GROWTH_STEP . " Stufen erhöht sich die Geburtenrate deiner Bevölkerung.</i>
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
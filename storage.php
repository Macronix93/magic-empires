<?php
require_once("includes/core.php");

if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$current_kingdom = $user->get_current_kingdom();
$building = fetch_kingdom_building($current_kingdom, BuildingTypes::BUILDING_STORAGE);
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
$view .= "<div style='margin: auto; width: 200px;'>
        <div class='split-content'>
            <div>
                <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung' title='Nahrung'/>
                " . fnum($kingdom->get_kingdom_food()) . "
            </div>
            <div>von " . fnum($kingdom->get_kingdom_max_food()) . "</div>
        </div>
        <div class='split-content'>
            <div>
                <img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz' title='Holz'/>
                " . fnum($kingdom->get_kingdom_wood()) . "
            </div>
            <div>von " . fnum($kingdom->get_kingdom_max_wood()) . "</div>
        </div>
        <div class='split-content'>
            <div>
                <img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein' title='Stein'/>
                " . fnum($kingdom->get_kingdom_stone()) . "
            </div>
            <div>von " . fnum($kingdom->get_kingdom_max_stone()) . "</div>
        </div>
        <div class='split-content'>
            <div>
                <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold' title='Gold'/>
                " . fnum($kingdom->get_kingdom_gold()) . "
            </div>
            <div>von " . fnum($kingdom->get_kingdom_max_gold()) . "</div>
        </div>
</div>";


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
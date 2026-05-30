<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_STORAGE);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

/*
 * HTML Content Part
 */
$view .= "<div style='margin: auto; width: 200px;'>
        <div class='split-content'>
            <div>
                " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . "
                " . fnum($kingdom->get_kingdom_food()) . "
            </div>
            <div>von " . fnum($kingdom->get_kingdom_max_food()) . "</div>
        </div>
        <div class='split-content'>
            <div>
                " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . "
                " . fnum($kingdom->get_kingdom_wood()) . "
            </div>
            <div>von " . fnum($kingdom->get_kingdom_max_wood()) . "</div>
        </div>
        <div class='split-content'>
            <div>
                " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . "
                " . fnum($kingdom->get_kingdom_stone()) . "
            </div>
            <div>von " . fnum($kingdom->get_kingdom_max_stone()) . "</div>
        </div>
        <div class='split-content'>
            <div>
                " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . "
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
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
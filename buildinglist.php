<?php
require_once("includes/core.php");

if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$dependency_text = "";

// Fetch all buildings and their dependencies
$buildings = (new Kingdoms($db_instance))->fetch_all_kingdom_buildings();

$view .= '<table class="table">
    <tr>
        <td class="td-center td-gradient" colspan="2">
            <b>Gebäude</b></td>
        <td class="td-center td-gradient">
            <b>Voraussetzungen</b></td>
    </tr>';

for ($i = 0; $i < count($buildings); $i++) {
    $current_building_level = $buildings[$i]->get_building_level();
    $building_dependencies = $buildings[$i]->get_building_dependencies();
    $building_dependencies_count = count($building_dependencies);

    if ($building_dependencies_count != 0) {
        foreach ($building_dependencies as $dependency) {
            $level_of_dependency_building = $buildings[$dependency["dependencyid"]]->get_building_level();

            if ($dependency["dependencylevel"] > $level_of_dependency_building) {
                $dependency_text .= " <span class='error'>" . $buildings[$dependency["dependencyid"]]->get_building_name() . " (" . $dependency["dependencylevel"] . ")</span>";
            } else {
                $dependency_text .= " <span class='passed'>" . $buildings[$dependency["dependencyid"]]->get_building_name() . " (" . $dependency["dependencylevel"] . ")</span>";
            }
        }
    }

    $view .= "<tr><td class='td-center' style='width: 5%;'>" . $buildings[$i]->get_building_icon() . "</td>
                                            <td style='width: 30%;'><a href='javascript:void(0);' onclick='openPopup(\"buildinginfo.php?bid=" . $i . "\", undefined, 580);'>" . $buildings[$i]->get_building_name() . " ($current_building_level)</a></td>
                                            <td>" . (!empty($dependency_text) ? $dependency_text : "") . "</td></tr>";

    $dependency_text = "";
}

$view .= '</table>';

/*
 * HTML Section
 */
$title = "Gebäudeliste";
$header = "Gebäudeliste";
$script_files = ["userinfo"];

include('layout/base.php');
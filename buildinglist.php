<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$view = "";
$dependency_text = "";

// Fetch all buildings and their dependencies
$buildings = fetch_all_buildings($user->get_current_kingdom());

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

    if (count($building_dependencies) != 0) {
        foreach ($building_dependencies as $dependency) {
            $level_of_dependency_building = $buildings[$dependency["dependencyid"]]->get_building_level();

            if ($dependency["dependencylevel"] > $level_of_dependency_building) {
                $dependency_text .= "<span class='error'>- " . $buildings[$dependency["dependencyid"]]->get_building_name() . " (" . $dependency["dependencylevel"] . ")</span><br>";
            } else {
                $dependency_text .= "<span class='passed'>- " . $buildings[$dependency["dependencyid"]]->get_building_name() . " (" . $dependency["dependencylevel"] . ")</span><br>";
            }
        }
    }

    $view .= "<tr><td class='td-center' style='width: 10%;'>" . $buildings[$i]->get_building_icon() . "</td>
                                            <td style='width: 40%;'><b>" . $buildings[$i]->get_building_name() . " ($current_building_level)</b></td>
                                            <td>" . (!empty($dependency_text) ? $dependency_text : "-") . "</td></tr>";

    $dependency_text = "";
}

$view .= '</table>';

/*
 * HTML Section
 */
$title = "Gebäudeliste";
$header = "Gebäudeliste";

include('layout/base.php');
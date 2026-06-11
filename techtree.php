<?php
require_once("includes/core.php");

if (!($user->is_logged_in())) {
    change_location("index.php");
    exit;
}

$dependency_text = "";

// Fetch all buildings and their dependencies
$kingdom = new Kingdom($db_instance, $user->get_current_kingdom());
$buildings = $kingdom->fetch_all_kingdom_buildings();
$techs = $kingdom->fetch_all_kingdom_techs();
$tc_level = $buildings[BuildingTypes::BUILDING_TOWNCENTER]->get_building_level();

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
    } else {
        continue;
    }

    $view .= "<tr><td class='td-center' style='width: 5%;'>" . $buildings[$i]->get_building_icon() . "</td>
                <td style='width: 30%;'>
                <a href='#'
                   data-on-click='openOverlay'
                   data-url='techinfo.php?bid=" . e($i) . "'
                   data-title='Gebäude-Info'>
                " . $buildings[$i]->get_building_name() . " ($current_building_level)
                </a>
                </td>
                <td>" . (!empty($dependency_text) ? $dependency_text : "") . "</td>
                </tr>
    ";

    $dependency_text = "";
}

$view .= '</table><br>';

$view .= '<table class="table">
    <tr>
        <td class="td-center td-gradient" colspan="2">
            <b>Forschung</b></td>
        <td class="td-center td-gradient">
            <b>Voraussetzungen</b></td>
    </tr>';

for ($i = 0; $i < count($techs); $i++) {
    $current_tech_level = $techs[$i]->get_tech_level();
    $tech_dependencies = $techs[$i]->get_tech_dependencies();
    $dependency_text = "";

    if (!empty($tech_dependencies)) {
        foreach ($tech_dependencies as $dependency) {
            // Building dependency
            if (isset($dependency["dependencyid"]) && $dependency["dependencyid"] !== -1) {
                $building_level_needed = $dependency["dependencylevel"];
                $building_level_current = $buildings[$dependency["dependencyid"]]->get_building_level();

                $dependency_text .= $building_level_needed > $building_level_current
                    ? " <span class='error'>{$buildings[$dependency["dependencyid"]]->get_building_name()} ($building_level_needed)</span>"
                    : " <span class='passed'>{$buildings[$dependency["dependencyid"]]->get_building_name()} ($building_level_needed)</span>";
            }

            // Tech dependency
            if (isset($dependency["techdepid"]) && $dependency["techdepid"] !== -1) {
                $tech_level_needed = $dependency["techdeplevel"];
                $tech_level_current = $techs[$dependency["techdepid"]]->get_tech_level();

                $dependency_text .= $tech_level_needed > $tech_level_current
                    ? " <span class='error'>{$techs[$dependency["techdepid"]]->get_tech_name()} ($tech_level_needed)</span>"
                    : " <span class='passed'>{$techs[$dependency["techdepid"]]->get_tech_name()} ($tech_level_needed)</span>";
            }
        }
    }

    $view .= "<tr>
                <td class='td-center' style='width: 5%;'>{$techs[$i]->get_tech_icon()}</td>
                <td style='width: 30%;'>
                    <a href='#' 
                       data-on-click='openOverlay' 
                       data-url='techinfo.php?tid=" . e($i) . "' 
                       data-title='Tech-Info'>
                        {$techs[$i]->get_tech_name()} ($current_tech_level)
                    </a>
                </td>
                <td>$dependency_text</td>
              </tr>";
}

$view .= '</table>';

/*
 * HTML Section
 */
$title = "Techtree";
$header = "Techtree";
$script_files = ["userinfo"];

include("layout/base.php");
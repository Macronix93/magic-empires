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

$view .= '<div class="title-border">Gebäude-Struktur</div>';
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

    if (!empty($building_dependencies)) {
        foreach ($building_dependencies as $dependency) {
            $level_of_dependency_building = $buildings[$dependency["dependencyid"]]->get_building_level();

            if ($dependency["dependencylevel"] > $level_of_dependency_building) {
                $dependency_text .= " <span class='error' style='white-space: nowrap;'>" . $buildings[$dependency["dependencyid"]]->get_building_name() . " (" . $dependency["dependencylevel"] . ")</span>";
            } else {
                $dependency_text .= " <span class='passed' style='white-space: nowrap;'>" . $buildings[$dependency["dependencyid"]]->get_building_name() . " (" . $dependency["dependencylevel"] . ")</span>";
            }
        }
    } else {
        $dependency_text = " - ";
    }

    $view .= "<tr><td class='td-center' style='width: 5%;'>" . $buildings[$i]->get_building_icon() . "</td>
                <td style='width: 35%;'>
                <a href='#'
                   data-on-click='openOverlay'
                   data-url='techinfo.php?bid=" . e($i) . "'
                   data-title='Gebäude-Info'>
                " . $buildings[$i]->get_building_name() . " ($current_building_level)
                </a>
                </td>
                <td class='techtree-requirements'>" . (!empty($dependency_text) ? $dependency_text : "-") . "</td>
                </tr>
    ";

    $dependency_text = "";
}

$view .= '</table><br>';

$uni_techs = [];
$smithy_techs = [];

foreach ($techs as $t) {
    if ($t->get_tech_id() >= TechTypes::TECH_TYPE_BLADES) {
        $smithy_techs[] = $t;
    } else {
        $uni_techs[] = $t;
    }
}

$renderTechTable = function ($tech_array, $title, $info_title) use ($buildings, $techs) {
    $html = '<div class="title-border">' . $title . '</div>';
    $html .= '<table class="table">
        <tr>
            <td class="td-center td-gradient" colspan="2"><b>Forschung</b></td>
            <td class="td-center td-gradient"><b>Voraussetzungen</b></td>
        </tr>';

    foreach ($tech_array as $t) {
        $current_tech_level = $t->get_tech_level();
        $tech_dependencies = $t->get_tech_dependencies();
        $dependency_text = "";

        if (!empty($tech_dependencies)) {
            foreach ($tech_dependencies as $dependency) {
                // Building dependencies
                if (isset($dependency["dependencyid"]) && $dependency["dependencyid"] !== -1) {
                    $needed = $dependency["dependencylevel"];
                    $current = $buildings[$dependency["dependencyid"]]->get_building_level();
                    $class = ($needed > $current) ? 'error' : 'passed';
                    $dependency_text .= " <span class='$class' style='white-space: nowrap;'>{$buildings[$dependency["dependencyid"]]->get_building_name()} ($needed)</span>";
                }
                // Tech dependencies
                if (isset($dependency["techdepid"]) && $dependency["techdepid"] !== -1) {
                    $needed = $dependency["techdeplevel"];
                    $current = $techs[$dependency["techdepid"]]->get_tech_level();
                    $class = ($needed > $current) ? 'error' : 'passed';
                    $dependency_text .= " <span class='$class' style='white-space: nowrap;'>{$techs[$dependency["techdepid"]]->get_tech_name()} ($needed)</span>";
                }
            }
        } else {
            $dependency_text = " - ";
        }

        $html .= "<tr>
                    <td class='td-center' style='width: 5%;'>{$t->get_tech_icon()}</td>
                    <td style='width: 35%;'>
                        <a href='#' data-on-click='openOverlay' data-url='techinfo.php?tid=" . e($t->get_tech_id()) . "' data-title='$info_title'>
                            {$t->get_tech_name()} ($current_tech_level)
                        </a>
                    </td>
                    <td class='techtree-requirements'>$dependency_text</td>
                  </tr>";
    }
    $html .= '</table><br>';

    return $html;
};

$view .= $renderTechTable($uni_techs, "Universitäts-Forschungen", "Tech-Info");
$view .= $renderTechTable($smithy_techs, "Schmiede-Verbesserungen", "Schmiede-Info");

$view .= '<div class="title-border">Einheiten</div>';
$view .= '<table class="table">
    <tr>
        <td class="td-center td-gradient" colspan="2"><b>Einheiten</b></td>
        <td class="td-center td-gradient"><b>Voraussetzungen</b></td>
    </tr>';

$res_soldiers = $db_instance->execute_query("SELECT * FROM soldier_list ORDER BY category, requiredlevel");

foreach ($res_soldiers as $row) {
    $s_obj = new Soldier();
    $s_obj->fill_from_row($row);

    $req_lvl = $s_obj->get_soldier_required_level();
    $barracks_lvl = $buildings[BuildingTypes::BUILDING_BARRACKS]->get_building_level();

    $is_hero = $s_obj->get_soldier_id() == Soldiers::SOLDIER_HERO;
    $status_class = $is_hero ? "style='font-style: italic;'" : (($barracks_lvl >= $req_lvl) ? "class='passed'" : "class='error'");

    $view .= "<tr>
                <td class='td-center' style='width: 5%;'>{$s_obj->get_soldier_icon()}</td>
                <td style='width: 35%;'>
                    <a href='#' 
                       data-on-click='openOverlay' 
                       data-url='techinfo.php?sid=" . $s_obj->get_soldier_id() . "' 
                       data-title='Einheiten-Info'>
                        " . $s_obj->get_soldier_name() . "
                    </a>
                </td>
                <td class='techtree-requirements'><span $status_class>" . ($is_hero ? "Verteilung alle 24 Stunden" : "Kaserne ($req_lvl)") . "</span></td>
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
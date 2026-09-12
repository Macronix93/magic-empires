<?php
require_once("includes/core.php");

if (!($user->is_logged_in())) {
    change_location("index.php");
    exit;
}

$dependency_text = "";

// Aktuelles Königreich
$current_kid = $user->get_current_kingdom();
$main_kid = $user->get_main_kingdom();

// Fetch all buildings and their dependencies
$kingdom = new Kingdom($db_instance, $user->get_current_kingdom());
$buildings = $kingdom->fetch_all_kingdom_buildings();
$techs = $kingdom->fetch_all_kingdom_techs();
$tc_level = $buildings[BuildingTypes::BUILDING_TOWNCENTER]->get_building_level();

if ($current_kid === $main_kid) {
    $main_buildings = $buildings;
    $main_techs = $techs;
} else {
    $main_k = new Kingdom($db_instance, $main_kid);
    $main_buildings = $main_k->fetch_all_kingdom_buildings();
    $main_techs = $main_k->fetch_all_kingdom_techs();
}

$view .= '<div class="title-border">Gebäude-Struktur</div>';
$view .= '<table class="table">
    <tr>
        <td class="td-center td-gradient" colspan="2">
            <b>Gebäude</b></td>
        <td class="td-center td-gradient">
            <b>Voraussetzungen</b></td>
    </tr>';

for ($i = 0; $i < count($buildings); $i++) {
    $is_embassy = ($i === BuildingTypes::BUILDING_EMBASSY);
    $target_buildings = $is_embassy ? $main_buildings : $buildings;

    $current_building_level = $target_buildings[$i]->get_building_level();
    $building_dependencies = $buildings[$i]->get_building_dependencies();

    if (!empty($building_dependencies)) {
        foreach ($building_dependencies as $dependency) {
            $level_of_dependency_building = $target_buildings[$dependency["dependencyid"]]->get_building_level();

            if ($dependency["dependencylevel"] > $level_of_dependency_building) {
                $dependency_text .= " <span class='error' style='white-space: nowrap;'>" . $target_buildings[$dependency["dependencyid"]]->get_building_name() . " (" . $dependency["dependencylevel"] . ")</span>";
            } else {
                $dependency_text .= " <span class='passed' style='white-space: nowrap;'>" . $target_buildings[$dependency["dependencyid"]]->get_building_name() . " (" . $dependency["dependencylevel"] . ")</span>";
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

$renderTechTable = function ($tech_array, $title, $info_title) use ($buildings, $techs, $main_buildings, $main_techs) {
    $html = '<div class="title-border">' . $title . '</div>';
    $html .= '<table class="table">
        <tr>
            <td class="td-center td-gradient" colspan="2"><b>Forschung</b></td>
            <td class="td-center td-gradient"><b>Voraussetzungen</b></td>
        </tr>';

    foreach ($tech_array as $t) {
        $is_imperial = ($t->get_tech_id() === TechTypes::TECH_TYPE_IMPERIAL);
        $target_buildings = $is_imperial ? $main_buildings : $buildings;
        $target_techs = $is_imperial ? $main_techs : $techs;

        $current_tech_level = $target_techs[$t->get_tech_id()]->get_tech_level();
        $tech_dependencies = $t->get_tech_dependencies();
        $dependency_text = "";

        if (!empty($tech_dependencies)) {
            foreach ($tech_dependencies as $dependency) {
                // Building dependencies
                if (isset($dependency["dependencyid"]) && $dependency["dependencyid"] !== -1) {
                    $needed = $dependency["dependencylevel"];
                    $current = $target_buildings[$dependency["dependencyid"]]->get_building_level();
                    $class = ($needed > $current) ? 'error' : 'passed';
                    $dependency_text .= " <span class='$class' style='white-space: nowrap;'>{$target_buildings[$dependency["dependencyid"]]->get_building_name()} ($needed)</span>";
                }
                // Tech dependencies
                if (isset($dependency["techdepid"]) && $dependency["techdepid"] !== -1) {
                    $needed = $dependency["techdeplevel"];
                    $current = $target_techs[$dependency["techdepid"]]->get_tech_level();
                    $class = ($needed > $current) ? 'error' : 'passed';
                    $dependency_text .= " <span class='$class' style='white-space: nowrap;'>{$target_techs[$dependency["techdepid"]]->get_tech_name()} ($needed)</span>";
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

// --- GUILD TECHS ---
$my_guild_id = $user->get_user_guild_id();
$guild_logic = new Guild($db_instance, $user, $my_guild_id);
$has_embassy = ($buildings[BuildingTypes::BUILDING_EMBASSY]->get_building_level() > 0);
$in_guild = ($my_guild_id > 0);

$view .= '<div class="title-border">Gilden-Forschungen</div>';
$view .= '<table class="table">
    <tr>
        <td class="td-center td-gradient" colspan="2"><b>Forschung</b></td>
        <td class="td-center td-gradient"><b>Voraussetzungen</b></td>
    </tr>';

$all_guild_techs = $guild_logic->get_all_techs();
foreach ($all_guild_techs as $gt) {
    $cur_lvl = (int)$gt["current_level"];
    $max_lvl = (int)$gt["max_level"];
    $icon = "images/icons/" . e($gt["icon"]) . ".png";

    $req_html = "";
    if (!$has_embassy) {
        $req_html .= "<span class='error' style='white-space: nowrap;'>Botschaft (1)</span> ";
    } else {
        $req_html .= "<span class='passed' style='white-space: nowrap;'>Botschaft (1)</span> ";
    }

    if (!$in_guild) {
        $req_html .= "<span class='error' style='white-space: nowrap;'>Gildenmitgliedschaft</span>";
    } else {
        $req_html .= "<span class='passed' style='white-space: nowrap;'>Gildenmitgliedschaft</span>";
    }

    $lvl_display = $in_guild ? "($cur_lvl/$max_lvl)" : "";

    $view .= "<tr>
                <td class='td-center' style='width: 5%;'>
                    <img src='$icon' class='buildable-icons' alt=''>
                </td>
                <td style='width: 35%;'>
                    <a href='#' data-on-click='openOverlay' data-url='techinfo.php?gtid=" . (int)$gt["id"] . "' data-title='Gilden-Forschung'>
                        " . e($gt["name"]) . " $lvl_display
                    </a>
                </td>
                <td class='techtree-requirements'>$req_html</td>
              </tr>";
}
$view .= '</table><br>';

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
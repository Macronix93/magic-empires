<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_SMITHY);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];
$kingdom_wood = $result["k_wood"];
$kingdom_food = $result["k_food"];
$kingdom_stone = $result["k_stone"];
$kingdom_gold = $result["k_gold"];

$kingdom_is_researching = false;
$kingdom_tech_id = -1;

// Fetch all buildings and their dependencies
$techs = $kingdom->fetch_all_kingdom_techs(BuildingTypes::BUILDING_SMITHY);
$buildings = $kingdom->fetch_all_kingdom_buildings();
$all_techs_for_check = $kingdom->fetch_all_kingdom_techs();
$tech_count = count($techs);
$tech_id = (empty($_GET["tid"]) ? 0 : (int)$_GET["tid"]);

if (isset($_GET["action"])) {
    $kingdom_is_researching = $kingdom->is_kingdom_smithing($current_kingdom);

    if ($kingdom_is_researching) {
        $kingdom_research_id = $kingdom->get_kingdom_research_id();
    }

    if (isset($techs[$tech_id])) {
        $tech_level = $techs[$tech_id]->get_tech_level();
        $tech_max_level = $techs[$tech_id]->get_tech_max_level();
        $costs = $techs[$tech_id]->calculate_tech_cost();
        $cost_wood = $costs["cost_wood"];
        $cost_food = $costs["cost_food"];
        $cost_stone = $costs["cost_stone"];
        $cost_gold = $costs["cost_gold"];

        // The action that was set is "research"
        if ($_GET["action"] == "research") {
            if ($tech_level >= $tech_max_level) {
                $error = "Die Forschung ist schon maximal erforscht!";
            } else {
                if ($kingdom_is_researching) {
                    $error = "Du forschst bereits!";
                } else {
                    $tech_dependencies = $techs[$tech_id]->get_tech_dependencies();

                    foreach ($tech_dependencies as $dependency) {
                        // Check BUILDING Dependency
                        if (!empty($dependency["dependencyid"]) && $dependency["dependencyid"] > 0) {
                            $building_level_needed = $dependency["dependencylevel"];
                            $building_level_current = $buildings[$dependency["dependencyid"]]->get_building_level();

                            if ($building_level_needed > $building_level_current) {
                                $error .= $techs[$tech_id]->get_tech_name() . " setzt " . $buildings[$dependency["dependencyid"]]->get_building_name() . " Stufe 
                                " . $building_level_needed . " voraus!<br />";
                            }
                        }

                        // Check TECH Dependency
                        if (!empty($dependency["techdepid"]) && $dependency["techdepid"] > 0) {
                            $tech_level_needed = $dependency["techdeplevel"];
                            $tech_level_current = $all_techs_for_check[$dependency["techdepid"]]->get_tech_level();

                            if ($tech_level_needed > $tech_level_current) {
                                $error .= $techs[$tech_id]->get_tech_name() . " setzt " . $all_techs_for_check[$dependency["techdepid"]]->get_tech_name() . " Stufe 
                                " . $tech_level_needed . " voraus!<br />";
                            }
                        }
                    }

                    // Dependency check passed - research/upgrade tech!
                    if (empty($error)) {
                        if ($cost_wood > $kingdom_wood || $cost_food > $kingdom_food || $cost_stone > $kingdom_stone || $cost_gold > $kingdom_gold) {
                            $error = "Nicht genügend Ressourcen!";
                        } else {
                            $tech_time = time() + (int)round($techs[$tech_id]->get_tech_time() * pow($techs[$tech_id]->get_tech_mult(), $tech_level));

                            // Subtract research costs from kingdom resources
                            $kingdom->give_kingdom_wood(-$cost_wood);
                            $kingdom->give_kingdom_food(-$cost_food);
                            $kingdom->give_kingdom_stone(-$cost_stone);
                            $kingdom->give_kingdom_gold(-$cost_gold);

                            $db_instance->execute_query("INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname) VALUES (?, ?, ?, ?, ?, ?, ?)",
                                [ActionTypes::ACTION_SMITHY_UPGRADE, $user->get_user_id(), $current_kingdom, $tech_id, $tech_time, $tech_level, $techs[$tech_id]->get_tech_name()]);

                            $kingdom_wood -= $cost_wood;
                            $kingdom_food -= $cost_food;
                            $kingdom_stone -= $cost_stone;
                            $kingdom_gold -= $cost_gold;
                        }
                    }
                }
            }
        } else if ($_GET["action"] == "cancel") { // The action that was set is "cancel research"
            if ($kingdom_is_researching && $kingdom_research_id == $tech_id) {
                $db_instance->execute_query("DELETE FROM events WHERE userid = ? AND buildingid = ? AND kingdomid = ?",
                    [$user->get_user_id(), $tech_id, $current_kingdom]);

                // Refund the player
                $kingdom->give_kingdom_wood($cost_wood);
                $kingdom->give_kingdom_food($cost_food);
                $kingdom->give_kingdom_stone($cost_stone);
                $kingdom->give_kingdom_gold($cost_gold);

                $kingdom_wood += $cost_wood;
                $kingdom_food += $cost_food;
                $kingdom_stone += $cost_stone;
                $kingdom_gold += $cost_gold;
            } else {
                $error = "Du forschst gerade nichts!";
            }
        }
    }
}

$last_researched_tech = $user->get_last_researched_tech($current_kingdom);

if (!empty($last_researched_tech)) {
    $researched_tech_name = $last_researched_tech["techname"];
    $researched_tech_level = $last_researched_tech["techlevel"];

    $view .= show_weighted_box("$researched_tech_name (" . $researched_tech_level . " → " . ($researched_tech_level + 1) . ")", "Forschung abgeschlossen:");

    $user->clear_last_researched_tech($current_kingdom);
}

/*
 * HTML Content Part
 */
$smithy_boni_view = "";

$blades_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES);
$shield_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL);
if ($blades_lvl > 0 || $shield_lvl > 0) {
    $atk = $blades_lvl * SMITHY_INF_ATK_BONUS;
    $def = $shield_lvl * SMITHY_INF_DEF_BONUS;
    $smithy_boni_view .= "<tr><td>Infanterie:</td><td class='passed'>+$atk Angriff / +$def Verteidigung</td></tr>";
}

$lance_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING);
$harn_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS);
if ($lance_lvl > 0 || $harn_lvl > 0) {
    $atk = $lance_lvl * SMITHY_CAV_ATK_BONUS;
    $def = $harn_lvl * SMITHY_CAV_DEF_BONUS;
    $smithy_boni_view .= "<tr><td>Kavallerie:</td><td class='passed'>+$atk Angriff / +$def Verteidigung</td></tr>";
}

$arrow_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS);
$wams_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET);
if ($arrow_lvl > 0 || $wams_lvl > 0) {
    $atk = $arrow_lvl * SMITHY_ARC_ATK_BONUS;
    $def = $wams_lvl * SMITHY_ARC_DEF_BONUS;
    $smithy_boni_view .= "<tr><td>Fernkampf:</td><td class='passed'>+$atk Angriff / +$def Verteidigung</td></tr>";
}

$weight_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_WEIGHT);
if ($weight_lvl > 0) {
    $percent = $weight_lvl * (SMITHY_WEIGHT_REDUCTION * 100);
    $smithy_boni_view .= "<tr><td>Waffengewicht:</td><td class='passed'>-$percent% Rekrutierungskosten & -zeit</td></tr>";
}

$siege_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SIEGE);
if ($siege_lvl > 0) {
    $percent = $siege_lvl * (SMITHY_SIEGE_BONUS * 100);
    $smithy_boni_view .= "<tr><td>Belagerung:</td><td class='passed'>+$percent% Schaden an Mauern</td></tr>";
}

if (!empty($smithy_boni_view)) {
    $view .= "<div class='title-border'>Aktive Schmiede-Boni</div>";
    $view .= "<table class='table' style='max-width: 500px; margin-bottom: 20px;'>$smithy_boni_view</table>";
}

$kingdom_is_researching = $kingdom->is_kingdom_smithing($current_kingdom);

if ($kingdom_is_researching) {
    $kingdom_research_id = $kingdom->get_kingdom_research_id();
}

$view .= '<table class="table">
            <colgroup>
                <col class="col-description">
                <col class="col-action">
            </colgroup>
            <tr>
                <td class="td-center td-gradient">
                    <b>Verbesserung</b></td>
                <td class="td-center td-gradient">
                    <b>Aktion</b></td>
            </tr>';

foreach ($techs as $i => $tech) {
    $show_tech = true;
    $tech_dependencies = $tech->get_tech_dependencies();

    foreach ($tech_dependencies as $dependency) {
        if (!empty($dependency["dependencyid"]) && $dependency["dependencyid"] > 0) {
            if ($dependency["dependencylevel"] > $buildings[$dependency["dependencyid"]]->get_building_level()) {
                $show_tech = false;
                break;
            }
        }
        if (!empty($dependency["techdepid"]) && $dependency["techdepid"] > 0) {
            if ($dependency["techdeplevel"] > $all_techs_for_check[$dependency["techdepid"]]->get_tech_level()) {
                $show_tech = false;
                break;
            }
        }
    }

    if (!$show_tech) continue;

    $level = $tech->get_tech_level();
    $max_level = $tech->get_tech_max_level();

    if ($level < $max_level) {
        $costs = $tech->calculate_tech_cost();
        $cost_wood = $costs["cost_wood"];
        $cost_food = $costs["cost_food"];
        $cost_stone = $costs["cost_stone"];
        $cost_gold = $costs["cost_gold"];

        $text_wood = get_resource_text($cost_wood, $kingdom_wood);
        $text_food = get_resource_text($cost_food, $kingdom_food);
        $text_stone = get_resource_text($cost_stone, $kingdom_stone);
        $text_gold = get_resource_text($cost_gold, $kingdom_gold);

        $res_html = "";
        if ($cost_food > 0) $res_html .= "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . $text_food . "</div>";
        if ($cost_wood > 0) $res_html .= "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . $text_wood . "</div>";
        if ($cost_stone > 0) $res_html .= "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . $text_stone . "</div>";
        if ($cost_gold > 0) $res_html .= "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . $text_gold . "</div>";

        $text_build = "";

        if ($kingdom_is_researching) {
            if ($kingdom_research_id == $i) {
                $result_ev = $db_instance->execute_query("SELECT buildingtime FROM events WHERE kingdomid = ? AND buildingid = ? AND actionid = ?",
                    [$current_kingdom, $i, ActionTypes::ACTION_SMITHY_UPGRADE]);
                $row_ev = $result_ev->fetch_assoc();

                $difference_time = $row_ev["buildingtime"] - time();

                $text_build = "Forschungszeit:<br><b><span class='js-countdown' 
                                       data-seconds='$difference_time' 
                                       data-hide-id='cancel-form'></span></b><br>
                              <form id='cancel-form' action='blacksmith.php' method='GET'>
                                <input type='hidden' name='action' value='cancel'>
                                <input type='hidden' name='tid' value='" . $i . "'>
                                <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                              </form>";
            } else {
                $text_build = "-";
            }
        } else {
            $disabled = $cost_wood > $kingdom_wood || $cost_food > $kingdom_food || $cost_stone > $kingdom_stone || $cost_gold > $kingdom_gold ? "disabled" : "";
            $text_build = "<form action='blacksmith.php' method='GET'>
                            <input type='hidden' name='action' value='research'>
                            <input type='hidden' name='tid' value='" . $i . "'>
                            <input type='submit' value='" . ($level > 0 ? "Upgrade" : "Forschen") . "' $disabled>
                          </form>";
        }

        $view .= "<tr>
            <td>
                <div class='map-legend' style='justify-content: left;'>
                <div class='legend-item'>" . $tech->get_tech_icon() . "</div>
                    <div class='legend-item'>
                        <b class='popup' id='description" . $i . "'>" . $tech->get_tech_name() . " 
                            <div id='description" . $i . "_box' class='popupbox'>" . $tech->get_tech_description() . "</div> ($level)
                        </b>
                    </div>
                </div>
                <div class='map-legend' style='justify-content: left; margin-top: 10px; gap: 5px;'>
                    $res_html
                </div>
                " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME) . " 
                " . convert_sec_to_str((int)round($tech->get_tech_time() * pow($tech->get_tech_mult(), $level))) . "
            </td>
            <td class='td-center'>" . $text_build . "</td>
        </tr>";
    }
}
$view .= "</table>";

/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = ["counter"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
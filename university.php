<?php
require_once("includes/core.php");

[
    "current_kingdom" => $current_kingdom,
    "building" => $building,
    "building_name" => $building_name,
    "kingdom" => $kingdom,
    "k_wood" => $kingdom_wood,
    "k_food" => $kingdom_food,
    "k_stone" => $kingdom_stone,
    "k_gold" => $kingdom_gold
] = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_UNIVERSITY);

$kingdom_is_researching = false;
$kingdom_tech_id = -1;

// Fetch all buildings and their dependencies
$techs = $kingdom->fetch_all_kingdom_techs(BuildingTypes::BUILDING_UNIVERSITY);
$buildings = $kingdom->fetch_all_kingdom_buildings();
$tech_count = count($techs);
$current_tech = (empty($_GET["id"]) ? 0 : (int)$_GET["id"]);
$tech_id = (empty($_GET["tid"]) ? 0 : (int)$_GET["tid"]);

if (isset($_GET["action"])) {
    $kingdom_is_researching = $kingdom->is_kingdom_researching($current_kingdom);

    if ($kingdom_is_researching) {
        $kingdom_research_id = $kingdom->get_kingdom_research_id();
    }

    if ($tech_id >= 0 && $tech_id < $tech_count) {
        $tech_level = $techs[$tech_id]->get_tech_level();
        $tech_max_level = $techs[$tech_id]->get_tech_max_level();
        $costs = $techs[$tech_id]->calculate_tech_cost();
        $cost_wood = $costs["cost_wood"];
        $cost_food = $costs["cost_food"];
        $cost_stone = $costs["cost_stone"];
        $cost_gold = $costs["cost_gold"];

        // The action that was set is "building"
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
                            $tech_level_current = $techs[$dependency["techdepid"]]->get_tech_level();

                            if ($tech_level_needed > $tech_level_current) {
                                $error .= $techs[$tech_id]->get_tech_name() . " setzt " . $techs[$dependency["techdepid"]]->get_tech_name() . " Stufe 
                                " . $tech_level_needed . " voraus!<br />";
                            }
                        }
                    }

                    // Dependency check passed - research/upgrade tech!
                    if (empty($error)) {
                        if ($cost_wood > $kingdom_wood || $cost_food > $kingdom_food || $cost_stone > $kingdom_stone || $cost_gold > $kingdom_gold) {
                            $error = "Nicht genügend Ressourcen!";
                        } else {

                            $tech_time = time() + $techs[$tech_id]->get_tech_time() * ($tech_level == 0 ? 1 : $tech_level + 1);

                            // Subtract research costs from kingdom resources
                            $kingdom->give_kingdom_wood(-$cost_wood);
                            $kingdom->give_kingdom_food(-$cost_food);
                            $kingdom->give_kingdom_stone(-$cost_stone);
                            $kingdom->give_kingdom_gold(-$cost_gold);

                            $db_instance->execute_query("INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname) VALUES (?, ?, ?, ?, ?, ?, ?)",
                                [ActionTypes::ACTION_RESEARCH_TECH, $user->get_user_id(), $current_kingdom, $tech_id, $tech_time, $techs[$tech_id]->get_tech_level(), $techs[$tech_id]->get_tech_name()]);
                        }
                    }
                }
            }
        } else if ($_GET["action"] == "cancel") { // The action that was set is "cancel research"
            if ($kingdom_is_researching) {
                $db_instance->execute_query("DELETE FROM events WHERE userid = '{$user->get_user_id()}' AND buildingid = '$tech_id' AND kingdomid = ?",
                    [$user->get_current_kingdom()]);

                // Refund the player
                $kingdom->give_kingdom_wood($cost_wood);
                $kingdom->give_kingdom_food($cost_food);
                $kingdom->give_kingdom_stone($cost_stone);
                $kingdom->give_kingdom_gold($cost_gold);
            } else {
                $error = "Du forschst gerade nichts!";
            }
        } else {
            $error = "Diese Aktion existiert nicht!";
        }
    } else {
        $error = "Diese Forschung existiert nicht!";
    }
}
$last_researched_tech = $user->get_last_researched_tech($current_kingdom);

if (!empty($last_researched_tech)) {
    $researched_tech_name = $last_researched_tech["techname"];
    $researched_tech_level = $last_researched_tech["techlevel"];

    $view .= show_weighted_box("$researched_tech_name (" . $researched_tech_level . " → " . ($researched_tech_level + 1) . ")", "Forschung abgeschlossen:");

    $user->clear_last_researched_tech($current_kingdom);
}

// Calculate and show tech boni
$boni_view = "";

$arch_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARCHITECTURE);
if ($arch_lvl > 0) {
    $percent = $arch_lvl * ARCHITECTURE_TIME_REDUCTION * 100;
    $boni_view .= "<tr><td>Baukunst:</td><td class='passed'>-$percent% Bauzeit</td></tr>";
}

$carto_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CARTOGRAPHY);
if ($carto_lvl > 0) {
    $reduction = (1 - $kingdom->get_march_speed_multiplier()) * 100;
    $boni_view .= "<tr><td>Kartografie:</td><td class='passed'>-" . round($reduction, 1) . "% Marschzeit</td></tr>";
}

$maint_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_MAINTENANCE);
if ($maint_lvl > 0) {
    $percent = $maint_lvl * MAINTENANCE_REPAIR_REDUCTION * 100;
    $boni_view .= "<tr><td>Mauerwartung:</td><td class='passed'>-$percent% Reparaturkosten</td></tr>";
}

$plund_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_PLUNDER);
if ($plund_lvl > 0) {
    $percent = $plund_lvl * PLUNDER_CAPACITY_BONUS * 100;
    $boni_view .= "<tr><td>Plünderungstaktik:</td><td class='passed'>+$percent% Beute-Kapazität</td></tr>";
}

$rites_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ANCESTRAL_RITES);
if ($rites_lvl > 0) {
    $effect = $rites_lvl * SHRINE_TECH_STEP * 100;
    $boni_view .= "<tr><td>Ahnenritus:</td><td class='passed'>+$effect% Schrein-Effektivität</td></tr>";
}

$settlement_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_IMPERIAL);
if ($settlement_lvl > 0) {
    $effekt = BASE_SETTLEMENT_LIMIT + $settlement_lvl;
    $boni_view .= "<tr><td>Siedlungsgründungen:</td><td class='passed'>$effekt Siedlungen</td></tr>";
}

if (!empty($boni_view)) {
    $view .= "<div class='title-border'>Aktive Boni (Forschung)</div>";
    $view .= "<table class='table' style='max-width: 450px; margin-bottom: 10px;'>$boni_view</table>";
}


/*
 * HTML Content Part
 */
// Get current ressources
$kingdom_wood = $kingdom->get_kingdom_wood();
$kingdom_food = $kingdom->get_kingdom_food();
$kingdom_stone = $kingdom->get_kingdom_stone();
$kingdom_gold = $kingdom->get_kingdom_gold();
$kingdom_is_researching = $kingdom->is_kingdom_researching($current_kingdom);

if ($kingdom_is_researching) {
    $kingdom_research_id = $kingdom->get_kingdom_research_id();
}

// Count max upgraded buildings
$count_maxed_techs = 0;
for ($i = 0; $i < $tech_count; $i++) {
    if ($techs[$i]->get_tech_level() >= $techs[$i]->get_tech_max_level())
        $count_maxed_techs++;
}

if ($count_maxed_techs === $tech_count) {
    $view = "Es wurden alle Forschungen geforscht.";
} else {
    $view .= '<table class="table">
                        <colgroup>
                            <col style="width: auto;">
                            <col style="width: 180px;">
                        </colgroup>
                            <tr>
                                <td class="td-center td-gradient">
                                    <b>Forschung</b></td>
                                <td class="td-center td-gradient">
                                    <b>Aktion</b></td>
                            </tr>';

    for ($i = 0; $i < $tech_count; $i++) {
        $show_tech = true;
        $tech_dependencies = $techs[$i]->get_tech_dependencies();

        $tech_dependencies = $techs[$i]->get_tech_dependencies();
        foreach ($tech_dependencies as $dependency) {
            // BUILDING DEPENDENCIES check
            if (!empty($dependency["dependencyid"]) && $dependency["dependencyid"] > 0) {
                $building_level_needed = $dependency["dependencylevel"];
                $building_level_current = $buildings[$dependency["dependencyid"]]->get_building_level();

                if ($building_level_needed > $building_level_current) {
                    $show_tech = false;
                    break;
                }
            }

            // TECH DEPENDENCIES check
            if (!empty($dependency["techdepid"]) && $dependency["techdepid"] > 0) {
                $tech_level_needed = $dependency["techdeplevel"];
                $tech_level_current = $techs[$dependency["techdepid"]]->get_tech_level();

                if ($tech_level_needed > $tech_level_current) {
                    $show_tech = false;
                    break;
                }
            }
        }


        $level = $techs[$i]->get_tech_level();
        $max_level = $techs[$i]->get_tech_max_level();

        if ($level < $max_level) {
            if ($show_tech) {
                if (!is_numeric($level)) {
                    $level = "0";
                }

                $costs = $techs[$i]->calculate_tech_cost();
                $cost_wood = $costs["cost_wood"];
                $cost_food = $costs["cost_food"];
                $cost_stone = $costs["cost_stone"];
                $cost_gold = $costs["cost_gold"];

                $text_wood = get_resource_text($cost_wood, $kingdom_wood);
                $text_food = get_resource_text($cost_food, $kingdom_food);
                $text_stone = get_resource_text($cost_stone, $kingdom_stone);
                $text_gold = get_resource_text($cost_gold, $kingdom_gold);
                $text_build = "";

                if ($kingdom_is_researching) {
                    if ($kingdom_research_id == $i) {
                        $result = $db_instance->execute_query("SELECT buildingtime FROM events WHERE kingdomid = ? AND buildingid = ? AND actionid = ?",
                            [$current_kingdom, $i, ActionTypes::ACTION_RESEARCH_TECH]);
                        $row = $result->fetch_assoc();

                        $difference_time = $row["buildingtime"] - time();

                        $text_build = "Forschungszeit:<br><b><span class='js-countdown' 
                                               data-seconds='$difference_time' 
                                               data-hide-id='cancel-form'></span></b><br>
                                      <form id='cancel-form' action='university.php' method='GET'>
                                        <input type='hidden' name='action' value='cancel'>
                                        <input type='hidden' name='tid' value='" . $i . "'>
                                        <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                                      </form>";
                    } else {
                        $text_build = "-";
                    }
                } else {
                    $disabled = $cost_wood > $kingdom_wood || $cost_food > $kingdom_food || $cost_stone > $kingdom_stone || $cost_gold > $kingdom_gold ? "disabled" : "";
                    $text_build = "<form action='university.php' method='GET'>
                                    <input type='hidden' name='action' value='research'>
                                    <input type='hidden' name='tid' value='" . $i . "'>
                                    <input type='submit' value='" . ($level > 0 ? "Upgrade" : "Forschen") . "' $disabled>
                                  </form>";
                }

                $resource_costs = "";
                if ($text_food > 0) {
                    $resource_costs .= "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . $text_food . "</div>";
                }
                if ($text_wood > 0) {
                    $resource_costs .= "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . $text_wood . "</div>";
                }
                if ($text_stone > 0) {
                    $resource_costs .= "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . $text_stone . "</div>";
                }
                if ($text_gold > 0) {
                    $resource_costs .= "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . $text_gold . "</div>";
                }

                $view .= "<tr>
                    <td>
                        <div class='map-legend' style='justify-content: left;'>
                        <div class='legend-item'>" . $techs[$i]->get_tech_icon() . "</div>
                            <div class='legend-item'>
                                <b class='popup' id='description" . $i . "'>" . $techs[$i]->get_tech_name() . " 
                                    <div id='description" . $i . "_box' class='popupbox'>" . $techs[$i]->get_tech_description() . "</div> ($level)
                                </b>
                            </div>
                        </div>
                        <div class='map-legend' style='justify-content: left; margin-top: 10px; gap: 5px;'>
                        $resource_costs
                        </div>
                        " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME) . " 
                        " . convert_sec_to_str($techs[$i]->get_tech_time() * ($level == 0 ? 1 : $level + 1)) . "
                    </td>
                    <td class='td-center'>" . $text_build . "</td>
                </tr>";
            }
        }
    }
    $view .= "</table>";
}

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
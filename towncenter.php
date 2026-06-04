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
    "k_gold" => $kingdom_gold,
    "k_villager" => $kingdom_villager
] = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_TOWNCENTER);

$kingdom_is_building = false;
$kingdom_building_id = -1;

// Fetch all buildings and their dependencies
$buildings = $kingdom->fetch_all_kingdom_buildings();
$building_count = count($buildings);
$current_building = (empty($_GET["id"]) ? 0 : (int)$_GET["id"]);
$build_id = (empty($_GET["bid"]) ? 0 : (int)$_GET["bid"]);
$tc_level = $buildings[BuildingTypes::BUILDING_TOWNCENTER]->get_building_level();

if (isset($_GET["action"])) {
    $kingdom_is_building = $kingdom->is_kingdom_building($current_kingdom);

    if ($kingdom_is_building) {
        $kingdom_building_id = $kingdom->get_kingdom_building_id();
    }

    if ($build_id >= BuildingTypes::BUILDING_TOWNCENTER && $build_id < $building_count) {
        $building_level = $buildings[$build_id]->get_building_level();
        $costs = $buildings[$build_id]->calculate_building_cost();
        $cost_wood = $costs["cost_wood"];
        $cost_food = $costs["cost_food"];
        $cost_stone = $costs["cost_stone"];
        $cost_gold = $costs["cost_gold"];

        // The action that was set is "building"
        if ($_GET["action"] == "build") {
            if ($building_level >= MAX_BUILDING_LEVEL) {
                $error = "Das Gebäude ist schon maximal ausgebaut!";
            } else {
                if ($kingdom_is_building) {
                    $error = "Du baust bereits!";
                } else {
                    if ($cost_wood > $kingdom_wood || $cost_food > $kingdom_food || $cost_stone > $kingdom_stone || $cost_gold > $kingdom_gold) {
                        $error = "Nicht genügend Ressourcen!";
                    } else {
                        $target_level = $building_level + 1;

                        if ($build_id != BuildingTypes::BUILDING_TOWNCENTER && $target_level > $tc_level) {
                            $error = "Dein Dorfzentrum ist zu niedrig! (Dorfzentrum Stufe $target_level benötigt)";
                        }

                        $building_dependencies = $buildings[$build_id]->get_building_dependencies();

                        foreach ($building_dependencies as $dependency) {
                            if ($dependency["dependencylevel"] > $buildings[$dependency["dependencyid"]]->get_building_level()) {
                                $error .= $buildings[$build_id]->get_building_name() . " setzt " . $buildings[$dependency["dependencyid"]]->get_building_name() . " 
                                            Stufe " . $dependency["dependencylevel"] . " voraus!<br>";
                            }
                        }

                        // Dependency check passed - build/upgrade building!
                        if (empty($error)) {
                            $building_time = time() + $buildings[$build_id]->get_building_time() * ($building_level == 0 ? 1 : $building_level + 1);

                            // Subtract building costs from kingdom resources
                            $kingdom->give_kingdom_wood(-$cost_wood);
                            $kingdom->give_kingdom_food(-$cost_food);
                            $kingdom->give_kingdom_stone(-$cost_stone);
                            $kingdom->give_kingdom_gold(-$cost_gold);

                            $db_instance->execute_query("INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname) VALUES (?, ?, ?, ?, ?, ?, ?)",
                                [ActionTypes::ACTION_BUILD_BUILDING, $user->get_user_id(), $current_kingdom, $build_id, $building_time, $buildings[$build_id]->get_building_level(), $buildings[$build_id]->get_building_name()]);
                        }
                    }
                }
            }
        } else if ($_GET["action"] == "cancel") { // The action that was set is "cancel building"
            if ($kingdom_is_building) {
                $db_instance->execute_query("DELETE FROM events WHERE userid = '{$user->get_user_id()}' AND buildingid = '$build_id' AND kingdomid = ?",
                    [$user->get_current_kingdom()]);

                // Refund the player
                $kingdom->give_kingdom_wood($cost_wood);
                $kingdom->give_kingdom_food($cost_food);
                $kingdom->give_kingdom_stone($cost_stone);
                $kingdom->give_kingdom_gold($cost_gold);

                $logger->log_game("ECONOMY", "BUILDING_CANCEL", [
                    "building" => $buildings[$build_id]->get_building_name(),
                    "refund" => $costs
                ], $current_kingdom);
            } else {
                $error = "Du baust gerade nichts!";
            }
        } else {
            $error = "Diese Aktion existiert nicht!";
        }
    } else {
        $error = "Dieses Gebäude existiert nicht!";
    }
}
$last_built_building = $user->get_last_built_building($current_kingdom);

if (!empty($last_built_building)) {
    $built_building_name = $last_built_building["buildingname"];
    $built_building_level = $last_built_building["buildinglevel"];

    $view .= show_weighted_box("$built_building_name (" . $built_building_level . " → " . ($built_building_level + 1) . ")", "Bau abgeschlossen:");

    $user->clear_last_built_building($current_kingdom);
}

/*
 * HTML Content Part
 */
// Get current ressources
$kingdom_wood = $kingdom->get_kingdom_wood();
$kingdom_food = $kingdom->get_kingdom_food();
$kingdom_stone = $kingdom->get_kingdom_stone();
$kingdom_gold = $kingdom->get_kingdom_gold();
$kingdom_is_building = $kingdom->is_kingdom_building($current_kingdom);

if ($kingdom_is_building) {
    $kingdom_building_id = $kingdom->get_kingdom_building_id();
}

// Count max upgraded buildings
$count_maxed_buildings = 0;
for ($i = 0; $i < $building_count; $i++) {
    if ($buildings[$i]->get_building_level() >= MAX_BUILDING_LEVEL)
        $count_maxed_buildings++;
}

if ($count_maxed_buildings === $building_count) {
    $view = "Es wurden alle Gebäude gebaut.";
} else {
    $view .= '<table class="table">
                        <colgroup>
                            <col style="width: auto;">
                            <col style="width: 180px;">
                        </colgroup>
                            <tr>
                                <td class="td-center td-gradient">
                                    <b>Gebäude</b></td>
                                <td class="td-center td-gradient">
                                    <b>Aktion</b></td>
                            </tr>';

    for ($i = 0; $i < $building_count; $i++) {
        $show_building = true;
        $building_dependencies = $buildings[$i]->get_building_dependencies();

        foreach ($building_dependencies as $dependency) {
            if (!$show_building) {
                break;
            }

            if ($dependency["dependencylevel"] > $buildings[$dependency["dependencyid"]]->get_building_level()) {
                $show_building = false;
            }
        }

        $level = $buildings[$i]->get_building_level();

        if ($level < MAX_BUILDING_LEVEL) {
            if ($show_building) {
                if (!is_numeric($level)) {
                    $level = "0";
                }

                $costs = $buildings[$i]->calculate_building_cost();
                $cost_wood = $costs["cost_wood"];
                $cost_food = $costs["cost_food"];
                $cost_stone = $costs["cost_stone"];
                $cost_gold = $costs["cost_gold"];

                $text_wood = get_resource_text($cost_wood, $kingdom_wood);
                $text_food = get_resource_text($cost_food, $kingdom_food);
                $text_stone = get_resource_text($cost_stone, $kingdom_stone);
                $text_gold = get_resource_text($cost_gold, $kingdom_gold);
                $text_build = "";

                if ($kingdom_is_building) {
                    if ($kingdom_building_id == $i) {
                        $result = $db_instance->execute_query("SELECT buildingtime FROM events WHERE kingdomid = ? AND buildingid = ? AND actionid = ?",
                            [$current_kingdom, $i, ActionTypes::ACTION_BUILD_BUILDING]);
                        $row = $result->fetch_assoc();

                        $difference_time = $row["buildingtime"] - time();

                        $text_build = "Bauzeit:<br><b><span id='counter'></b><br>
                                      <script type='text/javascript'>
                                            document.addEventListener('DOMContentLoaded', function () {
                                                  let diff = $difference_time;
                                                  startCountdown(undefined, diff || 0, 0, 'cancel-form');
                                            });
                                      </script>
                                      <form id='cancel-form' action='towncenter.php' method='GET'>
                                        <input type='hidden' name='action' value='cancel'>
                                        <input type='hidden' name='bid' value='" . $i . "'>
                                        <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                                      </form>";
                    } else {
                        $text_build = "-";
                    }
                } else {
                    $is_tc_limit_reached = ($i != BuildingTypes::BUILDING_TOWNCENTER && ($level + 1) > $tc_level);

                    $restriction_note = "";
                    if ($is_tc_limit_reached) {
                        $needed_tc = $level + 1;
                        $restriction_note = "<br><span class='error' style='font-size: 11px;'>Dorfzentrum Stufe $needed_tc benötigt</span>";
                    }

                    $res_disabled = $cost_wood > $kingdom_wood || $cost_food > $kingdom_food || $cost_stone > $kingdom_stone || $cost_gold > $kingdom_gold;
                    $is_disabled = ($res_disabled || $is_tc_limit_reached) ? "disabled" : "";

                    $text_build = "<form action='towncenter.php' method='GET'>
                                    <input type='hidden' name='action' value='build'>
                                    <input type='hidden' name='bid' value='" . $i . "'>
                                    <input type='submit' value='" . ($level > 0 ? "Upgrade" : "Bauen") . "' $is_disabled>
                                    $restriction_note
                                  </form>";
                }

                $view .= "<tr>
                    <td>
                        <div class='map-legend' style='justify-content: left;'>
                            <div class='legend-item'>" . $buildings[$i]->get_building_icon() . "</div>
                            <div class='legend-item'>
                                <b class='popup' id='description" . $i . "'>" . $buildings[$i]->get_building_name() . " (" . $buildings[$i]->get_building_level() . ")
                                    <div id='description" . $i . "_box' class='popupbox'>
                                        " . $buildings[$i]->get_building_description() . "
                                    </div>
                                </b>
                            </div>
                        </div>
                        <div class='map-legend' style='justify-content: left; margin-top: 10px; gap: 5px;'>
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . $text_food . "</div>
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . $text_wood . "</div>
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . $text_stone . "</div>
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . $text_gold . "</div>
                        </div>
                        <div class='map-legend' style='justify-content: left;'>
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_TIME) . " " . convert_sec_to_str($buildings[$i]->get_building_time() * ($level == 0 ? 1 : $level + 1)) . "</div>
                        </div>
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
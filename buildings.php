<?php
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$view = "";
$error = "";

// Get current kingdom stuff
$current_kingdom = $user->get_current_kingdom();
$kingdom = new Kingdoms($db_instance);
$kingdom->get_kingdom_info($current_kingdom);
$kingdom_wood = $kingdom->get_kingdom_wood();
$kingdom_food = $kingdom->get_kingdom_food();
$kingdom_stone = $kingdom->get_kingdom_stone();
$kingdom_gold = $kingdom->get_kingdom_gold();
$kingdom_villager = $kingdom->get_kingdom_villager();
$kingdom_is_building = false;
$kingdom_building_id = -1;

// Fetch all buildings and their dependencies
$buildings = fetch_all_buildings($current_kingdom);
$building_count = count($buildings);
$current_building = (empty($_GET["id"]) ? 0 : (int)$_GET["id"]);
$build_id = (empty($_GET["bid"]) ? 0 : (int)$_GET["bid"]);
$building_name = "";

// Soldier variables
$soldiers = [];
$soldiers_count = 0;

// Check if building is valid
if (isset($_GET["id"]) && ($current_building >= 0 && $current_building < $building_count)) {
    $building_name = $buildings[$current_building]->get_building_name();
}

// Handle current building logic and view
if ($current_building < BuildingTypes::BUILDING_TOWNCENTER || $current_building >= $building_count || !$buildings[$current_building]->is_built()) {
    change_location("buildings.php?id=0");
} else {
    switch ($current_building) {
        case BuildingTypes::BUILDING_TOWNCENTER:
            if (isset($_GET["action"])) {
                $kingdom_is_building = $kingdom->is_kingdom_building($current_kingdom);

                if ($kingdom_is_building) {
                    $kingdom_building_id = $kingdom->get_kingdom_building_id();
                }

                if ($build_id >= BuildingTypes::BUILDING_TOWNCENTER && $build_id < $building_count) {
                    $building_level = $buildings[$build_id]->get_building_level();
                    $costs = $buildings[$build_id]->calculate_building_cost();
                    $cost_wood = $costs["costWood"];
                    $cost_food = $costs["costFood"];
                    $cost_stone = $costs["costStone"];
                    $cost_gold = $costs["costGold"];

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
                                    $building_dependencies = $buildings[$build_id]->get_building_dependencies();

                                    foreach ($building_dependencies as $dependency) {
                                        if ($dependency["dependencylevel"] > $buildings[$dependency["dependencyid"]]->get_building_level()) {
                                            $error .= $buildings[$build_id]->get_building_name() . " setzt " . $buildings[$dependency["dependencyid"]]->get_building_name() . " Stufe " . $dependency["dependencylevel"] . " voraus!<br>";
                                        }
                                    }

                                    // Dependency check passed - build/upgrade building!
                                    if (empty($error)) {
                                        $building_time = time() + $buildings[$build_id]->get_building_time() * ($building_level == 0 ? 1 : $building_level + 1);

                                        // Subtract building costs from kingdom resources
                                        $kingdom->give_kingdom_wood($current_kingdom, -$cost_wood);
                                        $kingdom->give_kingdom_food($current_kingdom, -$cost_food);
                                        $kingdom->give_kingdom_stone($current_kingdom, -$cost_stone);
                                        $kingdom->give_kingdom_gold($current_kingdom, -$cost_gold);

                                        $db_instance->query("INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname) 
                                                    VALUES('" . ACTION_BUILD_BUILDING . "', '{$user->get_user_id()}', '$current_kingdom', '$build_id', '$building_time', '{$buildings[$build_id]->get_building_level()}', '{$buildings[$build_id]->get_building_name()}');");
                                    }
                                }
                            }
                        }
                    } else if ($_GET["action"] == "cancel") { // The action that was set is "cancel building"
                        if ($kingdom_is_building) {
                            $db_instance->query("DELETE FROM events WHERE userid = '{$user->get_user_id()}' AND buildingid = '$build_id'");

                            // Refund the player
                            $kingdom->give_kingdom_wood($current_kingdom, $cost_wood);
                            $kingdom->give_kingdom_food($current_kingdom, $cost_food);
                            $kingdom->give_kingdom_stone($current_kingdom, $cost_stone);
                            $kingdom->give_kingdom_gold($current_kingdom, $cost_gold);
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

                $view .= "<div class='info-box'><p><span class='event-finished'>Bau abgeschlossen:</span> $built_building_name (" . $built_building_level . " → " . ($built_building_level + 1) . ")</p></div>";

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
                            <tr>
                                <td class="td-center td-gradient" colspan="2">
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
                            $cost_wood = $costs["costWood"];
                            $cost_food = $costs["costFood"];
                            $cost_stone = $costs["costStone"];
                            $cost_gold = $costs["costGold"];

                            $text_wood = $buildings[$i]->get_resource_text($cost_wood, $kingdom_wood);
                            $text_food = $buildings[$i]->get_resource_text($cost_food, $kingdom_food);
                            $text_stone = $buildings[$i]->get_resource_text($cost_stone, $kingdom_stone);
                            $text_gold = $buildings[$i]->get_resource_text($cost_gold, $kingdom_gold);
                            $text_build = "";

                            if ($kingdom_is_building) {
                                if ($kingdom_building_id == $i) {
                                    $result = $db_instance->execute_query("SELECT buildingtime FROM events WHERE kingdomid = ? AND buildingid = ? AND actionid = ?", [$current_kingdom, $i, ACTION_BUILD_BUILDING]);
                                    $row = $result->fetch_assoc();

                                    $difference_time = $row["buildingtime"] - time();

                                    $text_build = "<b><span id='counter'></b><br>
                                                                      <script type='text/javascript'>
                                                                            document.addEventListener('DOMContentLoaded', function () {
                                                                                  let diff = $difference_time;
                                                                                  startCountdown(diff || 0);
                                                                            });
                                                                      </script>
                                                                      <form action='buildings.php' method='GET'>
                                                                        <input type='hidden' name='id' value='0'>
                                                                        <input type='hidden' name='action' value='cancel'>
                                                                        <input type='hidden' name='bid' value='" . $i . "'>
                                                                        <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                                                                      </form>";
                                } else {
                                    $text_build = "-";
                                }
                            } else {
                                if ($cost_wood > $kingdom_wood || $cost_food > $kingdom_food || $cost_stone > $kingdom_stone || $cost_gold > $kingdom_gold) {
                                    $text_build = "Nicht genug Rohstoffe!";
                                } else {
                                    $text_build = "<form action='buildings.php' method='GET'>
                                                                            <input type='hidden' name='id' value='0'>
                                                                            <input type='hidden' name='action' value='build'>
                                                                            <input type='hidden' name='bid' value='" . $i . "'>
                                                                            <input type='submit' value='" . ($level > 0 ? "Upgrade" : "Bauen") . "'>
                                                                          </form>";
                                }
                            }

                            $view .= "<tr><td class='td-center' style='width: 10%;'>" . $buildings[$i]->get_building_icon() . "</td>
                                            <td style='width: 40%;'><b>" . $buildings[$i]->get_building_name() . " ($level)</b><br><br>
                                            <img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz' title='Holz'/> " . $text_wood . "   <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung' title='Nahrung'/> " . $text_food . "<br>
                                            <img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein' title='Stein'/> " . $text_stone . "    <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold' title='Gold'/> " . $text_gold . "<br>
                                            <img src='images/icons/icon_hammer.png' class='ressource-icons' alt='Bauzeit' title='Bauzeit'/> " . convert_sec_to_str($buildings[$i]->get_building_time() * ($level == 0 ? 1 : $level + 1)) . "<br></td><td class='td-center' style='width: 40%;'>" . $text_build . "</td></tr>";
                        }
                    }
                }
                $view .= "</table>";
            }
            break;
        case BuildingTypes::BUILDING_UNIVERSITY:
            break;
        case BuildingTypes::BUILDING_BARRACKS:
            $result = $db_instance->execute_query("SELECT * FROM soldierlist");

            foreach ($result as $row) {
                $soldier = new Soldier();
                $soldier->set_soldier_id($row["id"]);
                $soldier->set_soldier_name($row["soldiername"]);
                $soldier->set_soldier_description($row["description"]);
                $soldier->set_soldier_attack($row["attack"]);
                $soldier->set_soldier_defense($row["defense"]);
                $soldier->set_soldier_food_cost($row["food"]);
                $soldier->set_soldier_gold_cost($row["gold"]);
                $soldier->set_soldier_villager_cost($row["villager"]);
                $soldier->set_soldier_required_level($row["requiredlevel"]);
                $soldier->set_soldier_time($row["requiredtime"]);
                $soldier->set_soldier_score_gain($row["scoregain"]);

                $soldiers[] = $soldier;
            }

            $soldiers_count = count($soldiers);
            $current_kingdom = $user->get_current_kingdom();
            $s_id = (empty($_GET["recruit"]) ? 0 : $_GET["recruit"]);

            $kingdom_recruiting_id = -1;
            $error = null;

            $kingdom_is_recruiting = $kingdom->is_kingdom_recruiting($current_kingdom);
            if ($kingdom_is_recruiting) {
                $kingdom_recruiting_id = $kingdom->get_kingdom_recruiting_id();
            }

            if (isset($_GET["recruit"]) && isset($_GET["count"])) {
                if ($_GET["count"] == "cancel") {
                    if ($kingdom_is_recruiting) {
                        // Calculate remaining soldiers to be recruited and resulting refunds
                        $result = $db_instance->execute_query("SELECT soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?", [$current_kingdom, ACTION_BUILD_TROOPS, $s_id]);
                        $soldier_goal = $result->fetch_assoc()['soldiergoal'];

                        // Refund player
                        $kingdom->give_kingdom_food($current_kingdom, $soldier_goal * $soldiers[$s_id]->get_soldier_food_cost());
                        $kingdom->give_kingdom_gold($current_kingdom, $soldier_goal * $soldiers[$s_id]->get_soldier_gold_cost());

                        // Delete the job
                        $db_instance->execute_query("DELETE FROM events WHERE userid = ? AND soldierid = ? AND kingdomid = ?", [$user->get_user_id(), $s_id, $current_kingdom]);
                    } else {
                        $error = "Du rekrutierst gerade nicht!";
                    }
                } else {
                    if ($kingdom_is_recruiting) {
                        $error = "Du bist bereits am Rekrutieren!";
                    } else if (!is_numeric($_GET["count"]) || $_GET["count"] < 1) {
                        $error = "Keine Angabe der Anzahl!";
                    } else if ($_GET["count"] > 99) {
                        $error = "Maximale Anzahl beträgt 99!";
                    } else if ($_GET["recruit"] < 0 || $_GET["recruit"] > $soldiers_count) {
                        $error = "Diese Einheit existiert nicht!";
                    } else {
                        $cost_food = $soldiers[$s_id]->get_soldier_food_cost() * $_GET["count"];
                        $cost_gold = $soldiers[$s_id]->get_soldier_gold_cost() * $_GET["count"];
                        $cost_villager = $soldiers[$s_id]->get_soldier_villager_cost() * $_GET["count"];

                        if ($cost_food > $kingdom_food) {
                            $error = "Nicht genug Nahrung!";
                        } else if ($cost_gold > $kingdom_gold) {
                            $error = "Nicht genug Gold!";
                        } else if ($cost_villager > $kingdom_villager) {
                            $error = "Nicht genug Dorfbewohner!";
                        } else {
                            $current_time = time();
                            $recruiting_time = $current_time + $soldiers[$s_id]->get_soldier_time() * $_GET["count"];

                            $query = "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname, soldierid, recruittime, soldiergoal) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $db_instance->execute_query($query, [ACTION_BUILD_TROOPS, $user->get_user_id(), $current_kingdom, '0', $current_time, '0', '-', $s_id, $recruiting_time, $_GET["count"]]);

                            // Subtract values for food and gold
                            $kingdom->give_kingdom_food($current_kingdom, -$cost_food);
                            $kingdom->give_kingdom_gold($current_kingdom, -$cost_gold);
                        }
                    }
                }
            }

            /*
             * HTML Content Part
             */
            $kingdom_food = $kingdom->get_kingdom_food();
            $kingdom_gold = $kingdom->get_kingdom_gold();
            $kingdom_villager = $kingdom->get_kingdom_villager();
            $last_recruited_soldier = $user->get_last_recruited_soldier($current_kingdom);

            if (!empty($last_recruited_soldier)) {
                $soldier_name = $last_recruited_soldier["soldiername"];
                $soldier_count = $last_recruited_soldier["soldiercount"];

                $view .= "<div class='info-box'><p><span class='event-finished'>Ausbildung abgeschlossen:</span> $soldier_name (+$soldier_count)</p></div>";

                $user->clear_last_recruited_soldier($current_kingdom);
            }
            $view .= '<table class="table">
                        <tr>
                            <td class="td-center td-gradient" colspan="2">
                                <b>Soldat</b></td>
                            <td class="td-center td-gradient">
                                <b>Aktion</b></td>
                        </tr>';
            $kingdom_is_recruiting = $kingdom->is_kingdom_recruiting($current_kingdom);

            if ($kingdom_is_recruiting) {
                $kingdom_recruiting_id = $kingdom->get_kingdom_recruiting_id();
            }

            // Get soldiers of kingdom
            $result = $db_instance->execute_query("SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?", [$current_kingdom]);

            foreach ($result as $row) {
                $soldier_id = $row['soldierid'] ?? -1;
                $sol_count = $row['soldiercount'] ?? 0;
                $kingdom_soldiers[$soldier_id] = $sol_count;
            }

            for ($i = 0; $i < $soldiers_count; $i++) {
                $cost_food = $soldiers[$i]->get_soldier_food_cost();
                $cost_gold = $soldiers[$i]->get_soldier_gold_cost();
                $cost_villager = $soldiers[$i]->get_soldier_villager_cost();

                $text_food = ($cost_food > $kingdom_food ? "<b class='error'>" . fnum($cost_food) . "</b>" : fnum($cost_food));
                $text_gold = ($cost_gold > $kingdom_gold ? "<b class='error'>" . fnum($cost_gold) . "</b>" : fnum($cost_gold));
                $text_villager = ($cost_villager > $kingdom_villager ? "<b class='error'>" . fnum($cost_villager) . "</b>" : fnum($cost_villager));

                if ($kingdom_is_recruiting) {
                    if ($kingdom_recruiting_id == $i) {
                        $result = $db_instance->execute_query("SELECT recruittime, soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?", [$current_kingdom, ACTION_BUILD_TROOPS, $i]);
                        $row = $result->fetch_assoc();
                        $recruit_time = $row["recruittime"];
                        $soldier_goal = $row["soldiergoal"];
                        $soldier_time = $soldiers[$i]->get_soldier_time();
                        $current_time = time();
                        $total_difference = $recruit_time - $current_time;
                        $remaining_time_in_seconds = max(0, $total_difference % $soldier_time);

                        // Job was just started
                        if ($remaining_time_in_seconds == 0) {
                            $remaining_time_in_seconds = $soldiers[$i]->get_soldier_time();
                        }

                        $text_build = "In Ausbildung: " . $soldier_goal . "<br><br><b><span id='counter'></span></b><br> 
                                                                      <script type='text/javascript'>
                                                                            document.addEventListener('DOMContentLoaded', function () {
                                                                                  let diff = $remaining_time_in_seconds;
                                                                                  startCountdown(diff || 0);
                                                                            });
                                                                      </script>
                                                                      <form action='buildings.php' method='GET'>
                                                                        <input type='hidden' name='id' value='2'>
                                                                        <input type='hidden' name='recruit' value='" . $i . "'>
                                                                        <input type='hidden' name='count' value='cancel'>
                                                                        <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                                                                      </form>";
                    } else {
                        $text_build = "-";
                    }
                } else {
                    if ($cost_food > $kingdom_food || $cost_gold > $kingdom_gold) {
                        $text_build = "Nicht genug Rohstoffe!";
                    } else if ($kingdom_villager < $cost_villager) {
                        $text_build = "Nicht genug Dorfbewohner!";
                    } else {
                        // Calculate the maximum soldiers recruitable based on each resource
                        $food_cost_per_soldier = $soldiers[$i]->get_soldier_food_cost();
                        $gold_cost_per_soldier = $soldiers[$i]->get_soldier_gold_cost();
                        $villager_cost_per_soldier = $soldiers[$i]->get_soldier_villager_cost();
                        $max_soldiers_food = floor($kingdom_food / $food_cost_per_soldier);
                        $max_soldiers_gold = floor($kingdom_gold / $gold_cost_per_soldier);
                        $max_soldiers_villagers = floor($kingdom_villager / $villager_cost_per_soldier);
                        $max_recruit_val = min($max_soldiers_food, $max_soldiers_gold, $max_soldiers_villagers);
                        $max_soldiers = min($max_recruit_val, 99);

                        $text_build = "<form action='buildings.php?' method='GET'>
                                                                            <input type='hidden' name='id' value='2'>
                                                                            <input type='hidden' name='recruit' value='" . $i . "'>
                                                                            <input type='text' name='count' id='count" . $i . "' size='2' maxlength='2'>
                                                                            <input type='button' value='Max.' onclick='fillMax(\"" . $i . "\", \"" . $max_soldiers . "\")'><br>
                                                                            <input type='submit' value='Ausbilden' style='margin-top: 10px'>
                                                                        </form>
                                                                        <script>
                                                                            function fillMax(i, maxValue) {
                                                                                document.getElementById('count' + i).value = maxValue;
                                                                                return false;
                                                                            }
                                                                        </script>";
                    }
                }

                $view .= "<tr>
                                                    <td class='td-center' style='width: 10%;'>" . $soldiers[$i]->get_soldier_icon() . "</td>
                                                    <td style='width: 40%;'><b class='popup' id='description" . $i . "'>" . $soldiers[$i]->get_soldier_name() . " 
                                                        <div id='description" . $i . "_box' class='popupbox'>" . $soldiers[$i]->get_soldier_description() . "</div>  (" . (isset($kingdom_soldiers[$i]) ? fnum($kingdom_soldiers[$i]) : 0) . ")</b><br><br>
                                                        <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung' title='Nahrung'/> " . $text_food . "
                                                        <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold' title='Gold'/> " . $text_gold . "
                                                        <img src='images/icons/icon_villager.png' class='ressource-icons' alt='Dorfbewohner' title='Dorfbewohner'/> " . $text_villager . "<br>
                                                        <img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff' title='Angriff'/> " . $soldiers[$i]->get_soldier_attack() . " 
                                                        <img src='images/icons/icon_shield.png' class='ressource-icons' alt='Verteidigung' title='Verteidigung'/> " . $soldiers[$i]->get_soldier_defense() . "<br>
                                                        <img src='images/icons/icon_time.png' class='ressource-icons' alt='Rekrutierzeit' title='Rekrutierzeit'/> " . convert_sec_to_str($soldiers[$i]->get_soldier_time()) . "
                                                        <br></td>
                                                    <td class='td-center' style='width: 40%;'>$text_build</td>
                                                </tr>";
            }
            $view .= '</table>';
            break;
        case BuildingTypes::BUILDING_WALL:
            $level = $buildings[BuildingTypes::BUILDING_WALL]->get_building_level() * DEFAULT_WALL_HP;

            /*
             * HTML Content Part
             */
            $view .= "<b>Verteidigungswert:</b> " . fnum($level);
            break;
        case BuildingTypes::BUILDING_SMITHY:
            // Schmiede
            /*
             * HTML Content Part
             */
            break;
        case BuildingTypes::BUILDING_MILL:
            /*
             * HTML Content Part
             */
            $view .= "<b>Nahrungsertrag pro Stunde:</b> " . fnum($kingdom->get_kingdom_food_per_hour());
            break;
        case BuildingTypes::BUILDING_SAWMILL:
            /*
             * HTML Content Part
             */
            $view .= "<b>Holzertrag pro Stunde:</b> " . fnum($kingdom->get_kingdom_wood_per_hour());
            break;
        case BuildingTypes::BUILDING_STONEMINE:
            /*
             * HTML Content Part
             */
            $view .= "<b>Steinertrag pro Stunde:</b> " . fnum($kingdom->get_kingdom_stone_per_hour());
            break;
        case BuildingTypes::BUILDING_GOLDMINE:
            /*
             * HTML Content Part
             */
            $view .= "<b>Goldertrag pro Stunde:</b> " . fnum($kingdom->get_kingdom_gold_per_hour());
            break;
        case BuildingTypes::BUILDING_STORAGE:
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
            break;
        case BuildingTypes::BUILDING_MARKETPLACE:
            if (isset($_GET["accept"])) {
                $result = $db_instance->execute_query("SELECT username, kingdomid, supply, supplyvalue, demand, demandvalue FROM marketplace WHERE offerid = ?", [$_GET["accept"]]);
                $row = $result->fetch_assoc();

                if ($row && $current_kingdom != $row["kingdomid"]) {
                    $supply = $row["supply"];
                    $supply_value = $row["supplyvalue"];
                    $demand = $row["demand"];
                    $demand_value = $row["demandvalue"];

                    // Check if kingdom has enough resources to handle the trade
                    if ($demand == 0 && $kingdom->get_kingdom_food() < $demand_value) {
                        $error = "Soviel Nahrung kannst du nicht aufbringen!";
                    } else if ($demand == 1 && $kingdom->get_kingdom_wood() < $demand_value) {
                        $error = "Soviel Holz kannst du nicht aufbringen!";
                    } else if ($demand == 2 && $kingdom->get_kingdom_stone() < $demand_value) {
                        $error = "Soviel Stein kannst du nicht aufbringen!";
                    } else if ($demand == 3 && $kingdom->get_kingdom_gold() < $demand_value) {
                        $error = "Soviel Gold kannst du nicht aufbringen!";
                    } else {
                        $other_kingdom = $row["kingdomid"];
                        $supply_resource = "";
                        $demand_resource = "";

                        // Give both kingdoms the respective resources
                        switch ($supply) {
                            case 0:
                                $kingdom->give_kingdom_food($current_kingdom, $supply_value);
                                $supply_resource = "Nahrung";
                                break;
                            case 1:
                                $kingdom->give_kingdom_wood($current_kingdom, $supply_value);
                                $supply_resource = "Holz";
                                break;
                            case 2:
                                $kingdom->give_kingdom_stone($current_kingdom, $supply_value);
                                $supply_resource = "Stein";
                                break;
                            case 3:
                                $kingdom->give_kingdom_gold($current_kingdom, $supply_value);
                                $supply_resource = "Gold";
                                break;
                        }
                        switch ($demand) {
                            case 0:
                                $kingdom->give_kingdom_food($current_kingdom, -$demand_value);
                                $kingdom->give_kingdom_food($other_kingdom, $demand_value);
                                $demand_resource = "Nahrung";
                                break;
                            case 1:
                                $kingdom->give_kingdom_wood($current_kingdom, -$demand_value);
                                $kingdom->give_kingdom_wood($other_kingdom, $demand_value);
                                $demand_resource = "Holz";
                                break;
                            case 2:
                                $kingdom->give_kingdom_stone($current_kingdom, -$demand_value);
                                $kingdom->give_kingdom_stone($other_kingdom, $demand_value);
                                $demand_resource = "Stein";
                                break;
                            case 3:
                                $kingdom->give_kingdom_gold($current_kingdom, -$demand_value);
                                $kingdom->give_kingdom_gold($other_kingdom, $demand_value);
                                $demand_resource = "Gold";
                                break;
                        }

                        // Delete the marketplace offer
                        $db_instance->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$_GET["accept"]]);

                        //TODO: Send a message to the other kingdom that the offer has been accepted
                    }
                } else {
                    $error = "Dieses Angebot existiert nicht oder ist von deinem Königreich!";
                }
            } else if (isset($_GET["delete"])) {
                $result = $db_instance->execute_query("SELECT supply, supplyvalue FROM marketplace WHERE offerid = ? AND kingdomid = ?", [$_GET["delete"], $current_kingdom]);
                $row = $result->fetch_assoc();

                if ($row) {
                    $supply = $row["supply"];
                    $supply_value = $row["supplyvalue"];

                    // Give supply ressources back to kingdom
                    switch ($supply) {
                        case 0:
                            $kingdom->give_kingdom_food($current_kingdom, $supply_value);
                            break;
                        case 1:
                            $kingdom->give_kingdom_wood($current_kingdom, $supply_value);
                            break;
                        case 2:
                            $kingdom->give_kingdom_stone($current_kingdom, $supply_value);
                            break;
                        case 3:
                            $kingdom->give_kingdom_gold($current_kingdom, $supply_value);
                            break;
                    }

                    // Delete the marketplace offer
                    $db_instance->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$_GET["delete"]]);
                } else {
                    $error = "Dieses Angebot existiert nicht oder ist nicht von deinem aktuellen Königreich!";
                }
            } else if (!empty($_GET["sv"]) && !empty($_GET["dv"])) {
                if ($_GET["s"] < 0 || $_GET["s"] > 3 || $_GET["d"] < 0 || $_GET["d"] > 3) {
                    $error = "Diese Ressource gibt es nicht!";
                } else if ($_GET["s"] == $_GET["d"]) {
                    $error = "Die Ressourcentypen dürfen nicht gleich sein!";
                } else {
                    if ($_GET["sv"] <= 0 || !is_numeric($_GET["sv"]) || $_GET["dv"] <= 0 || !is_numeric($_GET["dv"]) || $_GET["sv"] > 99999 || $_GET["dv"] > 99999) {
                        $error = "Die Werte müssen zwischen 1 und 99999 liegen!";
                    } else {
                        // Check if kingdom has enough ressources to handle the trade
                        if ($_GET["s"] == 0 && $kingdom->get_kingdom_food() < $_GET["sv"]) {
                            $error = "Soviel Nahrung kannst du nicht bieten!";
                        } else if ($_GET["s"] == 1 && $kingdom->get_kingdom_wood() < $_GET["sv"]) {
                            $error = "Soviel Holz kannst du nicht bieten!";
                        } else if ($_GET["s"] == 2 && $kingdom->get_kingdom_stone() < $_GET["sv"]) {
                            $error = "Soviel Stein kannst du nicht bieten!";
                        } else if ($_GET["s"] == 3 && $kingdom->get_kingdom_gold() < $_GET["sv"]) {
                            $error = "Soviel Gold kannst du nicht bieten!";
                        } else {
                            // Check if there is already an offer for this kingdom
                            $result = $db_instance->execute_query("SELECT offerid FROM marketplace WHERE kingdomid = ?", [$current_kingdom]);
                            $offer_id = $result->fetch_assoc()['offerid'] ?? 0;

                            if ($offer_id != 0) {
                                $error = "Du hast bereits ein Angebot für dieses Königreich am laufen!";
                            } else {
                                // No offer found for the kingdom - insert to database
                                $query = "INSERT INTO marketplace (userid, username, kingdomid, supply, supplyvalue, demand, demandvalue) VALUES(?, ?, ?, ?, ?, ?, ?);";
                                $result = $db_instance->execute_query($query, [$user->get_user_id(), $user->get_user_name(), $current_kingdom, $_GET["s"], $_GET["sv"], $_GET["d"], $_GET["dv"]]);

                                switch ($_GET["s"]) {
                                    case 0:
                                        $kingdom->give_kingdom_food($current_kingdom, -$_GET["sv"]);
                                        break;
                                    case 1:
                                        $kingdom->give_kingdom_wood($current_kingdom, -$_GET["sv"]);
                                        break;
                                    case 2:
                                        $kingdom->give_kingdom_stone($current_kingdom, -$_GET["sv"]);
                                        break;
                                    case 3:
                                        $kingdom->give_kingdom_gold($current_kingdom, -$_GET["sv"]);
                                        break;
                                }
                            }
                        }
                    }
                }
            }

            /*
             * HTML Content Part
             */
            $view .= '<table class="table">
                        <form action="buildings.php?" method="GET">
                            <input type="hidden" name="id" value="10">
                            <tr>
                                <td>
                                    <label for="sv">Ich biete:</label>
                                    <input type="text"
                                           name="sv"
                                           id="sv"
                                           size="5"
                                           maxlength="5">
                                    <label>
                                        <select name="s">
                                            <option value="0">Nahrung</option>
                                            <option value="1">Holz</option>
                                            <option value="2">Stein</option>
                                            <option value="3">Gold</option>
                                        </select>
                                    </label>
                                </td>
                                <td>
                                    <label for="dv">Ich suche:</label>
                                    <input type="text"
                                           name="dv"
                                           id="dv"
                                           size="5"
                                           maxlength="5">
                                    <label>
                                        <select name="d">
                                            <option value="0">Nahrung</option>
                                            <option value="1">Holz</option>
                                            <option value="2">Stein</option>
                                            <option value="3">Gold</option>
                                        </select>
                                    </label>
                                </td>
                                <td>
                                    <input type="submit" value="Abschicken">
                                </td>
                            </tr>
                        </form>
                    </table>
                    <br>
                    <br>
                    <table class="table" style="word-break: break-word">
                        <tr>
                            <td class="td-center td-gradient">
                                <b>Spieler</b>
                            </td>
                            <td class="td-center td-gradient">
                                <b>Königreich</b>
                            </td>
                            <td class="td-center td-gradient">
                                <b>Bietet</b>
                            </td>
                            <td class="td-center td-gradient">
                                <b>Benötigt</b>
                            </td>
                            <td class="td-center td-gradient">
                                <b>Aktion</b>
                            </td>
                        </tr>';

            $resource_icon = array(
                "<img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung' title='Nahrung'/>",
                "<img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz' title='Holz'/>",
                "<img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein' title='Stein'/>",
                "<img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold' title='Gold'/>"
            );

            $view .= "<p>Aktuelle Angebote</p>";

            $query = "
                                                    SELECT m.*, k.kingdomname 
                                                    FROM marketplace m 
                                                    LEFT JOIN kingdoms k 
                                                    ON m.kingdomid = k.id
                                        ";
            $result = $db_instance->execute_query($query);

            foreach ($result as $row) {
                $kingdom_name = $row['kingdomname'];

                $action = ($row["kingdomid"] == $current_kingdom) ? "Löschen" : "Annehmen";
                $param = ($row["kingdomid"] == $current_kingdom) ? "delete" : "accept";

                $text_build = "<form action='buildings.php' method='GET'>
                                                                    <input type='hidden' name='id' value='10'>
                                                                    <input type='hidden' name='$param' value='" . $row["offerid"] . "'>
                                                                    <input type='submit' value='$action'>
                                                                </form>";

                $view .= "<tr><td>{$row["username"]}</td>
                                                            <td>$kingdom_name</td>
                                                            <td class='td-center'>{$resource_icon[$row["supply"]]} " . fnum($row["supplyvalue"]) . "</td>
                                                            <td class='td-center'>{$resource_icon[$row["demand"]]} " . fnum($row["demandvalue"]) . "</td>
                                                            <td class='td-center'>$text_build</td>";
            }
            $view .= '</table>';
            break;
    }
}

/*
 * HTML Section
 */
$title = $building_name;
$header = empty($building_name) ? "Fehler" : $building_name . " (" . $buildings[$current_building]->get_building_level() . ")";
$script_files = ["counter"];

if (!empty($error)) {
    $view = "<div class='info-box'>" . $error . "</div>" . $view;
}

include('layout/base.php');
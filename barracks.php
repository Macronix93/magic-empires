<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_BARRACKS);

$current_kingdom = $result["current_kingdom"];
$building = $result["building"];
$building_name = $building->get_building_name();
$kingdom = $result["kingdom"];

$kingdom_food = $kingdom->get_kingdom_food();
$kingdom_gold = $kingdom->get_kingdom_gold();
$kingdom_stone = $kingdom->get_kingdom_stone();
$kingdom_wood = $kingdom->get_kingdom_wood();
$kingdom_villager = $kingdom->get_kingdom_villager();
$s_id = (empty($_GET["recruit"]) ? 0 : $_GET["recruit"]);
$kingdom_recruiting_id = -1;
$kingdom_is_recruiting = $kingdom->is_kingdom_recruiting($current_kingdom);

if ($kingdom_is_recruiting) {
    $kingdom_recruiting_id = $kingdom->get_kingdom_recruiting_id();
}

// Get all soldier types from the database
$result = $db_instance->execute_query("SELECT * FROM soldierlist");

foreach ($result as $row) {
    $soldier = new Soldier();
    $soldier->fill_from_row($row);

    $soldiers[] = $soldier;
    $kingdom_soldiers[$soldier->get_soldier_id()] = 0;
}

$soldiers_count = count($soldiers);

if (isset($_GET["recruit"]) && isset($_GET["count"])) {
    if ($_GET["count"] == "cancel") {
        if ($kingdom_is_recruiting) {
            // Calculate remaining soldiers to be recruited and resulting refunds
            $result = $db_instance->execute_query("SELECT soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?",
                [$current_kingdom, ActionTypes::ACTION_BUILD_TROOPS, $s_id]);
            $soldier_goal = $result->fetch_assoc()["soldiergoal"];

            // Refund player
            $kingdom->give_kingdom_food($soldier_goal * $soldiers[$s_id]->get_soldier_food_cost());
            $kingdom->give_kingdom_gold($soldier_goal * $soldiers[$s_id]->get_soldier_gold_cost());
            $kingdom->give_kingdom_wood($soldier_goal * $soldiers[$s_id]->get_soldier_wood_cost());
            $kingdom->give_kingdom_stone($soldier_goal * $soldiers[$s_id]->get_soldier_stone_cost());

            // Delete the job
            $db_instance->execute_query("DELETE FROM events WHERE userid = ? AND soldierid = ? AND kingdomid = ?",
                [$user->get_user_id(), $s_id, $current_kingdom]);

            $logger->log_game("ECONOMY", "RECRUIT_CANCEL", [
                "soldier_name" => $soldiers[$s_id]->get_soldier_name(),
                "amount_cancelled" => $soldier_goal
            ], $current_kingdom);
        } else {
            $error = "Du rekrutierst gerade nicht!";
        }
    } else {
        if ($kingdom_is_recruiting) {
            $error = "Du bist bereits am Rekrutieren!";
        } else if (!is_numeric($_GET["count"]) || $_GET["count"] < 1) {
            $error = "Keine Angabe der Anzahl!";
        } else if ($_GET["count"] > MAX_SOLDIERS_RECRUIT_INPUT) {
            $error = "Maximale Anzahl beträgt " . MAX_SOLDIERS_RECRUIT_INPUT . "!";
        } else if ($_GET["recruit"] < 0 || $_GET["recruit"] > $soldiers_count) {
            $error = "Diese Einheit existiert nicht!";
        } else if ($soldiers[$s_id]->get_soldier_required_level() > $building->get_building_level()) {
            $error = "Deine Kaserne hat eine zu niedrige Stufe für diese Einheit!";
        } else {
            $cost_food = $soldiers[$s_id]->get_soldier_food_cost() * $_GET["count"];
            $cost_gold = $soldiers[$s_id]->get_soldier_gold_cost() * $_GET["count"];
            $cost_stone = $soldiers[$s_id]->get_soldier_stone_cost() * $_GET["count"];
            $cost_wood = $soldiers[$s_id]->get_soldier_wood_cost() * $_GET["count"];
            $cost_villager = $soldiers[$s_id]->get_soldier_villager_cost() * $_GET["count"];

            if ($cost_food > $kingdom_food) {
                $error = "Nicht genug Nahrung!";
            } else if ($cost_gold > $kingdom_gold) {
                $error = "Nicht genug Gold!";
            } else if ($cost_stone > $kingdom_stone) {
                $error = "Nicht genug Stein!";
            } else if ($cost_wood > $kingdom_wood) {
                $error = "Nicht genug Holz!";
            } else if ($cost_villager > $kingdom_villager) {
                $error = "Nicht genug Dorfbewohner!";
            } else {
                $current_time = time();
                $recruiting_time = $current_time + $soldiers[$s_id]->get_soldier_time() * $_GET["count"];

                $query = "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname, soldierid, recruittime, soldiergoal) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $db_instance->execute_query($query,
                    [ActionTypes::ACTION_BUILD_TROOPS, $user->get_user_id(), $current_kingdom, '0', $current_time, '0', '-', $s_id, $recruiting_time, $_GET["count"]]);

                // Subtract values for food and gold
                $kingdom->give_kingdom_food(-$cost_food);
                $kingdom->give_kingdom_gold(-$cost_gold);
                $kingdom->give_kingdom_stone(-$cost_stone);
                $kingdom->give_kingdom_wood(-$cost_wood);
            }
        }
    }
}

/*
 * HTML Content Part
 */
$kingdom_food = $kingdom->get_kingdom_food();
$kingdom_gold = $kingdom->get_kingdom_gold();
$kingdom_stone = $kingdom->get_kingdom_stone();
$kingdom_wood = $kingdom->get_kingdom_wood();
$kingdom_villager = $kingdom->get_kingdom_villager();
$last_recruited_soldier = $user->get_last_recruited_soldier($current_kingdom);

if (!empty($last_recruited_soldier)) {
    $soldier_name = $last_recruited_soldier["soldiername"];
    $soldier_count = $last_recruited_soldier["soldiercount"];

    $view .= show_weighted_box("$soldier_name (+$soldier_count)", "Ausbildung abgeschlossen:");

    $user->clear_last_recruited_soldier($current_kingdom);
}
$view .= '<table class="table">
                        <colgroup>
                            <col style="width: auto;">
                            <col style="width: 180px;">
                        </colgroup>
                        <tr>
                            <td class="td-center td-gradient">
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
    if ($soldiers[$i]->get_soldier_required_level() > $building->get_building_level()) {
        continue;
    }

    $cost_food = $soldiers[$i]->get_soldier_food_cost();
    $cost_gold = $soldiers[$i]->get_soldier_gold_cost();
    $cost_stone = $soldiers[$i]->get_soldier_stone_cost();
    $cost_wood = $soldiers[$i]->get_soldier_wood_cost();
    $cost_villager = $soldiers[$i]->get_soldier_villager_cost();

    $text_food = ($cost_food > $kingdom_food ? "<b class='error'>" . fnum($cost_food) . "</b>" : fnum($cost_food));
    $text_gold = ($cost_gold > $kingdom_gold ? "<b class='error'>" . fnum($cost_gold) . "</b>" : fnum($cost_gold));
    $text_stone = ($cost_stone > 0 ? ($cost_stone > $kingdom_stone ? "<b class='error'>" . fnum($cost_stone) . "</b>" : fnum($cost_stone)) : "");
    $text_wood = ($cost_wood > 0 ? ($cost_wood > $kingdom_wood ? "<b class='error'>" . fnum($cost_wood) . "</b>" : fnum($cost_wood)) : "");
    $text_villager = ($cost_villager > $kingdom_villager ? "<b class='error'>" . fnum($cost_villager) . "</b>" : fnum($cost_villager));

    if ($kingdom_is_recruiting) {
        if ($kingdom_recruiting_id == $i) {
            $result = $db_instance->execute_query("SELECT recruittime, soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?",
                [$current_kingdom, ActionTypes::ACTION_BUILD_TROOPS, $i]);
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

            $text_build = "In Ausbildung: " . $soldier_goal . "<br><b><span id='counter'></span></b><br> 
                          <script type='text/javascript'>
                                document.addEventListener('DOMContentLoaded', function () {
                                      let diff = $remaining_time_in_seconds;
                                      startCountdown(undefined, diff || 0, 0, 'cancel-form');
                                });
                          </script>
                          <form id='cancel-form' action='barracks.php' method='GET'>
                            <input type='hidden' name='recruit' value='" . $i . "'>
                            <input type='hidden' name='count' value='cancel'>
                            <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                          </form>";
        } else {
            $text_build = "-";
        }
    } else {
        // Calculate the maximum soldiers recruitable based on each resource
        $max_soldiers = MAX_SOLDIERS_RECRUIT_INPUT;

        $food_cost = $soldiers[$i]->get_soldier_food_cost();
        $gold_cost = $soldiers[$i]->get_soldier_gold_cost();
        $stone_cost = $soldiers[$i]->get_soldier_stone_cost();
        $wood_cost = $soldiers[$i]->get_soldier_wood_cost();
        $vill_cost = $soldiers[$i]->get_soldier_villager_cost();

        if ($food_cost > 0) {
            $max_soldiers = min($max_soldiers, floor($kingdom_food / $food_cost));
        }
        if ($gold_cost > 0) {
            $max_soldiers = min($max_soldiers, floor($kingdom_gold / $gold_cost));
        }
        if ($stone_cost > 0) {
            $max_soldiers = min($max_soldiers, floor($kingdom_stone / $stone_cost));
        }
        if ($wood_cost > 0) {
            $max_soldiers = min($max_soldiers, floor($kingdom_wood / $wood_cost));
        }
        if ($vill_cost > 0) {
            $max_soldiers = min($max_soldiers, floor($kingdom_villager / $vill_cost));
        }

        $max_soldiers = max(0, $max_soldiers);

        $disabled = ($max_soldiers == 0) ? "disabled" : "";

        $text_build = "<form action='barracks.php' method='GET'>
                        <input type='hidden' name='recruit' value='" . e($i) . "'>
                        <input type='text' name='count' id='count" . e($i) . "' size='2' maxlength='2' $disabled>
                        <input type='button' value='Max.' 
                               data-on-click='fillMax' 
                               data-target='count" . e($i) . "' 
                               data-value='" . e($max_soldiers) . "' 
                               $disabled><br>
                        <input type='submit' value='Ausbilden' style='margin-top: 10px' $disabled>
                    </form>";
    }

    $view .= "<tr>
                    <td>
                        <div class='map-legend' style='justify-content: left;'>
                            <div class='legend-item'>" . $soldiers[$i]->get_soldier_icon() . "</div>
                            <div class='legend-item'>                        
                                <b class='popup' id='description" . $i . "'>" . $soldiers[$i]->get_soldier_name() . " 
                                    <div id='description" . $i . "_box' class='popupbox'>
                                        " . $soldiers[$i]->get_soldier_description() . "
                                    </div> (" . $kingdom_soldiers[$i] . ")
                                </b>
                            </div>
                        </div>
                        <div class='map-legend' style='justify-content: left; margin-top: 10px;'>
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . $text_food . "</div>
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . $text_gold . "</div>
                            " . ($cost_stone > 0 ? "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . $text_stone . "</div>" : "") . "
                            " . ($cost_wood > 0 ? "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . $text_wood . "</div>" : "") . "
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_VILLAGER) . " " . $text_villager . "</div>
                        </div>
                        <div class='map-legend' style='justify-content: left;'>
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " " . $soldiers[$i]->get_soldier_attack() . "</div>
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " " . $soldiers[$i]->get_soldier_defense() . "</div>
                        </div>
                        <div class='map-legend' style='justify-content: left;'>
                            <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME) . " " . convert_sec_to_str($soldiers[$i]->get_soldier_time()) . "</div>
                        </div>
                    </td>
                    <td class='td-center'>$text_build</td>
              </tr>";
}
$view .= '</table>';

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
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
$s_id = (isset($_GET["recruit"]) && is_numeric($_GET["recruit"])) ? (int)$_GET["recruit"] : -1;
$kingdom_recruiting_id = -1;
$kingdom_is_recruiting = $kingdom->is_kingdom_recruiting($current_kingdom);
$kingdom_is_upgrading = false;
$upgrade_event = null;

if ($kingdom_is_recruiting) {
    $kingdom_recruiting_id = $kingdom->get_kingdom_recruiting_id();
}

$res_upg = $db_instance->execute_query("SELECT * FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1",
    [$current_kingdom, ActionTypes::ACTION_UPGRADE_TROOPS]);

if ($res_upg->num_rows > 0) {
    $kingdom_is_upgrading = true;
    $upgrade_event = $res_upg->fetch_assoc();
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

// Standard soldier category
$active_cat = 0;

if (isset($_GET["recruit"]) && is_numeric($_GET["recruit"])) {
    $r_id = (int)$_GET["recruit"];

    if (isset($soldiers[$r_id])) {
        $active_cat = $soldiers[$r_id]->get_soldier_category();
    }
} else if (isset($_GET["cat"])) {
    $cat = (int)$_GET["cat"];

    if ($cat < 0 || $cat > SoldierTypes::SOLDIER_TYPE_SPECIAL) {
        $error = "Diese Kategorie gibt es nicht!";
    } else {
        $active_cat = (int)$_GET["cat"];
    }
} else if ($kingdom_is_recruiting) {
    if (isset($soldiers[$kingdom_recruiting_id])) {
        $active_cat = $soldiers[$kingdom_recruiting_id]->get_soldier_category();
    }
}

if (isset($_GET["recruit"]) && isset($_GET["count"])) {
    if (!is_numeric($_GET["recruit"])) {
        $error = "Ungültige Anfrage!";
    } else {
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
            } else if ($kingdom_is_upgrading && $upgrade_event["buildingid"] == $soldiers[$s_id]->get_soldier_id()) {
                $from_id = $upgrade_event["buildingid"];
                $to_id = $upgrade_event["soldierid"];
                $count = $upgrade_event["soldiergoal"];

                $diff_gold = ($soldiers[$to_id]->get_soldier_gold_cost() - $soldiers[$from_id]->get_soldier_gold_cost()) * $count;
                $diff_food = ($soldiers[$to_id]->get_soldier_food_cost() - $soldiers[$from_id]->get_soldier_food_cost()) * $count;
                $diff_wood = ($soldiers[$to_id]->get_soldier_wood_cost() - $soldiers[$from_id]->get_soldier_wood_cost()) * $count;
                $diff_stone = ($soldiers[$to_id]->get_soldier_stone_cost() - $soldiers[$from_id]->get_soldier_stone_cost()) * $count;

                $kingdom->give_kingdom_gold(max(0, $diff_gold));
                $kingdom->give_kingdom_food(max(0, $diff_food));
                $kingdom->give_kingdom_wood(max(0, $diff_wood));
                $kingdom->give_kingdom_stone(max(0, $diff_stone));

                // Give old troops back
                $db_instance->execute_query("UPDATE soldiers SET soldiercount = soldiercount + ? WHERE kingdomid = ? AND soldierid = ?",
                    [$count, $current_kingdom, $from_id]);

                $db_instance->execute_query("DELETE FROM events WHERE eventid = ?", [$upgrade_event["eventid"]]);

                change_location("barracks.php?cat=$active_cat");
                exit;
            } else {
                $error = "Du rekrutierst gerade nicht oder wertest nicht auf!";
            }
        } else {
            if ($kingdom_is_recruiting || $kingdom_is_upgrading) {
                $error = "Du bist bereits am Rekrutieren oder Aufwerten!";
            } else if (!is_numeric($_GET["count"]) || $_GET["count"] < 1) {
                $error = "Keine Angabe der Anzahl!";
            } else if ($_GET["count"] > MAX_SOLDIERS_RECRUIT_INPUT) {
                $error = "Maximale Anzahl beträgt " . MAX_SOLDIERS_RECRUIT_INPUT . "!";
            } else if ($_GET["recruit"] < 0 || $_GET["recruit"] > $soldiers_count) {
                $error = "Diese Einheit existiert nicht!";
            } else if ($soldiers[$s_id]->get_soldier_required_level() > $building->get_building_level()) {
                $error = "Deine Kaserne hat eine zu niedrige Stufe für diese Einheit!";
            } else if ($s_id == Soldiers::SOLDIER_HERO) {
                $error = "Helden können nicht ausgebildet werden!";
            } else {
                $count = (int)$_GET["count"];
                $source_soldier = $soldiers[(int)$_GET["recruit"]];
                $source_db_id = $source_soldier->get_soldier_id();

                $res_count = $db_instance->execute_query("SELECT soldiercount FROM soldiers WHERE kingdomid = ? AND soldierid = ?", [$current_kingdom, $source_db_id]);
                $current_owned = $res_count->fetch_assoc()["soldiercount"] ?? 0;

                if (!empty($_GET["upgrade_to"])) {
                    $target_db_id = (int)$_GET["upgrade_to"];
                    $target_soldier = null;

                    foreach ($soldiers as $s) {
                        if ($s->get_soldier_id() == $target_db_id) {
                            $target_soldier = $s;
                            break;
                        }
                    }

                    if ($target_db_id == $source_db_id) {
                        $error = "Du kannst eine Einheit nicht zu sich selbst aufwerten!";
                    } else if ($current_owned < $count) {
                        $error = "Nicht genügend Einheiten für das Upgrade vorhanden!";
                    } else if (!$target_soldier || $source_soldier->get_soldier_category() != $target_soldier->get_soldier_category()) {
                        $error = "Ungültiges Upgrade!";
                    } else if ($target_soldier->get_soldier_required_level() <= $source_soldier->get_soldier_required_level()) {
                        $error = "Upgrades sind nur zu Einheiten eines höheren Rangs möglich!";
                    } else if ($target_soldier->get_soldier_required_level() > $building->get_building_level()) {
                        $error = "Deine Kaserne hat eine zu niedrige Stufe für diese Einheit!";
                    } else if ($target_sol->get_soldier_category() == SoldierTypes::SOLDIER_TYPE_SPECIAL) {
                        $error = "Spezialeinheiten können nicht durch Aufwertung erhalten werden!";
                    } else {
                        $diff_gold = max(0, ($target_soldier->get_soldier_gold_cost() - $source_soldier->get_soldier_gold_cost()) * $count);
                        $diff_food = max(0, ($target_soldier->get_soldier_food_cost() - $source_soldier->get_soldier_food_cost()) * $count);
                        $diff_wood = max(0, ($target_soldier->get_soldier_wood_cost() - $source_soldier->get_soldier_wood_cost()) * $count);
                        $diff_stone = max(0, ($target_soldier->get_soldier_stone_cost() - $source_soldier->get_soldier_stone_cost()) * $count);

                        if ($kingdom_gold >= $diff_gold && $kingdom_food >= $diff_food && $kingdom_wood >= $diff_wood && $kingdom_stone >= $diff_stone) {
                            $upgrade_time = $target_soldier->get_soldier_time() * $count;
                            $finish_at = time() + $upgrade_time;

                            $kingdom->give_kingdom_gold(-$diff_gold);
                            $kingdom->give_kingdom_food(-$diff_food);
                            $kingdom->give_kingdom_wood(-$diff_wood);
                            $kingdom->give_kingdom_stone(-$diff_stone);

                            // Remove old troops
                            $db_instance->execute_query("UPDATE soldiers SET soldiercount = soldiercount - ? WHERE kingdomid = ? AND soldierid = ?",
                                [$count, $current_kingdom, $source_db_id]);

                            // Create Upgrade event
                            $db_instance->execute_query(
                                "INSERT INTO events (actionid, userid, kingdomid, buildingid, soldierid, recruittime, soldiergoal, buildingname) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                                [ActionTypes::ACTION_UPGRADE_TROOPS, $user->get_user_id(), $current_kingdom, $source_db_id, $target_db_id, $finish_at, $count, "Truppen-Upgrade"]
                            );

                            $logger->log_game("ECONOMY", "UPGRADE_START",
                                [
                                    "from" => $source_soldier->get_soldier_name(),
                                    "to" => $target_soldier->get_soldier_name(),
                                    "count" => $count
                                ], $current_kingdom);

                            change_location("barracks.php?cat=$active_cat");
                            exit;
                        } else {
                            $error = "Nicht genügend Ressourcen!";
                        }
                    }
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
$last_upgraded = $user->get_last_upgraded_soldier($current_kingdom);

if (!empty($last_upgraded)) {
    $view .= show_weighted_box($last_upgraded["name"] . " (+" . $last_upgraded["count"] . ")", "Aufwertung abgeschlossen:");

    $user->clear_last_upgraded_soldier($current_kingdom);
}

if (!empty($last_recruited_soldier)) {
    $soldier_name = $last_recruited_soldier["soldiername"];
    $soldier_count = $last_recruited_soldier["soldiercount"];

    $view .= show_weighted_box("$soldier_name (+$soldier_count)", "Ausbildung abgeschlossen:");

    $user->clear_last_recruited_soldier($current_kingdom);
}

$categories = [
    SoldierTypes::SOLDIER_TYPE_INFANTRY => "Infanterie",
    SoldierTypes::SOLDIER_TYPE_CAVALRY => "Kavallerie",
    SoldierTypes::SOLDIER_TYPE_ARCHERS => "Schützen",
    SoldierTypes::SOLDIER_TYPE_SPECIAL => "Spezial"
];

$view .= '<div id="kingdom-resources" 
    data-food="' . $kingdom_food . '" 
    data-wood="' . $kingdom_wood . '" 
    data-stone="' . $kingdom_stone . '" 
    data-gold="' . $kingdom_gold . '" 
    data-villager="' . $kingdom_villager . '"></div>';
$view .= "<div class='tab'>";

foreach ($categories as $id => $name) {
    $active_class = ($id === $active_cat) ? "active" : "";
    $view .= "<div class='tablinks $active_class' data-on-click='filterBarracks' data-category='$id'>$name</div>";
}

$view .= "</div>";
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
    $is_hero = ($soldiers[$i]->get_soldier_id() == Soldiers::SOLDIER_HERO);

    if ($soldiers[$i]->get_soldier_required_level() > $building->get_building_level()) {
        continue;
    }

    $unit_cat = $soldiers[$i]->get_soldier_category();

    $cost_food = $soldiers[$i]->get_soldier_food_cost();
    $cost_gold = $soldiers[$i]->get_soldier_gold_cost();
    $cost_stone = $soldiers[$i]->get_soldier_stone_cost();
    $cost_wood = $soldiers[$i]->get_soldier_wood_cost();
    $cost_villager = $soldiers[$i]->get_soldier_villager_cost();

    $text_food = "<span id='cost-food-$i'>" . fnum($cost_food) . "</span>";
    $text_gold = "<span id='cost-gold-$i'>" . fnum($cost_gold) . "</span>";
    $text_stone = "<span id='cost-stone-$i'>" . fnum($cost_stone) . "</span>";
    $text_wood = "<span id='cost-wood-$i'>" . fnum($cost_wood) . "</span>";
    $text_villager = "<span id='cost-villager-$i'>" . fnum($cost_villager) . "</span>";

    if ($is_hero) {
        $text_build = "<i>Einzigartig</i>";
    } else if ($kingdom_is_recruiting || $kingdom_is_upgrading) {
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

            $text_build = "In Ausbildung: " . $soldier_goal . "<br>
                            <b><span class='js-countdown' data-seconds='$remaining_time_in_seconds' data-hide-id='cancel-form'></span></b><br> 
                              <form id='cancel-form' action='barracks.php' method='GET'>
                                <input type='hidden' name='recruit' value='$i'>
                                <input type='hidden' name='count' value='cancel'>
                                <input type='hidden' name='cat' value='$unit_cat'>
                                <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                              </form>";
        } else if ($kingdom_is_upgrading && $upgrade_event["buildingid"] == $soldiers[$i]->get_soldier_id()) {
            $target_id = $upgrade_event["soldierid"];
            $target_name = "Unbekannt";
            $unit_time = 0;

            foreach ($soldiers as $s) {
                if ($s->get_soldier_id() == $target_id) {
                    $target_name = $s->get_soldier_name();
                    $unit_time = $s->get_soldier_time();
                    break;
                }
            }

            $total_diff = $upgrade_event["recruittime"] - time();
            $rem = max(0, $total_diff % $unit_time);
            if ($rem == 0) $rem = $unit_time;

            $text_build = "Aufwertung zu $target_name: " . $upgrade_event["soldiergoal"] . "<br>
            <b><span class='js-countdown' data-seconds='$rem' data-hide-id='cancel-form-upg'></span></b><br>
            <form id='cancel-form-upg' action='barracks.php' method='GET'>
                <input type='hidden' name='recruit' value='$i'>
                <input type='hidden' name='count' value='cancel'>
                <input type='hidden' name='cat' value='$unit_cat'>
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
        $time_needed = $soldiers[$i]->get_soldier_time();

        if ($food_cost > 0) $max_soldiers = min($max_soldiers, floor($kingdom_food / $food_cost));
        if ($gold_cost > 0) $max_soldiers = min($max_soldiers, floor($kingdom_gold / $gold_cost));
        if ($stone_cost > 0) $max_soldiers = min($max_soldiers, floor($kingdom_stone / $stone_cost));
        if ($wood_cost > 0) $max_soldiers = min($max_soldiers, floor($kingdom_wood / $wood_cost));
        if ($vill_cost > 0) $max_soldiers = min($max_soldiers, floor($kingdom_villager / $vill_cost));

        $max_soldiers = max(0, $max_soldiers);
        $disabled = ($max_soldiers == 0) ? "disabled" : "";

        $text_build = "<form action='barracks.php' method='GET' style='display: flex; flex-direction: column; gap: 5px; align-items: center;'>
                    <input type='hidden' name='recruit' value='$i'>
                    <input type='hidden' name='cat' value='$unit_cat'>
                    
                    <div style='display: flex; gap: 3px;'>
                        <input type='text' name='count' id='count$i' size='2' maxlength='2' 
                               class='js-recruit-input' data-id='$i'
                               data-cost-food='$cost_food' data-cost-gold='$cost_gold'
                               data-cost-stone='$cost_stone' data-cost-wood='$cost_wood'
                               data-cost-villager='$cost_villager' data-time-per-unit='$time_needed'
                               placeholder='0' $disabled>
                        <input type='button' value='Max.' data-on-click='fillMaxAndCalc' data-target='count$i' data-value='$max_soldiers' $disabled>
                    </div>";

        // Upgrade-Dropdown
        if ($unit_cat != SoldierTypes::SOLDIER_TYPE_SPECIAL && ($kingdom_soldiers[$soldiers[$i]->get_soldier_id()] ?? 0) > 0) {
            $possible_targets = [];
            foreach ($soldiers as $target_soldier) {
                if ($target_soldier->get_soldier_category() == $unit_cat &&
                    $target_soldier->get_soldier_required_level() > $soldiers[$i]->get_soldier_required_level() &&
                    $target_soldier->get_soldier_required_level() <= $building->get_building_level()) {
                    $possible_targets[] = $target_soldier;
                }
            }

            if (!empty($possible_targets)) {
                $text_build .= "<select name='upgrade_to' class='js-upgrade-select' data-id='$soldier_id' style='width: 110px; font-size: 11px;'>
                                <option value=''>Ausbildung</option>";

                foreach ($possible_targets as $pt) {
                    $text_build .= "<option value='" . $pt->get_soldier_id() . "' 
                                    data-ufood='" . $pt->get_soldier_food_cost() . "'
                                    data-ugold='" . $pt->get_soldier_gold_cost() . "'
                                    data-ustone='" . $pt->get_soldier_stone_cost() . "'
                                    data-uwood='" . $pt->get_soldier_wood_cost() . "'
                                    data-uvillager='" . $pt->get_soldier_villager_cost() . "'
                                    data-utime='" . $pt->get_soldier_time() . "'>
                                    Upgrade: " . $pt->get_soldier_name() . "</option>";
                }
                $text_build .= "</select>";
            }
        }

        $text_build .= "<input type='submit' value='Starten' $disabled>
                  </form>";
    }

    $row_style = ($unit_cat === $active_cat) ? "" : "display: none;";

    $view .= "<tr class='unit-row' data-unit-category='$unit_cat' style='$row_style'>
            <td>
                <div class='map-legend' style='justify-content: left;'>
                    <div class='legend-item'>" . $soldiers[$i]->get_soldier_icon() . "</div>
                    <div class='legend-item'>                        
                        <b class='popup' id='description" . $i . "'>" . $soldiers[$i]->get_soldier_name() . " 
                            <div id='description" . $i . "_box' class='popupbox'>
                                " . $soldiers[$i]->get_soldier_description() . "
                            </div> (" . ($kingdom_soldiers[$soldiers[$i]->get_soldier_id()] ?? 0) . ")
                        </b>
                    </div>
                </div>";

    if (!$is_hero) {
        $view .= "<div class='map-legend' style='justify-content: left; margin-top: 10px;'>
                    <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . $text_food . "</div>
                    <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . $text_gold . "</div>
                    " . ($cost_stone > 0 ? "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . $text_stone . "</div>" : "") . "
                    " . ($cost_wood > 0 ? "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . $text_wood . "</div>" : "") . "
                    <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_VILLAGER) . " " . $text_villager . "</div>
                </div>";
    }
    $view .= "<div class='map-legend' style='justify-content: left;'>
                    <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " " . $soldiers[$i]->get_soldier_attack() . "</div>
                    <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " " . $soldiers[$i]->get_soldier_defense() . "</div>
                </div>";
    if (!$is_hero) {
        $view .= "<div class='map-legend' style='justify-content: left;'>
                    <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME) . " 
                        <span id='time-$i'>" . convert_sec_to_str($soldiers[$i]->get_soldier_time()) . "</span>
                    </div>
                </div>";
    }
    $view .= "</td>
                <td class='td-center'>$text_build</td>
              </tr>";
}
$view .= '</table>';

/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = ["counter", "barracks"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
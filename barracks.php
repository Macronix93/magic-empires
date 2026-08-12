<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_BARRACKS);

$current_kingdom = $result["current_kingdom"];
$building = $result["building"];
$building_name = $building->get_building_name();
$kingdom = $result["kingdom"];

$troop_limit = $kingdom->get_troop_limit();
$total_occupied_space = $kingdom->get_current_troop_count(true, true);
$space_left = max(0, $troop_limit - $total_occupied_space);
$actual_units_total = $kingdom->get_current_troop_count(false, true);
$res_available = $db_instance->execute_query("SELECT IFNULL(SUM(soldiercount), 0) FROM soldiers WHERE kingdomid = ?", [$current_kingdom]);
$available_troops = (int)$res_available->fetch_row()[0];

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
$building_level = $building->get_building_level();
$dynamic_limit = max(MIN_SOLDIERS_RECRUIT_INPUT, (int)floor(($building_level / MAX_BUILDING_LEVEL) * MAX_SOLDIERS_RECRUIT_INPUT));

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
$result = $db_instance->execute_query("SELECT * FROM soldier_list");

foreach ($result as $row) {
    $soldier = new Soldier();
    $soldier->fill_from_row($row);

    $soldiers[$soldier->get_soldier_id()] = $soldier;
    $kingdom_soldiers[$soldier->get_soldier_id()] = 0;
}

$total_k_atk = 0;
$total_k_def = 0;
$total_k_units = 0;

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
} else if ($kingdom_is_upgrading) {
    $target_id = $upgrade_event["soldierid"];

    foreach ($soldiers as $s) {
        if ($s->get_soldier_id() == $target_id) {
            $active_cat = $s->get_soldier_category();
            break;
        }
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
                $refund_food = $soldier_goal * (int)($soldiers[$s_id]->get_soldier_food_cost());
                $refund_gold = $soldier_goal * (int)($soldiers[$s_id]->get_soldier_gold_cost());
                $refund_wood = $soldier_goal * (int)($soldiers[$s_id]->get_soldier_wood_cost());
                $refund_stone = $soldier_goal * (int)($soldiers[$s_id]->get_soldier_stone_cost());

                $kingdom->give_kingdom_food($refund_food);
                $kingdom->give_kingdom_gold($refund_gold);
                $kingdom->give_kingdom_wood($refund_wood);
                $kingdom->give_kingdom_stone($refund_stone);

                // Delete the job
                $db_instance->execute_query("DELETE FROM events WHERE userid = ? AND soldierid = ? AND kingdomid = ?",
                    [$user->get_user_id(), $s_id, $current_kingdom]);

                $logger->log_game("ECONOMY", "RECRUIT_CANCEL", [
                    "soldier_name" => $soldiers[$s_id]->get_soldier_name(),
                    "amount_cancelled" => $soldier_goal
                ], $current_kingdom);

                change_location("barracks.php?cat=$active_cat");
                exit;
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
            $count = (int)$_GET["count"];

            if ($kingdom_is_recruiting || $kingdom_is_upgrading) {
                $error = "Du bist bereits am Rekrutieren oder Aufwerten!";
            } else if (!is_numeric($_GET["count"]) || $_GET["count"] < 1) {
                $error = "Keine Angabe der Anzahl!";
            } else if ($_GET["count"] > $dynamic_limit) {
                $error = "Deine Kaserne erlaubt aktuell maximal $dynamic_limit Einheiten gleichzeitig!";
            } else if (empty($_GET["upgrade_to"]) && $count > $space_left) {
                $error = "Nicht genug Platz! Dein Truppenlimit lässt nur noch $space_left Einheiten zu.";
            } else if ($_GET["recruit"] < 0 || $_GET["recruit"] > $soldiers_count) {
                $error = "Diese Einheit existiert nicht!";
            } else if ($soldiers[$s_id]->get_soldier_required_level() > $building->get_building_level()) {
                $error = "Deine Kaserne hat eine zu niedrige Stufe für diese Einheit!";
            } else if ($s_id == Soldiers::SOLDIER_HERO) {
                $error = "Helden können nicht ausgebildet werden!";
            } else {
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
                    } else if ($target_soldier->get_soldier_category() == SoldierTypes::SOLDIER_TYPE_SPECIAL) {
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
                    $weight_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_WEIGHT);

                    $count = (int)$_GET["count"];

                    $unit_cost_food = (int)($soldiers[$s_id]->get_soldier_food_cost());
                    $unit_cost_gold = (int)($soldiers[$s_id]->get_soldier_gold_cost());
                    $unit_cost_wood = (int)($soldiers[$s_id]->get_soldier_wood_cost());
                    $unit_cost_stone = (int)($soldiers[$s_id]->get_soldier_stone_cost());

                    $total_food = $unit_cost_food * $count;
                    $total_gold = $unit_cost_gold * $count;
                    $total_wood = $unit_cost_wood * $count;
                    $total_stone = $unit_cost_stone * $count;
                    $cost_villager = $soldiers[$s_id]->get_soldier_villager_cost() * $_GET["count"];

                    if ($total_food > $kingdom_food) {
                        $error = "Nicht genug Nahrung!";
                    } else if ($total_gold > $kingdom_gold) {
                        $error = "Nicht genug Gold!";
                    } else if ($total_stone > $kingdom_stone) {
                        $error = "Nicht genug Stein!";
                    } else if ($total_wood > $kingdom_wood) {
                        $error = "Nicht genug Holz!";
                    } else if ($cost_villager > $kingdom_villager) {
                        $error = "Nicht genug Dorfbewohner!";
                    } else {
                        $current_time = time();
                        $weight_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_WEIGHT);
                        $discount = 1 - ($weight_lvl * SMITHY_WEIGHT_REDUCTION);
                        $single_unit_time = (int)round($soldiers[$s_id]->get_soldier_time() * $discount);

                        $recruiting_time = $current_time + ($single_unit_time * $count);

                        $query = "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname, soldierid, recruittime, soldiergoal) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $db_instance->execute_query($query,
                            [ActionTypes::ACTION_BUILD_TROOPS, $user->get_user_id(), $current_kingdom, '0', $current_time, '0', '-', $s_id, $recruiting_time, $count]);

                        // Subtract values
                        $kingdom->give_kingdom_food(-$total_food);
                        $kingdom->give_kingdom_gold(-$total_gold);
                        $kingdom->give_kingdom_stone(-$total_stone);
                        $kingdom->give_kingdom_wood(-$total_wood);

                        change_location("barracks.php?cat=$active_cat");
                        exit;
                    }
                }
            }
        }
    }
}

/*
 * HTML Content Part
 */
// Get soldiers of kingdom
$result_s = $db_instance->execute_query("SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?", [$current_kingdom]);

$inf_atk_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES);
$inf_def_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL);
$cav_atk_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING);
$cav_def_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS);
$arc_atk_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS);
$arc_def_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET);

$shrine_atk_mult = 1.0;
if ($kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
    $shrine_atk_mult += $kingdom->get_shrine_modifier();
}

$total_k_atk = 0;
$total_k_def = 0;
$total_k_units = 0;
$pure_base_atk = 0;
$pure_base_def = 0;
$total_smithy_atk = 0;
$total_shrine_atk = 0;

foreach ($result_s as $row) {
    $soldier_id = (int)($row["soldierid"] ?? -1);
    $sol_count = (int)($row["soldiercount"] ?? 0);
    $kingdom_soldiers[$soldier_id] = $sol_count;
    if ($sol_count <= 0) continue;

    $b_atk = 0;
    $b_def = 0;
    $total_k_units += $sol_count;

    if (isset($soldiers[$soldier_id])) {
        $s_obj = $soldiers[$soldier_id];
        $cat = $s_obj->get_soldier_category();

        $pure_base_atk += $sol_count * $s_obj->get_soldier_attack();
        $pure_base_def += $sol_count * $s_obj->get_soldier_defense();

        if ($cat == SoldierTypes::SOLDIER_TYPE_INFANTRY) {
            $b_atk = $inf_atk_lvl * SMITHY_INF_ATK_BONUS;
            $b_def = $inf_def_lvl * SMITHY_INF_DEF_BONUS;
        } else if ($cat == SoldierTypes::SOLDIER_TYPE_CAVALRY) {
            $b_atk = $cav_atk_lvl * SMITHY_CAV_ATK_BONUS;
            $b_def = $cav_def_lvl * SMITHY_CAV_DEF_BONUS;
        } else if ($cat == SoldierTypes::SOLDIER_TYPE_ARCHERS) {
            $b_atk = $arc_atk_lvl * SMITHY_ARC_ATK_BONUS;
            $b_def = $arc_def_lvl * SMITHY_ARC_DEF_BONUS;
        }

        $unit_atk_with_shrine = (int)($s_obj->get_soldier_attack() * $shrine_atk_mult);
        $unit_shrine_gain = $unit_atk_with_shrine - $s_obj->get_soldier_attack();

        $total_k_atk += $sol_count * ($unit_atk_with_shrine + $b_atk);
        $total_k_def += $sol_count * ($s_obj->get_soldier_defense() + $b_def);

        $total_smithy_atk += ($sol_count * $b_atk);
        $total_shrine_atk += ($sol_count * $unit_shrine_gain);
    }
}

$category_availability = [
    SoldierTypes::SOLDIER_TYPE_INFANTRY => false,
    SoldierTypes::SOLDIER_TYPE_CAVALRY => false,
    SoldierTypes::SOLDIER_TYPE_ARCHERS => false,
    SoldierTypes::SOLDIER_TYPE_SPECIAL => false
];

$barracks_lvl = $building->get_building_level();

foreach ($soldiers as $s) {
    $cat = $s->get_soldier_category();
    $owned = $kingdom_soldiers[$s->get_soldier_id()] ?? 0;
    $req = $s->get_soldier_required_level();

    if ($req <= $barracks_lvl || $owned > 0) {
        $category_availability[$cat] = true;
    }
}

if (!$category_availability[$active_cat]) {
    foreach ($category_availability as $id => $available) {
        if ($available) {
            $active_cat = $id;
            break;
        }
    }
}

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

$total_smithy_def = $total_k_def - $pure_base_def;

$atk_class = ($total_smithy_atk > 0 || $total_shrine_atk > 0) ? "passed" : "";
$def_class = ($total_smithy_def > 0) ? "passed" : "";

$view .= "
<div class='garnison-box'>
    <table style='width: 100%; border-collapse: collapse; border: none; background: transparent;'>
        <tr>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: left;'>
                <b>Garnisons-Stärke:</b>
            </td>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: right; white-space: nowrap;'>
                <span class='popup $atk_class' id='total_atk_info'>
                    " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " <span>" . fnum($total_k_atk) . "</span>
                    <div id='total_atk_info_box' class='popupbox' style='text-align:left;'>
                        <b>Angriffs-Bonus:</b><br>
                        Basis: " . fnum($pure_base_atk) . "<br>
                        " . ($total_smithy_atk > 0 ? "<span class='passed'>Schmiede: +" . fnum($total_smithy_atk) . "</span><br>" : "") . "
                        " . ($total_shrine_atk > 0 ? "<span class='passed'>Schrein: +" . fnum($total_shrine_atk) . "</span>" : "") . "
                    </div>
                </span>
                <span style='margin-left: 10px;' class='popup $def_class' id='total_def_info'>
                    " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " <span>" . fnum($total_k_def) . "</span>
                    <div id='total_def_info_box' class='popupbox' style='text-align:left;'>
                        <b>Verteidigungs-Bonus:</b><br>
                        Basis: " . fnum($pure_base_def) . "<br>
                        " . ($total_smithy_def > 0 ? "<span class='passed'>Schmiede: +" . fnum($total_smithy_def) . "</span>" : "") . "
                    </div>
                </span>
            </td>
        </tr>
        <tr>
            <td colspan='2' style='background: transparent; border: none; padding: 0;'>
                <hr>
            </td>
        </tr>
        <tr>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: left;'>
                <b>Truppen (gesamt):</b>
            </td>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: right;'>
                " . fnum($actual_units_total) . " / " . fnum($troop_limit) . "
            </td>
        </tr>
        <tr>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: left;'>
                <b>Truppen (verfügbar):</b>
            </td>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: right;'>
                " . fnum($available_troops) . "
            </td>
        </tr>
    </table>
</div>";

$categories = [
    SoldierTypes::SOLDIER_TYPE_INFANTRY => "Infanterie",
    SoldierTypes::SOLDIER_TYPE_CAVALRY => "Kavallerie",
    SoldierTypes::SOLDIER_TYPE_ARCHERS => "Schützen",
    SoldierTypes::SOLDIER_TYPE_SPECIAL => "Spezial"
];

$weight_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_WEIGHT);
$smithy_multiplier = 1 - ($weight_lvl * SMITHY_WEIGHT_REDUCTION);

$view .= '<div id="kingdom-resources" 
    data-food="' . $kingdom_food . '" 
    data-wood="' . $kingdom_wood . '" 
    data-stone="' . $kingdom_stone . '" 
    data-gold="' . $kingdom_gold . '" 
    data-villager="' . $kingdom_villager . '"
    data-dynamic-limit="' . $dynamic_limit . '"
    data-smithy-multiplier="' . $smithy_multiplier . '"
    data-space-left="' . $space_left . '"></div>';
$view .= "<div class='tab'>";

foreach ($categories as $id => $name) {
    if ($category_availability[$id]) {
        $active_class = ($id === $active_cat) ? "active" : "";

        $view .= "<div class='tablinks $active_class' data-on-click='filterBarracks' data-category='$id'>$name</div>";
    } else {
        $view .= "<div class='tablinks tab-disabled' title='Hier gibt es noch keine Einheiten'>$name</div>";
    }
}

$view .= "</div>";
$view .= '<table class="table">
                        <colgroup>
                            <col class="col-description">
                            <col class="col-action">
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

for ($i = 0; $i < $soldiers_count; $i++) {
    $s_id_internal = $soldiers[$i]->get_soldier_id();
    $owned_count = $kingdom_soldiers[$s_id_internal] ?? 0;
    $req_lvl = $soldiers[$i]->get_soldier_required_level();
    $barracks_lvl = $building->get_building_level();

    if ($req_lvl > $barracks_lvl && $owned_count <= 0) {
        continue;
    }

    $can_train = ($req_lvl <= $barracks_lvl);
    $is_hero = ($soldiers[$i]->get_soldier_id() == Soldiers::SOLDIER_HERO);

//    if ($soldiers[$i]->get_soldier_required_level() > $building->get_building_level()) {
//        continue;
//    }

    $unit_cat = $soldiers[$i]->get_soldier_category();
    $base_unit_time = $soldiers[$i]->get_soldier_time();
    $unit_time_display = (int)($base_unit_time * $smithy_multiplier);

    $cost_food = (int)($soldiers[$i]->get_soldier_food_cost());
    $cost_gold = (int)($soldiers[$i]->get_soldier_gold_cost());
    $cost_stone = (int)($soldiers[$i]->get_soldier_stone_cost());
    $cost_wood = (int)($soldiers[$i]->get_soldier_wood_cost());
    $cost_villager = $soldiers[$i]->get_soldier_villager_cost();

    $text_food = "<span id='cost-food-$i'>" . fnum($cost_food) . "</span>";
    $text_gold = "<span id='cost-gold-$i'>" . fnum($cost_gold) . "</span>";
    $text_stone = "<span id='cost-stone-$i'>" . fnum($cost_stone) . "</span>";
    $text_wood = "<span id='cost-wood-$i'>" . fnum($cost_wood) . "</span>";
    $text_villager = "<span id='cost-villager-$i'>" . fnum($cost_villager) . "</span>";

    $capacity_text = "";

    $plunder_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_PLUNDER);

    switch ($s_id_internal) {
        case Soldiers::SOLDIER_THIEF:
        case Soldiers::SOLDIER_RAIDER:
            $base_cap = ($s_id_internal == Soldiers::SOLDIER_THIEF) ? THIEF_BASE_CAPACITY : RAIDER_BASE_CAPACITY;
            $current_cap = floor($base_cap * (1 + ($plunder_lvl * PLUNDER_CAPACITY_BONUS)));

            $capacity_text = "<br><br><span style='font-size: 0.9em;'><b>Aktuelle Kapazität:</b> " . fnum($current_cap) . " Ressourcen / Einheit</span>";

            if ($plunder_lvl > 0) {
                $capacity_text .= "<br><small style='opacity: 0.7;'>(Inkl. " . ($plunder_lvl * PLUNDER_CAPACITY_BONUS * 100) . "% Forschungs-Bonus)</small>";
            }
            break;
        case Soldiers::SOLDIER_CONQUEROR:
            $base = (BASE_CONQUEST_CHANCE + MIN_CONQUEST_CHANCE) * 100;
            $step = MIN_CONQUEST_CHANCE * 100;
            $max = MAX_CONQUEST_CHANCE * 100;

            $capacity_text = "<br><br><span style='font-size: 0.9em;'>Erfolg: <b>$base%</b> Basis-Chance</span>";
            $capacity_text .= "<br><small>(+$step% je weiteren Eroberer, max. $max%)</small>";
            break;
        case Soldiers::SOLDIER_SETTLER_WAGON:
            $res_founded = $db_instance->execute_query(
                "SELECT COUNT(*) FROM kingdoms WHERE userid = ? AND creation_method = 0",
                [$user->get_user_id()]
            );
            $curr_founded = (int)$res_founded->fetch_row()[0];

            $res_imp = $db_instance->execute_query(
                "SELECT COUNT(*) FROM techs t JOIN kingdoms k ON t.kingdomid = k.id WHERE k.userid = ? AND t.techid = ? AND t.techlevel > 0",
                [$user->get_user_id(), TechTypes::TECH_TYPE_IMPERIAL]
            );
            $imp_bonus = (int)$res_imp->fetch_row()[0];

            $current_limit = min(GLOBAL_SETTLEMENT_MAX, BASE_SETTLEMENT_LIMIT + $imp_bonus);
            $base_chance = BASE_SETTLER_CHANCE * 100;

            $capacity_text = "<br><br><span style='font-size: 0.9em;'>Chance: <b>$base_chance%</b> Erfolgsrate</span>";
            $capacity_text .= "<br><span style='font-size: 0.9em;'>Imperium: <b>$curr_founded / $current_limit</b> gegründeten Siedlungen</span>";

            if ($current_limit >= GLOBAL_SETTLEMENT_MAX) {
                $capacity_text .= "<br><small class='error'>(Maximales Limit erreicht)</small>";
            } else {
                $capacity_text .= "<br><small>(Erhöhbar durch 'Imperium' Forschung)</small>";
            }
            break;
    }

    if ($is_hero) {
        $text_build = "<i>Einzigartig</i>";
    } else if (!$can_train) {
        $text_build = "<div style='font-size: 11px;'>
                        <span class='error' style='font-size: 11px;'>Benötigt Kaserne Stufe $req_lvl</span>
                      </div>";
    } else if ($kingdom_is_recruiting || $kingdom_is_upgrading) {
        if ($kingdom_recruiting_id == $i) {
            $result = $db_instance->execute_query("SELECT buildingtime, recruittime, soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?",
                [$current_kingdom, ActionTypes::ACTION_BUILD_TROOPS, $i]);
            $row = $result->fetch_assoc();

            $recruit_time_end = $row["recruittime"];
            $soldier_goal = $row["soldiergoal"];

            $current_unit_time = $unit_time_display;

            $current_time = time();
            $elapsed_since_unit_start = $current_time - $row["buildingtime"];
            $remaining_for_this_unit = $current_unit_time - $elapsed_since_unit_start;

            if ($remaining_for_this_unit <= 0) {
                $remaining_for_this_unit = $current_unit_time;
            }

            $text_build = "In Ausbildung: " . $soldier_goal . "<br>
                            <b><span class='js-countdown' data-seconds='$remaining_for_this_unit' data-hide-id='cancel-form'>" . format_time_for_js($remaining_for_this_unit) . "</span></b><br> 
                              <form id='cancel-form' action='barracks.php' method='GET'>
                                <input type='hidden' name='recruit' value='$i'>
                                <input type='hidden' name='count' value='cancel'>
                                <input type='hidden' name='cat' value='$unit_cat'>
                                <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                              </form>";
        } else if ($kingdom_is_upgrading && $upgrade_event["buildingid"] == $soldiers[$i]->get_soldier_id()) {
            $target_id = $upgrade_event["soldierid"];
            $target_name = "Unbekannt";
            $upg_unit_time = 0;

            foreach ($soldiers as $s) {
                if ($s->get_soldier_id() == $target_id) {
                    $target_name = $s->get_soldier_name();
                    $upg_unit_time = (int)($s->get_soldier_time() * $smithy_multiplier);
                    break;
                }
            }

            $total_diff = $upgrade_event["recruittime"] - time();
            $rem = max(0, $total_diff % $upg_unit_time);
            if ($rem == 0) $rem = $upg_unit_time;

            $text_build = "Aufwertung zu $target_name: " . $upgrade_event["soldiergoal"] . "<br>
            <b><span class='js-countdown' data-seconds='$rem' data-hide-id='cancel-form-upg'>" . format_time_for_js($rem) . "</span></b><br>
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
        $max_soldiers = $dynamic_limit;

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
        $owned_count = $kingdom_soldiers[$soldiers[$i]->get_soldier_id()] ?? 0;

        $can_train_at_least_one = ($max_soldiers > 0);

        $can_upgrade_to_anything = false;
        if ($unit_cat != SoldierTypes::SOLDIER_TYPE_SPECIAL && $owned_count > 0) {
            foreach ($soldiers as $target_soldier) {
                if ($target_soldier->get_soldier_category() == $unit_cat &&
                    $target_soldier->get_soldier_required_level() > $soldiers[$i]->get_soldier_required_level() &&
                    $target_soldier->get_soldier_required_level() <= $building->get_building_level()) {

                    $can_upgrade_to_anything = true;
                    break;
                }
            }
        }

        $is_disabled = (!$can_train_at_least_one && !$can_upgrade_to_anything);
        $disabled_attr = $is_disabled ? "disabled" : "";

        $text_build = "<form action='barracks.php' method='GET' style='display: flex; flex-direction: column; gap: 5px; align-items: center;'>
                        <input type='hidden' name='recruit' value='$i'>
                        <input type='hidden' name='cat' value='$unit_cat'>
                        
                        <div style='display: flex; gap: 3px;'>
                            <input type='text' name='count' id='count$i' size='2' maxlength='2' 
                                   class='js-recruit-input' data-id='$i'
                                   data-owned='$owned_count'
                                   data-cost-food='$cost_food' data-cost-gold='$cost_gold'
                                   data-cost-stone='$cost_stone' data-cost-wood='$cost_wood'
                                   data-cost-villager='$cost_villager' data-time-per-unit='$base_unit_time'
                                   inputmode='numeric' pattern='[0-9]*'
                                   placeholder='0' $disabled_attr>
                            <input type='button' value='Max.' data-on-click='fillMaxAndCalc' data-target='count$i' $disabled_attr>
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
                                    Aufwertung: " . $pt->get_soldier_name() . "</option>";
                }
                $text_build .= "</select>";
            }
        }

        $text_build .= "<input type='submit' name='start_action' value='Starten' $disabled_attr>
                  </form>";
    }

    $row_style = ($unit_cat === $active_cat) ? "" : "display: none;";
    $row_class = "unit-row" . (!$can_train ? " unit-not-trainable" : "");

    $view .= "<tr class='$row_class' data-unit-category='$unit_cat' style='$row_style'>
            <td>
                <div class='map-legend' style='justify-content: left;'>
                    <div class='legend-item'>" . $soldiers[$i]->get_soldier_icon() . "</div>
                    <div class='legend-item'>                        
                        <b class='popup' id='description" . $i . "'>" . $soldiers[$i]->get_soldier_name() . " 
                            <div id='description" . $i . "_box' class='popupbox'>
                                " . $soldiers[$i]->get_soldier_description() . "
                                " . $capacity_text . "
                            </div> (" . ($kingdom_soldiers[$soldiers[$i]->get_soldier_id()] ?? 0) . ")
                        </b>
                    </div>
                </div>";

    if (!$is_hero) {
        $view .= "<div class='map-legend' style='justify-content: left; margin-top: 10px;'>
                    " . ($cost_food > 0 ? "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . $text_food . "</div>" : "") . "
                    " . ($cost_gold > 0 ? "<div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . $text_gold . "</div>" : "") . "
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
                        <span id='time-$i'>" . convert_sec_to_str($unit_time_display) . "</span>
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
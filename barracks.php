<?php
require_once("includes/core.php");

$result = $user->check_user_login_and_kingdom(BuildingTypes::BUILDING_BARRACKS);

$current_kingdom = $result["current_kingdom"];
$building = $result["building"];
$building_name = $building->get_building_name();
$kingdom = $result["kingdom"];

$troop_limit = $kingdom->get_troop_limit();
$total_occupied_space = $kingdom->get_current_troop_count(true, true);
$actual_units_total = $kingdom->get_current_troop_count(false, true);
$space_left = max(0, $troop_limit - $total_occupied_space);

$kingdom_food = $kingdom->get_kingdom_food();
$kingdom_gold = $kingdom->get_kingdom_gold();
$kingdom_stone = $kingdom->get_kingdom_stone();
$kingdom_wood = $kingdom->get_kingdom_wood();
$kingdom_villager = $kingdom->get_kingdom_villager();
$weight_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_WEIGHT);
$smithy_multiplier = 1 - ($weight_lvl * SMITHY_WEIGHT_REDUCTION);
$s_id = -1;
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

$my_guild_id = $user->get_user_guild_id();

$res_any_support = $db_instance->execute_query("
    SELECT 1 FROM stationed_troops 
    WHERE target_kingdom_id = ? OR owner_id = ? 
    LIMIT 1", [$current_kingdom, $user->get_user_id()]);

$has_active_support = ($res_any_support->num_rows > 0);
$show_support_feature = ($my_guild_id > 0 || $has_active_support);

// Get all soldier types from the database
$result = $db_instance->execute_query("SELECT * FROM soldier_list");

foreach ($result as $row) {
    $soldier = new Soldier();
    $soldier->fill_from_row($row);

    $soldiers[$soldier->get_soldier_id()] = $soldier;
    $kingdom_soldiers[$soldier->get_soldier_id()] = 0;
}

$soldiers_count = count($soldiers);

// Get ALL soldiers from the kingdom
$total_kingdom_soldiers = [];
$res_total_units = $db_instance->execute_query("
    SELECT soldierid, SUM(cnt) as total_owned
    FROM (
        SELECT soldierid, soldiercount as cnt FROM soldiers WHERE kingdomid = ?
        UNION ALL
        SELECT soldierid, soldiercount as cnt FROM sent_troops WHERE source_kingdom_id = ?
        UNION ALL
        SELECT soldier_id as soldierid, soldiercount as cnt FROM stationed_troops WHERE source_kingdom_id = ?
        UNION ALL
        SELECT soldier_id as soldierid, soldiercount as cnt FROM mine_stationed_troops WHERE kingdom_id = ?
    ) t
    GROUP BY soldierid
", [$current_kingdom, $current_kingdom, $current_kingdom, $current_kingdom]);

while ($row_u = $res_total_units->fetch_assoc()) {
    $total_kingdom_soldiers[(int)$row_u["soldierid"]] = (int)$row_u["total_owned"];
}

// Standard soldier category
$active_cat = 0;

$last_recruited_soldier = $user->get_last_recruited_soldier($current_kingdom);
$last_upgraded = $user->get_last_upgraded_soldier($current_kingdom);

if (!empty($last_upgraded)) {
    if (isset($last_upgraded["category"])) {
        $active_cat = (int)$last_upgraded["category"];
    } else {
        foreach ($soldiers as $s) {
            if ($s->get_soldier_name() === ($last_upgraded["name"] ?? "")) {
                $active_cat = $s->get_soldier_category();
                break;
            }
        }
    }
} else if (!empty($last_recruited_soldier)) {
    if (isset($last_recruited_soldier["category"])) {
        $active_cat = (int)$last_recruited_soldier["category"];
    } else {
        foreach ($soldiers as $s) {
            if ($s->get_soldier_name() === ($last_recruited_soldier["soldiername"] ?? "")) {
                $active_cat = $s->get_soldier_category();
                break;
            }
        }
    }
} else if ($kingdom_is_upgrading && isset($upgrade_event["soldierid"])) {
    $target_id = (int)$upgrade_event["soldierid"];

    foreach ($soldiers as $s) {
        if ($s->get_soldier_id() == $target_id) {
            $active_cat = $s->get_soldier_category();
            break;
        }
    }
} else if ($kingdom_is_recruiting && isset($soldiers[$kingdom_recruiting_id])) {
    $active_cat = $soldiers[$kingdom_recruiting_id]->get_soldier_category();
} else if (isset($_GET["cat"])) {
    $cat = get_int("cat", -1);

    if ($cat === SoldierTypes::SOLDIER_TYPE_SUPPORT) {
        $active_cat = $show_support_feature ? SoldierTypes::SOLDIER_TYPE_SUPPORT : SoldierTypes::SOLDIER_TYPE_INFANTRY;
    } else if ($cat >= 0 && $cat <= SoldierTypes::SOLDIER_TYPE_SUPPORT) {
        $active_cat = $cat;
    }
}

if ($active_cat === SoldierTypes::SOLDIER_TYPE_SUPPORT && !$show_support_feature) {
    $active_cat = 0;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf_token()) {
        $error = "Ungültiger Sicherheits-Token!";
    } else {
        $action = post_str("action");

        if (isset($_POST["cat"])) {
            $posted_cat = post_int("cat");
            if ($posted_cat >= 0 && $posted_cat <= SoldierTypes::SOLDIER_TYPE_SUPPORT) {
                $active_cat = $posted_cat;
            }
        }

        if ($action === "return_support_stack") {
            $owner_id = post_int("owner_id", $user->get_user_id());
            $source_id = post_int("source_id", $current_kingdom);
            $target_id = post_int("target_id", $current_kingdom);

            $db_instance->begin_transaction();
            try {
                $res_stack = $db_instance->execute_query("
                    SELECT st.soldier_id, st.source_kingdom_id, st.owner_id, SUM(st.soldiercount) as soldiercount,
                           k_src.mapx as src_x, k_src.mapy as src_y, k_src.kingdomname as src_name,
                           k_tgt.mapx as tgt_x, k_tgt.mapy as tgt_y, k_tgt.kingdomname as tgt_name,
                           u_owner.username as owner_name, u_host.username as host_name, u_host.id as host_id
                    FROM stationed_troops st
                    JOIN kingdoms k_src ON st.source_kingdom_id = k_src.id
                    JOIN kingdoms k_tgt ON st.target_kingdom_id = k_tgt.id
                    JOIN users u_owner ON st.owner_id = u_owner.id
                    JOIN users u_host ON k_tgt.userid = u_host.id
                    WHERE st.owner_id = ? AND st.source_kingdom_id = ? AND st.target_kingdom_id = ?
                      AND (st.owner_id = ? OR k_tgt.userid = ?)
                    GROUP BY st.soldier_id, st.source_kingdom_id, st.owner_id, k_src.mapx, k_src.mapy, k_src.kingdomname, k_tgt.mapx, k_tgt.mapy, k_tgt.kingdomname, u_owner.username, u_host.username, u_host.id
                    FOR UPDATE
                ", [$owner_id, $source_id, $target_id, $user->get_user_id(), $user->get_user_id()]);
                $all_troops = $res_stack->fetch_all(MYSQLI_ASSOC);

                if (empty($all_troops)) {
                    throw new RuntimeException("Dieser Trupp ist nicht mehr an diesem Ort stationiert.");
                }

                $now = time();
                $map_helper = new Map($user);
                $travel_time = $map_helper->get_arrival_time(
                    $all_troops[0]["tgt_x"],
                    $all_troops[0]["tgt_y"],
                    $all_troops[0]["src_x"],
                    $all_troops[0]["src_y"],
                    $all_troops[0]["source_kingdom_id"],
                    null,
                    false,
                    false,
                    true
                );

                $db_instance->execute_query(
                    "INSERT INTO events (actionid, userid, kingdomid, targetid, targetx, targety, arrivaltime, buildingtime) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [ActionTypes::ACTION_SUPPORT_RETURN, $owner_id, $source_id, $target_id, $all_troops[0]["tgt_x"], $all_troops[0]["tgt_y"], $now + $travel_time, $now]
                );
                $new_event_id = $db_instance->insert_id;

                $units_data = [];
                foreach ($all_troops as $t) {
                    $db_instance->execute_query(
                        "INSERT INTO sent_troops (eventid, soldierid, soldiercount, initial_count, source_kingdom_id) VALUES (?, ?, ?, ?, ?)",
                        [$new_event_id, $t["soldier_id"], $t["soldiercount"], $t["soldiercount"], $t["source_kingdom_id"]]
                    );
                    $units_data[] = [
                        "id" => (int)$t["soldier_id"],
                        "count" => (int)$t["soldiercount"]
                    ];
                }

                $triggered_by_owner = ($all_troops[0]["owner_id"] == $user->get_user_id());
                if ($triggered_by_owner) {
                    $recipient_id = (int)$all_troops[0]["host_id"];
                    $recipient_name = $all_troops[0]["host_name"];
                    $action_type = "withdrawn";
                } else {
                    $recipient_id = (int)$all_troops[0]["owner_id"];
                    $recipient_name = $all_troops[0]["owner_name"];
                    $action_type = "dismissed";
                }

                $support_json = [
                    "template" => "support_recalled",
                    "action_type" => $action_type,
                    "owner_name" => $all_troops[0]["owner_name"],
                    "host_name" => $all_troops[0]["host_name"],
                    "target_name" => $all_troops[0]["tgt_name"],
                    "units" => $units_data
                ];
                Messages::send_server_message($recipient_id, $recipient_name, MessageCategories::CATEGORY_GUILD, $support_json);

                $db_instance->execute_query(
                    "DELETE FROM stationed_troops WHERE owner_id = ? AND source_kingdom_id = ? AND target_kingdom_id = ?",
                    [$owner_id, $source_id, $target_id]
                );
                $db_instance->commit();

                $_SESSION["game_success"] = "Die Truppen befinden sich nun auf dem Rückmarsch.";

                change_location("barracks.php?cat=" . SoldierTypes::SOLDIER_TYPE_SUPPORT);
            } catch (RuntimeException $e) {
                $db_instance->rollback();
                $error = $e->getMessage();
            } catch (Throwable $e) {
                $db_instance->rollback();
                $logger->error("barracks return_support_stack error: " . $e->getMessage());
                $error = "Interner Fehler beim Zurückrufen der Unterstützung.";
            }
        } else if ($action === "cancel") {
            $s_id = post_int("recruit", -1);

            $db_instance->begin_transaction();
            try {
                $db_instance->execute_query("SELECT id FROM kingdoms WHERE id = ? FOR UPDATE", [$current_kingdom]);

                if ($kingdom_is_recruiting && isset($soldiers[$s_id])) {
                    $event_row = $db_instance->execute_query(
                        "SELECT eventid, soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ? AND is_processing = 0 FOR UPDATE",
                        [$current_kingdom, ActionTypes::ACTION_BUILD_TROOPS, $s_id]
                    )->fetch_assoc();

                    if (!$event_row) {
                        throw new RuntimeException("Ausbildung ist bereits abgeschlossen oder wurde abgebrochen!");
                    }

                    $soldier_goal = (int)$event_row["soldiergoal"];
                    $db_instance->execute_query("DELETE FROM events WHERE eventid = ?", [$event_row["eventid"]]);

                    $refund_food = $soldier_goal * (int)$soldiers[$s_id]->get_soldier_food_cost();
                    $refund_gold = $soldier_goal * (int)$soldiers[$s_id]->get_soldier_gold_cost();
                    $refund_wood = $soldier_goal * (int)$soldiers[$s_id]->get_soldier_wood_cost();
                    $refund_stone = $soldier_goal * (int)$soldiers[$s_id]->get_soldier_stone_cost();

                    $kingdom->give_kingdom_food($refund_food);
                    $kingdom->give_kingdom_gold($refund_gold);
                    $kingdom->give_kingdom_wood($refund_wood);
                    $kingdom->give_kingdom_stone($refund_stone);
                    $db_instance->commit();

                    $logger->log_game("ECONOMY", "RECRUIT_CANCEL", [
                        "soldier_id" => $s_id,
                        "amount" => $soldier_goal
                    ], $current_kingdom);

                    change_location("barracks.php?cat=$active_cat");
                } else if ($kingdom_is_upgrading && isset($soldiers[$s_id]) && $upgrade_event["buildingid"] == $soldiers[$s_id]->get_soldier_id()) {
                    $upg_row = $db_instance->execute_query(
                        "SELECT eventid, soldiergoal, buildingid, soldierid FROM events WHERE eventid = ? AND is_processing = 0 FOR UPDATE",
                        [$upgrade_event["eventid"]]
                    )->fetch_assoc();

                    if (!$upg_row) {
                        throw new RuntimeException("Upgrade ist bereits abgeschlossen oder wurde abgebrochen!");
                    }

                    $db_instance->execute_query("DELETE FROM events WHERE eventid = ?", [$upg_row["eventid"]]);

                    $from_id = (int)$upg_row["buildingid"];
                    $to_id = (int)$upg_row["soldierid"];
                    $count = (int)$upg_row["soldiergoal"];

                    $diff_gold = ($soldiers[$to_id]->get_soldier_gold_cost() - $soldiers[$from_id]->get_soldier_gold_cost()) * $count;
                    $diff_food = ($soldiers[$to_id]->get_soldier_food_cost() - $soldiers[$from_id]->get_soldier_food_cost()) * $count;
                    $diff_wood = ($soldiers[$to_id]->get_soldier_wood_cost() - $soldiers[$from_id]->get_soldier_wood_cost()) * $count;
                    $diff_stone = ($soldiers[$to_id]->get_soldier_stone_cost() - $soldiers[$from_id]->get_soldier_stone_cost()) * $count;

                    $kingdom->give_kingdom_gold(max(0, $diff_gold));
                    $kingdom->give_kingdom_food(max(0, $diff_food));
                    $kingdom->give_kingdom_wood(max(0, $diff_wood));
                    $kingdom->give_kingdom_stone(max(0, $diff_stone));

                    $db_instance->execute_query(
                        "INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE soldiercount = soldiercount + VALUES(soldiercount)",
                        [$current_kingdom, $from_id, $soldiers[$from_id]->get_soldier_name(), $count]
                    );
                    $db_instance->commit();
                    change_location("barracks.php?cat=$active_cat");
                } else {
                    throw new RuntimeException("Du rekrutierst gerade nicht oder wertest nicht auf!");
                }
            } catch (RuntimeException $e) {
                $db_instance->rollback();
                $error = $e->getMessage();
            } catch (Throwable $e) {
                $db_instance->rollback();
                $logger->error("barracks cancel error: " . $e->getMessage());
                $error = "Interner Fehler beim Abbrechen.";
            }
        } else if ($action === "train") {
            $s_id = post_int("recruit", -1);
            $raw_count = trim(post_str("count"));

            if (!ctype_digit($raw_count) || (int)$raw_count < 1) {
                $error = "Keine gültige Anzahl angegeben!";
            } else {
                $count = (int)$raw_count;
                $upgrade_to = (post_int("upgrade_to") > 0) ? post_int("upgrade_to") : null;
                $is_space_exempt = in_array($s_id, TROOP_LIMIT_EXEMPT, true);

                if ($count > $dynamic_limit) {
                    $error = "Deine Kaserne erlaubt aktuell maximal $dynamic_limit Einheiten gleichzeitig!";
                } else if (!isset($soldiers[$s_id])) {
                    $error = "Diese Einheit existiert nicht!";
                } else if ($soldiers[$s_id]->get_soldier_required_level() > $building->get_building_level()) {
                    $error = "Deine Kaserne hat eine zu niedrige Stufe für diese Einheit!";
                } else if ($s_id == Soldiers::SOLDIER_HERO) {
                    $error = "Helden können nicht ausgebildet werden!";
                } else {
                    $source_soldier = $soldiers[$s_id];
                    $source_db_id = $source_soldier->get_soldier_id();

                    $db_instance->begin_transaction();
                    try {
                        $k_locked = $db_instance->execute_query(
                            "SELECT food, wood, stone, gold, villager FROM kingdoms WHERE id = ? FOR UPDATE",
                            [$current_kingdom]
                        )->fetch_assoc();

                        $busy_count = (int)$db_instance->execute_query(
                            "SELECT COUNT(*) FROM events WHERE kingdomid = ? AND actionid IN (?, ?)",
                            [$current_kingdom, ActionTypes::ACTION_BUILD_TROOPS, ActionTypes::ACTION_UPGRADE_TROOPS]
                        )->fetch_column();
                        if ($busy_count > 0) {
                            throw new RuntimeException("Du bist bereits am Rekrutieren oder Aufwerten!");
                        }

                        $pay_and_calc_finish = static function (array $costs, Soldier $unit) use ($kingdom, $smithy_multiplier, $count): array {
                            if (!$kingdom->pay_costs($costs)) {
                                throw new RuntimeException("Nicht genügend Ressourcen!");
                            }

                            $now = time();
                            $single_time = max(1, (int)round($unit->get_soldier_time() * $smithy_multiplier));
                            return [$now, $now + ($single_time * $count)];
                        };

                        if (!empty($upgrade_to)) {
                            $target_db_id = $upgrade_to;
                            $target_soldier = $soldiers[$target_db_id] ?? null;

                            if ($target_db_id == $source_db_id) {
                                throw new RuntimeException("Du kannst eine Einheit nicht zu sich selbst aufwerten!");
                            }

                            if (!$target_soldier || $source_soldier->get_soldier_category() != $target_soldier->get_soldier_category()) {
                                throw new RuntimeException("Ungültiges Upgrade!");
                            }

                            if ($target_soldier->get_soldier_required_level() <= $source_soldier->get_soldier_required_level()) {
                                throw new RuntimeException("Upgrades sind nur zu Einheiten eines höheren Rangs möglich!");
                            }

                            if ($target_soldier->get_soldier_required_level() > $building->get_building_level()) {
                                throw new RuntimeException("Deine Kaserne hat eine zu niedrige Stufe für diese Einheit!");
                            }

                            if ($target_soldier->get_soldier_category() == SoldierTypes::SOLDIER_TYPE_SPECIAL) {
                                throw new RuntimeException("Spezialeinheiten können nicht durch Aufwertung erhalten werden!");
                            }

                            $diff_gold = max(0, ($target_soldier->get_soldier_gold_cost() - $source_soldier->get_soldier_gold_cost()) * $count);
                            $diff_food = max(0, ($target_soldier->get_soldier_food_cost() - $source_soldier->get_soldier_food_cost()) * $count);
                            $diff_wood = max(0, ($target_soldier->get_soldier_wood_cost() - $source_soldier->get_soldier_wood_cost()) * $count);
                            $diff_stone = max(0, ($target_soldier->get_soldier_stone_cost() - $source_soldier->get_soldier_stone_cost()) * $count);

                            $db_instance->execute_query(
                                "UPDATE soldiers SET soldiercount = soldiercount - ? WHERE kingdomid = ? AND soldierid = ? AND soldiercount >= ?",
                                [$count, $current_kingdom, $source_db_id, $count]
                            );

                            if ($db_instance->affected_rows !== 1) {
                                throw new RuntimeException("Nicht genügend Einheiten für das Upgrade vorhanden!");
                            }

                            [$current_time, $finish_at] = $pay_and_calc_finish([
                                "cost_food" => $diff_food, "cost_wood" => $diff_wood,
                                "cost_stone" => $diff_stone, "cost_gold" => $diff_gold
                            ], $target_soldier);

                            $db_instance->execute_query(
                                "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, soldierid, recruittime, soldiergoal, buildingname)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                [ActionTypes::ACTION_UPGRADE_TROOPS, $user->get_user_id(), $current_kingdom, $source_db_id, $current_time, $target_db_id, $finish_at, $count, "Truppen-Upgrade"]
                            );

                            $db_instance->commit();
                            $logger->log_game("ECONOMY", "UPGRADE_START", [
                                "from" => $source_db_id,
                                "to" => $target_db_id,
                                "count" => $count
                            ], $current_kingdom);
                        } else {
                            $fresh_space_left = max(0, $kingdom->get_troop_limit() - $kingdom->get_current_troop_count(true, true));

                            if (!$is_space_exempt && $count > $fresh_space_left) {
                                throw new RuntimeException("Nicht genug Platz! Dein Truppenlimit lässt nur noch $fresh_space_left Einheiten zu.");
                            }

                            if (isset(SPECIAL_UNIT_LIMITS[$s_id])) {
                                $u_limit = $kingdom->get_special_unit_limit($s_id) ?? 0;
                                $u_name = SPECIAL_UNIT_LIMITS[$s_id]["name"];
                                $cur_total = $kingdom->get_unit_total_count($s_id, true, true);

                                if ($cur_total + $count > $u_limit) {
                                    $remaining = max(0, $u_limit - $cur_total);
                                    throw new RuntimeException("Maximal $u_limit $u_name pro Königreich erlaubt! (Bereits vorhanden/in Bau: $cur_total, noch möglich: $remaining)");
                                }
                            }

                            $total_food = (int)$source_soldier->get_soldier_food_cost() * $count;
                            $total_gold = (int)$source_soldier->get_soldier_gold_cost() * $count;
                            $total_wood = (int)$source_soldier->get_soldier_wood_cost() * $count;
                            $total_stone = (int)$source_soldier->get_soldier_stone_cost() * $count;
                            $cost_villager = (int)$source_soldier->get_soldier_villager_cost() * $count;

                            if ($total_food > (int)$k_locked["food"]) {
                                throw new RuntimeException("Nicht genug Nahrung!");
                            }

                            if ($total_gold > (int)$k_locked["gold"]) {
                                throw new RuntimeException("Nicht genug Gold!");
                            }

                            if ($total_stone > (int)$k_locked["stone"]) {
                                throw new RuntimeException("Nicht genug Stein!");
                            }

                            if ($total_wood > (int)$k_locked["wood"]) {
                                throw new RuntimeException("Nicht genug Holz!");
                            }

                            if ($cost_villager > (int)$k_locked["villager"]) {
                                throw new RuntimeException("Nicht genug Dorfbewohner!");
                            }

                            [$current_time, $recruiting_time] = $pay_and_calc_finish([
                                "cost_food" => $total_food, "cost_wood" => $total_wood,
                                "cost_stone" => $total_stone, "cost_gold" => $total_gold
                            ], $source_soldier);

                            $db_instance->execute_query(
                                "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname, soldierid, recruittime, soldiergoal)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                [ActionTypes::ACTION_BUILD_TROOPS, $user->get_user_id(), $current_kingdom, BuildingTypes::BUILDING_TOWNCENTER, $current_time, '0', '-', $s_id, $recruiting_time, $count]
                            );

                            $db_instance->commit();
                        }

                        change_location("barracks.php?cat=$active_cat");
                    } catch (RuntimeException $e) {
                        $db_instance->rollback();
                        $error = $e->getMessage();
                    } catch (Throwable $e) {
                        $db_instance->rollback();
                        $logger->error("barracks train error: " . $e->getMessage());
                        $error = "Interner Fehler beim Starten der Ausbildung.";
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
$res_own_only = $db_instance->execute_query("SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?", [$current_kingdom]);
foreach ($res_own_only as $r) {
    $kingdom_soldiers[(int)$r["soldierid"]] = (int)$r["soldiercount"];
}

$query_combined = "
    SELECT soldierid, SUM(soldiercount) AS total_count
    FROM (
        SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?
        UNION ALL
        SELECT soldier_id AS soldierid, soldiercount FROM stationed_troops WHERE target_kingdom_id = ?
    ) AS total
    GROUP BY soldierid";

$result_combined = $db_instance->execute_query($query_combined, [$current_kingdom, $current_kingdom]);

$inf_atk_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES);
$inf_def_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL);
$cav_atk_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING);
$cav_def_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS);
$arc_atk_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS);
$arc_def_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET);

$shrine_atk_mult = 1.0;
if ($kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
    $shrine_atk_mult += $kingdom->calculate_shrine_bonus($kingdom->get_shrine_modifier());
}

$total_k_atk = 0;
$total_k_def = 0;
$pure_base_atk = 0;
$pure_base_def = 0;
$total_smithy_atk = 0;
$total_shrine_atk = 0;
$support_k_atk = 0;
$support_k_def = 0;

$cat_atk = SoldierTypes::get_empty_category_map();
$cat_def = SoldierTypes::get_empty_category_map();

foreach ($result_combined as $row) {
    $soldier_id = (int)$row["soldierid"];
    $sol_count = (int)$row["total_count"];
    if ($sol_count <= 0) continue;

    if (isset($soldiers[$soldier_id])) {
        $s_obj = $soldiers[$soldier_id];
        $cat = $s_obj->get_soldier_category();

        $pure_base_atk += $sol_count * $s_obj->get_soldier_attack();
        $pure_base_def += $sol_count * $s_obj->get_soldier_defense();

        $b_atk = match ($cat) {
            SoldierTypes::SOLDIER_TYPE_INFANTRY => $inf_atk_lvl * SMITHY_INF_ATK_BONUS,
            SoldierTypes::SOLDIER_TYPE_CAVALRY => $cav_atk_lvl * SMITHY_CAV_ATK_BONUS,
            SoldierTypes::SOLDIER_TYPE_ARCHERS => $arc_atk_lvl * SMITHY_ARC_ATK_BONUS,
            default => 0
        };
        $b_def = match ($cat) {
            SoldierTypes::SOLDIER_TYPE_INFANTRY => $inf_def_lvl * SMITHY_INF_DEF_BONUS,
            SoldierTypes::SOLDIER_TYPE_CAVALRY => $cav_def_lvl * SMITHY_CAV_DEF_BONUS,
            SoldierTypes::SOLDIER_TYPE_ARCHERS => $arc_def_lvl * SMITHY_ARC_DEF_BONUS,
            default => 0
        };

        $unit_atk_with_shrine = (int)round($s_obj->get_soldier_attack() * $shrine_atk_mult);
        $unit_shrine_gain = $unit_atk_with_shrine - $s_obj->get_soldier_attack();

        $single_atk = $unit_atk_with_shrine + $b_atk;
        $single_def = $s_obj->get_soldier_defense() + $b_def;

        $final_atk = $sol_count * ($unit_atk_with_shrine + $b_atk);
        $final_def = $sol_count * ($s_obj->get_soldier_defense() + $b_def);

        $own_cnt = $kingdom_soldiers[$soldier_id] ?? 0;
        $sup_cnt = max(0, $sol_count - $own_cnt);
        if ($sup_cnt > 0) {
            $support_k_atk += $sup_cnt * $single_atk;
            $support_k_def += $sup_cnt * $single_def;
        }

        $total_k_atk += $final_atk;
        $total_k_def += $final_def;
        $total_smithy_atk += ($sol_count * $b_atk);
        $total_shrine_atk += ($sol_count * $unit_shrine_gain);

        if (isset($cat_atk[$cat])) {
            $cat_atk[$cat] += $final_atk;
            $cat_def[$cat] += $final_def;
        }
    }
}

$category_availability = [
    SoldierTypes::SOLDIER_TYPE_INFANTRY => false,
    SoldierTypes::SOLDIER_TYPE_CAVALRY => false,
    SoldierTypes::SOLDIER_TYPE_ARCHERS => false,
    SoldierTypes::SOLDIER_TYPE_SPECIAL => false,
    SoldierTypes::SOLDIER_TYPE_SUPPORT => $show_support_feature
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

if (empty($category_availability[$active_cat])) {
    foreach ($category_availability as $id => $available) {
        if ($available) {
            $active_cat = $id;
            break;
        }
    }
}

if (!empty($last_upgraded)) {
    $flash_box = show_weighted_box($last_upgraded["name"] . " (+" . $last_upgraded["count"] . ")", "Aufwertung abgeschlossen:");

    $user->clear_last_upgraded_soldier($current_kingdom);
}

if (!empty($last_recruited_soldier)) {
    $soldier_name = $last_recruited_soldier["soldiername"];
    $soldier_count = $last_recruited_soldier["soldiercount"];

    $flash_box = show_weighted_box("$soldier_name (+$soldier_count)", "Ausbildung abgeschlossen:");

    $user->clear_last_recruited_soldier($current_kingdom);
}

$total_smithy_def = $total_k_def - $pure_base_def;

$atk_class = ($total_smithy_atk > 0 || $total_shrine_atk > 0) ? "passed" : "";
$def_class = ($total_smithy_def > 0) ? "passed" : "";
$limit_class = ($total_occupied_space > $troop_limit) ? "error" : "";

$res_support_sum = $db_instance->execute_query("SELECT IFNULL(SUM(soldiercount), 0) FROM stationed_troops WHERE target_kingdom_id = ?", [$current_kingdom]);
$total_support_units = (int)$res_support_sum->fetch_row()[0];
$support_limit = $kingdom->get_support_limit();

$inf_cat = SoldierTypes::SOLDIER_TYPE_INFANTRY;
$cav_cat = SoldierTypes::SOLDIER_TYPE_CAVALRY;
$arc_cat = SoldierTypes::SOLDIER_TYPE_ARCHERS;
$spec_cat = SoldierTypes::SOLDIER_TYPE_SPECIAL;

$render_split_row = function (string $label, int $val, string $extra_class = ""): string {
    if ($val <= 0) return "";
    $class = "split-content" . ($extra_class ? " $extra_class" : "");
    return "<div class='$class' style='gap: 15px;'><span>$label:</span>" . fnum($val, true) . "</div>";
};

$cat_numbers_atk_str = $render_split_row("Infanterie", $cat_atk[$inf_cat])
    . $render_split_row("Kavallerie", $cat_atk[$cav_cat])
    . $render_split_row("Schützen", $cat_atk[$arc_cat])
    . $render_split_row("Spezial", $cat_atk[$spec_cat]);

$cat_numbers_def_str = $render_split_row("Infanterie", $cat_def[$inf_cat])
    . $render_split_row("Kavallerie", $cat_def[$cav_cat])
    . $render_split_row("Schützen", $cat_def[$arc_cat])
    . $render_split_row("Spezial", $cat_def[$spec_cat]);

$has_atk_bonus = ($total_smithy_atk > 0 || $total_shrine_atk > 0);
$has_def_bonus = ($total_smithy_def > 0);

$atk_bonus_html = "";
if ($has_atk_bonus) {
    $atk_bonus_html = "
                        <b>Angriffs-Bonus:</b>
                        <div class='split-content' style='gap: 15px;'><span>Basis:</span><span>" . fnum($pure_base_atk, true) . "</span></div>
                        " . ($total_smithy_atk > 0 ? "<div class='split-content passed' style='gap: 15px;'><span>Schmiede:</span><span>+" . fnum($total_smithy_atk, true) . "</span></div>" : "") . "
                        " . ($total_shrine_atk > 0 ? "<div class='split-content passed' style='gap: 15px;'><span>Schrein:</span><span>+" . fnum($total_shrine_atk, true) . "</span></div>" : "") . "
                        <hr>";
}
$def_bonus_html = "";
if ($has_def_bonus) {
    $def_bonus_html = "
                        <b>Verteidigungs-Bonus:</b>
                        <div class='split-content' style='gap: 15px;'><span>Basis:</span><span>" . fnum($pure_base_def, true) . "</span></div>
                        <div class='split-content passed' style='gap: 15px;'><span>Schmiede:</span><span>+" . fnum($total_smithy_def, true) . "</span></div>
                        <hr>";
}
$own_k_atk = max(0, $total_k_atk - $support_k_atk);
$own_k_def = max(0, $total_k_def - $support_k_def);

$support_atk_html = ($support_k_atk > 0)
    ? "<div class='split-content' style='gap: 15px;'><span>Eigene Truppen:</span><span>" . fnum($own_k_atk, true) . "</span></div>"
    . "<div class='split-content' style='gap: 15px; color: #389cff;'><span>Unterstützung:</span>+" . fnum($support_k_atk, true) . "</div>"
    : "";

$support_def_html = ($support_k_def > 0)
    ? "<div class='split-content' style='gap: 15px;'><span>Eigene Truppen:</span><span>" . fnum($own_k_def, true) . "</span></div>"
    . "<div class='split-content' style='gap: 15px; color: #389cff;'><span>Unterstützung:</span>+" . fnum($support_k_def, true) . "</div>"
    : "";

$view .= "
<div class='garnison-box'>
    <table style='width: 100%; border-collapse: collapse; border: none; background: transparent;'>
        <tr>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: left;'>
                <b>Garnisons-Stärke:</b>
            </td>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: right; white-space: nowrap;'>
                <span class='popup $atk_class' id='total_atk_info'>
                    " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " <span>" . fnum($total_k_atk, false, true) . "</span>
                    <div id='total_atk_info_box' class='popupbox' style='text-align: left; min-width: 240px;'>
                        <div class='split-content' style='gap: 15px; margin-bottom: 4px;'><b>Gesamt:</b><b>" . fnum($total_k_atk, true) . "</b></div>
                        $support_atk_html
                        <hr>
                        $atk_bonus_html
                        <b>Gattungen:</b>
                        $cat_numbers_atk_str
                    </div>
                </span>
                <span style='margin-left: 10px;' class='popup $def_class' id='total_def_info'>
                    " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " <span>" . fnum($total_k_def, false, true) . "</span>
                    <div id='total_def_info_box' class='popupbox' style='text-align: left; min-width: 240px;'>
                        <div class='split-content' style='gap: 15px; margin-bottom: 4px;'><b>Gesamt:</b><b>" . fnum($total_k_def, true) . "</b></div>
                        $support_def_html
                        <hr>
                        $def_bonus_html
                        <b>Gattungen:</b>
                        $cat_numbers_def_str
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
            <td class='$limit_class' style='background: transparent; border: none; padding: 2px 0; text-align: right;'>
                " . fnum($actual_units_total) . " / " . fnum($troop_limit) . "
            </td>
        </tr>
        <tr>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: left;'>
                <b>Truppen (verfügbar):</b>
            </td>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: right;'>
                " . fnum(array_sum($kingdom_soldiers)) . "
            </td>
        </tr>
        " . ($show_support_feature ? "
        <tr>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: left;'>
                <b>Unterstützung:</b>
            </td>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: right;'>
                " . fnum($total_support_units) . " / " . fnum($support_limit) . "
            </td>
        </tr>" : "") . "
        <tr>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: left;'>
                <b>Warteschlangenlimit:</b>
            </td>
            <td style='background: transparent; border: none; padding: 2px 0; text-align: right;'>
                $dynamic_limit Einheiten
            </td>
        </tr>
        <tr>
            <td colspan='2' style='background: transparent; border: none; padding-top: 10px; text-align: center;'>
                <button type='button' data-on-click='openAllTroopsOverlay'>Alle Truppen anzeigen</button>
            </td>
        </tr>
    </table>
</div>";

$res_other_k = $db_instance->execute_query(
    "SELECT 
                sub.id, 
                sub.kingdomname, 
                sub.mapx, 
                sub.mapy, 
                sub.k_pos, 
                sub.barracks_level,
                sub.already_sent
            FROM (
                SELECT 
                    k.id, 
                    k.kingdomname, 
                    k.mapx, 
                    k.mapy,
                    ROW_NUMBER() OVER (ORDER BY k.created_at, k.id) AS k_pos,
                    IFNULL(b.buildinglevel, 0) AS barracks_level,
                    (SELECT COUNT(*) FROM events e WHERE e.actionid = ? AND e.userid = ? AND e.kingdomid = ? AND e.targetid = k.id) AS already_sent
                FROM kingdoms k
                LEFT JOIN buildings b ON k.id = b.kingdomid AND b.buildingid = ?
                WHERE k.userid = ?
            ) sub
            WHERE sub.id != ?
            ORDER BY sub.k_pos",
    [
        ActionTypes::ACTION_SEND_TROOPS,
        $user->get_user_id(),
        $current_kingdom,
        BuildingTypes::BUILDING_BARRACKS,
        $user->get_user_id(),
        $current_kingdom
    ]
);
$other_kingdoms = $res_other_k->fetch_all(MYSQLI_ASSOC);

if (!empty($other_kingdoms)) {
    $map = new Map($user);
    $my_x = $kingdom->get_kingdom_map_x();
    $my_y = $kingdom->get_kingdom_map_y();

    $available_count = 0;
    $options_html = "";
    $first_available_id = null;

    foreach ($other_kingdoms as $ok) {
        $ok_id = (int)$ok["id"];
        $k_pos = $ok["k_pos"];
        $is_sent = !empty($ok["already_sent"]);

        $seconds = $map->get_arrival_time($my_x, $my_y, (int)$ok["mapx"], (int)$ok["mapy"], $current_kingdom, $ok_id);
        $time_str = convert_sec_to_str($seconds, true);

        if ((int)$ok["barracks_level"] <= 0) {
            $options_html .= "<option value='$ok_id' disabled data-x='{$ok["mapx"]}' data-y='{$ok["mapy"]}'>$k_pos - " . e($ok["kingdomname"]) . " (Keine Kaserne!)</option>";
        } else if (!$is_sent) {
            $available_count++;

            if ($first_available_id === null) {
                $first_available_id = $ok_id;
                $first_time_str = $time_str;
            }

            $selected = ($ok_id === $first_available_id) ? "selected" : "";
            $options_html .= "<option value='$ok_id' data-x='{$ok["mapx"]}' data-y='{$ok["mapy"]}' data-time='$time_str' $selected>$k_pos - " . e($ok["kingdomname"]) . " ({$ok["mapx"]}:{$ok["mapy"]})</option>";
        } else {
            $options_html .= "<option value='$ok_id' disabled style='color: #888;' data-time='$time_str'>$k_pos - " . e($ok["kingdomname"]) . " (Truppen unterwegs)</option>";
        }
    }

    $is_disabled = ($available_count === 0);
    $init_time_display = !empty($first_time_str) ? "(Dauer: $first_time_str)" : "";

    $view .= "<div style='display: flex; flex-direction: column; align-items: flex-start; margin: 0 auto 15px auto; width: fit-content;'>";

    if (!$is_disabled) {
        $view .= "<label for='station-arrival-display'>Ziel: <small id='station-arrival-display' style='opacity: 0.75;'>$init_time_display</small></label>";
    }

    $view .= "<div style='display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap;'>";
    if ($is_disabled) {
        $view .= "
            <select id='station_target_kingdom' class='js-barracks-send-select' disabled>
                <option value=''>-</option>
            </select>
            <button type='button' disabled title='Zu allen anderen Königreichen sind bereits Truppen unterwegs'>
                Truppen stationieren
            </button>";
    } else {
        $view .= "
            <select id='station_target_kingdom' class='js-barracks-send-select' data-on-change='changeStationTarget'>
                $options_html
            </select>
            <button type='button' data-on-click='openStationOverlay'>
                Truppen stationieren
            </button>";
    }
    $view .= "</div></div><div class='title-border'>Ausbildung & Aufwertung</div>";
}

$categories = SoldierTypes::get_labels();

$view .= '<div id="kingdom-resources" 
    data-food="' . $kingdom_food . '" 
    data-wood="' . $kingdom_wood . '" 
    data-stone="' . $kingdom_stone . '" 
    data-gold="' . $kingdom_gold . '" 
    data-villager="' . $kingdom_villager . '"
    data-dynamic-limit="' . $dynamic_limit . '"
    data-smithy-multiplier="' . $smithy_multiplier . '"
    data-space-left="' . $space_left . '"></div>';

$show_all_checked = (($_COOKIE["me_list_view"] ?? "0") === "1");
$is_support_tab = ($active_cat === SoldierTypes::SOLDIER_TYPE_SUPPORT);

$view .= "<div class='tab' id='barracks-tabs-standard' style='" . ($show_all_checked ? "display: none;" : "") . "'>";
foreach ($categories as $id => $name) {
    if ($id === SoldierTypes::SOLDIER_TYPE_SUPPORT && !$show_support_feature) {
        continue;
    }

    if ($category_availability[$id]) {
        $active_class = ($id === $active_cat) ? "active" : "";
        $view .= "<div class='tablinks $active_class' data-on-click='filterBarracks' data-category='$id'>$name</div>";
    } else {
        $view .= "<div class='tablinks tab-disabled' title='Hier gibt es noch keine Einheiten'>$name</div>";
    }
}
$view .= "</div>";

// Tab-Container 2: List View (Units & Support)
$view .= "<div class='tab' id='barracks-tabs-list' style='" . ($show_all_checked ? "" : "display: none;") . "'>";
$view .= "<div class='tablinks " . (!$is_support_tab ? "active" : "") . "' data-on-click='filterBarracksList' data-tab='units'>Einheiten</div>";
if ($show_support_feature) {
    $view .= "<div class='tablinks " . ($is_support_tab ? "active" : "") . "' data-on-click='filterBarracksList' data-tab='support'>Unterstützung</div>";
}
$view .= "</div>";

$recruitment_visible = !$is_support_tab;

$view .= '<table class="table" id="recruitment-table" style="' . ($recruitment_visible ? '' : "display: none;") . '">
                        <colgroup>
                            <col class="col-description">
                            <col class="col-action">
                        </colgroup>
                        <tr id="recruitment-header" style="' . ($show_all_checked ? 'display: none;' : '') . '">
                            <td class="td-center td-gradient">
                                <b>Soldat</b></td>
                            <td class="td-center td-gradient">
                                <b>Aktion</b></td>
                        </tr>';

$prev_cat = -1;
$category_names = [
    SoldierTypes::SOLDIER_TYPE_INFANTRY => "Infanterie",
    SoldierTypes::SOLDIER_TYPE_CAVALRY => "Kavallerie",
    SoldierTypes::SOLDIER_TYPE_ARCHERS => "Fernkampf",
    SoldierTypes::SOLDIER_TYPE_SPECIAL => "Spezialeinheiten"
];

$curr_founded = (int)($db_instance->execute_query(
    "SELECT COUNT(*) FROM kingdoms WHERE userid = ? AND creation_method != " . KingdomCreationTypes::KINGDOM_CREATION_CONQUEST,
    [$user->get_user_id()]
)->fetch_column() ?? 0);

$imp_bonus = (int)($db_instance->execute_query(
    "SELECT IFNULL(MAX(t.techlevel), 0) FROM techs t JOIN kingdoms k ON t.kingdomid = k.id WHERE k.userid = ? AND t.techid = ?",
    [$user->get_user_id(), TechTypes::TECH_TYPE_IMPERIAL]
)->fetch_column() ?? 0);

foreach ($soldiers as $i => $soldier_obj) {
    $s_id_internal = $soldier_obj->get_soldier_id();
    $owned_count = $kingdom_soldiers[$s_id_internal] ?? 0;
    $req_lvl = $soldier_obj->get_soldier_required_level();

    if ($req_lvl > $barracks_lvl && $owned_count <= 0) {
        continue;
    }

    $can_train = ($req_lvl <= $barracks_lvl);
    $is_hero = ($soldier_obj->get_soldier_id() == Soldiers::SOLDIER_HERO);
    $unit_cat = $soldier_obj->get_soldier_category();

    if ($prev_cat !== $unit_cat) {
        $cat_label = $category_names[$unit_cat] ?? "";
        $divider_style = ($show_all_checked && !$is_support_tab) ? "" : "display: none;";

        $view .= "<tr class='unit-category-divider' style='$divider_style'>
                    <td class='td-center td-gradient' style='border-top: 1px solid var(--box-header); border-bottom: 2px solid var(--box-header);'>
                        <b>$cat_label</b>
                    </td>
                    <td class='td-center td-gradient' style='border-top: 1px solid var(--box-header); border-bottom: 2px solid var(--box-header);'>
                        <b>Aktion</b>
                    </td>
                  </tr>";
    }
    $prev_cat = $unit_cat;

    $base_unit_time = $soldier_obj->get_soldier_time();
    $unit_time_display = max(1, (int)round($base_unit_time * $smithy_multiplier));
    $plunder_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_PLUNDER);

    $unit_special_limit = $kingdom->get_special_unit_limit($s_id_internal);
    $unit_total_in_kingdom = ($unit_special_limit !== null)
        ? $kingdom->get_unit_total_count($s_id_internal, true, true)
        : 0;
    $is_space_exempt = in_array($s_id_internal, TROOP_LIMIT_EXEMPT, true);

    $cost_food = (int)($soldier_obj->get_soldier_food_cost());
    $cost_gold = (int)($soldier_obj->get_soldier_gold_cost());
    $cost_stone = (int)($soldier_obj->get_soldier_stone_cost());
    $cost_wood = (int)($soldier_obj->get_soldier_wood_cost());
    $cost_villager = $soldier_obj->get_soldier_villager_cost();

    $text_food = "<span id='cost-food-$i'>" . fnum($cost_food) . "</span>";
    $text_gold = "<span id='cost-gold-$i'>" . fnum($cost_gold) . "</span>";
    $text_stone = "<span id='cost-stone-$i'>" . fnum($cost_stone) . "</span>";
    $text_wood = "<span id='cost-wood-$i'>" . fnum($cost_wood) . "</span>";
    $text_villager = "<span id='cost-villager-$i'>" . fnum($cost_villager) . "</span>";

    $capacity_text = "";

    switch ($s_id_internal) {
        case Soldiers::SOLDIER_THIEF:
        case Soldiers::SOLDIER_RAIDER:
            $base_cap = ($s_id_internal == Soldiers::SOLDIER_THIEF) ? THIEF_BASE_CAPACITY : RAIDER_BASE_CAPACITY;
            $current_cap = floor($base_cap * (1 + ($plunder_lvl * PLUNDER_CAPACITY_BONUS)));

            $capacity_text = "<br><br><span style='font-size: 0.9em;'><b>Aktuelle Kapazität:</b> " . fnum($current_cap) . " Ressourcen / Einheit</span>";

            if ($plunder_lvl > 0) {
                $capacity_text .= "<br><small style='opacity: 0.7;'>(Inkl. " . fdec($plunder_lvl * PLUNDER_CAPACITY_BONUS * 100) . "% Forschungs-Bonus)</small>";
            }
            break;
        case Soldiers::SOLDIER_CONQUEROR:
            $base = fdec(BASE_CONQUEST_CHANCE * 100);
            $step = fdec(MIN_CONQUEST_CHANCE * 100);
            $max = fdec(MAX_CONQUEST_CHANCE * 100);

            $capacity_text = "<br><br><span style='font-size: 0.9em;'>Erfolg: <b>$base%</b> Basis-Chance</span>";
            $capacity_text .= "<br><small>(+$step% je weiteren Eroberer, max. $max%)</small>";
            break;
        case Soldiers::SOLDIER_SETTLER_WAGON:
            $current_limit = min(GLOBAL_SETTLEMENT_MAX, BASE_SETTLEMENT_LIMIT + $imp_bonus);

            $base_chance = fdec(BASE_SETTLER_CHANCE * 100);

            $capacity_text = "<br><br><span style='font-size: 0.9em;'>Chance: <b>$base_chance%</b> Erfolgsrate</span>";
            $capacity_text .= "<br><span style='font-size: 0.9em;'>Imperium: <b>$curr_founded / $current_limit</b> gegründeten Siedlungen</span>";

            if ($curr_founded >= $current_limit) {
                $capacity_text .= "<br><small class='error'>(Maximales Limit erreicht)</small>";
            } else {
                $capacity_text .= "<br><small>(Erhöhbar durch 'Imperium' Forschung)</small>";
            }
            break;
        case Soldiers::SOLDIER_RAM:
            $siege_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SIEGE);
            $current_wall_dmg = RAM_FLAT_DAMAGE * (1 + ($siege_lvl * SMITHY_SIEGE_BONUS));

            $capacity_text = "<br><br><span style='font-size: 0.9em;'><b>Aktueller Mauerschaden:</b> " . fdec($current_wall_dmg) . " HP / Einheit</span>";

            if ($siege_lvl > 0) {
                $capacity_text .= "<br><small style='opacity: 0.7;'>(Inkl. " . fdec($siege_lvl * SMITHY_SIEGE_BONUS * 100) . "% Schmiede-Bonus)</small>";
            } else {
                $capacity_text .= "<br><small>(Erhöhbar durch 'Belagerungsgerät' in der Schmiede)</small>";
            }
            break;
    }

    if ($is_hero) {
        $text_build = "<i>Einzigartig</i>";
    } else if (!$can_train) {
        $text_build = "<div style='font-size: 11px;'>
                        <span class='error item-needed'>Kaserne Stufe $req_lvl benötigt</span>
                      </div>";
    } else if ($kingdom_is_recruiting || $kingdom_is_upgrading) {
        if ($kingdom_recruiting_id == $i) {
            $result = $db_instance->execute_query(
                "SELECT buildingtime, recruittime, soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?",
                [$current_kingdom, ActionTypes::ACTION_BUILD_TROOPS, $i]
            );

            $row = $result->fetch_assoc();
            $raw_goal = max(1, (int)$row["soldiergoal"]);
            $current_unit_time = max(1, $unit_time_display);
            $current_time = time();
            $recruittime = (int)$row["recruittime"];

            if ($current_time >= $recruittime) {
                $remaining_for_this_unit = 0;
                $soldier_goal = 1;
            } else {
                $elapsed_since_unit_start = max(0, $current_time - (int)$row["buildingtime"]);
                $units_already_done = (int)floor($elapsed_since_unit_start / $current_unit_time);
                $soldier_goal = max(1, $raw_goal - $units_already_done);
                $remaining_for_this_unit = $current_unit_time - ($elapsed_since_unit_start % $current_unit_time);
            }

            $vill_cost_per_unit = (int)$soldier_obj->get_soldier_villager_cost();

            $text_build = "In Ausbildung: <b id='recruit-goal-$i'>$soldier_goal</b><br>
                            <span class='js-countdown' 
                                  id='recruit-countdown-$i'
                                  data-seconds='$remaining_for_this_unit' 
                                  data-unit-time='$current_unit_time'
                                  data-goal='$soldier_goal'
                                  data-goal-target='recruit-goal-$i'
                                  data-villager-cost='$vill_cost_per_unit'
                                  data-produced-soldier-id='$s_id_internal'
                                  data-hide-id='cancel-form' 
                                  data-timer-cat='$unit_cat'>
                                " . format_time_for_js($remaining_for_this_unit) . "</span><br>
                              <form id='cancel-form' action='barracks.php' method='POST'>
                                    <input type='hidden' name='action' value='cancel'>
                                    <input type='hidden' name='recruit' value='$i'>
                                    <input type='hidden' name='cat' value='$unit_cat'>
                                    <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                                </form>";
        } else if ($kingdom_is_upgrading && $upgrade_event["buildingid"] == $soldier_obj->get_soldier_id()) {
            $target_id = (int)$upgrade_event["soldierid"];
            $target_name = "Unbekannt";
            $upg_unit_time = 1;

            foreach ($soldiers as $s) {
                if ($s->get_soldier_id() == $target_id) {
                    $target_name = $s->get_soldier_name();
                    $upg_unit_time = max(1, (int)round($s->get_soldier_time() * $smithy_multiplier));
                    break;
                }
            }

            $current_time = time();
            $raw_goal = max(1, (int)$upgrade_event["soldiergoal"]);
            $recruittime = (int)$upgrade_event["recruittime"];

            if ($current_time >= $recruittime) {
                $rem = 0;
                $upg_goal = 1;
            } else {
                $start_time = ((int)($upgrade_event["buildingtime"] ?? 0) > 0)
                    ? (int)$upgrade_event["buildingtime"]
                    : ($recruittime - ($raw_goal * $upg_unit_time));
                $elapsed = max(0, $current_time - $start_time);
                $units_already_done = (int)floor($elapsed / $upg_unit_time);
                $upg_goal = max(1, $raw_goal - $units_already_done);
                $rem = $upg_unit_time - ($elapsed % $upg_unit_time);
            }

            $text_build = "Aufwertung zu <b>$target_name:</b> <b id='upgrade-goal-$i'>$upg_goal</b><br>
            <span class='js-countdown' 
                  id='upgrade-countdown-$i'
                  data-seconds='$rem' 
                  data-unit-time='$upg_unit_time'
                  data-goal='$upg_goal'
                  data-goal-target='upgrade-goal-$i'
                  data-produced-soldier-id='$target_id'
                  data-hide-id='cancel-form-upg' 
                  data-timer-cat='$unit_cat'>
                " . format_time_for_js($rem) . "</span><br>
            <form id='cancel-form-upg' action='barracks.php' method='POST'>
                <input type='hidden' name='action' value='cancel'>
                <input type='hidden' name='recruit' value='$i'>
                <input type='hidden' name='cat' value='$unit_cat'>
                <input type='submit' value='Abbruch' style='margin-top: 5px;'>
            </form>";
        } else {
            $text_build = "-";
        }
    } else {
        // Calculate the maximum soldiers recruitable based on each resource
        $max_soldiers = $dynamic_limit;

        $food_cost = $soldier_obj->get_soldier_food_cost();
        $gold_cost = $soldier_obj->get_soldier_gold_cost();
        $stone_cost = $soldier_obj->get_soldier_stone_cost();
        $wood_cost = $soldier_obj->get_soldier_wood_cost();
        $vill_cost = $soldier_obj->get_soldier_villager_cost();

        if ($food_cost > 0) $max_soldiers = min($max_soldiers, floor($kingdom_food / $food_cost));
        if ($gold_cost > 0) $max_soldiers = min($max_soldiers, floor($kingdom_gold / $gold_cost));
        if ($stone_cost > 0) $max_soldiers = min($max_soldiers, floor($kingdom_stone / $stone_cost));
        if ($wood_cost > 0) $max_soldiers = min($max_soldiers, floor($kingdom_wood / $wood_cost));
        if ($vill_cost > 0) $max_soldiers = min($max_soldiers, floor($kingdom_villager / $vill_cost));

        $max_soldiers = max(0, $max_soldiers);
        $owned_count = $kingdom_soldiers[$soldier_obj->get_soldier_id()] ?? 0;

        $unit_cap_remaining = ($unit_special_limit !== null) ? max(0, $unit_special_limit - $unit_total_in_kingdom) : 999999;
        $max_space_for_this = $is_space_exempt ? $unit_cap_remaining : min($space_left, $unit_cap_remaining);
        $can_train_at_least_one = ($max_soldiers > 0 && $max_space_for_this > 0);

        $is_disabled = $can_train_at_least_one ? "" : "disabled";

        $limit_attrs = ($unit_special_limit !== null) ? " data-unit-limit='$unit_special_limit' data-unit-total='$unit_total_in_kingdom'" : "";
        if ($is_space_exempt) {
            $limit_attrs .= " data-space-exempt='true'";
        }

        $text_build = "<form action='barracks.php' method='POST' style='display: flex; flex-direction: column; gap: 5px; align-items: center;'>
                        <input type='hidden' name='action' value='train'>
                        <input type='hidden' name='recruit' value='$i'>
                        <input type='hidden' name='cat' value='$unit_cat'>
                        
                        <div style='display: flex; gap: 3px;'>
                            <input type='text' name='count' id='count$i' size='2' maxlength='2' 
                                   class='js-recruit-input' data-id='$i'
                                   data-owned='$owned_count'
                                   data-cost-food='$cost_food' data-cost-gold='$cost_gold'
                                   data-cost-stone='$cost_stone' data-cost-wood='$cost_wood'
                                   data-cost-villager='$cost_villager' data-time-per-unit='$base_unit_time'
                                   $limit_attrs
                                   inputmode='numeric' pattern='[0-9]*'
                                   placeholder='0' $is_disabled>
                            <input type='button' value='Max.' data-on-click='fillMaxAndCalc' data-target='count$i' $is_disabled>
                        </div>";

        // Upgrade-Dropdown
        if ($unit_cat != SoldierTypes::SOLDIER_TYPE_SPECIAL && ($kingdom_soldiers[$soldier_obj->get_soldier_id()] ?? 0) > 0) {
            $possible_targets = [];
            foreach ($soldiers as $target_soldier) {
                if ($target_soldier->get_soldier_category() == $unit_cat &&
                    $target_soldier->get_soldier_required_level() > $soldier_obj->get_soldier_required_level() &&
                    $target_soldier->get_soldier_required_level() <= $building->get_building_level()) {
                    $possible_targets[] = $target_soldier;
                }
            }

            if (!empty($possible_targets)) {
                $text_build .= "<select name='upgrade_to' class='js-upgrade-select' data-id='$s_id_internal'>
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

        $text_build .= "<input type='submit' name='start_action' value='Starten' disabled>
                  </form>";
    }

    $row_style = ($show_all_checked || $unit_cat === $active_cat) ? "" : "display: none;";
    if ($is_support_tab) {
        $row_style = "display: none;";
    }
    $row_class = "unit-row" . (!$can_train ? " unit-not-trainable" : "");

    $owned_in_garrison = $kingdom_soldiers[$s_id_internal] ?? 0;
    $owned_total = $total_kingdom_soldiers[$s_id_internal] ?? $owned_in_garrison;

    $stock_info = "";
    if ($owned_total > 0 || $unit_special_limit !== null) {
        $limit_display = ($unit_special_limit !== null) ? " / " . $unit_special_limit : "";
        $total_count_val = ($unit_special_limit !== null) ? $unit_total_in_kingdom : $owned_total;

        $stock_info = "<hr style='margin: 8px 0;'>"
            . "<b>Gesamt im Königreich:</b> " . fnum($total_count_val) . $limit_display;
    }

    $view .= "<tr class='$row_class' data-unit-category='$unit_cat' style='$row_style'>
            <td>
                <div class='map-legend' style='justify-content: left;'>
                    <div class='legend-item'>" . $soldier_obj->get_soldier_icon() . "</div>
                    <div class='legend-item'>                        
                        <b class='popup' id='description" . $i . "'>" . $soldier_obj->get_soldier_name() . " 
                            <div id='description" . $i . "_box' class='popupbox'>
                                " . $soldier_obj->get_soldier_description() . "
                                " . $capacity_text . "
                                " . $stock_info . "
                            </div> (<span class='js-unit-owned-count' data-soldier-id='$s_id_internal'>" . $owned_in_garrison . "</span>)
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
                    <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " " . $soldier_obj->get_soldier_attack() . "</div>
                    <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " " . $soldier_obj->get_soldier_defense() . "</div>
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

$view .= "<div id='support-container' data-unit-category='" . SoldierTypes::SOLDIER_TYPE_SUPPORT . "' style='" . ($recruitment_visible ? 'display: none;' : '') . "'>";

// --- SECTION: INCOMING ---
$map_support_helper = new Map($user);

// --- SECTION: INCOMING ---
$view .= "<div class='title-border'>Erhaltene Unterstützung</div>";
$res_in = $db_instance->execute_query("
    SELECT st.owner_id, st.source_kingdom_id, st.target_kingdom_id, st.soldier_id, 
           SUM(st.soldiercount) as soldiercount,
           u.username as owner_name, 
           k_src.kingdomname as src_kname, k_src.mapx as src_x, k_src.mapy as src_y,
           k_tgt.kingdomname as tgt_kname, k_tgt.mapx as tgt_x, k_tgt.mapy as tgt_y,
           sl.soldiername, 
           sl.icon 
    FROM stationed_troops st
    JOIN users u ON st.owner_id = u.id
    JOIN kingdoms k_src ON st.source_kingdom_id = k_src.id
    JOIN kingdoms k_tgt ON st.target_kingdom_id = k_tgt.id
    JOIN soldier_list sl ON st.soldier_id = sl.id
    WHERE k_tgt.userid = ?
    GROUP BY st.owner_id, st.source_kingdom_id, st.target_kingdom_id, st.soldier_id, 
             u.username, k_src.kingdomname, k_src.mapx, k_src.mapy, k_tgt.kingdomname, k_tgt.mapx, k_tgt.mapy, sl.soldiername, sl.icon
    ORDER BY k_tgt.kingdomname, u.username", [$user->get_user_id()]
);

$grouped_in = [];
foreach ($res_in as $row) {
    $key = $row["target_kingdom_id"] . '_' . $row["owner_id"] . '_' . $row["source_kingdom_id"];
    if (!isset($grouped_in[$key])) {
        $grouped_in[$key] = [
            "owner_name" => $row["owner_name"],
            "src_kname" => $row["src_kname"],
            "src_x" => (int)$row["src_x"],
            "src_y" => (int)$row["src_y"],
            "tgt_kname" => $row["tgt_kname"],
            "tgt_x" => (int)$row["tgt_x"],
            "tgt_y" => (int)$row["tgt_y"],
            "owner_id" => (int)$row["owner_id"],
            "source_id" => (int)$row["source_kingdom_id"],
            "target_id" => (int)$row["target_kingdom_id"],
            "troops" => []
        ];
    }
    $grouped_in[$key]["troops"][] = $row;
}

if (!empty($grouped_in)) {
    $view .= "<table class='table support-incoming-table'>
    <colgroup>
        <col style='width: 31%;'>  <!-- Soldaten -->
        <col style='width: 33%;'>  <!-- Unterstützer -->
        <col style='width: 18%;'>  <!-- Dauer -->
        <col style='width: 18%;'>  <!-- Aktion -->
    </colgroup>
    <tr>
        <td class='td-gradient td-center'><b>Soldaten</b></td>
        <td class='td-gradient td-center'><b>Unterstützer</b></td>
        <td class='td-gradient td-center'><b>Abzugszeit</b></td>
        <td class='td-gradient td-center'><b>Aktion</b></td>
    </tr>";

    foreach ($grouped_in as $data) {
        $troop_icons = "<div style='display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;'>";
        foreach ($data["troops"] as $t) {
            $troop_icons .= "
            <div class='unit-badge' title='{$t["soldiername"]}'>
                <img src='images/icons/{$t["icon"]}.png' class='ressource-icons' alt=''>
                <b>{$t["soldiercount"]}</b>
            </div>";
        }
        $troop_icons .= "</div>";

        $return_secs = $map_support_helper->get_arrival_time(
            $data["tgt_x"], $data["tgt_y"],
            $data["src_x"], $data["src_y"],
            $data["source_id"], null, false, false, true
        );
        $return_time_str = convert_sec_to_str($return_secs, true);

        $view .= "<tr>
            <td class='td-center'>$troop_icons</td>
            <td class='td-center'>
                <span style='font-weight: bold;'>" . e($data["owner_name"]) . "</span><br>
                <small style='opacity: 0.7;'>aus " . e($data["src_kname"]) . "</small><br>
                <small class='passed'>in " . e($data["tgt_kname"]) . "</small>
            </td>
            <td class='td-center'>$return_time_str</td>
            <td class='td-center'>
                <form method='POST'>
                    <input type='hidden' name='action' value='return_support_stack'>
                    <input type='hidden' name='target_id' value='{$data["target_id"]}'>
                    <input type='hidden' name='source_id' value='{$data["source_id"]}'>
                    <input type='hidden' name='owner_id' value='{$data["owner_id"]}'>
                    <input type='submit' name='return_support_stack' value='Entlassen'>
                </form>
            </td>
        </tr>";
    }
    $view .= "</table>";
} else {
    $view .= "<span class='no-event'>Keine Truppen von Gildenmitgliedern anwesend.</span>";
}

// --- SECTION: OUTGOING ---
$view .= "<br><div class='title-border'>Gesendete Unterstützung</div>";
$res_out = $db_instance->execute_query("
    SELECT st.target_kingdom_id, st.source_kingdom_id, st.soldier_id, SUM(st.soldiercount) as soldiercount,
           k_tgt.kingdomname as tgt_kname, k_tgt.mapx, k_tgt.mapy, 
           k_src.kingdomname as src_kname, k_src.mapx as src_x, k_src.mapy as src_y,
           sl.soldiername, sl.icon,
           u_tgt.username as target_owner_name, u_tgt.id as target_userid
    FROM stationed_troops st
    JOIN kingdoms k_tgt ON st.target_kingdom_id = k_tgt.id
    JOIN kingdoms k_src ON st.source_kingdom_id = k_src.id
    JOIN users u_tgt ON k_tgt.userid = u_tgt.id
    JOIN soldier_list sl ON st.soldier_id = sl.id
    WHERE st.owner_id = ?
    GROUP BY st.target_kingdom_id, st.source_kingdom_id, st.soldier_id,
             k_tgt.kingdomname, k_tgt.mapx, k_tgt.mapy, k_src.kingdomname, k_src.mapx, k_src.mapy,
             sl.soldiername, sl.icon, u_tgt.username, u_tgt.id",
    [$user->get_user_id()]
);

$grouped_out = [];
foreach ($res_out as $row) {
    $key = $row["source_kingdom_id"] . '_' . $row["target_kingdom_id"];
    if (!isset($grouped_out[$key])) {
        $grouped_out[$key] = [
            "tgt_kname" => $row["tgt_kname"],
            "src_kname" => $row["src_kname"],
            "src_x" => (int)$row["src_x"],
            "src_y" => (int)$row["src_y"],
            "target_owner" => $row["target_owner_name"],
            "target_userid" => (int)$row["target_userid"],
            "mapx" => (int)$row["mapx"],
            "mapy" => (int)$row["mapy"],
            "target_id" => (int)$row["target_kingdom_id"],
            "source_id" => (int)$row["source_kingdom_id"],
            "troops" => []
        ];
    }
    $grouped_out[$key]["troops"][] = $row;
}

if (!empty($grouped_out)) {
    $view .= "<table class='table support-outgoing-table'>
    <colgroup>
        <col style='width: 22%;'>  <!-- Soldaten -->
        <col style='width: 38%;'>  <!-- Ziel-Königreich & Koordinaten -->
        <col style='width: 16%;'>  <!-- Dauer -->
        <col style='width: 16%;'>  <!-- Aktion -->
    </colgroup>
    <tr>
        <td class='td-gradient td-center'><b>Soldaten</b></td>
        <td class='td-gradient td-center'><b>Königreich</b></td>
        <td class='td-gradient td-center'><b>Abzugszeit</b></td>
        <td class='td-gradient td-center'><b>Aktion</b></td>
    </tr>";

    foreach ($grouped_out as $data) {
        $troop_icons = "<div style='display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;'>";
        foreach ($data["troops"] as $t) {
            $troop_icons .= "
            <div class='unit-badge' title='{$t["soldiername"]}'>
                <img src='images/icons/{$t["icon"]}.png' class='ressource-icons' alt=''>
                <b>{$t["soldiercount"]}</b>
            </div>";
        }
        $troop_icons .= "</div>";

        $return_secs = $map_support_helper->get_arrival_time(
            $data["mapx"], $data["mapy"],
            $data["src_x"], $data["src_y"],
            $data["source_id"], null, false, false, true
        );
        $return_time_str = convert_sec_to_str($return_secs, true);

        $sender_link = "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $data["target_userid"] . "' data-title='Spieler-Info'>" . e($data["target_owner"]) . "</a>";
        $c_link = "<a href='map.php?startx={$data["mapx"]}&starty={$data["mapy"]}' data-on-click='mapJump' data-x='{$data["mapx"]}' data-y='{$data["mapy"]}'>{$data["mapx"]}:{$data["mapy"]}</a>";

        $view .= "<tr>
            <td class='td-center'>$troop_icons</td>
            <td class='td-center'>
                <b>" . e($data["tgt_kname"]) . "</b>
                <small>($c_link)</small><br>$sender_link<br>
                <small style='opacity: 0.7;'>aus " . e($data["src_kname"]) . "</small>
            </td>
            <td class='td-center'>$return_time_str</td>
            <td class='td-center'>
                <form method='POST'>
                    <input type='hidden' name='action' value='return_support_stack'>
                    <input type='hidden' name='return_support_stack' value='1'>
                    <input type='hidden' name='target_id' value='{$data["target_id"]}'>
                    <input type='hidden' name='source_id' value='{$data["source_id"]}'>
                    <input type='submit' name='return_support_stack' value='Heimrufen'>
                </form>
            </td>
        </tr>";
    }
    $view .= "</table>";
} else {
    $view .= "<span class='no-event'>Keine Truppen bei Gildenmitgliedern stationiert.</span>";
}
$view .= "</div>";

/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = ["timer", "barracks", "userinfo"];

include("layout/base.php");
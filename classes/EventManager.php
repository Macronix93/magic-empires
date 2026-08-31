<?php

class EventManager
{
    private mysqli $mysqli;
    private User $user;
    private static ?array $cached_soldiers = null;

    public function __construct(User $user)
    {
        $this->mysqli = Database::get_instance()->get_connection();
        $this->user = $user;
    }

    public function process_all(): void
    {
        $this->check_watchtower_notifications($this->user->get_user_id());

        $uid = $this->user->get_user_id();
        if ($uid <= 0) return;

        $now = time();

        $query = "
            SELECT e.* 
            FROM events e
            LEFT JOIN kingdoms k ON e.targetid = k.id
            WHERE (e.userid = ? OR k.userid = ?)
        ";
        $result = $this->mysqli->execute_query($query, [$uid, $uid]);

        foreach ($result as $row) {
            $is_due = false;

            if (in_array($row["actionid"], [
                    ActionTypes::ACTION_BUILD_BUILDING,
                    ActionTypes::ACTION_RESEARCH_TECH,
                    ActionTypes::ACTION_SMITHY_UPGRADE])
                && $row["buildingtime"] <= $now) $is_due = true;

            if ($row["actionid"] == ActionTypes::ACTION_BUILD_TROOPS) {
                $soldiers_stats = $this->load_soldier_data();
                $s_id = $row["soldierid"];
                $time_per_unit = $soldiers_stats[$s_id]->get_soldier_time();

                $next_unit_ready = $row["recruittime"] - (($row["soldiergoal"] - 1) * $time_per_unit);

                if ($now >= $next_unit_ready) $is_due = true;
            }

            if (in_array($row["actionid"], [
                    ActionTypes::ACTION_SEND_TROOPS,
                    ActionTypes::ACTION_RETURN_TROOPS,
                    ActionTypes::ACTION_RECEIVE_RESOURCES,
                    ActionTypes::ACTION_RETURN_RESOURCES,
                    ActionTypes::ACTION_UPGRADE_TROOPS,
                    ActionTypes::ACTION_STATION_TROOPS,
                    ActionTypes::ACTION_SUPPORT_RETURN])
                && $row["arrivaltime"] <= $now) $is_due = true;

            if (!$is_due) continue;

            $lock_timeout = 10;

            if ($row["is_processing"] > 0 && ($now - $row["is_processing"]) < $lock_timeout) {
                continue;
            }

            $this->mysqli->execute_query(
                "UPDATE events SET is_processing = ? WHERE eventid = ? AND (is_processing = 0 OR is_processing < ?)",
                [$now, $row["eventid"], ($now - 60)]
            );

            if ($this->mysqli->affected_rows === 1) {
                try {
                    $this->mysqli->begin_transaction();

                    $this->handle_event($row);

                    $this->mysqli->commit();
                } catch (Throwable $t) {
                    $this->mysqli->rollback();
                    $this->mysqli->execute_query("UPDATE events SET is_processing = 0 WHERE eventid = ?", [$row["eventid"]]);

                    $action_name = $this->get_action_name((int)$row["actionid"]);
                    $event_data = json_encode($row, JSON_UNESCAPED_UNICODE);

                    $error_msg = sprintf(
                        "Event ID %d crashed: Action: %s (ID: %d) | User: %d | Kingdom: %d | Error: %s | Data: %s",
                        $row["eventid"],
                        $action_name,
                        $row["actionid"],
                        $row["userid"],
                        $row["kingdomid"],
                        $t->getMessage(),
                        $event_data
                    );

                    Logger::get_instance()->error($error_msg);
                }
            }
        }
    }

    private function get_action_name(int $id): string
    {
        return match ($id) {
            ActionTypes::ACTION_BUILD_BUILDING => "Gebäudebau",
            ActionTypes::ACTION_BUILD_TROOPS => "Rekrutierung",
            ActionTypes::ACTION_SEND_TROOPS => "Truppenversand (Angriff/Stationierung)",
            ActionTypes::ACTION_RETURN_TROOPS => "Truppenrückkehr",
            ActionTypes::ACTION_RESEARCH_TECH => "Forschung",
            ActionTypes::ACTION_RECEIVE_RESOURCES => "Ressourcen-Eingang",
            ActionTypes::ACTION_RETURN_RESOURCES => "Ressourcen-Rückkehr",
            ActionTypes::ACTION_UPGRADE_TROOPS => "Truppen-Upgrade",
            ActionTypes::ACTION_SMITHY_UPGRADE => "Schmiede-Verbesserung",
            default => "Unbekannt"
        };
    }

    public function handle_event(array $row): void
    {
        switch ($row["actionid"]) {
            case ActionTypes::ACTION_RESEARCH_TECH:
            case ActionTypes::ACTION_SMITHY_UPGRADE:
                $this->handle_research($row);
                break;
            case ActionTypes::ACTION_BUILD_BUILDING:
                $this->handle_building($row);
                break;
            case ActionTypes::ACTION_BUILD_TROOPS:
                $this->handle_recruitment($row);
                break;
            case ActionTypes::ACTION_SEND_TROOPS:
                $this->handle_combat($row);
                break;
            case ActionTypes::ACTION_RETURN_TROOPS:
            case ActionTypes::ACTION_SUPPORT_RETURN:
                $this->handle_troop_return($row);
                break;
            case ActionTypes::ACTION_RECEIVE_RESOURCES:
                $this->handle_resource_transfer($row);
                break;
            case ActionTypes::ACTION_RETURN_RESOURCES:
                $origin_kingdom_id = (int)$row["kingdomid"];
                $kingdom = new Kingdom($this->mysqli, $origin_kingdom_id);
                $returned_resources = [];

                // Classic Trade
                if ($row["buildinglevel"] > 0) {
                    $res_type = (int)$row["buildingid"];
                    $amount = (int)$row["buildinglevel"];
                    $kingdom->modify_resource($res_type, $amount);
                    $returned_resources[$res_type] = $amount;
                }

                // Multi Resources
                $multi_res_map = [
                    ResourceTypes::RESOURCE_TYPE_FOOD => (int)$row["loot_food"],
                    ResourceTypes::RESOURCE_TYPE_WOOD => (int)$row["loot_wood"],
                    ResourceTypes::RESOURCE_TYPE_STONE => (int)$row["loot_stone"],
                    ResourceTypes::RESOURCE_TYPE_GOLD => (int)$row["loot_gold"]
                ];

                foreach ($multi_res_map as $type => $amount) {
                    if ($amount > 0) {
                        $kingdom->modify_resource($type, $amount);
                        $returned_resources[$type] = ($returned_resources[$type] ?? 0) + $amount;
                    }
                }

                if (!empty($returned_resources)) {
                    Logger::get_instance()->log_game("TRADE", "TRANSPORT_RETURNED", [
                        "event_id" => (int)$row["eventid"],
                        "resources" => $returned_resources
                    ], $origin_kingdom_id);
                }

                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
                break;
            case ActionTypes::ACTION_UPGRADE_TROOPS:
                $this->handle_upgrade_finish($row);
                break;
            case ActionTypes::ACTION_STATION_TROOPS:
                $this->handle_support_arrival($row);
                break;
        }
    }

    private function handle_research(array $row): void
    {
        if ($row["buildingtime"] > time()) return;

        $kingdom_id = $row["kingdomid"];
        $tech_id = $row["buildingid"];

        if ($row["buildinglevel"] == 0) {
            $this->mysqli->execute_query("INSERT INTO techs (kingdomid, techid, techname, techlevel) VALUES (?, ?, ?, ?)",
                [$kingdom_id, $tech_id, $row["buildingname"], 1]);
        } else {
            $this->mysqli->execute_query("UPDATE techs SET techlevel = techlevel + 1 WHERE kingdomid = ? AND techid = ?",
                [$kingdom_id, $tech_id]);
        }

        $kingdom = new Kingdom($this->mysqli, $kingdom_id);

        // Apply resource effects
        switch ($tech_id) {
            case TechTypes::TECH_TYPE_WOOD_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_wood_rate = base_wood_rate + ? WHERE id = ?",
                    [RESEARCH_WOOD_INC, $kingdom_id]);

                $kingdom = new Kingdom($this->mysqli, $kingdom_id);
                $kingdom->recalculate_production();
                break;

            case TechTypes::TECH_TYPE_FOOD_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_food_rate = base_food_rate + ? WHERE id = ?",
                    [RESEARCH_FOOD_INC, $kingdom_id]);

                $kingdom = new Kingdom($this->mysqli, $kingdom_id);
                $kingdom->recalculate_production();
                break;

            case TechTypes::TECH_TYPE_STONE_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_stone_rate = base_stone_rate + ? WHERE id = ?",
                    [RESEARCH_STONE_INC, $kingdom_id]);

                $kingdom = new Kingdom($this->mysqli, $kingdom_id);
                $kingdom->recalculate_production();
                break;

            case TechTypes::TECH_TYPE_GOLD_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_gold_rate = base_gold_rate + ? WHERE id = ?",
                    [RESEARCH_GOLD_INC, $kingdom_id]);

                $kingdom = new Kingdom($this->mysqli, $kingdom_id);
                $kingdom->recalculate_production();
                break;
            case TechTypes::TECH_TYPE_STORAGE_INC:
                $kingdom->set_kingdom_max_food($kingdom->get_kingdom_max_food() + RESEARCH_STORAGE_INC);
                $kingdom->set_kingdom_max_wood($kingdom->get_kingdom_max_wood() + RESEARCH_STORAGE_INC);
                $kingdom->set_kingdom_max_stone($kingdom->get_kingdom_max_stone() + RESEARCH_STORAGE_INC);
                $kingdom->set_kingdom_max_gold($kingdom->get_kingdom_max_gold() + RESEARCH_STORAGE_INC);
                break;
            case TechTypes::TECH_TYPE_WALL_HP_INC:
                if ($kingdom->get_wall_hp() == $kingdom->get_wall_max_hp()) {
                    $kingdom->set_wall_hp($kingdom->get_wall_hp() + RESEARCH_WALL_HP_INC);
                }
                break;
            case TechTypes::TECH_TYPE_ANCESTRAL_RITES:
                $kingdom->recalculate_production();
                break;
            case TechTypes::TECH_TYPE_CARTOGRAPHY:
            case TechTypes::TECH_TYPE_PLUNDER:
            case TechTypes::TECH_TYPE_ARCANE_INTEL:
            case TechTypes::TECH_TYPE_MAINTENANCE:
            case TechTypes::TECH_TYPE_ARCHITECTURE:
                break;
        }

        // Calculate score
        $res = $this->mysqli->execute_query("SELECT techscore FROM tech_list WHERE id = ?", [$tech_id]);
        $score_gain = $res->fetch_assoc()["techscore"] * $row["buildinglevel"] + 1;

        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

        $this->user->set_last_researched_tech($kingdom_id, $row["buildingname"], $row["buildinglevel"]);
        $this->update_user_score((int)$score_gain, $this->user);

        Logger::get_instance()->log_game("ECONOMY", "RESEARCH_FINISH", [
            "tech_id" => $tech_id,
            "tech_name" => $row["buildingname"],
            "level" => $row["buildinglevel"] + 1
        ], $kingdom_id);
    }

    private function handle_building(array $row): void
    {
        if ($row["buildingtime"] > time()) return;

        $res_check = $this->mysqli->execute_query(
            "SELECT userid FROM kingdoms WHERE id = ?", [$row["kingdomid"]]
        );
        $current_owner = $res_check->fetch_column();

        if ($current_owner != $row["userid"]) {
            // Kingdom was conquered! Delete building action
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
            return;
        }

        $res = $this->mysqli->execute_query("SELECT buildingscore FROM building_list WHERE id = ?", [$row["buildingid"]]);
        $score_gain = $res->fetch_assoc()["buildingscore"] * ($row["buildinglevel"] + 1);

        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

        if ($row["buildinglevel"] == 0) {
            $this->mysqli->execute_query("INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel) VALUES (?, ?, ?, ?)",
                [$row["kingdomid"], $row["buildingid"], $row["buildingname"], 1]);
        } else {
            $this->mysqli->execute_query("UPDATE buildings SET buildinglevel = buildinglevel + 1 WHERE kingdomid = ? AND buildingid = ?",
                [$row["kingdomid"], $row["buildingid"]]);
        }

        $this->user->set_last_built_building($row["kingdomid"], $row["buildingname"], $row["buildinglevel"]);
        $this->update_user_score((int)$score_gain, $this->user);
        update_player_stat((int)$row["userid"], "buildings_upgraded");

        // Special effects for a building after construction
        $this->apply_building_effects($row["buildingid"], $row["buildinglevel"], $row["kingdomid"]);

        Logger::get_instance()->log_game("ECONOMY", "BUILDING_UPGRADE", [
            "building" => $row["buildingname"],
            "level" => $row["buildinglevel"] + 1
        ], $row["kingdomid"]);
    }

    private function handle_upgrade_finish(array $row): void
    {
        $now = time();
        $kingdom_id = $row["kingdomid"];
        $from_id = $row["buildingid"];
        $to_id = $row["soldierid"];
        $goal = $row["soldiergoal"];

        $res_to = $this->mysqli->execute_query("SELECT soldiername, requiredtime, scoregain FROM soldier_list WHERE id = ?", [$to_id]);
        $target_data = $res_to->fetch_assoc();

        $res_from = $this->mysqli->execute_query("SELECT scoregain FROM soldier_list WHERE id = ?", [$from_id]);
        $source_score = $res_from->fetch_assoc()["scoregain"];

        $unit_time = $target_data["requiredtime"];
        $s_name = $target_data["soldiername"];
        $target_score = $target_data["scoregain"];

        $total_duration = $goal * $unit_time;
        $start_time = $row["recruittime"] - $total_duration;

        $units_finished_total = floor(($now - $start_time) / $unit_time);
        $units_to_add = min($goal, $units_finished_total);

        if ($units_to_add > 0) {
            $this->mysqli->execute_query(
                "INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
             VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE soldiercount = soldiercount + ?",
                [$kingdom_id, $to_id, $s_name, $units_to_add, $units_to_add]
            );

            $this->mysqli->execute_query("UPDATE events SET soldiergoal = soldiergoal - ? WHERE eventid = ?", [$units_to_add, $row["eventid"]]);

            $this->user->set_last_upgraded_soldier($kingdom_id, $s_name, $units_to_add);
            $score_difference = ($target_score - $source_score) * $units_to_add;

            if ($score_difference != 0) {
                $this->update_user_score((int)$score_difference, $this->user);
            }

            update_player_stat((int)$row["userid"], "units_upgraded", $units_to_add);
        }

        if ($units_to_add >= $goal) {
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
        } else {
            $this->mysqli->execute_query("UPDATE events SET is_processing = 0 WHERE eventid = ?", [$row["eventid"]]);
        }
    }

    private function handle_recruitment(array $row): void
    {
        $soldiers = $this->load_soldier_data();
        $s_id = $row["soldierid"];

        $kingdom = new Kingdom($this->mysqli, $row["kingdomid"]);
        $weight_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_WEIGHT);
        $discount = 1 - ($weight_lvl * SMITHY_WEIGHT_REDUCTION);

        $unit_time = (int)round($soldiers[$s_id]->get_soldier_time() * $discount);
        if ($unit_time < 1) $unit_time = 1;

        $now = time();
        $start_time = $row["buildingtime"];
        $elapsed = $now - $start_time;
        $total_finished_since_start = floor($elapsed / $unit_time);

        if ($total_finished_since_start > 0) {
            $units_to_deliver = min((int)$total_finished_since_start, $row["soldiergoal"]);

            if ($units_to_deliver > 0) {
                $soldier_name = $soldiers[$s_id]->get_soldier_name();

                $this->mysqli->execute_query(
                    "INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
                 VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE soldiercount = soldiercount + ?",
                    [$row["kingdomid"], $s_id, $soldier_name, $units_to_deliver, $units_to_deliver]
                );

                $vill_total = $units_to_deliver * $soldiers[$s_id]->get_soldier_villager_cost();
                $this->mysqli->execute_query("UPDATE kingdoms SET villager = villager - ? WHERE id = ?",
                    [$vill_total, $row["kingdomid"]]);

                $this->mysqli->execute_query(
                    "UPDATE events SET soldiergoal = soldiergoal - ?, buildingtime = buildingtime + (? * ?) WHERE eventid = ?",
                    [$units_to_deliver, $units_to_deliver, $unit_time, $row["eventid"]]
                );

                $this->user->set_last_recruited_soldier($row["kingdomid"], $soldier_name, $units_to_deliver);
                $this->update_user_score((int)($units_to_deliver * $soldiers[$s_id]->get_soldier_score_gain()), $this->user);
                update_player_stat((int)$row["userid"], "units_produced", $units_to_deliver);
            }
        }

        $res = $this->mysqli->execute_query("SELECT soldiergoal FROM events WHERE eventid = ?", [$row["eventid"]]);
        $check = $res->fetch_assoc();

        if (!$check || $check["soldiergoal"] <= 0) {
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
        } else {
            $this->mysqli->execute_query("UPDATE events SET is_processing = 0 WHERE eventid = ?", [$row["eventid"]]);
        }
    }

    public function handle_combat(array $row): void
    {
        $target_id = (int)$row["targetid"];
        $attacker_id = (int)$row["userid"];

        $res_atk = $this->mysqli->execute_query("SELECT username FROM users WHERE id = ?", [$attacker_id]);
        $atk_data = $res_atk->fetch_assoc();
        $attacker_name = $atk_data["username"] ?? "Unbekannt";
        $attacker_user_obj = new User($attacker_id, $attacker_name, (int)$row["kingdomid"]);

        $home_kingdom = new Kingdom($this->mysqli, $row["kingdomid"]);

        $message = "";
        $return_time = (int)($row["arrivaltime"] - $row["buildingtime"]);

        $conquest = new Conquest($this->mysqli);
        $conquest->set_event_id($row["eventid"]);
        $conquest->fetch_sent_troops();
        $conquest->initialize_soldier_types();

        $c_link = "<a href='map.php?startx={$row["targetx"]}&starty={$row["targety"]}' data-on-click='mapJump' data-x='{$row["targetx"]}' data-y='{$row["targety"]}'>{$row["targetx"]}:{$row["targety"]}</a>";

        // Check for troop composition
        $res = $this->mysqli->execute_query(
            "SELECT soldierid, soldiercount FROM sent_troops WHERE eventid = ?",
            [$row["eventid"]]
        );

        $combat_units = 0;
        $scout_count = 0;
        while ($st = $res->fetch_assoc()) {
            if ((int)$st["soldierid"] === Soldiers::SOLDIER_SCOUT) {
                $scout_count = (int)$st["soldiercount"];
            } else {
                $combat_units += (int)$st["soldiercount"];
            }
        }

        if ($target_id > 0) {
            $check = $this->mysqli->execute_query("SELECT id FROM kingdoms WHERE id = ?", [$target_id]);

            if ($check->num_rows === 0) {
                $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, targetid = -1, is_processing = 0 WHERE eventid = ?",
                    [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);

                send_server_message($row["userid"], "System", "Dein Ziel wurde aufgegeben. Deine Truppen kehren um.", MessageCategories::CATEGORY_WAR);
                return;
            }
        }

        $result_dmg = 0;

        if ($target_id == WORLD_EVENT_ID) {
            $world_event_manager = new WorldEvent($this->mysqli);
            $active_event = $world_event_manager->get_active_event();

            if ($active_event) {
                $home_k = new Kingdom($this->mysqli, $row["kingdomid"]);

                $shrine_atk_mult = 1.0;
                if ($home_k->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
                    $shrine_atk_mult += $home_k->get_shrine_modifier();
                }

                $inf_atk_lvl = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES);
                $cav_atk_lvl = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING);
                $arc_atk_lvl = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS);

                $raw_damage = 0;
                $report_units = [];

                $res_troops = $this->mysqli->execute_query("
                    SELECT st.soldiercount, sl.attack, sl.category, sl.soldiername, sl.icon 
                    FROM sent_troops st 
                    JOIN soldier_list sl ON st.soldierid = sl.id 
                    WHERE st.eventid = ?", [$row["eventid"]]);

                while ($t = $res_troops->fetch_assoc()) {
                    $cat = (int)$t["category"];

                    $smithy_bonus = match ($cat) {
                        0 => $inf_atk_lvl * SMITHY_INF_ATK_BONUS,
                        1 => $cav_atk_lvl * SMITHY_CAV_ATK_BONUS,
                        2 => $arc_atk_lvl * SMITHY_ARC_ATK_BONUS,
                        default => 0
                    };

                    $final_atk = (int)($t["attack"] * $shrine_atk_mult) + $smithy_bonus;
                    $raw_damage += ($final_atk * $t["soldiercount"]);

                    $report_units[] = ["name" => $t["soldiername"], "count" => $t["soldiercount"], "icon" => $t["icon"]];
                }

                $result_dmg = $world_event_manager->record_damage($active_event["id"], $attacker_id, $raw_damage, $active_event["event_type"], (int)$row["kingdomid"]);

                $msg = "<div class='battle-report'>";

                $pool = $world_event_manager->get_monster_pool();
                $monster = $pool[$active_event["monster_index"]];
                $event_title = ($active_event["event_type"] === "BOSS_HP") ? "Schlacht gegen " . $monster["name"] : "Angriff auf das Zentrum";

                $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";
                foreach ($report_units as $ru) $units_html .= BattleReportRenderer::render_unit_card($ru["name"], $ru["count"], 0, $ru["icon"], true);
                $units_html .= "</div>";

                if ($result_dmg == -1) {
                    // Boss already dead
                    $msg .= BattleReportRenderer::render_outcome_box($event_title, "Als deine Truppen das Zentrum erreichten, war das Monster bereits von anderen Herrschern besiegt worden! $units_html",
                        0, 0, "Die Soldaten feiern den Sieg und kehren heim.");
                } else if ($result_dmg == -2) {
                    // No tries anymore
                    $msg .= BattleReportRenderer::render_outcome_box("Keine Versuche", "Deine Truppen sind angekommen, aber du hast bereits alle Versuche für dieses Event aufgebraucht! $units_html",
                        0, 0, "Die Soldaten ziehen unverrichteter Dinge ab.", "error");
                } else {
                    // Sucessful Attack
                    Logger::get_instance()->log_game("COMBAT", "WORLD_EVENT_ATTACK", [
                        "event_id" => $active_event["id"],
                        "event_type" => $active_event["event_type"],
                        "damage_caused" => $result_dmg,
                        "is_boss_kill" => ($active_event["event_type"] === "BOSS_HP" && $result_dmg >= $active_event["current_hp"]),
                        "troops" => $report_units
                    ], (int)$row["kingdomid"]);
                }

                $msg .= "</div>";
            } else {
                $msg = BattleReportRenderer::render_outcome_box(
                    "Event-Bericht",
                    "Deine Truppen haben das Zentrum erreicht, aber derzeit findet kein Event statt.",
                );
            }

            if ($result_dmg == -1 || $result_dmg == -2) {
                send_server_message($attacker_id, $attacker_name, $msg, MessageCategories::CATEGORY_WAR);
            }

            $duration = $world_event_manager->get_current_duration();

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $duration, $row["eventid"]]);

            return;
        }

        if ($target_id == -3) {
            if ($combat_units === 0 && $scout_count > 0) {
                $this->process_monster_spy_mission($row, $scout_count, $attacker_user_obj, $return_time);
            } else {
                $this->process_monster_battle($row, $home_kingdom, $attacker_user_obj, $return_time);
            }
            return;
        }

        if ($target_id == -2) {
            if ($combat_units === 0 && $scout_count > 0) {
                $this->process_resource_spy_mission($row, $scout_count, $attacker_user_obj, $return_time);
            } else {
                $this->handle_raider_plunder($row, $message, $attacker_user_obj);

                // Troop return
                $res_check = $this->mysqli->execute_query("SELECT COUNT(*) FROM sent_troops WHERE eventid = ?", [$row["eventid"]]);
                if ($res_check->fetch_row()[0] > 0) {
                    $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                        [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);
                } else {
                    $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
                }
            }
            return;
        }

        if ($target_id == -1) {
            $this->process_empty_field_conquest($row, $message, $attacker_user_obj);

            // Troop return
            $res_check = $this->mysqli->execute_query("SELECT COUNT(*) FROM sent_troops WHERE eventid = ?", [$row["eventid"]]);
            if ($res_check->fetch_row()[0] > 0) {
                $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                    [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);
            } else {
                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
            }
            return;
        }

        $enemy_kingdom = new Kingdom($this->mysqli, $target_id);

        // User only sent spies to scout
        if ($combat_units === 0 && $scout_count > 0 && $attacker_id != $enemy_kingdom->get_kingdom_owner_id()) {
            $this->process_spy_mission($row, $scout_count, $home_kingdom, $enemy_kingdom, $attacker_user_obj, $return_time);
            return;
        }

        $current_owner_id = $enemy_kingdom->get_kingdom_owner_id();

        if ($attacker_id == $current_owner_id) {
            $message = "<div class='battle-report'>";
            $main_text = "Deine Truppen sind erfolgreich bei deinem Königreich {$enemy_kingdom->get_kingdom_name()} ($c_link) angekommen.";
            $sub_text = "Die Soldaten stehen ab sofort zur Verteidigung bereit.";

            $message .= BattleReportRenderer::render_outcome_box(
                "Verstärkung angekommen",
                $main_text,
                0, 0,
                $sub_text,
                "success"
            );

            $message .= "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";

            $stationed_units = $conquest->get_battle_result_data(true);

            foreach ($stationed_units as $u) {
                $message .= "<div style='flex: 0 1 fit-content;'>" . BattleReportRenderer::render_unit_card($u["name"], $u["initial"], 0, $u["icon"]) . "</div>";
            }

            $message .= "</div>";
            $message .= "</div>";

            $conquest->set_target_id($row["targetid"]);
            $conquest->deploy_soldiers_to_kingdom();
        } else {
            $this->process_battle($row, $conquest, $home_kingdom, $enemy_kingdom, $attacker_user_obj, $return_time);
            return;
        }

        send_server_message($attacker_id, $attacker_name, $message, MessageCategories::CATEGORY_WAR);
    }

    public function handle_troop_return(array $row): void
    {
        $owner_id = (int)$row["userid"];
        $home_id = (int)$row["kingdomid"];

        $check_home = $this->mysqli->execute_query("SELECT userid FROM kingdoms WHERE id = ?", [$home_id]);
        $current_home_owner = $check_home->fetch_assoc()["userid"] ?? null;

        if ($current_home_owner !== $owner_id) {
            $main_res = $this->mysqli->execute_query("SELECT mainkingdom, username FROM users WHERE id = ?", [$owner_id]);
            $user_data = $main_res->fetch_assoc();
            $main_k_id = $user_data["mainkingdom"];
            $u_name = $user_data["username"];

            if ($main_k_id && $main_k_id != $home_id) {
                $old_k_name = $this->mysqli->execute_query("SELECT kingdomname FROM kingdoms WHERE id = ?", [$home_id])->fetch_column() ?? "Unbekannt";
                $main_k_name = $this->mysqli->execute_query("SELECT kingdomname FROM kingdoms WHERE id = ?", [$main_k_id])->fetch_column() ?? "Hauptstadt";

                $this->mysqli->execute_query(
                    "UPDATE events SET kingdomid = ?, arrivaltime = arrivaltime + 600, is_processing = 0 WHERE eventid = ?",
                    [$main_k_id, $row["eventid"]]
                );

                $msg = "<div class='battle-report'>";
                $msg .= BattleReportRenderer::render_outcome_box(
                    "Heimat-Königreich verloren!",
                    "Während deine Truppen auf dem Rückmarsch waren, wurde dein Königreich <b>" . e($old_k_name) . "</b> von einem Feind erobert!<br><br>
             Deine Einheiten haben den Befehl erhalten, sofort zu deinem Haupt-Königreich <b>" . e($main_k_name) . "</b> abzudrehen.",
                    0, 0,
                    "Durch das neue Ziel verzögert sich die Ankunft um 10 Minuten.",
                    "error"
                );
                $msg .= "</div>";

                send_server_message($owner_id, $u_name, $msg, MessageCategories::CATEGORY_WAR);

                Logger::get_instance()->log_game("COMBAT", "TROOP_REDIRECTED", ["from" => $home_id, "to" => $main_k_id], $main_k_id);
            } else {
                $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$row["eventid"]]);
                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
            }
            return;
        }

        $target_x = $row["targetx"];
        $target_y = $row["targety"];
        $res = $this->mysqli->execute_query("SELECT username FROM users WHERE id = ?", [$owner_id]);
        $u_name = $res->fetch_assoc()["username"] ?? "Spieler";

        if ($row["targetid"] <= -1) {
            $res_map = $this->mysqli->execute_query(
                "SELECT ft.fieldname FROM map m JOIN field_types ft ON m.fieldtype = ft.fieldid WHERE m.mapx = ? AND m.mapy = ?",
                [$target_x, $target_y]
            );
            $map_info = $res_map->fetch_assoc();

            if ($row["targetid"] == -3) {
                $field_name = "Monstercamp";
            } else if ($row["targetid"] == WORLD_EVENT_ID) {
                $field_name = "Auge des Sturms";
            } else {
                $field_name = $map_info["fieldname"] ?? "Unbekannt";
            }
        } else {
            $enemy_k = new Kingdom($this->mysqli, $row["targetid"]);
            $field_name = " {$enemy_k->get_kingdom_owner_name()} ({$enemy_k->get_kingdom_name()})";
        }

        // Prepare loot
        $loot = [];
        if ($row["loot_food"] > 0) $loot[ResourceTypes::RESOURCE_TYPE_FOOD] = $row["loot_food"];
        if ($row["loot_wood"] > 0) $loot[ResourceTypes::RESOURCE_TYPE_WOOD] = $row["loot_wood"];
        if ($row["loot_stone"] > 0) $loot[ResourceTypes::RESOURCE_TYPE_STONE] = $row["loot_stone"];
        if ($row["loot_gold"] > 0) $loot[ResourceTypes::RESOURCE_TYPE_GOLD] = $row["loot_gold"];
        if ($row["loot_coins"] > 0) $loot[ResourceTypes::RESOURCE_TYPE_COINS] = $row["loot_coins"];
        $loot_coins = (int)($row["loot_coins"] ?? 0);

        // Generate troop cards
        $res_troops = $this->mysqli->execute_query(
            "SELECT sl.soldiername, st.soldiercount, st.initial_count, sl.icon 
             FROM sent_troops st 
             JOIN soldier_list sl ON st.soldierid = sl.id 
             WHERE st.eventid = ?",
            [$row["eventid"]]
        );

        $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin: 15px 0; justify-content: center;'>";
        while ($t = $res_troops->fetch_assoc()) {
            $initial = (int)$t["initial_count"];
            $survivors = (int)$t["soldiercount"];
            $losses = max(0, $initial - $survivors);

            $units_html .= BattleReportRenderer::render_unit_card($t["soldiername"], $initial, $losses, $t["icon"]);
        }
        $units_html .= "</div>";

        $home_k = new Kingdom($this->mysqli, $row["kingdomid"]);
        $home_name = $home_k->get_kingdom_name();

        $c_link = "<a href='map.php?startx=$target_x&starty=$target_y' data-on-click='mapJump' data-x='$target_x' data-y='$target_y'>$target_x:$target_y</a>";
        $main_text = "Deine Truppen sind vom Feldzug zu <b>$field_name</b> ($c_link) zurückgekehrt. ";
        $main_text .= !empty($loot) ? "Die Heimkehrer haben wertvolle Beute im Gepäck!" : "Die Soldaten beziehen wieder ihre Quartiere.";
        $main_text .= BattleReportRenderer::render_resource_list($loot);
        $main_text .= $units_html;

        // Render box
        $msg = "<div class='battle-report'>";
        $msg .= BattleReportRenderer::render_outcome_box(
            "Truppenrückkehr - " . e($home_name),
            $main_text
        );
        $msg .= "</div>";

        // Set troops back to kingdom
        $res_update = $this->mysqli->execute_query("SELECT soldierid, soldiercount FROM sent_troops WHERE eventid = ?", [$row["eventid"]]);
        while ($sol = $res_update->fetch_assoc()) {
            $this->mysqli->execute_query("UPDATE soldiers SET soldiercount = soldiercount + ? WHERE kingdomid = ? AND soldierid = ?",
                [$sol["soldiercount"], $row["kingdomid"], $sol["soldierid"]]);
        }

        // Give looted resources to kingdom
        if (!empty($loot)) {
            foreach ($loot as $type => $amount) {
                $home_k->modify_resource((int)$type, (int)$amount);
            }
        }
        // Coins
        if ($loot_coins > 0) {
            $this->user->give_user_coins($loot_coins);
        }

        // Send server message to owner
        if ($row["targetid"] != WORLD_EVENT_ID) {
            send_server_message($owner_id, $u_name, $msg, MessageCategories::CATEGORY_WAR);
        }

        // Cleanup
        $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$row["eventid"]]);
        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
    }

    private function handle_resource_transfer(array $row): void
    {
        if ($row["arrivaltime"] > time()) return;

        $original_recipient_id = (int)$row["userid"];
        $target_kingdom_id = (int)$row["kingdomid"];

        $res_check = $this->mysqli->execute_query("SELECT userid, kingdomname FROM kingdoms WHERE id = ?", [$target_kingdom_id]);
        $k_data = $res_check->fetch_assoc();

        if (!$k_data) {
            $res_user = $this->mysqli->execute_query("SELECT mainkingdom, username FROM users WHERE id = ?", [$original_recipient_id]);
            $u_data = $res_user->fetch_assoc();

            if ($u_data && $u_data["mainkingdom"] > 0) {
                $this->mysqli->execute_query(
                    "UPDATE events SET actionid = ?, arrivaltime = UNIX_TIMESTAMP() + 1800, is_processing = 0, buildingname = 'Transport-Fehlgeschlagen' WHERE eventid = ?",
                    [ActionTypes::ACTION_RETURN_RESOURCES, $row["eventid"]]
                );

                send_server_message($original_recipient_id, $u_data["username"], "Das Königreich existiert nicht mehr. Deine Karawane kehrt um.", MessageCategories::CATEGORY_TRADE);
            } else {
                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
            }
            return;
        }

        $current_owner_id = (int)$k_data["userid"];

        if ($current_owner_id !== $original_recipient_id) {
            $res_user = $this->mysqli->execute_query("SELECT mainkingdom, username FROM users WHERE id = ?", [$original_recipient_id]);
            $u_data = $res_user->fetch_assoc();
            $new_target_id = $u_data["mainkingdom"] ?? -1;

            if ($new_target_id != -1 && $new_target_id != $target_kingdom_id) {
                $delay = 1800;

                $this->mysqli->execute_query(
                    "UPDATE events SET kingdomid = ?, arrivaltime = arrivaltime + ?, is_processing = 0, buildingname = 'Umgeleiteter Transport' WHERE eventid = ?",
                    [$new_target_id, $delay, $row["eventid"]]
                );

                $msg = "<b>Handels-Info:</b> Deine Karawane wurde zu deinem Haupt-Königreich umgeleitet, da das ursprüngliche Ziel den Besitzer gewechselt hat.";
                send_server_message($original_recipient_id, $u_data["username"], $msg, MessageCategories::CATEGORY_TRADE);
            } else {
                $msg = "<b>Handels-Info:</b> Eine Warenlieferung ging verloren, da du über keine Königreiche mehr verfügst, die die Waren aufnehmen könnten.";
                send_server_message($original_recipient_id, $u_data["username"] ?? "Spieler", $msg, MessageCategories::CATEGORY_TRADE);

                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
            }
            return;
        }

        $target_k = new Kingdom($this->mysqli, $target_kingdom_id);
        $loot_received = [];

        // Normal Trading
        if ($row["buildinglevel"] > 0) {
            $res_type = (int)$row["buildingid"];
            $amount = (int)$row["buildinglevel"];

            $target_k->modify_resource($res_type, $amount);

            $loot_received[$res_type] = $amount;
        }

        // Internal Multi Transport
        $multi_res = [
            ResourceTypes::RESOURCE_TYPE_FOOD => $row["loot_food"],
            ResourceTypes::RESOURCE_TYPE_WOOD => $row["loot_wood"],
            ResourceTypes::RESOURCE_TYPE_STONE => $row["loot_stone"],
            ResourceTypes::RESOURCE_TYPE_GOLD => $row["loot_gold"]
        ];

        foreach ($multi_res as $type => $amount) {
            if ($amount > 0) {
                $target_k->modify_resource($type, $amount);

                $loot_received[$type] = ($loot_received[$type] ?? 0) + $amount;
            }
        }

        $msg = "<div class='battle-report'>";
        $main_text = "Eine Karawane ist in deinem Königreich <b>" . e($target_k->get_kingdom_name()) . "</b> eingetroffen.";
        $msg .= BattleReportRenderer::render_outcome_box("Warenlieferung", $main_text, 0, 0, "Die Vorräte wurden in die Lager eingelagert.", "neutral", $loot_received);
        $msg .= "</div>";

        $res_u = $this->mysqli->execute_query("SELECT username FROM users WHERE id = ?", [$original_recipient_id]);
        $u_name = $res_u->fetch_column() ?: "Spieler";

        send_server_message($original_recipient_id, $u_name, $msg, MessageCategories::CATEGORY_TRADE);

        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
    }

    // Helper functions

    private function apply_building_effects(int $bid, int $lvl, int $kid): void
    {
        switch ($bid) {
            case BuildingTypes::BUILDING_WALL:
                $hp = ($lvl + 1) * DEFAULT_WALL_HP;

                $this->mysqli->execute_query("UPDATE kingdoms SET wallhp = ? WHERE id = ?", [$hp, $kid]);
                break;
            case BuildingTypes::BUILDING_STORAGE:
                $new_level = $lvl + 1;
                $new_max = (int)round(STORAGE_STARTING_VALUE * pow(STORAGE_INC_FACTOR, $new_level - 1));

                $this->mysqli->execute_query("UPDATE kingdoms SET maxfood = ?, maxwood = ?, maxstone = ?, maxgold = ? WHERE id = ?",
                    [$new_max, $new_max, $new_max, $new_max, $kid]);
                break;
            case BuildingTypes::BUILDING_MILL:
                $this->update_production($kid, "foodrate", BASE_FOOD_GAIN, "foodperhour");
                break;
            case BuildingTypes::BUILDING_SAWMILL:
                $this->update_production($kid, "woodrate", BASE_WOOD_GAIN, "woodperhour");
                break;
            case BuildingTypes::BUILDING_STONEMINE:
                $this->update_production($kid, "stonerate", BASE_STONE_GAIN, "stoneperhour");
                break;
            case BuildingTypes::BUILDING_GOLDMINE:
                $this->update_production($kid, "goldrate", BASE_GOLD_GAIN, "goldperhour");
                break;
            case BuildingTypes::BUILDING_ESTATE:
                $new_level = $lvl + 1;
                $new_limit = (int)round(ESTATE_VILLAGER_BASE_INC + pow($new_level, 1.25) * ESTATE_VILLAGER_BASE_STEP);
                $growth_increase = 0;

                if ($new_level % ESTATE_VILLAGER_GROWTH_STEP === 0) {
                    $growth_increase = 1;
                }

                $this->mysqli->execute_query("UPDATE kingdoms SET maxvillager = maxvillager + ?, villagerperhour = villagerperhour + ? WHERE id = ?",
                    [$new_limit, $growth_increase, $kid]
                );
                break;
        }
    }

    private function update_production(int $kid, string $rate_field, int $base, string $target_field): void
    {
        $res = $this->mysqli->execute_query("SELECT ft.$rate_field FROM map m JOIN field_types ft ON m.fieldtype = ft.fieldid WHERE m.kingdomid = ?", [$kid]);
        $rate = $res->fetch_assoc()[$rate_field];

        $base_field = "base_" . str_replace("perhour", "_rate", $target_field);
        $increase = $base * $rate;

        $this->mysqli->execute_query("UPDATE kingdoms SET $base_field = $base_field + ? WHERE id = ?", [$increase, $kid]);

        $kingdom = new Kingdom($this->mysqli, $kid);
        $kingdom->recalculate_production();
    }

    private function process_empty_field_conquest(array $row, string &$message, User $attacker_user): void
    {
        $event_id = $row["eventid"];
        $target_x = $row["targetx"];
        $target_y = $row["targety"];
        $uid = $attacker_user->get_user_id();

        $check_field = $this->mysqli->execute_query("SELECT kingdomid FROM map WHERE mapx = ? AND mapy = ?", [$target_x, $target_y])->fetch_assoc();

        if ($check_field["kingdomid"] != -1) {
            $message .= BattleReportRenderer::render_outcome_box(
                "Gründung abgebrochen",
                "Bei der Ankunft mussten unsere Siedler feststellen, dass das Land nicht mehr frei ist.",
                0, 0,
                "In der Zwischenzeit hat sich dort etwas anderes niedergelassen. Die Truppen kehren um.",
                "error"
            );
            return;
        }

        $res_curr = $this->mysqli->execute_query("SELECT COUNT(*) FROM kingdoms WHERE userid = ? AND creation_method = 0", [$uid]);
        $current_count = (int)$res_curr->fetch_column();

        $res_imp = $this->mysqli->execute_query("
            SELECT COUNT(*) FROM techs t JOIN kingdoms k ON t.kingdomid = k.id 
            WHERE k.userid = ? AND t.techid = ? AND t.techlevel > 0
        ", [$uid, TechTypes::TECH_TYPE_IMPERIAL]);
        $imp_bonus = (int)$res_imp->fetch_column();
        $limit = min(GLOBAL_SETTLEMENT_MAX, BASE_SETTLEMENT_LIMIT + $imp_bonus);

        if ($current_count >= $limit) {
            $message .= BattleReportRenderer::render_outcome_box(
                "Gründung untersagt",
                "Deine Siedler sind bereit, das Banner zu hissen, aber deine Verwaltung meldet: <b>Limit erreicht!</b>",
                0, 0,
                "Das Imperium kann derzeit keine weiteren Königreiche verwalten. Die Truppen kehren um.",
                "error"
            );
            return;
        }

        $res = $this->mysqli->execute_query(
            "SELECT soldiercount FROM sent_troops WHERE eventid = ? AND soldierid = ?",
            [$event_id, Soldiers::SOLDIER_SETTLER_WAGON]
        );
        $wagon_count = ($res->num_rows > 0) ? $res->fetch_column() : 0;

        if ($wagon_count > 0) {
            $chance = BASE_SETTLER_CHANCE + (($wagon_count - 1) * SETTLER_CHANCE_STEP);
            $chance = min(MAX_SETTLER_CHANCE, $chance);

            if (mt_rand(0, 100) <= ($chance * 100)) {
                $new_kingdom_obj = new Kingdom($this->mysqli);
                $new_kingdom_id = $new_kingdom_obj->create_kingdom(
                    $attacker_user->get_user_id(),
                    $attacker_user->get_user_name(),
                    true,
                    $target_x,
                    $target_y
                );

                if ($new_kingdom_id) {
                    if ($wagon_count > 1) {
                        $this->mysqli->execute_query(
                            "UPDATE sent_troops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?",
                            [$event_id, Soldiers::SOLDIER_SETTLER_WAGON]
                        );
                    } else {
                        $this->mysqli->execute_query(
                            "DELETE FROM sent_troops WHERE eventid = ? AND soldierid = ?",
                            [$event_id, Soldiers::SOLDIER_SETTLER_WAGON]
                        );
                    }

                    $founded_name = $new_kingdom_obj->get_kingdom_name();

                    $atk_main = "<b>Erfolg!</b> Unsere Siedler haben fruchtbares Land erschlossen.";
                    $atk_sub = "Das neue Königreich <b>" . e($founded_name) . "</b> wurde erfolgreich gegründet und steht nun unter deinem Banner. Die restlichen Truppen kehren heim.";
                    $message .= BattleReportRenderer::render_outcome_box("Neues Dorf gegründet", $atk_main, 0, 0, $atk_sub, "success");

                    Logger::get_instance()->log_game("ECONOMY", "KINGDOM_FOUNDED", [
                        "new_kingdom_id" => $new_kingdom_id,
                        "new_name" => $founded_name,
                        "x" => $target_x,
                        "y" => $target_y
                    ], $new_kingdom_id);
                } else {
                    $message .= BattleReportRenderer::render_outcome_box("Gründungsfehler", "Obwohl das Land ideal schien, verhinderte ein Fehler den Bau.", 0, 0,
                        "Kontaktiere bitte den Support.", "error");
                }
            } else {
                $atk_main = "Die Gründung ist fehlgeschlagen.";
                $atk_sub = "Die Siedler konnten sich nicht auf einen Standort einigen. Bei einer Erfolgschance von " . ($chance * 100) . "% haben sie aufgegeben und kehren um.";
                $message .= BattleReportRenderer::render_outcome_box("Expedition gescheitert", $atk_main, 0, 0, $atk_sub, "error");

                Logger::get_instance()->log_game("ECONOMY", "SETTLE_FAILED", [
                    "x" => $target_x,
                    "y" => $target_y,
                    "reason" => "Error or not free"
                ], $row["kingdomid"]);
            }
        } else {
            $atk_main = "Hier kann eine Siedlung errichtet werden.";
            $atk_sub = "Du hast zwar Truppen geschickt, aber keinen <b>Gründungskarren</b>. Ohne Siedler können wir dieses Land nicht beanspruchen.";
            $message .= BattleReportRenderer::render_outcome_box("Keine Siedler", $atk_main, 0, 0, $atk_sub);
        }

        send_server_message($attacker_user->get_user_id(), $attacker_user->get_user_name(), $message, MessageCategories::CATEGORY_WAR);
    }

    private function process_battle(array $row, Conquest $conquest, Kingdom $home_kingdom, Kingdom $enemy_kingdom, User $attacker_user, int $return_time): void
    {
        $attacker_id = $attacker_user->get_user_id();
        $attacker_name = $attacker_user->get_user_name();

        $enemy_user_id = $enemy_kingdom->get_kingdom_owner_id();
        $enemy_user_name = $enemy_kingdom->get_kingdom_owner_name();
        $enemy_user = new User($enemy_user_id, $enemy_user_name);

        if ($conquest->has_noob_protection($attacker_user->get_user_score(), $enemy_user->get_user_score())) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);

            $message = "Der Gegner steht unter Noob-Schutz! Die Truppen machen sich auf den Heimweg.";
            send_server_message($attacker_id, $attacker_name, $message, MessageCategories::CATEGORY_WAR);
            return;
        }

        // Initialize battle calculation
        $conquest->set_target_id($row["targetid"]);
        $conquest->set_enemy_kingdom($enemy_kingdom);
        $conquest->initialize_soldier_types();
        $conquest->initialize_soldier_values();
        $conquest->get_enemy_soldiers();
        $conquest->set_initial_soldiers();
        $conquest->calculate_wall_bonus();
        $conquest->set_soldier_stats($home_kingdom, $enemy_kingdom);
        $conquest->calculate_battle_outcome();
        $conquest->calculate_wall_damage();
        $conquest->calculate_loss_counts();

        $total_def_initial = $conquest->get_initial_enemy_count();
        $total_def_losses = $conquest->get_enemy_loss_count();

        if ($total_def_initial > 0 && $total_def_losses > 0) {
            $loss_ratio = $total_def_losses / $total_def_initial;

            $conquest->apply_losses_to_stationed_troops($loss_ratio);
        }

        $atk_units = $conquest->get_battle_result_data(true);
        $def_units = $conquest->get_battle_result_data(false);

        update_player_stat($attacker_id, "units_fallen_pvp", $conquest->get_my_loss_count());
        update_player_stat($enemy_user_id, "units_fallen_pvp", $total_def_losses);

        // Variables for Battle Log
        $victory = ($total_def_losses == $total_def_initial);
        $wall_before = $enemy_kingdom->get_wall_hp();
        $wall_after = $conquest->calculate_wall_damage();

        // Battle Log Start
        $message = "<div class='battle-report'>";
        $c_link = "<a href='map.php?startx={$row["targetx"]}&starty={$row["targety"]}' data-on-click='mapJump' data-x='{$row["targetx"]}' data-y='{$row["targety"]}'>{$row["targetx"]}:{$row["targety"]}</a>";
        $message .= "<div class='title-border'>Kampfbericht: <b>" . e($enemy_user_name) . "</b> ($c_link)</div>";

        $enemy_msg = "<div class='battle-report'>";
        $home_x = $home_kingdom->get_kingdom_map_x();
        $home_y = $home_kingdom->get_kingdom_map_y();

        $h_link = "<a href='map.php?startx=$home_x&starty=$home_y' data-on-click='mapJump' data-x='$home_x' data-y='$home_y'>$home_x:$home_y</a>";
        $enemy_msg .= "<div class='title-border'>Angriff von: <b>" . e($attacker_name) . "</b> ($h_link)</div>";

        $message .= BattleReportRenderer::render_vs_grid($atk_units, $def_units, "Deine Truppen", "Verteidiger");
        $enemy_msg .= BattleReportRenderer::render_vs_grid($def_units, $atk_units, "Deine Verteidigung", "Angreifer");

        // Battle Outcome Logic
        $no_defenders = ($total_def_initial == 0);
        $attacker_total_loss = ($conquest->get_initial_soldier_count() == $conquest->get_my_loss_count());
        $surviving_scouts = $conquest->get_surviving_count(Soldiers::SOLDIER_SCOUT);

        // Attacker Box Logic
        if ($no_defenders) {
            // CASE A: No Defenders -> Troops always survive
            $atk_title = "Kampfausgang: Ungehinderter Vorstoß";
            $atk_main = "Es waren keine feindlichen Truppen zur Verteidigung bereit.";
            $atk_sub = "Unsere Soldaten haben das Gebiet gesichert und kehren nun um.";
            $atk_type = "success";
        } else if ($attacker_total_loss && $surviving_scouts <= 0) {
            // CASE B: Normal Battle, but all troops lost
            $atk_title = "Kampfausgang: Totale Niederlage";
            $atk_main = "Die Schlacht war ein totaler Fehlschlag!";
            $atk_sub = "Kein einziger Soldat kehrt lebend zurück.";
            $atk_type = "error";
        } else {
            // CASE C: Normal Battle against troops
            $atk_title = "Kampfausgang";
            $atk_main = $victory ? "Der Sieg ist unser! Die Verteidigung wurde durchbrochen." : "Unser Angriff wurde zurückgeschlagen!";
            $atk_sub = ($conquest->get_initial_soldier_count() > $conquest->get_my_loss_count())
                ? "Die verbleibenden Truppen machen sich auf den Heimweg."
                : "Alle Kampftruppen sind im Einsatz gefallen.";
            $atk_type = $victory ? "success" : "error";
        }

        $message .= BattleReportRenderer::render_outcome_box($atk_title, $atk_main, $wall_before, $wall_after, $atk_sub, $atk_type);

        // Defender Box
        if ($no_defenders) {
            // CASE A: No Defenders
            $def_main = "Ein feindlicher Trupp wurde vor unseren Toren gesichtet.";
            $def_sub = "Der Angreifer konnte ungehindert vordringen. 
                        Da sie jedoch keine Eroberungsabsichten hatten, zogen sie nach einer Machtdemonstration wieder ab.";
            $def_type = "neutral";
        } else if ($victory) {
            // CASE B: Normal Battle and Defender lost all troops
            $def_main = "<span class='error'>Das Königreich wurde überrannt!</span>";
            $def_sub = "Die Verteidiger wurden bis auf den letzten Mann aufgerieben.";
            $def_type = "error";
        } else {
            // FALL C: Defended successfully
            $def_main = "<span class='passed'>Die Angreifer wurden erfolgreich abgewehrt!</span>";
            $def_sub = "Unsere Garnison hält die Stellung.";
            $def_type = "success";
        }

        $enemy_msg .= BattleReportRenderer::render_outcome_box("Kampfausgang", $def_main, $wall_before, $wall_after, $def_sub, $def_type);

        // Conquering logic
        $was_conquered = false;

        if ($victory && $conquest->has_conquerer()) {
            $was_conquered = $this->handle_post_battle_conquest($row, $conquest, $enemy_kingdom, $enemy_user, $attacker_user, $message, $enemy_msg);
        }

        // Score & Wall-Updates
        $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$conquest->get_my_score_loss(), $attacker_id]);
        $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$conquest->get_enemy_score_loss(), $enemy_user_id]);
        $this->mysqli->execute_query("UPDATE kingdoms SET wallhp = ? WHERE id = ?", [$conquest->calculate_wall_damage(), $enemy_kingdom->get_kingdom_id()]);

        // Event-Handling: Delete troops or send back
        if ($conquest->get_initial_soldier_count() == $conquest->get_my_loss_count()) {
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
        } else {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);
        }

        // Thieving logic
        $surviving_thieves = $conquest->get_surviving_count(Soldiers::SOLDIER_THIEF);

        if ($surviving_thieves > 0) {
            $plunder_lvl = $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_PLUNDER);
            $capacity_per_thief = THIEF_BASE_CAPACITY * (1 + ($plunder_lvl * PLUNDER_CAPACITY_BONUS));
            $total_capacity = (int)($surviving_thieves * $capacity_per_thief);

            $enemy_storage_lvl = $enemy_kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_STORAGE);
            $secure_factor = $enemy_storage_lvl * STORAGE_SECURE_PERCENT_STEP;

            $stealable_info = [];
            $total_stealable_volume = 0;

            $resource_keys = ["food", "wood", "stone", "gold"];
            $resource_ids = [
                "food" => ResourceTypes::RESOURCE_TYPE_FOOD,
                "wood" => ResourceTypes::RESOURCE_TYPE_WOOD,
                "stone" => ResourceTypes::RESOURCE_TYPE_STONE,
                "gold" => ResourceTypes::RESOURCE_TYPE_GOLD
            ];

            foreach ($resource_keys as $key) {
                $current_stock = match ($key) {
                    "food" => $enemy_kingdom->get_kingdom_food(),
                    "wood" => $enemy_kingdom->get_kingdom_wood(),
                    "stone" => $enemy_kingdom->get_kingdom_stone(),
                    "gold" => $enemy_kingdom->get_kingdom_gold(),
                };

                $max_capacity = match ($key) {
                    "food" => $enemy_kingdom->get_kingdom_max_food(),
                    "wood" => $enemy_kingdom->get_kingdom_max_wood(),
                    "stone" => $enemy_kingdom->get_kingdom_max_stone(),
                    "gold" => $enemy_kingdom->get_kingdom_max_gold(),
                };

                $secure_amount = floor($max_capacity * $secure_factor);
                $stealable = max(0, $current_stock - $secure_amount);

                if ($stealable > 0) {
                    $stealable_info[$key] = $stealable;
                    $total_stealable_volume += $stealable;
                }
            }

            $stolen_total = ["food" => 0, "wood" => 0, "stone" => 0, "gold" => 0];

            if ($total_stealable_volume > 0) {
                $steal_ratio = min(1.0, $total_capacity / $total_stealable_volume);

                $actual_carried = 0;
                foreach ($stealable_info as $key => $amount) {
                    $to_take = floor($amount * $steal_ratio);

                    if ($to_take > 0) {
                        $stolen_total[$key] = (int)$to_take;
                        $actual_carried += (int)$to_take;

                        $enemy_kingdom->modify_resource($resource_ids[$key], -(int)$to_take);
                    }
                }

                $this->mysqli->execute_query(
                    "UPDATE events SET loot_food = ?, loot_wood = ?, loot_stone = ?, loot_gold = ? WHERE eventid = ?",
                    [$stolen_total["food"], $stolen_total["wood"], $stolen_total["stone"], $stolen_total["gold"], $row["eventid"]]
                );

                if ($actual_carried > 0) {
                    $loot = [
                        "food" => $stolen_total["food"],
                        "wood" => $stolen_total["wood"],
                        "stone" => $stolen_total["stone"],
                        "gold" => $stolen_total["gold"]
                    ];

                    $message .= BattleReportRenderer::render_resource_box($loot, "Erbeutete Ressourcen");
                    $enemy_msg .= BattleReportRenderer::render_resource_box($loot, "Gestohlene Ressourcen", "error");

                    update_player_stat($attacker_id, "resources_stolen", $actual_carried);
                }
            } else {
                $message .= "<br><br>🎒 <b>Raubzug gescheitert:</b><br>Es gab keine ungeschützten Ressourcen zu holen.";
            }
        }

        $surviving_scouts = $conquest->get_surviving_count(Soldiers::SOLDIER_SCOUT);

        if ($surviving_scouts > 0 && !$was_conquered) {
            $initial_scouts = $conquest->get_initial_count_by_id(Soldiers::SOLDIER_SCOUT, true);
            $lost_scouts = $initial_scouts - $surviving_scouts;

            $message .= $this->generate_scout_report($initial_scouts, $lost_scouts, $enemy_kingdom);
        }

        $total_losses_in_this_battle = $conquest->get_my_loss_count() + $conquest->get_enemy_loss_count();

        if ($total_losses_in_this_battle > 0) {
            update_global_stat("total_fallen_soldiers", $total_losses_in_this_battle);
        }

        $message .= "</div>";
        $enemy_msg .= "</div>";

        // Send message to both sides
        send_server_message($attacker_id, $attacker_name, $message, MessageCategories::CATEGORY_WAR);
        send_server_message($enemy_user_id, $enemy_user_name, $enemy_msg, MessageCategories::CATEGORY_WAR);

        // Logging
        $log_details = [
            "attacker_id" => $attacker_id,
            "defender_id" => $enemy_user_id,
            "target_coords" => $row["targetx"] . ":" . $row["targety"],
            "troops_sent" => $conquest->get_initial_soldiers_detailed(),
            "troops_defender" => $conquest->get_initial_enemy_detailed(),
            "losses_attacker" => $conquest->get_attacker_losses_detailed(),
            "losses_defender" => $conquest->get_defender_losses_detailed(),
            "wall_before" => $enemy_kingdom->get_wall_hp(),
            "wall_after" => $conquest->calculate_wall_damage()
        ];

        Logger::get_instance()->log_game("COMBAT", "RESULT", $log_details, $row["kingdomid"]);

        update_global_stat("total_battles");
    }

    private function handle_post_battle_conquest(array $row, Conquest $conquest, Kingdom $enemy_kingdom, User $enemy_user,
                                                 User  $attacker_user, string &$message, string &$enemy_msg): bool
    {
        $rate = $conquest->get_conquering_rate($conquest->get_conquerer_count());
        $is_conquered = $conquest->is_conquered($rate);

        if ($is_conquered) {
            $this->mysqli->execute_query(
                "DELETE FROM events WHERE kingdomid = ? AND actionid IN (?, ?, ?, ?)",
                [
                    $enemy_kingdom->get_kingdom_id(),
                    ActionTypes::ACTION_BUILD_BUILDING,
                    ActionTypes::ACTION_RESEARCH_TECH,
                    ActionTypes::ACTION_BUILD_TROOPS,
                    ActionTypes::ACTION_UPGRADE_TROOPS
                ]
            );

            $c_id = $conquest->fetch_conquerer_id();
            $soldier_types = $conquest->get_soldier_types();
            $score_loss = $soldier_types[$c_id]["score"];

            // Score decrease for losing one Conquerer
            $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$score_loss, $attacker_user->get_user_id()]);

            $this->mysqli->execute_query($conquest->get_conquerer_count() <= 1 ? "DELETE FROM sent_troops WHERE eventid = ? AND soldierid = ?"
                : "UPDATE sent_troops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?", [$row["eventid"], $c_id]);

            // Check: Is it the last kingdom of the defender?
            $k_count_res = $this->mysqli->execute_query("SELECT COUNT(*) FROM kingdoms WHERE userid = ?", [$enemy_user->get_user_id()]);
            $has_more_kingdoms = ($k_count_res->fetch_column() > 1);

            // Get all Buildings for score loss
            $res_b_score = $this->mysqli->execute_query("
                SELECT SUM((b.buildinglevel * (b.buildinglevel + 1) / 2) * bl.buildingscore) AS loss 
                FROM buildings b 
                JOIN building_list bl ON b.buildingid = bl.id 
                WHERE b.kingdomid = ?", [$enemy_kingdom->get_kingdom_id()]);
            $village_building_score = (int)($res_b_score->fetch_assoc()["loss"] ?? 0);

            // Get alle Techs for score loss
            $res_t_score = $this->mysqli->execute_query("
                SELECT SUM((t.techlevel * (t.techlevel + 1) / 2) * tl.techscore) AS loss 
                FROM techs t 
                JOIN tech_list tl ON t.techid = tl.id 
                WHERE t.kingdomid = ?", [$enemy_kingdom->get_kingdom_id()]);
            $village_tech_score = (int)($res_t_score->fetch_assoc()["loss"] ?? 0);

            // Gesamtwert des Dorfes
            $total_village_value = $village_building_score + $village_tech_score;

            if ($has_more_kingdoms) {
                // Defender still has some other kingdoms
                $this->mysqli->execute_query("DELETE FROM events WHERE kingdomid = ? AND userid = ?", [$enemy_kingdom->get_kingdom_id(), $enemy_user->get_user_id()]);

                // Remove score from defender
                $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$total_village_value, $enemy_user->get_user_id()]);
                // Add score to attacker
                $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$total_village_value, $attacker_user->get_user_id()]);

                if ($enemy_kingdom->get_kingdom_id() == $enemy_user->get_main_kingdom()) {
                    $new_main_id = $this->mysqli->execute_query("SELECT id FROM kingdoms WHERE userid = ? AND id != ? LIMIT 1",
                        [$enemy_user->get_user_id(), $enemy_kingdom->get_kingdom_id()])->fetch_column();

                    if ($new_main_id) {
                        $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$new_main_id, $enemy_user->get_user_id()]);

                        // Move Embassy, if it exists
                        $check_embassy = $this->mysqli->execute_query(
                            "SELECT buildinglevel FROM buildings WHERE kingdomid = ? AND buildingid = ?",
                            [$enemy_kingdom->get_kingdom_id(), BuildingTypes::BUILDING_EMBASSY]
                        );

                        if ($check_embassy->num_rows > 0) {
                            $this->mysqli->execute_query(
                                "UPDATE buildings SET kingdomid = ? WHERE kingdomid = ? AND buildingid = ?",
                                [$new_main_id, $enemy_kingdom->get_kingdom_id(), BuildingTypes::BUILDING_EMBASSY]
                            );
                        }
                    }
                }
            } else {
                // Give attacker score
                $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$total_village_value, $attacker_user->get_user_id()]);

                // Defender was completely destroyed -> defender starts over again
                $this->mysqli->execute_query("UPDATE users SET score = ? WHERE id = ?", [STARTING_SCORE, $enemy_user->get_user_id()]);
                $this->mysqli->execute_query("DELETE FROM events WHERE userid = ?", [$enemy_user->get_user_id()]);

                $new_k_id = new Kingdom($this->mysqli)->create_kingdom($enemy_user->get_user_id(), $enemy_user->get_user_name());

                if ($new_k_id) {
                    $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$new_k_id, $enemy_user->get_user_id()]);
                }
            }

            // Kingdom now belongs to the attacker
            $this->mysqli->execute_query("UPDATE kingdoms SET userid = ?, username = ?, creation_method = 1, created_at = ? WHERE id = ?",
                [$attacker_user->get_user_id(), $attacker_user->get_user_name(), time(), $enemy_kingdom->get_kingdom_id()]);

            // Message for Attacker
            $atk_main = "<b>Glorreicher Sieg!</b> Das Königreich wurde eingenommen und gehört nun dir.";
            $atk_sub = "Für die Eroberung hat sich ein <b>Eroberer</b> geopfert.";
            $message .= BattleReportRenderer::render_outcome_box("Eroberung erfolgreich", $atk_main, 0, 0, $atk_sub, "success");

            // Message for Defender
            $def_main = "<b>Das Schicksal hat sich gegen uns gewandt!</b> Unser Königreich wurde vom Gegner besetzt.";
            $def_sub = "";
            if (!$has_more_kingdoms) $def_sub = "Da dies dein letztes Dorf war, musst du an einem neuen Standort von vorne beginnen.";
            $enemy_msg .= BattleReportRenderer::render_outcome_box("Königreich verloren", $def_main, 0, 0, $def_sub, "error");

            return true;
        } else {
            $fail_main = "Die Eroberung ist gescheitert. Unsere Truppen konnten die Kontrolle über das Stadtzentrum nicht sichern.";
            $fail_sub = "Die Chance auf Erfolg lag bei " . $rate . "%. Die Soldaten ziehen sich zurück.";
            $message .= BattleReportRenderer::render_outcome_box("Eroberungsversuch", $fail_main, 0, 0, $fail_sub, "error");

            return false;
        }
    }

    public function cleanup_marketplace(): void
    {
        $now = time();
        // Find all expired offers
        $result = $this->mysqli->execute_query("SELECT * FROM marketplace WHERE expires_at <= ?", [$now]);

        while ($row = $result->fetch_assoc()) {
            $offer_id = $row["offerid"];
            $k_id = $row["kingdomid"];
            $res_type = $row["supply"];
            $amount = $row["supplyvalue"];
            $u_id = $row["userid"];
            $u_name = $row["username"];

            // Give the resources back to the original kingdom
            $res_field = match ($res_type) {
                ResourceTypes::RESOURCE_TYPE_FOOD => "food",
                ResourceTypes::RESOURCE_TYPE_WOOD => "wood",
                ResourceTypes::RESOURCE_TYPE_STONE => "stone",
                ResourceTypes::RESOURCE_TYPE_GOLD => "gold",
                default => null
            };

            if ($res_field) {
                $this->mysqli->execute_query("UPDATE kingdoms SET $res_field = $res_field + ? WHERE id = ?",
                    [$amount, $k_id]
                );
            }

            $loot = [$res_type => $amount];
            $msg = "<div class='battle-report'>";
            $msg .= BattleReportRenderer::render_outcome_box("Marktplatz-Info", "Ein Handelsangebot ist abgelaufen.", 0, 0,
                "Die Ressourcen wurden sicher in dein Lager zurückgebracht.",
                "neutral", $loot);
            $msg .= "</div>";

            send_server_message($u_id, $u_name, $msg, MessageCategories::CATEGORY_TRADE);

            // Delete offer
            $this->mysqli->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$offer_id]);
        }
    }

    private function update_user_score(int $add, User $target_user): void
    {
        if ($add == 0) return;

        $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?",
            [$add, $target_user->get_user_id()]);
    }

    private function load_soldier_data(): array
    {
        if (self::$cached_soldiers !== null) {
            return self::$cached_soldiers;
        }

        $soldiers = [];
        $res = $this->mysqli->execute_query("SELECT * FROM soldier_list");

        foreach ($res as $row) {
            $s = new Soldier();
            $s->set_soldier_id($row["id"]);
            $s->set_soldier_name($row["soldiername"]);
            $s->set_soldier_villager_cost($row["villager"]);
            $s->set_soldier_time($row["requiredtime"]);
            $s->set_soldier_score_gain($row["scoregain"]);
            $soldiers[$row["id"]] = $s;
        }

        self::$cached_soldiers = $soldiers;

        return $soldiers;
    }

    public function check_watchtower_notifications(?int $specific_user_id = null): void
    {
        $current_time = time();

        $user_filter = $specific_user_id ? " AND k.userid = " . $specific_user_id : "";

        $query = "
            SELECT e.eventid, e.arrivaltime, e.targetid, e.kingdomid AS source_id,
                   k.userid, k.username, k.kingdomname
            FROM events e
            JOIN kingdoms k ON e.targetid = k.id
            WHERE e.actionid = " . ActionTypes::ACTION_SEND_TROOPS . "
              AND e.userid != k.userid
              AND e.notification_sent = 0
              AND EXISTS (
                  SELECT 1 FROM sent_troops st 
                  WHERE st.eventid = e.eventid 
                  AND st.soldierid != " . Soldiers::SOLDIER_SCOUT . "
              )
              $user_filter
        ";
        $results = $this->mysqli->query($query);

        foreach ($results as $row) {
            $target_kingdom = new Kingdom($this->mysqli, $row["targetid"]);
            $wt_level = $target_kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_WATCHTOWER);

            if ($wt_level <= 0) continue;

            $visibility_window = $wt_level * WATCHTOWER_DETECTION_PER_LEVEL;
            $detection_time = $row["arrivaltime"] - $visibility_window;

            if ($current_time >= $detection_time) {
                $this->mysqli->execute_query(
                    "UPDATE events SET notification_sent = 1 WHERE eventid = ? AND notification_sent = 0",
                    [$row["eventid"]]
                );

                if ($this->mysqli->affected_rows !== 1) {
                    continue;
                }

                $intel_level = $target_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARCANE_INTEL);
                $time_to_arrival = convert_sec_to_str($row["arrivaltime"] - $current_time);

                $msg = "<div class='battle-report'>";
                $main_text = "Unsere Grenzwachen in <b>" . e($row["kingdomname"]) . "</b> haben herannahende Truppen gesichtet!<br>";

                // Level 1: Only arrival time
                $sub_text = "Ankunft in ca.: " . $time_to_arrival;

                // Level 2: Enemy Kingdom Name
                if ($intel_level >= 2) {
                    $res_source = $this->mysqli->execute_query("SELECT kingdomname, mapx, mapy FROM kingdoms WHERE id = ?", [$row["source_id"]]);
                    if ($src = $res_source->fetch_assoc()) {
                        $main_text .= "<br>Herkunft: <b>" . e($src["kingdomname"]) . "</b> (" . $src["mapx"] . ":" . $src["mapy"] . ")";
                    }
                }

                // Level 3: Rough Troop Strength
                if ($intel_level >= 3) {
                    $res_count = $this->mysqli->execute_query("SELECT SUM(soldiercount) as total FROM sent_troops WHERE eventid = ?", [$row["eventid"]]);
                    $total_units = $res_count->fetch_assoc()["total"] ?? 0;

                    if ($total_units < 50) $strength_label = "Ein kleiner Trupp";
                    else if ($total_units < 200) $strength_label = "Eine ansehnliche Streitmacht";
                    else if ($total_units < 1000) $strength_label = "Ein großes Heer";
                    else $strength_label = "Eine gewaltige Armee";

                    $main_text .= "<br>Späherbericht: <i>$strength_label (ca. " . fnum($total_units) . " Einheiten)</i>";
                }

                // Level 4 and higher: Exact Troop Strength and Troop Power
                if ($intel_level >= 4) {
                    $main_text .= "<br><br><b>Identifizierte Einheiten:</b><br>";
                    $main_text .= "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 5px;'>";

                    $total_atk = 0;
                    $total_def = 0;

                    $res_troops = $this->mysqli->execute_query("
                                                SELECT sl.soldiername, sl.icon, sl.attack, sl.defense, st.soldiercount 
                                                FROM sent_troops st 
                                                JOIN soldier_list sl ON st.soldierid = sl.id 
                                                WHERE st.eventid = ?", [$row["eventid"]]);

                    while ($t = $res_troops->fetch_assoc()) {
                        $count = (int)$t["soldiercount"];

                        $total_atk += (int)round($count * $t["attack"]);
                        $total_def += (int)round($count * $t["defense"]);

                        $icon_path = "images/icons/" . $t["icon"] . ".png";
                        $main_text .= "<div class='unit-badge' title='" . e($t["soldiername"]) . "'>";
                        $main_text .= "<img src='$icon_path' alt=''>";
                        $main_text .= "<b>" . fnum($count) . "</b>";
                        $main_text .= "</div>";
                    }
                    $main_text .= "</div>";

                    if ($intel_level >= 5) {
                        $main_text .= "<div style='margin-top: 12px; padding-top: 8px; border-top: 1px ridge rgba(212,175,55,0.4); text-align: left;'>";
                        $main_text .= "<b>Geschätzte Gesamtstärke:</b><br>";

                        $main_text .= "<span style='margin-right: 20px;'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " " . fnum($total_atk) . "</span>";
                        $main_text .= "<span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " " . fnum($total_def) . "</span>";

                        $main_text .= "</div>";
                    }
                }

                $msg .= BattleReportRenderer::render_outcome_box("WACHTURM-MELDUNG", $main_text, 0, 0, $sub_text, "error");
                $msg .= "</div>";

                send_server_message($row["userid"], $row["username"], $msg, MessageCategories::CATEGORY_WAR);
            }
        }
    }

    private function process_spy_mission(array $row, int $atk_scouts, Kingdom $home_k, Kingdom $enemy_k, User $attacker_user,
                                         int   $return_time): void
    {
        $enemy_owner_id = $enemy_k->get_kingdom_owner_id();
        $enemy_owner_name = $enemy_k->get_kingdom_owner_name();
        $attacker_id = $attacker_user->get_user_id();
        $attacker_name = $attacker_user->get_user_name();
        $event_id = $row["eventid"];

        // Get scout stats
        $res_stats = $this->mysqli->execute_query("SELECT attack, defense FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_SCOUT]);
        $scout_stats = $res_stats->fetch_assoc();
        $s_atk = (int)$scout_stats["attack"];
        $s_def = (int)$scout_stats["defense"];

        // Calc def bonus and watch tower
        $res_def = $this->mysqli->execute_query(
            "SELECT soldiercount FROM soldiers WHERE kingdomid = ? AND soldierid = ?",
            [$enemy_k->get_kingdom_id(), Soldiers::SOLDIER_SCOUT]
        );
        $def_scouts = ($res_def->num_rows > 0) ? (int)$res_def->fetch_column() : 0;
        $wt_level = $enemy_k->get_kingdom_building_level(BuildingTypes::BUILDING_WATCHTOWER);

        // Pool calc (like in Conquest)
        $p_atk_pool = $atk_scouts * $s_atk;
        $p_def_pool = $atk_scouts * $s_def;

        $e_atk_pool = $def_scouts * $s_atk;
        $e_def_pool = $def_scouts * ($s_def + $wt_level);

        $p_loss_ratio = ($p_def_pool > 0) ? min(1.0, $e_atk_pool / $p_def_pool) : 1.0;
        $e_loss_ratio = ($e_def_pool > 0) ? min(1.0, $p_atk_pool / $e_def_pool) : 1.0;

        // If defender has no scouts, there will be no losses at all
        if ($def_scouts === 0) {
            $atk_losses = 0;
            $def_losses = 0;
        } else {
            $atk_losses = (int)round($atk_scouts * $p_loss_ratio);
            $def_losses = (int)round($def_scouts * $e_loss_ratio);
        }

        $res_scout_stats = $this->mysqli->execute_query("SELECT scoregain FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_SCOUT]);
        $scout_score_val = (int)$res_scout_stats->fetch_column() ?: 1;

        // Attacker losses
        if ($atk_losses > 0) {
            $this->mysqli->execute_query(
                "UPDATE sent_troops SET soldiercount = GREATEST(0, soldiercount - ?) WHERE eventid = ? AND soldierid = ?",
                [$atk_losses, $event_id, Soldiers::SOLDIER_SCOUT]
            );

            $atk_score_loss = $atk_losses * $scout_score_val;
            $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$atk_score_loss, $attacker_id]);
        }

        // Defender losses
        if ($def_losses > 0) {
            $this->mysqli->execute_query(
                "UPDATE soldiers SET soldiercount = GREATEST(0, soldiercount - ?) WHERE kingdomid = ? AND soldierid = ?",
                [$def_losses, $enemy_k->get_kingdom_id(), Soldiers::SOLDIER_SCOUT]
            );

            $def_score_loss = $def_losses * $scout_score_val;
            $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$def_score_loss, $enemy_owner_id]);
        }

        // Scout report
        $survivors = $atk_scouts - $atk_losses;

        // Message Attacker
        if ($survivors > 0) {
            $msg_atk = $this->generate_scout_report($atk_scouts, $atk_losses, $enemy_k);

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?", [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
        } else {
            $msg_atk = "<div class='battle-report'>";
            $msg_atk .= BattleReportRenderer::render_outcome_box(
                "Spionage gescheitert",
                "Unsere Späher wurden in <b>" . e($enemy_k->get_kingdom_name()) . "</b> entdeckt und abgefangen.",
                0, 0,
                "Kein einziger Späher kehrte lebend zurück.",
                "error"
            );
            $msg_atk .= "<div style='display: flex; justify-content: center; margin-top: 10px;'>" . BattleReportRenderer::render_unit_card("Deine Späher", $atk_scouts, $atk_losses, "icon_scout", true) . "</div>";
            $msg_atk .= "</div>";

            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        }

        // Defender Message
        $msg_def = "<div class='battle-report'>";
        $def_sub = ($atk_losses >= $atk_scouts) ? "Unsere Wachen konnten alle Spione eliminieren." : "Einigen Spionen gelang die Flucht.";
        $msg_def .= BattleReportRenderer::render_outcome_box(
            "Grenzwache: Eindringlinge!",
            "Späher aus <b>" . e($home_k->get_kingdom_name()) . "</b> wurden dabei ertappt, wie sie unsere Stadt ausspionierten.",
            0, 0,
            $def_sub
        );

        if ($def_scouts > 0) {
            $msg_def .= "<div style='display: flex; justify-content: center; margin-top: 10px;'>" . BattleReportRenderer::render_unit_card("Deine Späher", $def_scouts, $def_losses, "icon_scout", true) . "</div>";
        }

        $msg_def .= "</div>";

        send_server_message($attacker_id, $attacker_name, $msg_atk, MessageCategories::CATEGORY_WAR);
        send_server_message($enemy_owner_id, $enemy_owner_name, $msg_def, MessageCategories::CATEGORY_WAR);

        if ($atk_losses + $def_losses > 0) {
            update_global_stat("total_fallen_soldiers", ($atk_losses + $def_losses));
        }

        Logger::get_instance()->log_game("COMBAT", "SPY_RESULT", [
            "attacker_id" => $attacker_id,
            "defender_id" => $enemy_owner_id,
            "target_coords" => $row["targetx"] . ":" . $row["targety"],
            "scouts_sent" => $atk_scouts,
            "scouts_lost" => $atk_losses,
            "success" => ($survivors > 0)
        ], $home_k->get_kingdom_id());

        update_player_stat($attacker_id, "spy_count");
        update_player_stat($attacker_id, "units_fallen_pvp", $atk_losses);
        update_player_stat($enemy_owner_id, "units_fallen_pvp", $def_losses);
    }

    private function generate_scout_report(int $atk_scouts, int $atk_losses, Kingdom $enemy_k): string
    {
        $survivors = $atk_scouts - $atk_losses;
        $tx = $enemy_k->get_kingdom_map_x();
        $ty = $enemy_k->get_kingdom_map_y();

        $report = "<div class='battle-report'>";
        $report .= "<div class='battle-column'>";
        $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
        $report .= "<div class='title-border'>Spionagebericht: " . e($enemy_k->get_kingdom_name()) . " ($c_link)</div>";
        $report .= "<div class='report-section-title'>Ressourcen</div>";

        // TIER 1: Resources
        $res = [
            "food" => $enemy_k->get_kingdom_food(),
            "wood" => $enemy_k->get_kingdom_wood(),
            "stone" => $enemy_k->get_kingdom_stone(),
            "gold" => $enemy_k->get_kingdom_gold()
        ];
        $prod = [
            "food" => $enemy_k->get_kingdom_food_per_hour(),
            "wood" => $enemy_k->get_kingdom_wood_per_hour(),
            "stone" => $enemy_k->get_kingdom_stone_per_hour(),
            "gold" => $enemy_k->get_kingdom_gold_per_hour()
        ];

        $report .= BattleReportRenderer::render_scout_resource_bar($res, $prod);

        // TIER 2 & 3: Buildings
        if ($survivors >= 5) {
            $report .= "<div class='report-section-title' style='margin-top: 10px;'>Identifizierte Gebäude</div>";
            $report .= "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 5px; text-align: left;'>";

            if ($survivors >= 15) {
                // Tier 3: ALl Buildings
                $b_res = $this->mysqli->execute_query("SELECT buildingname, buildinglevel FROM buildings WHERE kingdomid = ? ORDER BY buildinglevel DESC", [$enemy_k->get_kingdom_id()]);

                while ($b = $b_res->fetch_assoc()) {
                    $report .= "<div>• {$b["buildingname"]} (Stufe " . (int)$b["buildinglevel"] . ")</div>";
                }
            } else {
                // Tier 2: Only Main Buildings
                $report .= "<div>• Dorfzentrum (Stufe " . $enemy_k->get_kingdom_building_level(BuildingTypes::BUILDING_TOWNCENTER) . ")</div>";
                $report .= "<div>• Mauer (Stufe " . $enemy_k->get_kingdom_building_level(BuildingTypes::BUILDING_WALL) . ")</div>";
                $report .= "<div>• Lager (Stufe " . $enemy_k->get_kingdom_building_level(BuildingTypes::BUILDING_STORAGE) . ")</div>";
            }
            $report .= "</div>";
        }

        // TIER 3: Troops
        if ($survivors >= 15) {
            $report .= "<div class='report-section-title' style='margin-top: 10px;'>Gegnerische Garnison</div>";

            $t_res = $this->mysqli->execute_query(
                "SELECT s.soldiername, s.soldiercount, sl.icon 
                     FROM soldiers s 
                     JOIN soldier_list sl ON s.soldierid = sl.id 
                     WHERE s.kingdomid = ? AND s.soldiercount > 0",
                [$enemy_k->get_kingdom_id()]
            );

            if ($t_res->num_rows > 0) {
                $report .= "<div style='display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px; justify-content: center;'>";

                while ($t = $t_res->fetch_assoc()) {
                    $report .= BattleReportRenderer::render_unit_card(
                        $t["soldiername"],
                        (int)$t["soldiercount"],
                        0,
                        $t["icon"],
                        true
                    );
                }

                $report .= "</div>";
            } else {
                $report .= "<div style='text-align: left; margin-top: 10px;'><i>Keine Truppen stationiert.</i></div>";
            }
        }

        // TIER 4: Techs
        if ($survivors >= 20) {
            $report .= "<div class='report-section-title' style='margin-top: 10px;'>Erforschte Technologien</div>";
            $report .= "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 5px; text-align: left;'>";

            $t_res = $this->mysqli->execute_query(
                "SELECT techname, techlevel FROM techs WHERE kingdomid = ? ORDER BY techlevel DESC",
                [$enemy_k->get_kingdom_id()]
            );

            if ($t_res->num_rows > 0) {
                while ($t = $t_res->fetch_assoc()) {
                    $report .= "<div>• {$t["techname"]} (Stufe " . (int)$t["techlevel"] . ")</div>";
                }
            } else {
                $report .= "<i>Keine nennenswerten Forschungen gefunden.</i>";
            }
            $report .= "</div>";
        }

        $report .= "</div>";

        $report .= BattleReportRenderer::render_own_scout_status($atk_scouts, $atk_losses);
        $report .= "</div>";

        return $report;
    }

    private function handle_raider_plunder(array $row, string &$message, User $attacker_user): void
    {
        $event_id = $row["eventid"];
        $target_x = $row["targetx"];
        $target_y = $row["targety"];
        $home_kingdom_id = $row["kingdomid"];

        // Check already plundered tile
        $res_data = $this->mysqli->execute_query("SELECT * FROM resource_tiles_data WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
        $tile = $res_data->fetch_assoc();

        if (!$tile || (time() > $tile["expires_at"] && $tile["expires_at"] > 0)) {
            $message = "<div class='battle-report'>";
            $message .= BattleReportRenderer::render_outcome_box(
                "Plünderung fehlgeschlagen",
                "Deine Truppen finden nur ein geplündertes Lager vor.",
                0, 0,
                "Jemand war schneller! Die Truppen kehren um."
            );
            $message .= "</div>";

            $this->mysqli->execute_query("UPDATE map SET kingdomid = -1 WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
            return;
        }

        // Count raiders
        $res = $this->mysqli->execute_query(
            "SELECT soldiercount FROM sent_troops WHERE eventid = ? AND soldierid = ?",
            [$event_id, Soldiers::SOLDIER_RAIDER]
        );
        $raider_count = ($res->num_rows > 0) ? (int)$res->fetch_column() : 0;

        if ($raider_count > 0) {
            $home_k = new Kingdom($this->mysqli, $home_kingdom_id);
            $plunder_lvl = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_PLUNDER);
            $tile_total = $tile["food"] + $tile["wood"] + $tile["stone"] + $tile["gold"];

            $losses = 0;

            if (mt_rand(1, 100) <= RAIDER_LOSS_CHANCE) {
                $loss_roll = mt_rand(RAIDER_LOSS_MIN_PERC, RAIDER_LOSS_MAX_PERC) / 100;
                $losses = (int)ceil($raider_count * $loss_roll);

                $res_score = $this->mysqli->execute_query("SELECT scoregain FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_RAIDER]);
                $total_score_loss = $losses * ($res_score->fetch_column() ?: 1);
                $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$total_score_loss, $attacker_user->get_user_id()]);

                if ($losses >= $raider_count) {
                    $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ? AND soldierid = ?", [$event_id, Soldiers::SOLDIER_RAIDER]);

                    $losses = $raider_count;
                } else {
                    $this->mysqli->execute_query("UPDATE sent_troops SET soldiercount = soldiercount - ? WHERE eventid = ? AND soldierid = ?", [$losses, $event_id, Soldiers::SOLDIER_RAIDER]);
                }
            }

            $survivors = $raider_count - $losses;
            $loot_f = $loot_w = $loot_s = $loot_g = 0;
            $total_actually_looted = 0;

            if ($survivors > 0) {
                $survivor_base_cap = (int)($survivors * RAIDER_BASE_CAPACITY * (1 + ($plunder_lvl * PLUNDER_CAPACITY_BONUS)));

                if ($survivor_base_cap >= $tile_total) {
                    $loot_f = $tile["food"];
                    $loot_w = $tile["wood"];
                    $loot_s = $tile["stone"];
                    $loot_g = $tile["gold"];
                } else {
                    $efficiency = mt_rand(MIN_PLUNDER_PERC, MAX_PLUNDER_PERC) / 100;
                    $total_to_take = min($tile_total, (int)($survivor_base_cap * $efficiency));

                    $take_factor = $total_to_take / $tile_total;

                    $loot_f = (int)floor($tile["food"] * $take_factor);
                    $loot_w = (int)floor($tile["wood"] * $take_factor);
                    $loot_s = (int)floor($tile["stone"] * $take_factor);
                    $loot_g = (int)floor($tile["gold"] * $take_factor);

                    $loot_array = [
                        "food" => $loot_f,
                        "wood" => $loot_w,
                        "stone" => $loot_s,
                        "gold" => $loot_g
                    ];

                    foreach ($loot_array as $res_key => $amount) {
                        if ($amount > 0) {
                            $variation = mt_rand(MIN_PLUNDER_PERC, MAX_PLUNDER_PERC) / 100;
                            $new_amount = (int)round($amount * $variation);
                            $loot_array[$res_key] = min($new_amount, $tile[$res_key]);
                        }
                    }

                    $current_total = array_sum($loot_array);
                    if ($current_total > $survivor_base_cap) {
                        $correction_factor = $survivor_base_cap / $current_total;
                        foreach ($loot_array as $res_key => $amount) {
                            $loot_array[$res_key] = (int)floor($amount * $correction_factor);
                        }
                    }

                    $loot_f = $loot_array["food"];
                    $loot_w = $loot_array["wood"];
                    $loot_s = $loot_array["stone"];
                    $loot_g = $loot_array["gold"];
                }

                $total_actually_looted = $loot_f + $loot_w + $loot_s + $loot_g;

                update_player_stat($attacker_user->get_user_id(), "resources_looted", $total_actually_looted);
            }

            // Build message
            $coords = "(<a href='map.php?startx=$target_x&starty=$target_y' data-on-click='mapJump' data-x='$target_x' data-y='$target_y'>$target_x:$target_y</a>)";

            $loot_data = [];
            $is_success = ($survivors > 0);
            $is_empty = (($tile_total - $total_actually_looted) <= 5);

            if ($is_success) {
                if ($loot_f > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_FOOD] = $loot_f;
                if ($loot_w > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_WOOD] = $loot_w;
                if ($loot_s > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_STONE] = $loot_s;
                if ($loot_g > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_GOLD] = $loot_g;

                $report_title = "Erfolgreiche Plünderung";
                $report_type = "normal";
                $sub_text = "Die Überlebenden treten mit der Beute den Rückweg an.";
                $main_text = "Unsere Räuber haben ein verlassenes Lager $coords überfallen und Ressourcen erbeutet:";
                $main_text .= BattleReportRenderer::render_resource_list($loot_data);

                if ($is_empty) {
                    $main_text .= "<br><b>Das Lager wurde komplett geleert.</b>";
                }
            } else {
                $report_title = "Plünderung gescheitert";
                $report_type = "error";
                $sub_text = "Niemand kehrte lebend zurück, die Beute ging verloren!";
                $main_text = "Unsere Räuber haben ein verlassenes Lager $coords überfallen, wurden aber im Hinterhalt von Dieben überwältigt!";
            }

            // Unit Badge
            $main_text .= "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";
            $main_text .= BattleReportRenderer::render_unit_card("Räuber", $raider_count, $losses, "icon_robber");
            $main_text .= "</div>";

            if ($losses > 0) {
                update_global_stat("total_fallen_soldiers", $losses);
                update_player_stat($attacker_user->get_user_id(), "units_fallen_pve", $losses);

                $main_text .= "<div style='margin-top: 10px; color: #ff4d4d; font-size: 0.9em;'>";
                $main_text .= "⚠️ <b>Verluste:</b> $losses Räuber wurden bei Kämpfen mit im Hinterhalt lauernden Dieben getötet.";
                $main_text .= "</div>";
            }

            $message = "<div class='battle-report'>";
            $message .= BattleReportRenderer::render_outcome_box($report_title, $main_text, 0, 0, $sub_text, $report_type);
            $message .= "</div>";

            // If we could take everything (or the field is now empty), we remove the field
            if ($is_empty) {
                update_player_stat($attacker_user->get_user_id(), "res_tiles_cleared");

                $this->mysqli->execute_query("UPDATE map SET kingdomid = -1 WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
                $this->mysqli->execute_query("DELETE FROM resource_tiles_data WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
            } else {
                $this->mysqli->execute_query("UPDATE resource_tiles_data SET food = food - ?, wood = wood - ?, stone = stone - ?, gold = gold - ? WHERE mapx = ? AND mapy = ?",
                    [$loot_f, $loot_w, $loot_s, $loot_g, $target_x, $target_y]);
            }

            // Save loot / Update event
            if ($is_success) {
                $this->mysqli->execute_query(
                    "UPDATE events SET actionid = ?, arrivaltime = ?, loot_food = ?, loot_wood = ?, loot_stone = ?, loot_gold = ?, is_processing = 0 WHERE eventid = ?",
                    [ActionTypes::ACTION_RETURN_TROOPS, time() + (int)($row["arrivaltime"] - $row["buildingtime"]), $loot_f, $loot_w, $loot_s, $loot_g, $event_id]
                );
            } else {
                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
            }
        } else {
            $message = "<div class='battle-report'>";
            $message .= BattleReportRenderer::render_outcome_box("Keine Räuber",
                "Ohne spezialisierte Räuber können wir diese massiven Vorräte nicht abtransportieren.", 0, 0,
                "Die Truppen kehren unverrichteter Dinge um.");
            $message .= "</div>";

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + (int)($row["arrivaltime"] - $row["buildingtime"]), $event_id]);
        }

        Logger::get_instance()->log_game("ECONOMY", "TILE_PLUNDER", [
            "target_coords" => "$target_x:$target_y",
            "raiders_sent" => $raider_count,
            "raiders_lost" => $losses ?? 0,
            "loot" => $loot_data ?? [],
            "was_emptied" => $is_empty ?? false,
        ], $home_kingdom_id);

        send_server_message($attacker_user->get_user_id(), $attacker_user->get_user_name(), $message, MessageCategories::CATEGORY_WAR);
    }

    private function process_resource_spy_mission(array $row, int $atk_scouts, User $attacker_user, int $return_time): void
    {
        $tx = (int)$row["targetx"];
        $ty = (int)$row["targety"];
        $event_id = (int)$row["eventid"];
        $u_id = $attacker_user->get_user_id();

        $res_tile = $this->mysqli->execute_query("SELECT * FROM resource_tiles_data WHERE mapx = ? AND mapy = ?", [$tx, $ty])->fetch_assoc();

        if (!$res_tile || (time() > $res_tile["expires_at"] && $res_tile["expires_at"] > 0)) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
            return;
        }

        $base_danger = BASE_DANGER_RATE_SCOUTING / 2;
        $safety_bonus = sqrt($atk_scouts);
        $detection_chance = $base_danger / $safety_bonus;

        $losses = 0;
        if (mt_rand(1, 100) <= $detection_chance) {
            $losses = 1;
        }

        $survivors = $atk_scouts - $losses;

        if ($losses > 0) {
            $res_scout_score = $this->mysqli->execute_query("SELECT scoregain FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_SCOUT]);
            $scout_score_val = (int)$res_scout_score->fetch_column() ?: 1;

            $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$scout_score_val, $u_id]);
            update_global_stat("total_fallen_soldiers", $losses);
            update_player_stat($u_id, "units_fallen_pve", $losses);

            $this->mysqli->execute_query("UPDATE sent_troops SET soldiercount = soldiercount - ? WHERE eventid = ? AND soldierid = ?",
                [$losses, $event_id, Soldiers::SOLDIER_SCOUT]);
        }

        $message = "<div class='battle-report'>";
        $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";

        if ($survivors > 0) {
            $message .= "<div class='title-border'>Spionagebericht: Vorratslager ($c_link)</div>";
            $message .= "<div class='report-section-title'>Gefundene Vorräte</div>";

            $loot = [
                "food" => $res_tile["food"], "wood" => $res_tile["wood"],
                "stone" => $res_tile["stone"], "gold" => $res_tile["gold"]
            ];
            $message .= BattleReportRenderer::render_scout_resource_bar($loot);

            $outcome_title = "Erfolg!";

            if ($losses > 0) {
                $message .= BattleReportRenderer::render_outcome_box($outcome_title,
                    "Ein Späher verunglückte bei der Mission, aber die anderen konnten die Vorräte schätzen.", 0, 0, "", "success");
            }

            $message .= BattleReportRenderer::render_own_scout_status($atk_scouts, $losses);

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
        } else {
            $message .= "<div class='title-border'>Mission gescheitert ($c_link)</div>";

            $message .= BattleReportRenderer::render_outcome_box(
                "Totalverlust",
                "Dein Späher ist auf dem Weg zum Lager spurlos verschwunden. Wir haben keine Informationen erhalten.",
                0, 0, "", "error"
            );

            $message .= BattleReportRenderer::render_own_scout_status($atk_scouts, $losses);

            $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$event_id]);
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        }

        $message .= "</div>";

        send_server_message($u_id, $attacker_user->get_user_name(), $message, MessageCategories::CATEGORY_WAR);

        update_player_stat($u_id, "spy_count");

        Logger::get_instance()->log_game("COMBAT", "TILE_SPY", [
            "target_coords" => "$tx:$ty",
            "scouts_sent" => $atk_scouts,
            "scouts_lost" => $losses,
            "success" => ($survivors > 0)
        ], $row["kingdomid"]);
    }

    public function process_monster_battle(array $row, Kingdom $home_k, User $attacker_user, int $return_time): void
    {
        $attacker_id = $attacker_user->get_user_id();
        $tx = (int)$row["targetx"];
        $ty = (int)$row["targety"];
        $event_id = (int)$row["eventid"];

        $conquest = new Conquest($this->mysqli);
        $conquest->set_event_id($event_id);
        $conquest->fetch_sent_troops();
        $conquest->initialize_soldier_types();
        $conquest->get_monster_defenders($tx, $ty);

        $res_expires = $this->mysqli->execute_query("SELECT expires_at FROM monster_camps WHERE mapx = ? AND mapy = ?", [$tx, $ty]);
        $row_camp = $res_expires->fetch_assoc();

        if (!$row_camp || (time() > $row_camp["expires_at"] && $row_camp["expires_at"] > 0)) {
            $message = "<div class='battle-report'>";
            $message .= BattleReportRenderer::render_outcome_box(
                "Lager bereits geräumt",
                "Deine Truppen sind angekommen (<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>), aber das Monstercamp wurde bereits vernichtet.",
                0, 0,
                "Die Soldaten treten unverrichteter Dinge den Rückweg an."
            );
            $message .= "</div>";

            send_server_message($attacker_id, $attacker_user->get_user_name(), $message, MessageCategories::CATEGORY_WAR);

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
            return;
        }

        $conquest->initialize_soldier_values();
        $conquest->set_initial_monster_battle();
        $soldier_types = $conquest->get_soldier_types();
        $monster_data = $conquest->get_monster_enemy_data();

        // Get camp level
        $camp_res = $this->mysqli->execute_query("SELECT level FROM monster_camps WHERE mapx = ? AND mapy = ?", [$tx, $ty]);
        $camp_lvl = (int)($camp_res->fetch_column() ?: 1);

        $atk_atk_pool = 0;
        $atk_def_pool = 0;
        $mon_atk_pool = 0;
        $mon_def_pool = 0;

        foreach ($soldier_types as $id => $s) {
            $initial_own = $conquest->get_initial_count_by_id($id, true);
            if ($initial_own > 0) {
                $cat = (int)$s["category"];
                $lvl_atk = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES + ($cat * 2));
                $lvl_def = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL + ($cat * 2));

                $bonus_atk = match ($cat) {
                    0 => SMITHY_INF_ATK_BONUS,
                    1 => SMITHY_CAV_ATK_BONUS,
                    2 => SMITHY_ARC_ATK_BONUS,
                    default => 0
                };
                $bonus_def = match ($cat) {
                    0 => SMITHY_INF_DEF_BONUS,
                    1 => SMITHY_CAV_DEF_BONUS,
                    2 => SMITHY_ARC_DEF_BONUS,
                    default => 0
                };

                $shrine_mult = 1.0;
                if ($home_k->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
                    $shrine_mult += $home_k->get_shrine_modifier();
                }

                $atk_atk_pool += ($initial_own * (($s["attack"] * $shrine_mult) + ($lvl_atk * $bonus_atk)));
                $atk_def_pool += ($initial_own * ($s["defense"] + ($lvl_def * $bonus_def)));
            }
        }

        foreach ($monster_data as $m) {
            $mon_atk_pool += ($m["count"] * $m["atk"]);
            $mon_def_pool += ($m["count"] * $m["def"]);
        }

        $lethality = LETHALITY_PVE;
        $atk_loss_ratio = ($atk_def_pool > 0) ? min(1.0, $mon_atk_pool / ($atk_def_pool * $lethality)) : 1.0;
        $mon_loss_ratio = ($mon_def_pool > 0) ? min(1.0, $atk_atk_pool / ($mon_def_pool * $lethality)) : 1.0;

        if ($atk_atk_pool > 0 && $mon_atk_pool > 0) {
            $ratio = $atk_atk_pool / $mon_atk_pool;

            $clamped_ratio_val = max(0.0, min(1.0, $ratio / MONSTER_DMG_CLAMPED_MAX_VAL));
            $lossMultiplier = pow(1.0 - $clamped_ratio_val, MONSTER_DMG_LOSS_EXPONENT);

            $atk_loss_ratio = $atk_loss_ratio * $lossMultiplier;
        }

        $total_score_loss = 0;
        $monsters_slain = 0;
        $surviving_attacker_units = 0;
        $total_monsters_remaining = 0;

        $report_attacker_units = [];
        $report_monster_units = [];

        // Attacker Losses
        foreach ($soldier_types as $id => $s) {
            $initial = $conquest->get_initial_count_by_id($id, true);

            if ($initial > 0) {
                $cat = (int)$s["category"];
                $lvl_atk = $home_k->get_kingdom_tech_level(13 + ($cat * 2));
                $lvl_def = $home_k->get_kingdom_tech_level(14 + ($cat * 2));

                $b_atk_val = match ($cat) {
                    0 => SMITHY_INF_ATK_BONUS,
                    1 => SMITHY_CAV_ATK_BONUS,
                    2 => SMITHY_ARC_ATK_BONUS,
                    default => 0
                };
                $b_def_val = match ($cat) {
                    0 => SMITHY_INF_DEF_BONUS,
                    1 => SMITHY_CAV_DEF_BONUS,
                    2 => SMITHY_ARC_DEF_BONUS,
                    default => 0
                };

                $shrine_mult = ($home_k->get_kingdom_alignment() == 1) ? (1.0 + $home_k->get_shrine_modifier()) : 1.0;

                $display_atk = (int)(($s["attack"] * $shrine_mult) + ($lvl_atk * $b_atk_val));
                $display_def = (int)($s["defense"] + ($lvl_def * $b_def_val));

                $loss = (int)round($initial * $atk_loss_ratio);
                $surviving_attacker_units += ($initial - $loss);

                $res_icon = $this->mysqli->execute_query("SELECT icon FROM soldier_list WHERE id = ?", [$id]);
                $report_attacker_units[] = [
                    "name" => $s["soldiername"],
                    "initial" => $initial,
                    "losses" => $loss,
                    "icon" => $res_icon->fetch_column() ?: "icon_error",
                    "atk" => $display_atk,
                    "def" => $display_def
                ];

                if ($loss > 0) {
                    $this->mysqli->execute_query("UPDATE sent_troops SET soldiercount = soldiercount - ? WHERE eventid = ? AND soldierid = ?", [$loss, $event_id, $id]);
                }
                $total_score_loss += ($loss * $s["score"]);
            }
        }

        // Monster Losses
        foreach ($monster_data as $m) {
            $initial = $m["count"];
            $loss = (int)round($initial * $mon_loss_ratio);

            if ($mon_loss_ratio < 1.0 && $loss >= $initial) {
                $loss = $initial - 1;
            }

            $survivors = $initial - $loss;
            $monsters_slain += $loss;
            $total_monsters_remaining += $survivors;

            $report_monster_units[] = [
                "name" => $m["name"],
                "initial" => $initial,
                "losses" => $loss,
                "icon" => $m["icon"],
                "atk" => $m["atk"],
                "def" => $m["def"]
            ];
        }

        update_player_stat($attacker_id, "monster_kills", $monsters_slain);
        $total_atk_loss = 0;
        foreach ($report_attacker_units as $au) {
            $total_atk_loss += $au["losses"];
        }
        if ($total_atk_loss > 0) {
            update_player_stat($attacker_id, "units_fallen_pve", $total_atk_loss);
        }

        $looted_coins = 0;
        $loot_res = ["food" => 0, "wood" => 0, "stone" => 0, "gold" => 0];
        $victory = ($total_monsters_remaining <= 0);

        if (!$victory) {
            $monster_ids = array_keys($monster_data);

            foreach ($report_monster_units as $index => $rep_m) {
                $current_m_id = $monster_ids[$index];
                $rem_count = $rep_m["initial"] - $rep_m["losses"];

                if ($rep_m["losses"] > 0) {
                    if ($rem_count <= 0) {
                        $this->mysqli->execute_query("DELETE FROM monster_camp_units WHERE mapx = ? AND mapy = ? AND monster_id = ?", [$tx, $ty, $current_m_id]);
                    } else {
                        $this->mysqli->execute_query("UPDATE monster_camp_units SET count = ? WHERE mapx = ? AND mapy = ? AND monster_id = ?", [$rem_count, $tx, $ty, $current_m_id]);
                    }
                }
            }
        } else {
            $reward_factor = 1.0;
            if ($camp_lvl >= 8) {
                $reward_factor += LOOT_FACTOR_HIGH_CAMPS;
            } else if ($camp_lvl >= 5) {
                $reward_factor += LOOT_FACTOR_MID_CAMPS;
            }

            $looted_coins = mt_rand(
                MONSTER_CAMP_COIN_MIN_PER_LVL * $camp_lvl,
                MONSTER_CAMP_COIN_MAX_PER_LVL * $camp_lvl
            );

            $res_keys = ["food", "wood", "stone", "gold"];
            foreach ($res_keys as $key) {
                $spawn_chance = in_array($key, ["gold", "food"]) ? 100 : MONSTER_CAMP_RES_CHANCE;

                if (mt_rand(1, 100) <= $spawn_chance) {
                    $base_amount = $camp_lvl * MONSTER_CAMP_BASE_RESOURCE_LOOT * $reward_factor;

                    if (in_array($key, ["wood", "stone"])) {
                        $min_p = MIN_MONSTER_CAMP_WOOD_AND_STONE_PERC;
                        $max_p = MAX_MONSTER_CAMP_WOOD_AND_STONE_PERC;
                    } else {
                        $min_p = MIN_MONSTER_CAMP_RESOURCE_PERC;
                        $max_p = MAX_MONSTER_CAMP_RESOURCE_PERC;
                    }

                    $loot_res[$key] = (int)round($base_amount * (mt_rand($min_p, $max_p) / 100));
                } else {
                    $loot_res[$key] = 0;
                }
            }

            if (array_sum($loot_res) === 0) {
                $random_key = $res_keys[array_rand($res_keys)];
                $loot_res[$random_key] = (int)($camp_lvl * MONSTER_CAMP_BASE_RESOURCE_LOOT);
            }

            $this->mysqli->execute_query("DELETE FROM monster_camps WHERE mapx = ? AND mapy = ?", [$tx, $ty]);
            $this->mysqli->execute_query("UPDATE map SET kingdomid = -1 WHERE mapx = ? AND mapy = ?", [$tx, $ty]);
        }

        $message = "<div class='battle-report'>";
        $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
        $message .= "<div class='title-border'>Kampfbericht: Monstercamp ($c_link)</div>";
        $message .= BattleReportRenderer::render_vs_grid($report_attacker_units, $report_monster_units, "Deine Truppen", "Monsterhorde (Lv $camp_lvl)");

        if ($victory) {
            $sub = ($surviving_attacker_units > 0)
                ? "Deine Truppen haben überlebt und bringen die Beute nach Hause!"
                : "Das Camp wurde gesäubert, aber alle deine Truppen fielen im Kampf. Die Beute ist verloren!";

            $loot_display = [];
            if ($surviving_attacker_units > 0) {
                $loot_display = [
                    ResourceTypes::RESOURCE_TYPE_COINS => $looted_coins,
                    ResourceTypes::RESOURCE_TYPE_FOOD => $loot_res["food"],
                    ResourceTypes::RESOURCE_TYPE_WOOD => $loot_res["wood"],
                    ResourceTypes::RESOURCE_TYPE_STONE => $loot_res["stone"],
                    ResourceTypes::RESOURCE_TYPE_GOLD => $loot_res["gold"]
                ];
            }

            $message .= BattleReportRenderer::render_outcome_box("Sieg!", "Das Camp wurde gesäubert.", 0, 0, $sub, "success",
                ($surviving_attacker_units > 0 ? $loot_display : []));

            update_player_stat($attacker_id, "camps_cleared");
        } else {
            $res_style = "neutral";

            if ($surviving_attacker_units > 0) {
                if ($total_atk_loss === 0 && $monsters_slain === 0) {
                    $res_title = "Pattsituation";
                    $res_text = "Keine der Seiten konnte die Verteidigung durchbrechen.";
                    $res_sub = "Die Truppen-Verluste blieben auf beiden Seiten aus. Wir ziehen uns zurück.";
                } else if ($total_atk_loss === 0 && $monsters_slain > 0) {
                    $res_title = "Erfolgreiches Gefecht";
                    $res_text = "Wir haben die Reihen der Monster gelichtet!";
                    $res_sub = "Unsere Truppen haben den Gegner ohne eigene Verluste attackiert und ziehen sich taktisch zurück.";
                    $res_style = "success";
                } else if ($monsters_slain >= $total_atk_loss) {
                    $res_title = "Taktischer Rückzug";
                    $res_text = "Die Monsterhorde wurde geschwächt.";
                    $res_sub = "Wir haben dem Gegner Verluste zugefügt, konnten das Camp aber nicht säubern.";
                } else {
                    $res_title = "Harter Widerstand";
                    $res_text = "Die Monster waren diesmal zu stark!";
                    $res_sub = "Unsere Truppen mussten fliehen, um eine Vernichtung zu verhindern.";
                    $res_style = "error";
                }
            } else {
                // Everything lost
                $res_title = "Niederlage";
                $res_text = "Deine Armee wurde vollständig vernichtet!";
                $res_sub = "Kein einziger Soldat kehrte lebend aus dem Kampf zurück.";
                $res_style = "error";
            }

            $message .= BattleReportRenderer::render_outcome_box($res_title, $res_text, 0, 0, $res_sub, $res_style);
        }
        $message .= "</div>";

        send_server_message($attacker_id, $attacker_user->get_user_name(), $message, MessageCategories::CATEGORY_WAR);

        $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$total_score_loss, $attacker_id]);
        update_global_stat("total_slain_monsters", $monsters_slain);

        $total_loot = array_sum($loot_res);
        if ($total_loot > 0) {
            update_player_stat($attacker_id, "resources_looted", $total_loot);
        }

        if ($surviving_attacker_units > 0) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, loot_coins = ?, 
                    loot_food = ?, loot_wood = ?, loot_stone = ?, loot_gold = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $looted_coins,
                    $loot_res["food"], $loot_res["wood"], $loot_res["stone"], $loot_res["gold"], $event_id]
            );
        } else {
            $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$event_id]);
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        }

        Logger::get_instance()->log_game("COMBAT", "MONSTER_BATTLE", [
            "target_coords" => "$tx:$ty",
            "victory" => $victory,
            "troops_sent" => $conquest->get_initial_soldiers_detailed(),
            "attacker_losses" => $total_atk_loss,
            "monsters_slain" => $monsters_slain,
            "loot_res" => $loot_res,
            "loot_coins" => $looted_coins
        ], $row["kingdomid"]);
    }

    public function process_monster_spy_mission(array $row, int $atk_scouts, User $attacker_user, int $return_time): void
    {
        $attacker_id = $attacker_user->get_user_id();
        $event_id = (int)$row["eventid"];
        $tx = (int)$row["targetx"];
        $ty = (int)$row["targety"];

        $res_camp = $this->mysqli->execute_query("
                SELECT ml.id AS monster_id, mc.level, mc.expires_at, mcu.count, ml.monster_name, ml.icon, ml.attack, ml.defense
                FROM monster_camps mc
                JOIN monster_camp_units mcu ON mc.mapx = mcu.mapx AND mc.mapy = mcu.mapy
                JOIN monster_list ml ON mcu.monster_id = ml.id
                WHERE mc.mapx = ? AND mc.mapy = ?", [$tx, $ty]);

        $units = $res_camp->fetch_all(MYSQLI_ASSOC);

        if (empty($units) || (time() > $units[0]["expires_at"] && $units[0]["expires_at"] > 0)) {
            $message = "<div class='battle-report'><div class='battle-column'>";
            $message .= BattleReportRenderer::render_outcome_box(
                "Spionage zwecklos",
                "Unsere Späher berichten, dass das Camp bei (<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>) bereits aufgelöst wurde.",
                0, 0,
                "Es gibt hier nichts mehr zu sehen. Die Späher kehren heim."
            );
            $message .= "</div></div>";

            send_server_message($attacker_id, $attacker_user->get_user_name(), $message, MessageCategories::CATEGORY_WAR);

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
            return;
        }

        $camp_lvl = $units[0]["level"] ?? 1;

        $base_danger = BASE_DANGER_RATE_SCOUTING;
        $safety_bonus = sqrt($atk_scouts);
        $detection_chance = $base_danger / $safety_bonus;

        $losses = 0;
        if (mt_rand(1, 100) <= $detection_chance) {
            $losses = 1;
        }

        $survivors = $atk_scouts - $losses;

        if ($losses > 0) {
            $res_scout_score = $this->mysqli->execute_query("SELECT scoregain FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_SCOUT]);
            $scout_score_val = (int)$res_scout_score->fetch_column() ?: 1;
            $total_score_loss = $losses * $scout_score_val;

            $this->mysqli->execute_query("UPDATE sent_troops SET soldiercount = soldiercount - ? WHERE eventid = ? AND soldierid = ?",
                [$losses, $event_id, Soldiers::SOLDIER_SCOUT]);
            update_global_stat("total_fallen_soldiers", $losses);
            update_player_stat($attacker_id, "units_fallen_pve", $losses);

            $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$total_score_loss, $attacker_id]);
        }

        $message = "<div class='battle-report'>";
        $message .= "<div class='battle-column'>";

        $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
        $message .= "<div class='title-border'>Spionagebericht: Monstercamp ($c_link)</div>";

        if ($survivors > 0) {
            $message .= "<div class='report-section-title'>Gesichtete Kreaturen (Stufe $camp_lvl)</div>";
            $message .= "<div style='display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 15px; justify-content: center;'>";

            $sim_data = [];
            foreach ($units as $u) {
                $sim_data[$u["monster_id"]] = $u["count"];

                $message .= BattleReportRenderer::render_unit_card(
                    $u["monster_name"],
                    (int)$u["count"],
                    0,
                    $u["icon"],
                    true
                );
            }
            $encoded_monsters = urlencode(json_encode($sim_data));
            $sim_link = "warsim.php?import_monsters=" . $encoded_monsters;

            $message .= "</div>";

            $message .= "<div style='text-align: center;'>
                    <a href='$sim_link'>
                        <button type='button'>⚔️ Werte in War Simulator übertragen</button>
                    </a>
             </div>";

            $est_min_coins = MONSTER_CAMP_COIN_MIN_PER_LVL * $camp_lvl;
            $est_max_coins = MONSTER_CAMP_COIN_MAX_PER_LVL * $camp_lvl;
            $base_res_amount = $camp_lvl * MONSTER_CAMP_BASE_RESOURCE_LOOT;

            $reward_factor = 1.0;
            if ($camp_lvl >= 8) $reward_factor += LOOT_FACTOR_HIGH_CAMPS;
            else if ($camp_lvl >= 5) $reward_factor += LOOT_FACTOR_MID_CAMPS;

            $est_min_coins = (int)($est_min_coins);
            $est_max_coins = (int)($est_max_coins);
            $base_res_amount *= $reward_factor;

            $message .= "<div class='report-section-title'>Geschätzte Beute</div>";
            $message .= "<div style='background: rgba(0,0,0,0.3); padding: 10px; border-radius: 5px; text-align: left;'>";
            $message .= "<b>Münzen:</b> $est_min_coins bis $est_max_coins " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . "<br>";

            $est_min_food_gold = (int)($base_res_amount * (MIN_MONSTER_CAMP_RESOURCE_PERC / 100));
            $est_max_food_gold = (int)($base_res_amount * (MAX_MONSTER_CAMP_RESOURCE_PERC / 100));
            $est_min_wood_stone = (int)($base_res_amount * (MIN_MONSTER_CAMP_WOOD_AND_STONE_PERC / 100));
            $est_max_wood_stone = (int)($base_res_amount * (MAX_MONSTER_CAMP_WOOD_AND_STONE_PERC / 100));

            $message .= "<b>Nahrung/Gold:</b> " . fnum($est_min_food_gold) . " bis " . fnum($est_max_food_gold) . "<br>";
            $message .= "<b>Holz/Stein:</b> " . fnum($est_min_wood_stone) . " bis " . fnum($est_max_wood_stone) . "<br>";

            $message .= "<div style='margin-top: 5px; font-size: 13px; opacity: 0.8;'>";
            $message .= "<i>Hinweis: Nahrung und Gold sind garantiert. Holz und Stein generieren zu " . MONSTER_CAMP_RES_CHANCE . "%.</i>";
            $message .= "</div>";
            $message .= "</div>";
        } else {
            $atk_main = "Mission gescheitert!";
            $atk_sub = "Keiner der Späher kehrte aus dem Camp zurück.";
            $atk_type = "error";
        }

        if (!empty($atk_main) && !empty($atk_sub) && !empty($atk_type)) {
            $message .= BattleReportRenderer::render_outcome_box($atk_main, "Lagebericht der Kundschafter", 0, 0, $atk_sub, $atk_type);
        }
        $message .= BattleReportRenderer::render_own_scout_status($atk_scouts, $losses);
        $message .= "</div></div>";

        send_server_message($attacker_id, $attacker_user->get_user_name(), $message, MessageCategories::CATEGORY_WAR);

        if ($survivors > 0) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
        } else {
            $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$event_id]);
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        }

        update_player_stat($attacker_id, "spy_count");
    }

    private function handle_support_arrival(array $row): void
    {
        $event_id = (int)$row["eventid"];
        $sender_id = (int)$row["userid"];
        $target_kid = (int)$row["targetid"];

        $query = "
            SELECT k.userid AS recipient_id, k.username AS recipient_name, k.kingdomname AS target_name,
                   u_send.guildid AS sender_gid, u_recv.guildid AS recipient_gid, u_send.username AS sender_name
            FROM kingdoms k
            JOIN users u_send ON u_send.id = ?
            JOIN users u_recv ON u_recv.id = k.userid
            WHERE k.id = ?
        ";
        $data = $this->mysqli->execute_query($query, [$sender_id, $target_kid])->fetch_assoc();

        if (!$data) {
            $this->turn_back_support($row, "Ziel unbekannt", "Das Ziel-Königreich existiert nicht mehr.");
            return;
        }

        $is_still_ally = ($data["sender_gid"] > 0 && $data["sender_gid"] === $data["recipient_gid"]);

        if (!$is_still_ally) {
            $this->turn_back_support($row, "Kein Bündnis", "Da ihr nicht mehr in derselben Gilde seid, wurde deinen Truppen der Einlass verwehrt.");
            return;
        }

        $target_k = new Kingdom($this->mysqli, $target_kid);
        $support_limit = SUPPORT_LIMIT_BASE + ($target_k->get_kingdom_building_level(BuildingTypes::BUILDING_BARRACKS) * SUPPORT_LIMIT_PER_BARRACKS);
        $current_support = (int)$this->mysqli->execute_query("SELECT IFNULL(SUM(soldiercount), 0) FROM stationed_troops WHERE target_kingdom_id = ?", [$target_kid])->fetch_column();

        $res_incoming = $this->mysqli->execute_query("SELECT soldierid, soldiercount, initial_count FROM sent_troops WHERE eventid = ?", [$event_id]);
        $incoming_troops = $res_incoming->fetch_all(MYSQLI_ASSOC);
        $incoming_count = array_sum(array_column($incoming_troops, 'soldiercount'));

        if (($current_support + $incoming_count) > $support_limit) {
            $this->turn_back_support($row, "Lager voll", "Das Unterstützungslager in <b>{$data["target_name"]}</b> ist bereits voll belegt.");
            return;
        }

        foreach ($incoming_troops as $t) {
            $this->mysqli->execute_query("
                INSERT INTO stationed_troops (owner_id, source_kingdom_id, target_kingdom_id, soldier_id, soldiercount)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE soldiercount = soldiercount + VALUES(soldiercount)",
                [$sender_id, $row["kingdomid"], $target_kid, $t["soldierid"], $t["soldiercount"]]
            );
        }

        $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 15px;'>";
        foreach ($incoming_troops as $t) {
            $s_info = $this->mysqli->execute_query("SELECT soldiername, icon FROM soldier_list WHERE id = ?", [$t["soldierid"]])->fetch_assoc();
            $units_html .= BattleReportRenderer::render_unit_card($s_info["soldiername"], $t["soldiercount"], 0, $s_info["icon"], true);
        }
        $units_html .= "</div>";

        $c_link = "<a href='map.php?startx={$row["targetx"]}&starty={$row["targety"]}' data-on-click='mapJump' data-x='{$row["targetx"]}' data-y='{$row["targety"]}'>{$row["targetx"]}:{$row["targety"]}</a>";

        $msg_recv = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                "Gilden-Unterstützung erhalten",
                "Die Truppen von <b>" . e($data["sender_name"]) . "</b> sind in <b>" . e($data["target_name"]) . "</b> ($c_link) eingetroffen. $units_html",
                0, 0, "Sie schützen ab sofort dein Königreich.", "support"
            ) . "</div>";
        send_server_message($data["recipient_id"], $data["recipient_name"], $msg_recv, MessageCategories::CATEGORY_WAR);

        $msg_send = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                "Unterstützung angekommen",
                "Deine Truppen haben <b>" . e($data["target_name"]) . "</b> ($c_link) von <b>" . e($data["recipient_name"]) . "</b> erreicht und die Stellung bezogen. $units_html",
                0, 0, "Du kannst sie jederzeit über deine Kaserne zurückrufen.", "support"
            ) . "</div>";
        send_server_message($sender_id, $data["sender_name"], $msg_send, MessageCategories::CATEGORY_WAR);

        $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$event_id]);
        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
    }

    private function turn_back_support(array $row, string $reason_short, string $long_text): void
    {
        $now = time();
        $duration = max(60, (int)($row["arrivaltime"] - $row["buildingtime"]));

        $this->mysqli->execute_query("
            UPDATE events SET 
                actionid = ?, 
                arrivaltime = ?, 
                buildingtime = ?, 
                is_processing = 0 
            WHERE eventid = ?",
            [ActionTypes::ACTION_SUPPORT_RETURN, $now + $duration, $now, $row["eventid"]]
        );

        $res_sender = $this->mysqli->execute_query("SELECT username FROM users WHERE id = ?", [$row["userid"]]);
        $s_name = $res_sender->fetch_column();

        $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                "Hilfsaktion fehlgeschlagen: $reason_short",
                $long_text,
                0, 0, "Deine Truppen haben sofort den Rückmarsch angetreten.", "error"
            ) . "</div>";

        send_server_message($row["userid"], $s_name, $msg, MessageCategories::CATEGORY_WAR);
    }
}
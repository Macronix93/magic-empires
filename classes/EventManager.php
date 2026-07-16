<?php

class EventManager
{
    private mysqli $mysqli;
    private User $user;

    public function __construct(User $user)
    {
        $this->mysqli = Database::get_instance()->get_connection();
        $this->user = $user;
    }

    public function process_all(): void
    {
        $this->cleanup_marketplace();
        $this->check_watchtower_notifications();

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
                    ActionTypes::ACTION_UPGRADE_TROOPS])
                && $row["arrivaltime"] <= $now) $is_due = true;

            if (!$is_due) continue;

            if ($row["is_processing"] == 1) {
                $grace_period = 30;
                $event_time = max($row["buildingtime"], $row["recruittime"], $row["arrivaltime"]);

                if (($now - $event_time) < $grace_period) {
                    continue;
                }
            }

            $this->mysqli->execute_query("UPDATE events SET is_processing = 1 WHERE eventid = ?", [$row["eventid"]]);

            try {
                $this->handle_event($row);
            } catch (Throwable $t) {
                $this->mysqli->execute_query("UPDATE events SET is_processing = 0 WHERE eventid = ?", [$row["eventid"]]);
                Logger::get_instance()->error("Event " . $row["eventid"] . " crashed: " . $t->getMessage());
            }
        }
    }

    private function handle_event(array $row): void
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
                $this->handle_troop_return($row);
                break;
            case ActionTypes::ACTION_RECEIVE_RESOURCES:
                $this->handle_resource_transfer($row);
                break;
            case ActionTypes::ACTION_UPGRADE_TROOPS:
                $this->handle_upgrade_finish($row);
                break;
        }
    }

    private function handle_research(array $row): void
    {
        if ($row["buildingtime"] >= time()) return;

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
                $kingdom->recalculate_production();
                break;
            case TechTypes::TECH_TYPE_FOOD_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_food_rate = base_food_rate + ? WHERE id = ?",
                    [RESEARCH_FOOD_INC, $kingdom_id]);
                $kingdom->recalculate_production();
                break;
            case TechTypes::TECH_TYPE_STONE_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_stone_rate = base_stone_rate + ? WHERE id = ?",
                    [RESEARCH_STONE_INC, $kingdom_id]);
                $kingdom->recalculate_production();
                break;
            case TechTypes::TECH_TYPE_GOLD_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_gold_rate = base_gold_rate + ? WHERE id = ?",
                    [RESEARCH_GOLD_INC, $kingdom_id]);
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
        $this->update_user_score($score_gain, $this->user);

        Logger::get_instance()->log_game("ECONOMY", "RESEARCH_FINISH", [
            "tech_id" => $tech_id,
            "tech_name" => $row["buildingname"],
            "level" => $row["buildinglevel"] + 1
        ], $kingdom_id);
    }

    private function handle_building(array $row): void
    {
        if ($row["buildingtime"] >= time()) return;

        $res = $this->mysqli->execute_query("SELECT buildingscore FROM building_list WHERE id = ?", [$row["buildingid"]]);
        $score_gain = $res->fetch_assoc()["buildingscore"] * $row["buildinglevel"] + 1;

        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

        if ($row["buildinglevel"] == 0) {
            $this->mysqli->execute_query("INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel) VALUES (?, ?, ?, ?)",
                [$row["kingdomid"], $row["buildingid"], $row["buildingname"], 1]);
        } else {
            $this->mysqli->execute_query("UPDATE buildings SET buildinglevel = buildinglevel + 1 WHERE kingdomid = ? AND buildingid = ?",
                [$row["kingdomid"], $row["buildingid"]]);
        }

        $this->user->set_last_built_building($row["kingdomid"], $row["buildingname"], $row["buildinglevel"]);
        $this->update_user_score($score_gain, $this->user);

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
                $this->update_user_score($score_difference, $this->user);
            }
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
                $this->update_user_score($units_to_deliver * $soldiers[$s_id]->get_soldier_score_gain(), $this->user);
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
        $attacker_id = (int)$row["userid"];

        $res_atk = $this->mysqli->execute_query("SELECT username FROM users WHERE id = ?", [$attacker_id]);
        $atk_data = $res_atk->fetch_assoc();
        $attacker_name = $atk_data["username"] ?? "Unbekannt";
        $attacker_user_obj = new User($attacker_id, $attacker_name, (int)$row["kingdomid"]);

        $map = new Map($this->mysqli, $attacker_user_obj);
        $home_kingdom = new Kingdom($this->mysqli, $row["kingdomid"]);
        $my_x = $home_kingdom->get_kingdom_map_x();
        $my_y = $home_kingdom->get_kingdom_map_y();

        $message = "";
        $return_time = $map->get_arrival_time($row["targetx"], $row["targety"], $my_x, $my_y);

        $conquest = new Conquest($this->mysqli);
        $conquest->set_event_id($row["eventid"]);
        $conquest->fetch_sent_troops();
        $conquest->initialize_soldier_types();

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

        if ($row["targetid"] == -1 || $row["targetid"] == -2) {
            $this->process_empty_field_conquest($row, $message, $attacker_user_obj);

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);
        } else {
            $enemy_kingdom = new Kingdom($this->mysqli, $row["targetid"]);

            // User only sent spies to scout
            if ($combat_units === 0 && $scout_count > 0 && $attacker_id != $enemy_kingdom->get_kingdom_owner_id()) {
                $this->process_spy_mission($row, $scout_count, $home_kingdom, $enemy_kingdom, $attacker_user_obj);
                return;
            }

            $current_owner_id = $enemy_kingdom->get_kingdom_owner_id();

            if ($attacker_id == $current_owner_id) {
                $message = "<div class='battle-report'>";
                $main_text = "Deine Truppen sind erfolgreich bei deinem Königreich {$enemy_kingdom->get_kingdom_name()} ({$row["targetx"]}:{$row["targety"]}) angekommen.";
                $sub_text = "Die Soldaten stehen ab sofort zur Verteidigung bereit.";

                $message .= BattleReportRenderer::render_outcome_box(
                    "Verstärkung angekommen",
                    $main_text,
                    0, 0,
                    $sub_text
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
                $this->mysqli->execute_query(
                    "UPDATE events SET kingdomid = ?, arrivaltime = arrivaltime + 600, is_processing = 0 WHERE eventid = ?",
                    [$main_k_id, $row["eventid"]]
                );

                send_server_message($owner_id, $u_name, "Dein Heimatdorf wurde erobert! Deine Truppen wurden zu deinem Hauptkönigreich umgeleitet.", MessageCategories::CATEGORY_WAR);
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

        if ($row["targetid"] == -1 || $row["targetid"] == -2) {
            $res = $this->mysqli->execute_query("SELECT ft.fieldname FROM map m JOIN field_types ft ON m.fieldtype = ft.fieldid WHERE m.mapx = ? AND m.mapy = ?",
                [$target_x, $target_y]);

            $field_name = $res->fetch_assoc()["fieldname"] ?? "Unbekannt";
        } else {
            $enemy_k = new Kingdom($this->mysqli, $row["targetid"]);

            $field_name = " {$enemy_k->get_kingdom_owner_name()} ({$enemy_k->get_kingdom_name()})";
        }

        $loot = [];
        if ($row["loot_food"] > 0) $loot[ResourceTypes::RESOURCE_TYPE_FOOD] = $row["loot_food"];
        if ($row["loot_wood"] > 0) $loot[ResourceTypes::RESOURCE_TYPE_WOOD] = $row["loot_wood"];
        if ($row["loot_stone"] > 0) $loot[ResourceTypes::RESOURCE_TYPE_STONE] = $row["loot_stone"];
        if ($row["loot_gold"] > 0) $loot[ResourceTypes::RESOURCE_TYPE_GOLD] = $row["loot_gold"];

        $msg = "<div class='battle-report'>";
        $sub_text = !empty($loot) ? "Die Heimkehrer haben wertvolle Beute im Gepäck!" : "Die Soldaten beziehen wieder ihre Quartiere.";
        $main_text = "Deine Truppen sind vom Feldzug zu <b>$field_name</b> ($target_x:$target_y) zurückgekehrt. $sub_text";

        $msg .= BattleReportRenderer::render_outcome_box(
            "Truppenrückkehr",
            $main_text,
            0, 0,
            "",
            "neutral",
            $loot
        );

        $msg .= "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";

        $res_troops = $this->mysqli->execute_query(
            "SELECT sl.soldiername, st.soldiercount, sl.icon 
             FROM sent_troops st 
             JOIN soldier_list sl ON st.soldierid = sl.id 
             WHERE st.eventid = ?",
            [$row["eventid"]]
        );

        while ($t = $res_troops->fetch_assoc()) {
            $msg .= "<div style='flex: 0 1 fit-content;'>" . BattleReportRenderer::render_unit_card($t["soldiername"], $t["soldiercount"], 0, $t["icon"]) . "</div>";
        }
        $msg .= "</div>";
        $msg .= "</div>";

        $res = $this->mysqli->execute_query("SELECT soldierid, soldiercount FROM sent_troops WHERE eventid = ?", [$row["eventid"]]);
        while ($sol = $res->fetch_assoc()) {
            $this->mysqli->execute_query("UPDATE soldiers SET soldiercount = soldiercount + ? WHERE kingdomid = ? AND soldierid = ?",
                [$sol["soldiercount"], $row["kingdomid"], $sol["soldierid"]]);
        }

        if ($row["loot_food"] > 0 || $row["loot_wood"] > 0 || $row["loot_stone"] > 0 || $row["loot_gold"] > 0) {
            $home_k = new Kingdom($this->mysqli, $row["kingdomid"]);
            $home_k->give_kingdom_food($row["loot_food"]);
            $home_k->give_kingdom_wood($row["loot_wood"]);
            $home_k->give_kingdom_stone($row["loot_stone"]);
            $home_k->give_kingdom_gold($row["loot_gold"]);
        }

        send_server_message($owner_id, $u_name, $msg, MessageCategories::CATEGORY_WAR);

        // Delete the event and sent troops
        $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$row["eventid"]]);
        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
    }

    private function handle_resource_transfer(array $row): void
    {
        if ($row["arrivaltime"] >= time()) return;

        $original_recipient_id = (int)$row["userid"];
        $target_kingdom_id = (int)$row["kingdomid"];

        $res_check = $this->mysqli->execute_query("SELECT userid, kingdomname FROM kingdoms WHERE id = ?", [$target_kingdom_id]);
        $k_data = $res_check->fetch_assoc();
        $current_owner_id = $k_data ? (int)$k_data["userid"] : -1;

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

                $msg = "<b>Handels-Info:</b> Deine Karawane konnte <b>" . e($k_data["kingdomname"] ?? "das Ziel") . "</b> nicht erreichen, 
                            da es den Besitzer gewechselt hat. Die Waren wurden zu deinem Haupt-Königreich umgeleitet.";
                send_server_message($original_recipient_id, $u_data["username"], $msg, MessageCategories::CATEGORY_TRADE);
            } else {
                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
            }
            return;
        }

        $target_k = new Kingdom($this->mysqli, $target_kingdom_id);
        $res_type = (int)$row["buildingid"];
        $amount = (int)$row["buildinglevel"];

        $target_k->modify_resource($res_type, $amount);

        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

        $loot = [$res_type => $amount];
        $msg = "<div class='battle-report'>";
        $main_text = "Eine Karawane ist in deinem Königreich <b>" . e($target_k->get_kingdom_name()) . "</b> eingetroffen.";
        $msg .= BattleReportRenderer::render_outcome_box("Warenlieferung", $main_text, 0, 0, "Die Vorräte wurden in die Lager eingelagert.", "success", $loot);
        $msg .= "</div>";

        $res_u = $this->mysqli->execute_query("SELECT username FROM users WHERE id = ?", [$original_recipient_id]);
        $u_name = $res_u->fetch_column() ?: "Spieler";

        send_server_message($original_recipient_id, $u_name, $msg, MessageCategories::CATEGORY_TRADE);
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
                $limit_increase = ESTATE_MAX_VILLAGER_INC;
                $growth_increase = 0;

                if ($new_level % ESTATE_VILLAGER_GROWTH_STEP === 0) {
                    $growth_increase = 1;
                }

                $this->mysqli->execute_query("UPDATE kingdoms SET maxvillager = maxvillager + ?, villagerperhour = villagerperhour + ? WHERE id = ?",
                    [$limit_increase, $growth_increase, $kid]
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

        //$this->mysqli->execute_query("UPDATE kingdoms SET $target_field = $target_field + ? WHERE id = ?", [$base * $rate, $kid]);
    }

    private function process_empty_field_conquest(array $row, string &$message, User $attacker_user): void
    {
        $event_id = $row["eventid"];
        $target_x = $row["targetx"];
        $target_y = $row["targety"];

        $res_map = $this->mysqli->execute_query("SELECT kingdomid FROM map WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
        $is_resource_tile = ($res_map->fetch_column() == -2);

        if ($is_resource_tile) {
            $this->handle_raider_plunder($row, $message, $attacker_user);
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
                $new_kingdom_id = new Kingdom($this->mysqli)->create_kingdom(
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

                    $atk_main = "<b>Erfolg!</b> Unsere Siedler haben fruchtbares Land erschlossen.";
                    $atk_sub = "Das neue Königreich <b>ID: $new_kingdom_id</b> wurde erfolgreich gegründet und steht nun unter deinem Banner. Die restlichen Truppen kehren heim.";
                    $message .= BattleReportRenderer::render_outcome_box("Neues Dorf gegründet", $atk_main, 0, 0, $atk_sub, "success");
                } else {
                    $message .= BattleReportRenderer::render_outcome_box("Gründungsfehler", "Obwohl das Land ideal schien, verhinderte ein Fehler den Bau.", 0, 0,
                        "Kontaktiere bitte den Support.", "error");
                }
            } else {
                $atk_main = "Die Gründung ist fehlgeschlagen.";
                $atk_sub = "Die Siedler konnten sich nicht auf einen Standort einigen. Bei einer Erfolgschance von " . ($chance * 100) . "% haben sie aufgegeben und kehren um.";
                $message .= BattleReportRenderer::render_outcome_box("Expedition gescheitert", $atk_main, 0, 0, $atk_sub, 'error');
            }
        } else {
            $atk_main = "Hier kann eine Siedlung errichtet werden.";
            $atk_sub = "Du hast zwar Truppen geschickt, aber keinen <b>Gründungskarren</b>. Ohne Siedler können wir dieses Land nicht beanspruchen.";
            $message .= BattleReportRenderer::render_outcome_box("Keine Siedler", $atk_main, 0, 0, $atk_sub);
        }
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
        $conquest->set_soldier_stats($home_kingdom);
        $conquest->calculate_battle_outcome();
        $conquest->calculate_wall_damage();
        $conquest->calculate_loss_counts();

        // Variables for Battle Log
        $atk_units = $conquest->get_battle_result_data(true);
        $def_units = $conquest->get_battle_result_data(false);

        $victory = ($conquest->get_enemy_loss_count() == $conquest->get_initial_enemy_count());
        $wall_before = $enemy_kingdom->get_wall_hp();
        $wall_after = $conquest->calculate_wall_damage();

        // Battle Log Start
        $message = "<div class='battle-report'>";
        $message .= "<div class='title-border'>Kampfbericht: <b>" . e($enemy_user_name) . "</b> (" . e($enemy_kingdom->get_kingdom_name()) . ")</div>";

        $enemy_msg = "<div class='battle-report'>";
        $enemy_msg .= "<div class='title-border'>Angriff von: <b>" . e($attacker_name) . "</b> (" . e($home_kingdom->get_kingdom_name()) . ")</div>";

        $message .= BattleReportRenderer::render_vs_grid($atk_units, $def_units, "Deine Truppen", "Verteidiger");
        $enemy_msg .= BattleReportRenderer::render_vs_grid($def_units, $atk_units, "Deine Verteidigung", "Angreifer");

        // Battle Outcome Logic
        $attacker_total_loss = ($conquest->get_initial_soldier_count() == $conquest->get_my_loss_count());
        $surviving_scouts = $conquest->get_surviving_count(Soldiers::SOLDIER_SCOUT);

        // Attacker Box
        if ($attacker_total_loss && $surviving_scouts <= 0) {
            $atk_title = "Kampfausgang: Totale Niederlage";
            $atk_main = "Die Schlacht war ein totaler Fehlschlag!";
            $atk_sub = "Kein einziger Soldat kehrt lebend zurück.";
            $atk_type = "error";
        } else {
            $atk_title = "Kampfausgang";
            $atk_main = $victory ? "Der Sieg ist unser! Die Verteidigung wurde durchbrochen." : "Unser Angriff wurde zurückgeschlagen!";
            $atk_sub = ($conquest->get_initial_soldier_count() > $conquest->get_my_loss_count())
                ? "Die verbleibenden Truppen machen sich auf den Heimweg."
                : "Alle Kampftruppen sind im Einsatz gefallen.";
            $atk_type = $victory ? "success" : "error";
        }

        $message .= BattleReportRenderer::render_outcome_box($atk_title, $atk_main, $wall_before, $wall_after, $atk_sub, $atk_type);

        // Defender Box
        if ($victory) {
            $def_main = "<span class='error'>Das Königreich wurde überrannt!</span>";
            $def_sub = "Die Verteidiger wurden bis auf den letzten Mann aufgerieben.";
            $def_type = "error";
        } else {
            $def_main = "<span class='passed'>Die Angreifer wurden erfolgreich abgewehrt!</span>";
            $def_sub = "Unsere Garnison hält die Stellung.";
            $def_type = "success";
        }

        $enemy_msg .= BattleReportRenderer::render_outcome_box("Kampfausgang", $def_main, $wall_before, $wall_after, $def_sub, $def_type);

        // Conquering logic
        if ($victory && $conquest->has_conquerer()) {
            $this->handle_post_battle_conquest($row, $conquest, $enemy_kingdom, $enemy_user, $attacker_user, $message, $enemy_msg);
        }

        // Score & Wall-Updates
        $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$conquest->get_my_score_loss(), $attacker_id]);
        $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$conquest->get_enemy_score_loss(), $enemy_user_id]);
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
                }
            } else {
                $message .= "<br><br>🎒 <b>Raubzug gescheitert:</b><br>Es gab keine ungeschützten Ressourcen zu holen.";
            }
        }

        //$attacker_survived = ($conquest->get_initial_soldier_count() > $conquest->get_my_loss_count());
        $surviving_scouts = $conquest->get_surviving_count(Soldiers::SOLDIER_SCOUT);

        if ($surviving_scouts > 0) {
            $initial_scouts = $conquest->get_initial_count_by_id(Soldiers::SOLDIER_SCOUT, true);
            $lost_scouts = $initial_scouts - $surviving_scouts;

            $message .= $this->generate_scout_report($initial_scouts, $lost_scouts, $enemy_kingdom);
        }
//        else if ($attacker_survived) {
//            $enemy_survived = ($conquest->get_initial_enemy_count() > $conquest->get_enemy_loss_count());
//
//            if ($enemy_survived) {
//                $message .= "<div class='battle-unit-card' style='margin-top:10px;'>
//                        <div class='battle-unit-info'>🛡️ Unsere Soldaten berichten, dass der Gegner nach dem Kampf noch Truppen übrig hatte.</div>
//                     </div>";
//            } else {
//                $message .= "<div class='battle-unit-card' style='margin-top:10px;'>
//                        <div class='battle-unit-info'>🏰 Unsere Soldaten berichten, dass die feindliche Garnison vollständig vernichtet wurde.</div>
//                     </div>";
//            }
//        }

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
    }

    private function handle_post_battle_conquest(array $row, Conquest $conquest, Kingdom $enemy_kingdom, User $enemy_user,
                                                 User  $attacker_user, string &$message, string &$enemy_msg): void
    {
        $rate = $conquest->get_conquering_rate($conquest->get_conquerer_count());
        $is_conquered = $conquest->is_conquered($rate);

        if ($is_conquered) {
            $c_id = $conquest->fetch_conquerer_id();
            $soldier_types = $conquest->get_soldier_types();
            $score_loss = $soldier_types[$c_id]["score"];

            // Score decrease for losing one Conquerer
            $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$score_loss, $attacker_user->get_user_id()]);

            $this->mysqli->execute_query($conquest->get_conquerer_count() <= 1 ? "DELETE FROM sent_troops WHERE eventid = ? AND soldierid = ?"
                : "UPDATE sent_troops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?", [$row["eventid"], $c_id]);

            // Check: Is it the last kingdom of the defender?
            $k_count_res = $this->mysqli->execute_query("SELECT COUNT(*) FROM kingdoms WHERE userid = ?", [$enemy_user->get_user_id()]);
            $loss_res = $this->mysqli->execute_query("SELECT SUM((b.buildinglevel * (b.buildinglevel + 1) / 2) * bl.buildingscore) AS loss 
                                            FROM buildings b 
                                            JOIN building_list bl ON b.buildingid = bl.id 
                                            WHERE b.kingdomid = ?",
                [$enemy_kingdom->get_kingdom_id()]);
            $total_building_score_loss = (int)($loss_res->fetch_assoc()["loss"] ?? 0);
            $has_more_kingdoms = ($k_count_res->fetch_column() > 1);

            if ($has_more_kingdoms) {
                // Defender still has some other kingdoms
                $this->mysqli->execute_query("DELETE FROM events WHERE kingdomid = ? AND userid = ?", [$enemy_kingdom->get_kingdom_id(), $enemy_user->get_user_id()]);
                $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$total_building_score_loss, $enemy_user->get_user_id()]);

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
                // Defender was completely destroyed -> defender starts over again
                $this->mysqli->execute_query("UPDATE users SET score = 2 WHERE id = ?", [$enemy_user->get_user_id()]);
                $this->mysqli->execute_query("DELETE FROM events WHERE userid = ?", [$enemy_user->get_user_id()]);

                $new_k_id = new Kingdom($this->mysqli)->create_kingdom($enemy_user->get_user_id(), $enemy_user->get_user_name());

                if ($new_k_id) {
                    $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$new_k_id, $enemy_user->get_user_id()]);
                }
            }

            // Kingdom now belongs to the attacker
            $this->mysqli->execute_query("UPDATE kingdoms SET userid = ?, username = ?, creation_method = 1 WHERE id = ?",
                [$attacker_user->get_user_id(), $attacker_user->get_user_name(), $enemy_kingdom->get_kingdom_id()]);

            // Message for Attacker
            $atk_main = "<b>Glorreicher Sieg!</b> Das Königreich wurde eingenommen und gehört nun dir.";
            $atk_sub = "Für die Eroberung hat sich ein <b>Eroberer</b> geopfert.";
            $message .= BattleReportRenderer::render_outcome_box("Eroberung erfolgreich", $atk_main, 0, 0, $atk_sub, "success");

            // Message for Defender
            $def_main = "<b>Das Schicksal hat sich gegen uns gewandt!</b> Unser Königreich wurde vom Gegner besetzt.";
            $def_sub = "";
            if (!$has_more_kingdoms) $def_sub = "<br>Da dies dein letztes Dorf war, musst du an einem neuen Standort von vorne beginnen.";
            $enemy_msg .= BattleReportRenderer::render_outcome_box("Königreich verloren", $def_main, 0, 0, $def_sub, "error");
        } else {
            $fail_main = "Die Eroberung ist gescheitert. Unsere Truppen konnten die Kontrolle über das Stadtzentrum nicht sichern.";
            $fail_sub = "Die Chance auf Erfolg lag bei " . $rate . "%. Die Soldaten ziehen sich zurück.";
            $message .= BattleReportRenderer::render_outcome_box("Eroberungsversuch", $fail_main, 0, 0, $fail_sub, "error");
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

        return $soldiers;
    }

    private function check_watchtower_notifications(): void
    {
        $current_time = time();
        $query = "
            SELECT e.eventid, e.arrivaltime, e.targetid, e.kingdomid AS source_id,
                   k.userid, k.username, k.kingdomname
            FROM events e
            JOIN kingdoms k ON e.targetid = k.id
            WHERE e.actionid = ? 
              AND e.notification_sent = 0
        ";

        $results = $this->mysqli->execute_query($query, [ActionTypes::ACTION_SEND_TROOPS]);

        foreach ($results as $row) {
            $target_kingdom = new Kingdom($this->mysqli, $row["targetid"]);
            $wt_level = $target_kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_WATCHTOWER);

            if ($wt_level <= 0) continue;

            $visibility_window = $wt_level * WATCHTOWER_DETECTION_PER_LEVEL;
            $detection_time = $row["arrivaltime"] - $visibility_window;

            if ($current_time >= $detection_time) {
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

                        $total_atk += $count * $t["attack"];
                        $total_def += $count * $t["defense"];

                        $icon_path = "images/icons/" . $t["icon"] . ".png";
                        $main_text .= "<div class='unit-badge' title='" . e($t["soldiername"]) . "'>";
                        $main_text .= "<img src='$icon_path' alt=''>";
                        $main_text .= "<b>" . fnum($count) . "x</b>";
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

                $this->mysqli->execute_query("UPDATE events SET notification_sent = 1 WHERE eventid = ?", [$row["eventid"]]);
            }
        }
    }

    private function process_spy_mission(array $row, int $atk_scouts, Kingdom $home_k, Kingdom $enemy_k, User $attacker_user): void
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

        // Attacker losses
        if ($atk_losses > 0) {
            $this->mysqli->execute_query(
                "UPDATE sent_troops SET soldiercount = GREATEST(0, soldiercount - ?) WHERE eventid = ? AND soldierid = ?",
                [$atk_losses, $event_id, Soldiers::SOLDIER_SCOUT]
            );
        }

        // Defender losses
        if ($def_losses > 0) {
            $this->mysqli->execute_query(
                "UPDATE soldiers SET soldiercount = GREATEST(0, soldiercount - ?) WHERE kingdomid = ? AND soldierid = ?",
                [$def_losses, $enemy_k->get_kingdom_id(), Soldiers::SOLDIER_SCOUT]
            );
        }

        // Scout report
        $survivors = $atk_scouts - $atk_losses;

        if ($survivors > 0) {
            $msg_atk = $this->generate_scout_report($atk_scouts, $atk_losses, $enemy_k);

            $msg_def = "⚠️ <b>Spionage-Warnung!</b><br>Späher aus <b>{$home_k->get_kingdom_name()}</b> ({$home_k->get_kingdom_map_x()}:{$home_k->get_kingdom_map_y()}) wurden dabei ertappt, wie sie unsere Stadt auskundschafteten.";
            if ($def_losses > 0) $msg_def .= "<br>Unsere Grenzwache verlor dabei $def_losses Späher.";

            $this->mysqli->execute_query(
                "UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + 20, $event_id]
            );
        } else {
            $msg_atk = "❌ <b>Spionage fehlgeschlagen!</b><br>Unsere Späher wurden in {$enemy_k->get_kingdom_name()} entdeckt und abgefangen. Alle Späher sind gefallen.";
            $msg_def = "⚔️ <b>Spionage abgewehrt!</b><br>Wir haben feindliche Späher von <b>$attacker_name</b> entdeckt und vernichtet. Unsere Geheimnisse sind sicher.";

            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        }

        send_server_message($attacker_id, $attacker_name, $msg_atk, MessageCategories::CATEGORY_WAR);
        send_server_message($enemy_owner_id, $enemy_owner_name, $msg_def, MessageCategories::CATEGORY_WAR);
    }

    private function generate_scout_report(int $atk_scouts, int $atk_losses, Kingdom $enemy_k): string
    {
        $survivors = $atk_scouts - $atk_losses;
        $report = "<div class='battle-report'>";
        $report .= "<div class='battle-column'>";
        $report .= "<div class='title-border'>Spionagebericht: " . e($enemy_k->get_kingdom_name()) . "</div>";
        $report .= "<div class='report-section-title'>Ressourcen</div>";

        // TIER 1: Resources
        $res = [
            "food" => $enemy_k->get_kingdom_food(),
            "wood" => $enemy_k->get_kingdom_wood(),
            "stone" => $enemy_k->get_kingdom_stone(),
            "gold" => $enemy_k->get_kingdom_gold()
        ];
        $report .= BattleReportRenderer::render_scout_resource_bar($res);

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
            $report .= "<div style='display: flex; flex-wrap: wrap; gap: 10px;'>";

            $t_res = $this->mysqli->execute_query(
                "SELECT s.soldiername, s.soldiercount, sl.icon 
             FROM soldiers s 
             JOIN soldier_list sl ON s.soldierid = sl.id 
             WHERE s.kingdomid = ? AND s.soldiercount > 0",
                [$enemy_k->get_kingdom_id()]
            );

            if ($t_res->num_rows > 0) {
                while ($t = $t_res->fetch_assoc()) {
                    $report .= "<div style='flex: 1 1 200px;'>" . BattleReportRenderer::render_unit_card($t["soldiername"], $t["soldiercount"], 0, $t["icon"], true) . "</div>";
                }
            } else {
                $report .= "<i>Keine Truppen stationiert.</i>";
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

        if (!$tile) {
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
            $max_capacity = (int)($raider_count * RAIDER_BASE_CAPACITY * (1 + ($plunder_lvl * PLUNDER_CAPACITY_BONUS)));

            // Plunder (proportional or all)
            $tile_total = $tile["food"] + $tile["wood"] + $tile["stone"] + $tile["gold"];
            $total_to_take = min($max_capacity, $tile_total);

            if ($total_to_take <= 0) {
                $message = "<div class='battle-report'>";
                $message .= BattleReportRenderer::render_outcome_box("Lager leer", "Das Lager ist bereits vollkommen leer.", 0, 0, "Rückzug ohne Beute.");
                $message .= "</div>";

                $this->mysqli->execute_query("UPDATE map SET kingdomid = -1 WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
                return;
            }

            // Calc factor: how much can we take?
            $take_factor = $total_to_take / $tile_total;

            $loot_f = (int)floor($tile["food"] * $take_factor);
            $loot_w = (int)floor($tile["wood"] * $take_factor);
            $loot_s = (int)floor($tile["stone"] * $take_factor);
            $loot_g = $total_to_take - ($loot_f + $loot_w + $loot_s);

            if ($loot_g > $tile["gold"]) {
                $loot_g = $tile["gold"];
            }

            $total_actually_looted = $loot_f + $loot_w + $loot_s + $loot_g;

            // Base risk: 5% of plunder
            $losses = 0;

            if (mt_rand(1, 100) <= RAIDER_LOSS_CHANCE) { // 15% Danger chance
                $loss_percent = mt_rand(5, RAIDER_LOSS_CHANCE) / 100;
                $losses = (int)ceil($raider_count * $loss_percent);

                // Score loss for fallen raiders
                $res_score = $this->mysqli->execute_query("SELECT scoregain FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_RAIDER]);
                $score_per_raider = $res_score->fetch_column() ?: 1;
                $total_score_loss = $losses * $score_per_raider;

                $this->mysqli->execute_query("UPDATE users SET score = GREATEST(2, score - ?) WHERE id = ?", [$total_score_loss, $attacker_user->get_user_id()]);

                // Reduce troops in event
                if ($losses >= $raider_count) {
                    $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ? AND soldierid = ?", [$event_id, Soldiers::SOLDIER_RAIDER]);

                    $losses = $raider_count; // Everyone dead
                } else {
                    $this->mysqli->execute_query("UPDATE sent_troops SET soldiercount = soldiercount - ? WHERE eventid = ? AND soldierid = ?", [$losses, $event_id, Soldiers::SOLDIER_RAIDER]);
                }
            }

            // Build message
            $message = "<div class='battle-report'>";

            $loot_data = [];
            if ($loot_f > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_FOOD] = $loot_f;
            if ($loot_w > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_WOOD] = $loot_w;
            if ($loot_s > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_STONE] = $loot_s;
            if ($loot_g > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_GOLD] = $loot_g;

            $survivors = $raider_count - $losses;
            $coords = "($target_x:$target_y)";
            $main_text = "Unsere Räuber haben ein verlassenes Lager $coords überfallen und Ressourcen erbeutet:";
            $sub_text = ($survivors > 0) ? "Die Überlebenden treten mit der Beute den Rückweg an." : "Niemand kehrte lebend zurück, die Beute ging verloren!";

            $message .= BattleReportRenderer::render_outcome_box("Erfolgreiche Plünderung", $main_text, 0, 0, $sub_text, "normal", $loot_data);

            $message .= "<div style='max-width: 350px; margin: 10px auto; width: 100%;'>";
            $message .= BattleReportRenderer::render_unit_card("Räuber", $raider_count, $losses, "icon_robber");
            $message .= "</div>";
            $message .= "</div>";

            // If we could take everything (or the field is now empty), we remove the field
            if (($tile_total - $total_actually_looted) < 10) {
                $this->mysqli->execute_query("UPDATE map SET kingdomid = -1 WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
                $this->mysqli->execute_query("DELETE FROM resource_tiles_data WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);

                $message .= "Das Lager wurde komplett geleert.<br>";
            } else {
                $this->mysqli->execute_query("UPDATE resource_tiles_data SET food = food - ?, wood = wood - ?, stone = stone - ?, gold = gold - ? WHERE mapx = ? AND mapy = ?",
                    [$loot_f, $loot_w, $loot_s, $loot_g, $target_x, $target_y]);
            }

            // Save loot
            $this->mysqli->execute_query(
                "UPDATE events SET loot_food = ?, loot_wood = ?, loot_stone = ?, loot_gold = ? WHERE eventid = ?",
                [$loot_f, $loot_w, $loot_s, $loot_g, $event_id]
            );

            if ($losses > 0) {
                $message .= "<br><span class='error'>⚠️ <b>Verluste:</b> $losses Räuber wurden bei Kämpfen mit im Hinterhalt lauernden Dieben getötet oder verletzt.</span><br>";
            }
        } else {
            $message = "<div class='battle-report'>";
            $message .= BattleReportRenderer::render_outcome_box("Keine Räuber", "Ohne spezialisierte Räuber können wir diese massiven Vorräte nicht abtransportieren.", 0, 0,
                "Die Truppen kehren unverrichteter Dinge um.");
            $message .= "</div>";
        }
    }
}
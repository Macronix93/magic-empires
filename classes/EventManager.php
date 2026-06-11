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
        $result = $this->mysqli->execute_query("SELECT * FROM events WHERE userid = ?", [$uid]);

        foreach ($result as $row) {
            $is_due = false;

            if (in_array($row["actionid"], [ActionTypes::ACTION_BUILD_BUILDING, ActionTypes::ACTION_RESEARCH_TECH])
                && $row["buildingtime"] <= $now) $is_due = true;
            if ($row["actionid"] == ActionTypes::ACTION_BUILD_TROOPS) {
                $soldiers_stats = $this->load_soldier_data();
                $s_id = $row["soldierid"];
                $time_per_unit = $soldiers_stats[$s_id]->get_soldier_time();

                $next_unit_ready = $row["recruittime"] - (($row["soldiergoal"] - 1) * $time_per_unit);

                if ($now >= $next_unit_ready) $is_due = true;
            }
            if (in_array($row["actionid"], [ActionTypes::ACTION_SEND_TROOPS, ActionTypes::ACTION_RETURN_TROOPS,
                    ActionTypes::ACTION_RECEIVE_RESOURCES, ActionTypes::ACTION_RETURN_RESOURCES, ActionTypes::ACTION_UPGRADE_TROOPS])
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
        $res = $this->mysqli->execute_query("SELECT techscore FROM techlist WHERE id = ?", [$tech_id]);
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

        $res = $this->mysqli->execute_query("SELECT buildingscore FROM buildinglist WHERE id = ?", [$row["buildingid"]]);
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

        $res_to = $this->mysqli->execute_query("SELECT soldiername, requiredtime, scoregain FROM soldierlist WHERE id = ?", [$to_id]);
        $target_data = $res_to->fetch_assoc();

        $res_from = $this->mysqli->execute_query("SELECT scoregain FROM soldierlist WHERE id = ?", [$from_id]);
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

        $soldier_name = $soldiers[$s_id]->get_soldier_name();
        $soldier_time = $soldiers[$s_id]->get_soldier_time();

        $total_difference = $row["recruittime"] - time();
        $number_left_to_recruit = max(0, ceil($total_difference / $soldier_time));
        $soldier_difference = $row["soldiergoal"] - $number_left_to_recruit;

        if ($soldier_difference != 0) {
            $this->mysqli->execute_query("UPDATE events SET soldiergoal = soldiergoal - ? WHERE kingdomid = ? AND soldierid = ?",
                [$soldier_difference, $row["kingdomid"], $s_id]);

            $soldier_name = $soldiers[$s_id]->get_soldier_name();
            $this->mysqli->execute_query("INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
                                                VALUES (?, ?, ?, ?) 
                                                ON DUPLICATE KEY UPDATE soldiercount = soldiercount + ?",
                [$row["kingdomid"], $s_id, $soldier_name, $soldier_difference, $soldier_difference]);

            $this->user->set_last_recruited_soldier($row["kingdomid"], $soldier_name, $soldier_difference);

            $vill_cost = $soldier_difference * $soldiers[$s_id]->get_soldier_villager_cost();
            $this->mysqli->execute_query("UPDATE kingdoms SET villager = villager - ? WHERE id = ?", [$vill_cost, $row["kingdomid"]]);
            // apply_villager_cap ?

            $score_gain = $soldier_difference * $soldiers[$s_id]->get_soldier_score_gain();
            $this->update_user_score($score_gain, $this->user);
        }

        if ($number_left_to_recruit == 0) {
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

            Logger::get_instance()->log_game("ECONOMY", "RECRUIT_FINISH", [
                "soldier_id" => $s_id,
                "soldier_name" => $soldier_name,
                "amount" => $row["soldiergoal"]
            ], $row["kingdomid"]);
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

        // Truppenzusammensetzung prüfen
        $res = $this->mysqli->execute_query(
            "SELECT soldierid, soldiercount FROM senttroops WHERE eventid = ?",
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
                [ActionTypes::ACTION_RETURN_TROOPS, time() + 10, $row["eventid"]]);
        } else {
            $enemy_kingdom = new Kingdom($this->mysqli, $row["targetid"]);

            // User only sent spies to scout
            if ($combat_units === 0 && $scout_count > 0 && $attacker_id != $enemy_kingdom->get_kingdom_owner_id()) {
                $this->process_spy_mission($row, $scout_count, $home_kingdom, $enemy_kingdom, $attacker_user_obj);
                return;
            }

            $current_owner_id = $enemy_kingdom->get_kingdom_owner_id();

            if ($attacker_id == $current_owner_id) {
                $message .= "Deine Truppen sind erfolgreich bei deinem Königreich {$enemy_kingdom->get_kingdom_name()} ({$row["targetx"]}:{$row["targety"]}) angekommen.<br><br>";

                $conquest->set_target_id($row["targetid"]);
                $conquest->deploy_soldiers_to_kingdom();
                $message .= $conquest->get_my_message();
            } else {
                $this->process_battle($row, $conquest, $home_kingdom, $enemy_kingdom, $attacker_user_obj, 10);
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
                $this->mysqli->execute_query("DELETE FROM senttroops WHERE eventid = ?", [$row["eventid"]]);
                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
            }
            return;
        }

        $target_x = $row["targetx"];
        $target_y = $row["targety"];
        $res = $this->mysqli->execute_query("SELECT username FROM users WHERE id = ?", [$owner_id]);
        $u_name = $res->fetch_assoc()["username"] ?? "Spieler";

        if ($row["targetid"] == -1 || $row["targetid"] == -2) {
            $res = $this->mysqli->execute_query("SELECT ft.fieldname FROM map m JOIN fieldtypes ft ON m.fieldtype = ft.fieldid WHERE m.mapx = ? AND m.mapy = ?",
                [$target_x, $target_y]);

            $field_name = $res->fetch_assoc()["fieldname"] ?? "Unbekannt";
        } else {
            $enemy_k = new Kingdom($this->mysqli, $row["targetid"]);

            $field_name = " {$enemy_k->get_kingdom_owner_name()} ({$enemy_k->get_kingdom_name()})";
        }

        $msg = "Deine Truppen sind vom Feldzug zu $field_name ($target_x:$target_y) zurückgekehrt!";

        $res = $this->mysqli->execute_query("SELECT soldierid, soldiercount FROM senttroops WHERE eventid = ?", [$row["eventid"]]);

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

            $msg .= "<br>Die Heimkehrer haben Beute abgeliefert:<br><br>";
            if ($row["loot_food"] > 0) $msg .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . $row["loot_food"] . " ";
            if ($row["loot_wood"] > 0) $msg .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . $row["loot_wood"] . " ";
            if ($row["loot_stone"] > 0) $msg .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . $row["loot_stone"] . " ";
            if ($row["loot_gold"] > 0) $msg .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . $row["loot_gold"] . " ";
        }

        send_server_message($owner_id, $u_name, $msg, MessageCategories::CATEGORY_WAR);

        // Delete the event and senttroops
        $this->mysqli->execute_query("DELETE FROM senttroops WHERE eventid = ?", [$row["eventid"]]);
        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
    }

    private function handle_resource_transfer(array $row): void
    {
        if ($row["arrivaltime"] >= time()) return;

        // buildingid = Resource type, buildinglevel = Resource amount
        $target_k = new Kingdom($this->mysqli, $row["kingdomid"]);
        $res_type = $row["buildingid"];
        $amount = $row["buildinglevel"];

        switch ($res_type) {
            case ResourceTypes::RESOURCE_TYPE_FOOD:
                $target_k->give_kingdom_food($amount);
                break;
            case ResourceTypes::RESOURCE_TYPE_WOOD:
                $target_k->give_kingdom_wood($amount);
                break;
            case ResourceTypes::RESOURCE_TYPE_STONE:
                $target_k->give_kingdom_stone($amount);
                break;
            case ResourceTypes::RESOURCE_TYPE_GOLD:
                $target_k->give_kingdom_gold($amount);
                break;
        }

        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

        $msg = "Eine Warenlieferung ist in deinem Königreich \"{$target_k->get_kingdom_name()}\" 
                ({$target_k->get_kingdom_map_x()}:{$target_k->get_kingdom_map_y()}) angekommen:<br><br>" . get_resource_icon($res_type) . " " . fnum($amount);
        send_server_message($this->user->get_user_id(), $this->user->get_user_name(), $msg, MessageCategories::CATEGORY_TRADE);
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
                $bonus = floor((round(STORAGE_STARTING_VALUE * pow(STORAGE_INC_FACTOR, $lvl))) / 100) * 100;

                $this->mysqli->execute_query("UPDATE kingdoms SET maxfood = maxfood + ?, maxwood = maxwood + ?, maxstone = maxstone + ?, maxgold = maxgold + ? WHERE id = ?",
                    [$bonus, $bonus, $bonus, $bonus, $kid]);
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
        $res = $this->mysqli->execute_query("SELECT ft.$rate_field FROM map m JOIN fieldtypes ft ON m.fieldtype = ft.fieldid WHERE m.kingdomid = ?", [$kid]);
        $rate = $res->fetch_assoc()[$rate_field];
        $this->mysqli->execute_query("UPDATE kingdoms SET $target_field = $target_field + ? WHERE id = ?", [$base * $rate, $kid]);
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
            "SELECT soldiercount FROM senttroops WHERE eventid = ? AND soldierid = ?",
            [$event_id, Soldiers::SOLDIER_SETTLER_WAGON]
        );
        $wagon_count = ($res->num_rows > 0) ? $res->fetch_column() : 0;

        if ($wagon_count > 0) {
            $chance = BASE_SETTLER_CHANCE + (($wagon_count - 1) * SETTLER_CHANCE_STEP);
            $chance = min(MAX_SETTLER_CHANCE, $chance);

            $message .= "Deine Siedler versuchen, ein neues Königreich zu gründen... (Erfolgschance: " . ($chance * 100) . "%)<br>";

            if (mt_rand(0, 100) <= ($chance * 100)) {
                $new_kingdom_id = (new Kingdom($this->mysqli))->create_kingdom(
                    $attacker_user->get_user_id(),
                    $attacker_user->get_user_name(),
                    true,
                    $target_x,
                    $target_y
                );

                if ($new_kingdom_id) {
                    if ($wagon_count > 1) {
                        $this->mysqli->execute_query(
                            "UPDATE senttroops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?",
                            [$event_id, Soldiers::SOLDIER_SETTLER_WAGON]
                        );
                    } else {
                        $this->mysqli->execute_query(
                            "DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?",
                            [$event_id, Soldiers::SOLDIER_SETTLER_WAGON]
                        );
                    }

                    $message .= "<br><span class='passed'><b>Erfolg!</b></span> Ein neues Königreich wurde gegründet. <br>";
                    $message .= "Es hört auf den Namen: <b>Königreich $new_kingdom_id</b>.<br>";
                    $message .= "Die restlichen Truppen machen sich auf den Rückweg.";
                } else {
                    $message .= "<span class='error'>Fehler beim Erstellen des Königreichs!</span>";
                }
            } else {
                $message .= "<span class='error'>Die Gründung ist fehlgeschlagen.</span> Die Siedler konnten sich nicht auf ein Gebiet einigen und kehren unverrichteter Dinge zurück.<br>";
            }
        } else {
            $message .= "Ohne Gründungskarren können wir hier keine Siedlung errichten. Deine Truppen kehren um.<br>";
        }
    }

    private function process_battle(array $row, Conquest $conquest, Kingdom $home_kingdom, Kingdom $enemy_kingdom, User $attacker_user, int $return_time): void
    {
        $attacker_id = $attacker_user->get_user_id();
        $attacker_name = $attacker_user->get_user_name();

        $enemy_user_id = $enemy_kingdom->get_kingdom_owner_id();
        $enemy_user_name = $enemy_kingdom->get_kingdom_owner_name();
        $enemy_user = new User($enemy_user_id, $enemy_user_name);

        $message = "";
        $enemy_msg = "";

        if ($conquest->has_noob_protection($attacker_user->get_user_score(), $enemy_user->get_user_score())) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);

            $message = "Der Gegner steht unter Noob-Schutz! Die Truppen machen sich auf den Heimweg.";
            send_server_message($attacker_id, $attacker_name, $message, MessageCategories::CATEGORY_WAR);
            return;
        }

        // Prepare battle logs
        $message .= "Es hat ein Kampf stattgefunden mit Spieler $enemy_user_name ({$enemy_kingdom->get_kingdom_name()})!<br>";
        $enemy_msg .= "Du wurdest vom Spieler $attacker_name ({$home_kingdom->get_kingdom_name()} {$home_kingdom->get_kingdom_map_x()}:{$home_kingdom->get_kingdom_map_y()}) angegriffen!<br>";

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

        // Table layouts
        $table_header = "<table class='table' style='width: 100%;'><tr><td class='td-center td-gradient'>Einheit</td><td class='td-center td-gradient'>Eigene Truppen</td><td class='td-center td-gradient'>Eigene Verluste</td><td class='td-center td-gradient'>Gegn. Truppen</td><td class='td-center td-gradient'>Gegn. Verluste</td></tr>";

        $summary = "<tr><td class='td-center'><b>Summe</b></td><td class='td-center'>{$conquest->get_initial_soldier_count()}</td><td class='td-center'>{$conquest->get_my_loss_count()}</td><td class='td-center'>{$conquest->get_initial_enemy_count()}</td><td class='td-center'>{$conquest->get_enemy_loss_count()}</td></tr></table><br>";

        $message .= $table_header . $conquest->get_my_message() . $summary . $conquest->append_my_after_battle_message();
        //$message .= "<br><b>Punkteverlust durch Truppen:</b> -" . fnum($conquest->get_my_score_loss()) . " Punkte.<br>";

        $enemy_msg .= $table_header . $conquest->get_enemy_message() . "<tr><td class='td-center'><b>Summe</b></td><td class='td-center'>{$conquest->get_initial_enemy_count()}</td><td class='td-center'>{$conquest->get_enemy_loss_count()}</td><td class='td-center'>{$conquest->get_initial_soldier_count()}</td><td class='td-center'>{$conquest->get_my_loss_count()}</td></tr></table><br>" . $conquest->append_enemy_after_battle_message();
        //$enemy_msg .= "<br><b>Punkteverlust durch Truppen:</b> -" . fnum($conquest->get_enemy_score_loss()) . " Punkte.<br>";

        // Conquering logic
        if ($conquest->get_enemy_loss_count() == $conquest->get_initial_enemy_count()) {
            if ($conquest->has_conquerer()) {
                $this->handle_post_battle_conquest($row, $conquest, $enemy_kingdom, $enemy_user, $attacker_user, $message, $enemy_msg);
            }
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

            $message .= "Die verbleibenden Truppen machen sich auf den Heimweg.";
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
                    $message .= "<br><br>💰 <b>Raubzug erfolgreich:</b><br>Deine Diebe haben Ressourcen erbeutet (<b>$actual_carried</b> Einheiten insgesamt).";
                    $enemy_msg .= "<br>⚠️ <b>Plünderung:</b><br>Feindliche Diebe haben deine Lager durchwühlt und <b>$actual_carried</b> Ressourcen geraubt!";
                }
            } else {
                $message .= "<br><br>🎒 <b>Raubzug gescheitert:</b><br>Es gab keine ungeschützten Ressourcen zu holen.";
            }
        }

        $attacker_survived = ($conquest->get_initial_soldier_count() > $conquest->get_my_loss_count());
        $surviving_scouts = $conquest->get_surviving_count(Soldiers::SOLDIER_SCOUT);

        if (!$attacker_survived && $surviving_scouts <= 0) {
            $message = "<b>Die Schlacht war ein totaler Fehlschlag!</b><br>Kein einziger Soldat kehrte lebend zurück. Wir haben keine Informationen über die verbliebene Stärke des Gegners.";
        } else {
            if ($surviving_scouts > 0) {
                $message .= $this->generate_scout_report($surviving_scouts, $enemy_kingdom);
            } else if ($attacker_survived) {
                $message .= "<br><br>🛡️ <b>Bericht der Heimkehrer:</b><br>Unsere Soldaten berichten, dass der Gegner nach dem Kampf noch Truppen übrig hatte.";
            }
        }

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
        $message .= "Es wird versucht, das Königreich zu erobern... (Chance: $rate %)<br>";

        if ($conquest->is_conquered($rate)) {
            $c_id = $conquest->fetch_conquerer_id();
            $soldier_types = $conquest->get_soldier_types();
            $score_loss = $soldier_types[$c_id]["score"];

            // Score decrease for losing one Conquerer
            $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$score_loss, $attacker_user->get_user_id()]);

            $this->mysqli->execute_query($conquest->get_conquerer_count() <= 1 ? "DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?"
                : "UPDATE senttroops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?", [$row["eventid"], $c_id]);

            // Check: Is it the last kingdom of the defender?
            $k_count_res = $this->mysqli->execute_query("SELECT COUNT(*) FROM kingdoms WHERE userid = ?", [$enemy_user->get_user_id()]);
            $loss_res = $this->mysqli->execute_query("SELECT SUM((b.buildinglevel * (b.buildinglevel + 1) / 2) * bl.buildingscore) AS loss 
                                            FROM buildings b 
                                            JOIN buildinglist bl ON b.buildingid = bl.id 
                                            WHERE b.kingdomid = ?",
                [$enemy_kingdom->get_kingdom_id()]);
            $total_building_score_loss = (int)($loss_res->fetch_assoc()["loss"] ?? 0);

            if ($k_count_res->fetch_column() > 1) {
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

                $new_k_id = (new Kingdom($this->mysqli))->create_kingdom($enemy_user->get_user_id(), $enemy_user->get_user_name());

                if ($new_k_id) {
                    $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$new_k_id, $enemy_user->get_user_id()]);
                }
            }

            // Kingdom now belongs to the attacker
            $this->mysqli->execute_query("UPDATE kingdoms SET userid = ?, username = ?, creation_method = 1 WHERE id = ?",
                [$attacker_user->get_user_id(), $attacker_user->get_user_name(), $enemy_kingdom->get_kingdom_id()]);

            $message .= "Die Eroberung war erfolgreich!<br>Für die Eroberung hat sich ein Eroberer geopfert.<br>Das Königreich gehört nun dir.<br>";
            $message .= "Der Gegner hat <b>" . fnum($total_building_score_loss) . " Punkte</b> durch den Verlust der Gebäude verloren.<br>";
            $enemy_msg .= "Unser Königreich wurde vom Gegner eingenommen...";
            $enemy_msg .= "Du hast <b>" . fnum($total_building_score_loss) . " Punkte</b> durch den Verlust deiner Gebäude verloren.<br>";
        } else {
            $message .= "Die Eroberung ist gescheitert...<br>";
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

            $msg = "Ein Handelsangebot von dir ist abgelaufen. Die Ressourcen wurden an dein Königreich zurückerstattet.";
            send_server_message($u_id, $u_name, $msg, MessageCategories::CATEGORY_TRADE);

            // Delete offer
            $this->mysqli->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$offer_id]);
        }
    }

    private function update_user_score(int $add, User $target_user): void
    {
        $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?",
            [$add, $target_user->get_user_id()]);
        $target_user->set_user_score($target_user->get_user_score() + $add);
    }

    private function load_soldier_data(): array
    {
        $soldiers = [];
        $res = $this->mysqli->execute_query("SELECT * FROM soldierlist");

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
            SELECT e.eventid, e.arrivaltime, e.targetid, k.userid, k.username, k.kingdomname
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

                $msg = "<b>Wachturm-Meldung:</b> In " . $row['kingdomname'] . " wurden Truppen gesichtet!";

                if ($intel_level >= 1) {
                    $msg .= "<br>Ankunft in ca.: " . $time_to_arrival;
                }
                if ($intel_level >= 3) {
                    // TODO: Show troop count etc.
                    $msg .= "<br>Unsere Späher melden eine große Armee!";
                }

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
        $res_stats = $this->mysqli->execute_query("SELECT attack, defense FROM soldierlist WHERE id = ?", [Soldiers::SOLDIER_SCOUT]);
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
                "UPDATE senttroops SET soldiercount = GREATEST(0, soldiercount - ?) WHERE eventid = ? AND soldierid = ?",
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
            $msg_atk = "<b>Erfolgreiche Spionage in {$enemy_k->get_kingdom_name()} ({$enemy_k->get_kingdom_map_x()}:{$enemy_k->get_kingdom_map_y()}):</b><br>";
            $msg_atk .= $this->generate_scout_report($survivors, $enemy_k);

            if ($atk_losses > 0) $msg_atk .= "<br><span class='error'>Verluste: $atk_losses Späher.</span>";

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

    private function generate_scout_report(int $survivors, Kingdom $enemy_k): string
    {
        $report = "<br><br>🔍 <b>Spionagebericht unserer Überlebenden:</b><br><br>";

        // Tier 1: Resources
        $report .= "💰 <b>Ressourcen:</b><br>";
        $report .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . fnum($enemy_k->get_kingdom_food()) . " | ";
        $report .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . fnum($enemy_k->get_kingdom_wood()) . " | ";
        $report .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . fnum($enemy_k->get_kingdom_stone()) . " | ";
        $report .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . fnum($enemy_k->get_kingdom_gold()) . "<br>";

        // Tier 2 and 3: Troops and full building info
        if ($survivors >= 15) {
            // TIER 3: Detaillierte Liste ALLER Gebäude
            $report .= "<br>🏰 <b>Gebäudeinformationen:</b><br>";
            $b_res = $this->mysqli->execute_query("SELECT buildingname, buildinglevel FROM buildings WHERE kingdomid = ? ORDER BY buildinglevel DESC", [$enemy_k->get_kingdom_id()]);

            if ($b_res->num_rows > 0) {
                $report .= "<table class='table'>";
                while ($b = $b_res->fetch_assoc()) {
                    $report .= "<tr><td>{$b["buildingname"]}</td><td>Stufe " . (int)$b["buildinglevel"] . "</td></tr>";
                }
                $report .= "</table>";
            }
        } else if ($survivors >= 5) {
            // Tier 2: Only main buildings
            $report .= "<br>🏰 <b>Gebäudeinformationen:</b><br>";
            $report .= "- Dorfzentrum: Stufe " . $enemy_k->get_kingdom_building_level(BuildingTypes::BUILDING_TOWNCENTER) . "<br>";
            $report .= "- Mauer: Stufe " . $enemy_k->get_kingdom_building_level(BuildingTypes::BUILDING_WALL) . "<br>";
            $report .= "- Lager: Stufe " . $enemy_k->get_kingdom_building_level(BuildingTypes::BUILDING_STORAGE) . "<br>";
        }

        // Tier 3: Troop info
        if ($survivors >= 15) {
            $report .= "<br>🛡️ <b>Gegnerische Truppen:</b><br>";
            $t_res = $this->mysqli->execute_query("SELECT soldiername, soldiercount FROM soldiers WHERE kingdomid = ? AND soldiercount > 0", [$enemy_k->get_kingdom_id()]);

            if ($t_res->num_rows > 0) {
                $report .= "<table class='table'>";

                while ($t = $t_res->fetch_assoc()) {
                    $report .= "<tr><td>{$t["soldiername"]}</td><td>" . fnum($t["soldiercount"]) . "</td></tr>";
                }

                $report .= "</table>";
            } else {
                $report .= "Keine Truppen stationiert.<br>";
            }
        }

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
            $message .= "Deine Truppen finden nur ein geplündertes Lager vor. Jemand war schneller!<br>";

            $this->mysqli->execute_query("UPDATE map SET kingdomid = -1 WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
            return;
        }

        // Count raiders
        $res = $this->mysqli->execute_query(
            "SELECT soldiercount FROM senttroops WHERE eventid = ? AND soldierid = ?",
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
                $message .= "Das Lager ist bereits vollkommen leer.<br>";

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
                $res_score = $this->mysqli->execute_query("SELECT scoregain FROM soldierlist WHERE id = ?", [Soldiers::SOLDIER_RAIDER]);
                $score_per_raider = $res_score->fetch_column() ?: 1;
                $total_score_loss = $losses * $score_per_raider;

                $this->mysqli->execute_query("UPDATE users SET score = GREATEST(2, score - ?) WHERE id = ?", [$total_score_loss, $attacker_user->get_user_id()]);

                // Reduce troops in event
                if ($losses >= $raider_count) {
                    $this->mysqli->execute_query("DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?", [$event_id, Soldiers::SOLDIER_RAIDER]);

                    $losses = $raider_count; // Everyone dead
                } else {
                    $this->mysqli->execute_query("UPDATE senttroops SET soldiercount = soldiercount - ? WHERE eventid = ? AND soldierid = ?", [$losses, $event_id, Soldiers::SOLDIER_RAIDER]);
                }
            }

            // Build message
            $message .= "💰 <b>Überfall auf Vorratslager erfolgreich!</b><br>";
            $message .= "Deine Räuber konnten insgesamt <b>" . fnum($total_actually_looted) . " Einheiten</b> sichern.<br>";

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

            if (($raider_count - $losses) > 0) {
                $message .= "<br>Die Überlebenden treten mit der Beute den Rückweg an.";
            } else {
                $message .= "<br><span class='error'>Niemand kehrt lebend zurück, die Beute ist verloren!</span>";

                //$this->mysqli->execute_query("UPDATE events SET loot_food = 0, loot_wood = 0, loot_stone = 0, loot_gold = 0 WHERE eventid = ?", [$event_id]);
            }
        } else {
            $message .= "Deine Truppen finden ein Vorratslager vor, aber ohne spezialisierte <b>Räuber</b> können sie die massiven Vorräte nicht abtransportieren. Sie kehren um.<br>";
        }
    }
}
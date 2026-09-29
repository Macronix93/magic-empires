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
        $uid = $this->user->get_user_id();
        if ($uid <= 0) return;

        $this->process_mines();
        $this->check_watchtower_notifications($uid);

        $current_kid = $this->user->get_current_kingdom();
        if ($current_kid > 0) {
            $lab_lvl = new Kingdom($current_kid)->get_kingdom_building_level(BuildingTypes::BUILDING_ALCHEMY_LAB);

            if ($lab_lvl > 0) {
                new Alchemy()->process_kingdom($current_kid, $lab_lvl);
            }
        }

        $now = time();

        $query = "
            SELECT e.* 
            FROM events e
            LEFT JOIN kingdoms k ON e.targetid = k.id
            WHERE (
                e.userid = ? 
                    OR k.userid = ? 
                    OR (e.guild_id > 0 AND e.guild_id = (SELECT guildid FROM users WHERE id = ? LIMIT 1))
                    OR (
                        e.targetid = " . MapFieldTypes::MAP_FIELD_MINE . "
                        AND EXISTS (
                            SELECT 1 
                            FROM mines mn 
                            JOIN mine_stationed_troops mst ON mn.id = mst.mine_id 
                            WHERE mn.mapx = e.targetx 
                                AND mn.mapy = e.targety 
                                AND mst.user_id = ?
                    )
                )
            )
        ";
        $result = $this->mysqli->execute_query($query, [$uid, $uid, $uid, $uid]);

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
                    ActionTypes::ACTION_SUPPORT_RETURN,
                    ActionTypes::ACTION_MINE_GATHER])
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
                if ($row["guild_id"] !== null) {
                    $this->handle_guild_research($row);
                } else {
                    $this->handle_research($row);
                }
                break;
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
                $kingdom = new Kingdom($origin_kingdom_id);
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

        $kingdom = new Kingdom($kingdom_id);

        // Apply resource effects
        switch ($tech_id) {
            case TechTypes::TECH_TYPE_WOOD_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_wood_rate = base_wood_rate + ? WHERE id = ?",
                    [RESEARCH_WOOD_INC, $kingdom_id]);

                $kingdom = new Kingdom($kingdom_id);
                $kingdom->recalculate_production();
                break;

            case TechTypes::TECH_TYPE_FOOD_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_food_rate = base_food_rate + ? WHERE id = ?",
                    [RESEARCH_FOOD_INC, $kingdom_id]);

                $kingdom = new Kingdom($kingdom_id);
                $kingdom->recalculate_production();
                break;

            case TechTypes::TECH_TYPE_STONE_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_stone_rate = base_stone_rate + ? WHERE id = ?",
                    [RESEARCH_STONE_INC, $kingdom_id]);

                $kingdom = new Kingdom($kingdom_id);
                $kingdom->recalculate_production();
                break;

            case TechTypes::TECH_TYPE_GOLD_INC:
                $this->mysqli->execute_query("UPDATE kingdoms SET base_gold_rate = base_gold_rate + ? WHERE id = ?",
                    [RESEARCH_GOLD_INC, $kingdom_id]);

                $kingdom = new Kingdom($kingdom_id);
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
        $score_gain = $res->fetch_assoc()["techscore"] * ($row["buildinglevel"] + 1);

        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

        $this->user->set_last_researched_tech($kingdom_id, $row["buildingname"], $row["buildinglevel"]);
        $this->update_user_score((int)$score_gain, $this->user);

        Logger::get_instance()->log_game("ECONOMY", "RESEARCH_FINISH", [
            "tech_id" => $tech_id,
            "tech_name" => $row["buildingname"],
            "level" => $row["buildinglevel"] + 1
        ], $kingdom_id);

        // Send push msg
        $k_name = $this->mysqli->execute_query("SELECT kingdomname FROM kingdoms WHERE id = ?", [$kingdom_id])->fetch_column() ?: "Dein Königreich";

        send_user_push(
            (int)$row["userid"],
            "📜 Forschung fertig: $k_name",
            "{$row["buildingname"]} (" . ($row["buildinglevel"] + 1) . ") wurde in $k_name erfolgreich erforscht.",
            "building",
            "university.php"
        );
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

        // Send push msg
        $k_name = $this->mysqli->execute_query("SELECT kingdomname FROM kingdoms WHERE id = ?", [$row["kingdomid"]])->fetch_column() ?: "Dein Königreich";
        $new_lvl = (int)$row["buildinglevel"] + 1;

        send_user_push(
            (int)$row["userid"],
            "🏰 Bau fertig: $k_name",
            "{$row["buildingname"]} ($new_lvl) in $k_name wurde fertiggestellt.",
            "building",
            "towncenter.php"
        );
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

            $res_cat = $this->mysqli->execute_query("SELECT category FROM soldier_list WHERE id = ?", [$to_id]);
            $target_cat = (int)$res_cat->fetch_column();
            $this->user->set_last_upgraded_soldier($kingdom_id, $s_name, $units_to_add, $target_cat);
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

        $kingdom = new Kingdom($row["kingdomid"]);
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

                $this->user->set_last_recruited_soldier($row["kingdomid"], $soldier_name, $units_to_deliver, (int)$soldiers[$s_id]->get_soldier_category());
                $this->update_user_score((int)($units_to_deliver * $soldiers[$s_id]->get_soldier_score_gain()), $this->user);
                update_player_stat((int)$row["userid"], "units_produced", $units_to_deliver);
            }
        }

        $res = $this->mysqli->execute_query("SELECT soldiergoal FROM events WHERE eventid = ?", [$row["eventid"]]);
        $check = $res->fetch_assoc();

        if (!$check || $check["soldiergoal"] <= 0) {
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

            $k_name = $this->mysqli->execute_query("SELECT kingdomname FROM kingdoms WHERE id = ?", [$row["kingdomid"]])->fetch_column() ?: "Dein Königreich";
            $s_name = $soldiers[$s_id]->get_soldier_name();

            send_user_push(
                (int)$row["userid"],
                "⚔️ Rekrutierung fertig: $k_name",
                "Die Ausbildung von $s_name in $k_name ist abgeschlossen.",
                "building",
                "barracks.php"
            );
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

        $home_kingdom = new Kingdom($row["kingdomid"]);
        $return_time = (int)($row["arrivaltime"] - $row["buildingtime"]);

        $conquest = new Conquest();
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

        if ($target_id > 0) {
            $check = $this->mysqli->execute_query("SELECT id FROM kingdoms WHERE id = ?", [$target_id]);

            if ($check->num_rows === 0) {
                $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, targetid = -1, is_processing = 0 WHERE eventid = ?",
                    [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);

                $target_lost_json = [
                    "template" => "target_lost"
                ];
                send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $target_lost_json);
                return;
            }
        }

        if ($target_id == MapFieldTypes::MAP_FIELD_WORLD_EVENT) {
            $world_event_manager = new WorldEvent();
            $active_event = $world_event_manager->get_active_event();

            if ($active_event) {
                $raw_damage = 0;
                $report_units = [];
                $damage_per_kingdom = [];

                $res_troops = $this->mysqli->execute_query("
                    SELECT st.soldiercount, st.source_kingdom_id, sl.attack, sl.category, sl.soldiername, sl.icon 
                    FROM sent_troops st 
                    JOIN soldier_list sl ON st.soldierid = sl.id 
                    WHERE st.eventid = ?",
                    [$row["eventid"]]
                );

                $kingdom_cache = [];

                while ($t = $res_troops->fetch_assoc()) {
                    $src_kid = (int)$t["source_kingdom_id"];

                    if (!isset($kingdom_cache[$src_kid])) {
                        $temp_k = new Kingdom($src_kid);
                        $kingdom_cache[$src_kid] = [
                            "alignment" => $temp_k->get_kingdom_alignment(),
                            "shrine_mod" => $temp_k->get_shrine_modifier(),
                            "techs" => [
                                0 => $temp_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES),
                                1 => $temp_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING),
                                2 => $temp_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS)
                            ]
                        ];
                    }

                    $k_data = $kingdom_cache[$src_kid];
                    $cat = (int)$t["category"];

                    $shrine_atk_mult = 1.0;
                    if ($k_data["alignment"] == AlignmentTypes::ALIGN_WAR) {
                        $shrine_atk_mult += $k_data["shrine_mod"];
                    }

                    $smithy_bonus = 0;
                    if (isset($k_data["techs"][$cat])) {
                        $tech_lvl = $k_data["techs"][$cat];
                        $smithy_bonus = match ($cat) {
                            0 => $tech_lvl * SMITHY_INF_ATK_BONUS,
                            1 => $tech_lvl * SMITHY_CAV_ATK_BONUS,
                            2 => $tech_lvl * SMITHY_ARC_ATK_BONUS,
                            default => 0
                        };
                    }

                    $final_unit_atk = (int)($t["attack"] * $shrine_atk_mult) + $smithy_bonus;
                    $unit_damage = ($final_unit_atk * $t["soldiercount"]);
                    $raw_damage += $unit_damage;

                    $damage_per_kingdom[$src_kid] = ($damage_per_kingdom[$src_kid] ?? 0) + $unit_damage;

                    $s_name = $t["soldiername"];
                    if (!isset($report_units[$s_name])) {
                        $report_units[$s_name] = ["name" => $s_name, "count" => 0, "icon" => $t["icon"]];
                    }
                    $report_units[$s_name]["count"] += $t["soldiercount"];
                }

                $top_contributing_kid = (int)$row["kingdomid"];
                if (!empty($damage_per_kingdom)) {
                    arsort($damage_per_kingdom);

                    $top_contributing_kid = array_key_first($damage_per_kingdom);
                }

                $result_dmg = $world_event_manager->record_damage($active_event["id"], $attacker_id, $raw_damage, $active_event["event_type"], $top_contributing_kid);

                $pool = $world_event_manager->get_monster_pool();
                $monster = $pool[$active_event["monster_index"]];
                $event_title = ($active_event["event_type"] === "BOSS_HP") ? "Schlacht gegen " . $monster["name"] : "Angriff auf das Zentrum";

                if ($result_dmg == -1 || $result_dmg == -2) {
                    $event_json = [
                        "template" => "world_event_missed",
                        "status" => ($result_dmg == -1) ? "boss_dead" : "no_attempts",
                        "event_title" => $event_title,
                        "units" => array_values($report_units)
                    ];
                    send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $event_json);
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
            } else {
                $no_event_json = [
                    "template" => "world_event_missed",
                    "status" => "no_event",
                    "event_title" => "Event-Bericht",
                    "units" => []
                ];
                send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $no_event_json);
            }

            $duration = $world_event_manager->get_current_duration();
            $event_type = $active_event["event_type"] ?? "BOSS_HP";

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, buildingname = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $duration, $event_type, $row["eventid"]]);

            return;
        }

        if ($target_id == MapFieldTypes::MAP_FIELD_MINE) {
            $tx = (int)$row["targetx"];
            $ty = (int)$row["targety"];
            $event_id = (int)$row["eventid"];

            if ($combat_units === 0 && $scout_count > 0) {
                $this->process_mine_spy_mission($row, $scout_count, $attacker_user_obj, $return_time);
                return;
            }

            $mine = $this->mysqli->execute_query("SELECT * FROM mines WHERE mapx = ? AND mapy = ? FOR UPDATE", [$tx, $ty])->fetch_assoc();

            if (!$mine) {
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $empty_mine_json = [
                    "template" => "outcome_box",
                    "title" => "Erzmine erschöpft",
                    "main_text" => "Deine Truppen sind bei den Koordinaten ($c_link) eingetroffen, aber die Erzmine existiert nicht mehr oder wurde bereits vollständig abgebaut.",
                    "sub_text" => "Deine Einheiten haben unverrichteter Dinge den Rückmarsch angetreten.",
                    "result_type" => "neutral"
                ];
                send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $empty_mine_json);

                $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                    [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
                return;
            }

            $mine_id = (int)$mine["id"];
            $attacker_guild_id = $attacker_user_obj->get_user_guild_id();
            $now = time();

            $defenders = $this->mysqli->execute_query("
                SELECT mst.*, u.username, u.guildid, k.kingdomname, sl.attack, sl.defense, IFNULL(sl.category, 0) AS category
                FROM mine_stationed_troops mst
                JOIN users u ON mst.user_id = u.id
                JOIN kingdoms k ON mst.kingdom_id = k.id
                JOIN soldier_list sl ON mst.soldier_id = sl.id
                WHERE mst.mine_id = ?
            ", [$mine_id])->fetch_all(MYSQLI_ASSOC);

            $is_mine_empty = empty($defenders);

            $claimed_gid = (int)($mine["claimed_guild_id"] ?? 0);

            $occupants_share_guild = false;
            if (!$is_mine_empty && $attacker_guild_id > 0) {
                $occupants_share_guild = true;

                foreach ($defenders as $d) {
                    if ((int)$d["guildid"] !== $attacker_guild_id) {
                        $occupants_share_guild = false;
                        break;
                    }
                }
            }

            $same_guild = (!$is_mine_empty && $attacker_guild_id > 0 && ($claimed_gid === $attacker_guild_id || $occupants_share_guild));
            $same_user = (!$is_mine_empty && (int)$mine["claimed_user_id"] === $attacker_id);

            if ($same_guild && $claimed_gid !== $attacker_guild_id) {
                $this->mysqli->execute_query("UPDATE mines SET claimed_guild_id = ? WHERE id = ?", [$attacker_guild_id, $mine_id]);
            }

            // PEACEFUL ARRIVAL
            if ($is_mine_empty || $same_guild || $same_user) {
                $max_capacity = (int)$mine["max_troops"];

                $current_troops_count = (int)$this->mysqli->execute_query(
                    "SELECT IFNULL(SUM(soldiercount), 0) FROM mine_stationed_troops WHERE mine_id = ?",
                    [$mine_id]
                )->fetch_column();

                $free_space = max(0, $max_capacity - $current_troops_count);

                $res_sent = $this->mysqli->execute_query("
                    SELECT st.soldierid, st.soldiercount, sl.attack 
                    FROM sent_troops st 
                    JOIN soldier_list sl ON st.soldierid = sl.id 
                    WHERE st.eventid = ?", [$event_id]
                );
                $incoming_troops = $res_sent->fetch_all(MYSQLI_ASSOC);

                $src_k = new Kingdom((int)$row["kingdomid"]);
                $src_kname = e($src_k->get_kingdom_name());
                $sx = $src_k->get_kingdom_map_x();
                $sy = $src_k->get_kingdom_map_y();

                if ($free_space <= 0 && !$is_mine_empty) {
                    $this->mysqli->execute_query(
                        "UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                        [ActionTypes::ACTION_RETURN_TROOPS, $now + $return_time, $event_id]
                    );

                    $units_data = [];
                    foreach ($incoming_troops as $u) {
                        $units_data[] = ["id" => (int)$u["soldierid"], "count" => (int)$u["soldiercount"]];
                    }

                    $full_json = [
                        "template" => "mine_station_result",
                        "status" => "full",
                        "target_x" => $tx,
                        "target_y" => $ty,
                        "src_kname" => $src_kname,
                        "max_capacity" => $max_capacity,
                        "units" => $units_data
                    ];

                    send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $full_json);

                    send_user_push(
                        $attacker_id,
                        "⛏️ Mine voll!",
                        "Deine Truppen konnten die Mine bei ($tx:$ty) nicht betreten, da sie voll ist.",
                        "troops",
                        "overview.php"
                    );

                    return;
                }

                $troops_to_station = [];
                $troops_to_return = [];
                $remaining_slots = $free_space;

                foreach ($incoming_troops as $t) {
                    $cnt = (int)$t["soldiercount"];
                    $sid = (int)$t["soldierid"];
                    $atk = (int)$t["attack"];

                    if ($remaining_slots > 0) {
                        $take = min($cnt, $remaining_slots);
                        $overflow = $cnt - $take;

                        $troops_to_station[] = [
                            "soldierid" => $sid,
                            "soldiercount" => $take,
                            "attack" => $atk
                        ];
                        $remaining_slots -= $take;

                        if ($overflow > 0) {
                            $troops_to_return[] = [
                                "soldierid" => $sid,
                                "soldiercount" => $overflow
                            ];
                        }
                    } else {
                        $troops_to_return[] = [
                            "soldierid" => $sid,
                            "soldiercount" => $cnt
                        ];
                    }
                }

                $last_update = (int)($mine["last_update"] ?: $now);
                $elapsed = max(0, $now - $last_update);
                if ($elapsed > 0 && !$is_mine_empty) {
                    $cur_atk = (float)$this->mysqli->execute_query(
                        "SELECT IFNULL(SUM(soldiercount * unit_atk), 0) FROM mine_stationed_troops WHERE mine_id = ?",
                        [$mine_id]
                    )->fetch_column();

                    if ($cur_atk > 0) {
                        $work_total = (float)$mine["work_total"];
                        $max_rate = $work_total / MINE_MIN_DURATION_SECONDS;
                        $effective_rate = min($cur_atk * MINE_WORK_RATE_FACTOR, $max_rate);
                        $work_delta = $effective_rate * $elapsed;

                        $this->mysqli->execute_query("
                            UPDATE mine_stationed_troops 
                            SET work_contributed = work_contributed + (? * ((soldiercount * unit_atk) / ?))
                            WHERE mine_id = ?
                        ", [$work_delta, $cur_atk, $mine_id]);

                        $this->mysqli->execute_query(
                            "UPDATE mines SET work_done = work_done + ?, last_update = ? WHERE id = ?",
                            [$work_delta, $now, $mine_id]
                        );
                    }
                }

                $insert_batch = [];
                foreach ($troops_to_station as $ts) {
                    $insert_batch[] = "($mine_id, $attacker_id, " . (int)$row["kingdomid"] . ", {$ts["soldierid"]}, {$ts["soldiercount"]}, {$ts["attack"]}, $now)";
                }
                if (!empty($insert_batch)) {
                    $this->mysqli->query("INSERT INTO mine_stationed_troops (mine_id, user_id, kingdom_id, soldier_id, soldiercount, unit_atk, arrived_at) VALUES " . implode(',', $insert_batch));
                }

                if ($is_mine_empty) {
                    $this->mysqli->execute_query(
                        "UPDATE mines SET claimed_guild_id = ?, claimed_user_id = ?, last_update = ? WHERE id = ?",
                        [$attacker_guild_id > 0 ? $attacker_guild_id : null, $attacker_id, $now, $mine_id]
                    );
                }

                $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$event_id]);
                if (empty($troops_to_return)) {
                    $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);

                    $stationed_units = [];
                    foreach ($troops_to_station as $u) {
                        $stationed_units[] = ["id" => (int)$u["soldierid"], "count" => (int)$u["soldiercount"]];
                    }

                    $started_json = [
                        "template" => "mine_station_result",
                        "status" => "started",
                        "target_x" => $tx,
                        "target_y" => $ty,
                        "src_kname" => $src_kname,
                        "src_x" => $sx,
                        "src_y" => $sy,
                        "units" => $stationed_units
                    ];

                    send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $started_json);
                } else {
                    $return_batch = [];
                    foreach ($troops_to_return as $tr) {
                        $return_batch[] = "($event_id, {$tr["soldierid"]}, {$tr["soldiercount"]}, {$tr["soldiercount"]}, " . (int)$row["kingdomid"] . ")";
                    }
                    if (!empty($return_batch)) {
                        $this->mysqli->query("INSERT INTO sent_troops (eventid, soldierid, soldiercount, initial_count, source_kingdom_id) VALUES " . implode(',', $return_batch));
                    }

                    $this->mysqli->execute_query(
                        "UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                        [ActionTypes::ACTION_RETURN_TROOPS, $now + $return_time, $event_id]
                    );

                    $stationed_units = [];
                    foreach ($troops_to_station as $ts) {
                        $stationed_units[] = ["id" => (int)$ts["soldierid"], "count" => (int)$ts["soldiercount"]];
                    }

                    $returned_units = [];
                    foreach ($troops_to_return as $tr) {
                        $returned_units[] = ["id" => (int)$tr["soldierid"], "count" => (int)$tr["soldiercount"]];
                    }

                    $partial_json = [
                        "template" => "mine_station_result",
                        "status" => "partial",
                        "target_x" => $tx,
                        "target_y" => $ty,
                        "src_kname" => $src_kname,
                        "stationed_units" => $stationed_units,
                        "returned_units" => $returned_units
                    ];

                    send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $partial_json);
                }

                return;
            }

            // BATTLE FOR THE MINE
            if ((int)$mine["level"] === 1) {
                $claimed_uid = (int)$mine["claimed_user_id"];
                $claimed_score = (int)$this->mysqli->execute_query("SELECT score FROM users WHERE id = ?", [$claimed_uid])->fetch_column();

                if ($conquest->has_noob_protection($attacker_user_obj->get_user_score(), $claimed_score)) {
                    $this->mysqli->execute_query(
                        "UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                        [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]
                    );

                    $noob_mine_json = [
                        "template" => "mine_station_result",
                        "status" => "noob_protected",
                        "target_x" => $tx,
                        "target_y" => $ty
                    ];

                    send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $noob_mine_json);
                    return;
                }
            }

            // Attacker Boni per Kingdom
            $atk_shrine = 1.0;
            if ($home_kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
                $atk_shrine += $home_kingdom->calculate_shrine_bonus($home_kingdom->get_shrine_modifier());
            }

            $atk_techs = [
                0 => ["a" => $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES) * SMITHY_INF_ATK_BONUS,
                    "d" => $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL) * SMITHY_INF_DEF_BONUS],
                1 => ["a" => $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING) * SMITHY_CAV_ATK_BONUS,
                    "d" => $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS) * SMITHY_CAV_DEF_BONUS],
                2 => ["a" => $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS) * SMITHY_ARC_ATK_BONUS,
                    "d" => $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET) * SMITHY_ARC_DEF_BONUS],
            ];

            $res_atk_troops = $this->mysqli->execute_query("
                SELECT st.soldierid, st.soldiercount, sl.attack, sl.defense, sl.category 
                FROM sent_troops st 
                JOIN soldier_list sl ON st.soldierid = sl.id 
                WHERE st.eventid = ?", [$event_id])->fetch_all(MYSQLI_ASSOC);

            $total_atk_units = array_sum(array_column($res_atk_troops, "soldiercount"));
            $total_def_units = array_sum(array_column($defenders, "soldiercount"));

            // Troop cards and base values for Attacker
            $atk_cards = [];
            $atk_prepared = [];
            foreach ($res_atk_troops as $at) {
                $cat = (int)$at["category"];
                $t_bonus_a = $atk_techs[$cat]["a"] ?? 0;
                $t_bonus_d = $atk_techs[$cat]["d"] ?? 0;

                $final_atk = (int)round(($at["attack"] * $atk_shrine) + $t_bonus_a);
                $final_def = (int)round($at["defense"] + $t_bonus_d);

                $atk_prepared[] = [
                    "count" => (int)$at["soldiercount"],
                    "cat" => $cat,
                    "atk" => $final_atk,
                    "def" => $final_def
                ];

                $atk_cards[] = [
                    "id" => (int)$at["soldierid"],
                    "initial" => (int)$at["soldiercount"],
                    "losses" => 0,
                    "atk" => $final_atk,
                    "def" => $final_def
                ];
            }

            // Troop cards and base values for Defender
            $def_cards = [];
            $def_prepared = [];
            $k_cache = [];

            foreach ($defenders as $dt) {
                $dkid = (int)$dt["kingdom_id"];

                if (!isset($k_cache[$dkid])) {
                    $k_obj = new Kingdom($dkid);
                    $sh_mod = 1.0;

                    if ($k_obj->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
                        $sh_mod += $k_obj->calculate_shrine_bonus($k_obj->get_shrine_modifier());
                    }

                    $k_cache[$dkid] = [
                        "shrine" => $sh_mod,
                        "techs" => [
                            0 => ["a" => $k_obj->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES) * SMITHY_INF_ATK_BONUS,
                                "d" => $k_obj->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL) * SMITHY_INF_DEF_BONUS],
                            1 => ["a" => $k_obj->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING) * SMITHY_CAV_ATK_BONUS,
                                "d" => $k_obj->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS) * SMITHY_CAV_DEF_BONUS],
                            2 => ["a" => $k_obj->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS) * SMITHY_ARC_ATK_BONUS,
                                "d" => $k_obj->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET) * SMITHY_ARC_DEF_BONUS],
                        ]
                    ];
                }

                $cat = (int)$dt["category"];
                $d_shrine = $k_cache[$dkid]["shrine"];
                $d_bonus_a = $k_cache[$dkid]["techs"][$cat]["a"] ?? 0;
                $d_bonus_d = $k_cache[$dkid]["techs"][$cat]["d"] ?? 0;

                $final_atk = (int)round(($dt["attack"] * $d_shrine) + $d_bonus_a);
                $final_def = (int)round($dt["defense"] + $d_bonus_d);

                $def_prepared[] = [
                    "count" => (int)$dt["soldiercount"],
                    "cat" => $cat,
                    "atk" => $final_atk,
                    "def" => $final_def
                ];

                $def_cards[] = [
                    "id" => (int)$dt["soldier_id"],
                    "initial" => (int)$dt["soldiercount"],
                    "losses" => 0,
                    "atk" => $final_atk,
                    "def" => $final_def
                ];
            }

            // Calculate Battle with RPS
            $atk_power = 0;
            foreach ($atk_prepared as $ap) {
                $bonus = 1.0;

                if ($total_def_units > 0) {
                    foreach ($def_prepared as $dp) {
                        $d_share = $dp["count"] / $total_def_units;
                        if (($ap["cat"] === 0 && $dp["cat"] === 1) ||
                            ($ap["cat"] === 1 && $dp["cat"] === 2) ||
                            ($ap["cat"] === 2 && $dp["cat"] === 0)) {
                            $bonus += (RPS_BONUS * $d_share);
                        }
                    }
                }

                $atk_power += $ap["count"] * (($ap["atk"] * $bonus) + $ap["def"]);
            }

            $def_power = 0;
            foreach ($def_prepared as $dp) {
                $bonus = 1.0;
                if ($total_atk_units > 0) {
                    foreach ($atk_prepared as $ap) {
                        $a_share = $ap["count"] / $total_atk_units;
                        if (($dp["cat"] === 0 && $ap["cat"] === 1) ||
                            ($dp["cat"] === 1 && $ap["cat"] === 2) ||
                            ($dp["cat"] === 2 && $ap["cat"] === 0)) {
                            $bonus += (RPS_BONUS * $a_share);
                        }
                    }
                }
                $def_power += $dp["count"] * (($dp["atk"] * $bonus) + $dp["def"]);
            }

            $attacker_wins = ($atk_power > $def_power);

            $def_players = [];
            foreach ($defenders as $d) {
                $key = $d["username"] . " (" . $d["kingdomname"] . ")";
                $def_players[$key] = true;
            }
            $def_list = !empty($def_players) ? e(implode(", ", array_keys($def_players))) : "Unbekannt";

            if ($attacker_wins) {
                // ATTACKER WINS - MINE OVERTAKEN
                $def_groups = [];
                foreach ($defenders as $d) {
                    $key = $d["user_id"] . "_" . $d["kingdom_id"];
                    if (!isset($def_groups[$key])) {
                        $def_groups[$key] = ["user_id" => (int)$d["user_id"], "username" => $d["username"], "kingdom_id" => (int)$d["kingdom_id"], "troops" => []];
                    }
                    $def_groups[$key]["troops"][] = $d;
                }

                foreach ($def_groups as $dg) {
                    $res_dg_k = $this->mysqli->execute_query("SELECT kingdomname, mapx, mapy FROM kingdoms WHERE id = ?", [$dg["kingdom_id"]])->fetch_assoc();
                    $dg_name = $res_dg_k["kingdomname"] ?? "Königreich";
                    $dg_x = (int)($res_dg_k["mapx"] ?? 1);
                    $dg_y = (int)($res_dg_k["mapy"] ?? 1);

                    $map_helper = new Map(new User((int)$dg["user_id"], ""));
                    $travel_time = $map_helper->get_arrival_time($dg_x, $dg_y, $tx, $ty, (int)$dg["kingdom_id"], MapFieldTypes::MAP_FIELD_MINE);

                    $this->mysqli->execute_query("
                        INSERT INTO events (actionid, userid, kingdomid, targetid, targetx, targety, arrivaltime, buildingtime, loot_food, loot_wood, loot_stone, loot_gold, buildingname)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 'Minen-Vertreibung')
                    ", [ActionTypes::ACTION_RETURN_TROOPS, $dg["user_id"], $dg["kingdom_id"], MapFieldTypes::MAP_FIELD_MINE, $tx, $ty, $now + $travel_time, $now]);
                    $ret_id = $this->mysqli->insert_id;

                    $insert_def_troops = [];
                    foreach ($dg["troops"] as $dt) {
                        $insert_def_troops[] = "($ret_id, " . (int)$dt["soldier_id"] . ", " . (int)$dt["soldiercount"] . ", " . (int)$dt["soldiercount"] . ", " . (int)$dg["kingdom_id"] . ")";
                    }
                    if (!empty($insert_def_troops)) {
                        $this->mysqli->query("INSERT INTO sent_troops (eventid, soldierid, soldiercount, initial_count, source_kingdom_id) VALUES " . implode(',', $insert_def_troops));
                    }

                    $def_json = [
                        "template" => "mine_battle",
                        "role" => "defender",
                        "outcome" => "evicted",
                        "target_x" => $tx,
                        "target_y" => $ty,
                        "home_kname" => $dg_name,
                        "home_x" => $dg_x,
                        "home_y" => $dg_y,
                        "attacker_name" => $attacker_name,
                        "def_units" => $def_cards,
                        "atk_units" => $atk_cards
                    ];
                    send_server_message($dg["user_id"], $dg["username"], MessageCategories::CATEGORY_WAR, $def_json);

                    send_user_push(
                        (int)$dg["user_id"],
                        "⚔️ Mine verloren!",
                        "Deine Schürfer in der Erzmine bei ($tx:$ty) wurden von $attacker_name vertrieben!",
                        "combat",
                        "map.php?startx=$tx&starty=$ty"
                    );
                }

                $this->mysqli->execute_query("DELETE FROM mine_stationed_troops WHERE mine_id = ?", [$mine_id]);
                $this->mysqli->execute_query("
                    UPDATE mines SET 
                        claimed_guild_id = ?, claimed_user_id = ?, last_update = ? 
                    WHERE id = ?
                ", [$attacker_guild_id > 0 ? $attacker_guild_id : null, $attacker_id, $now, $mine_id]);

                $insert_atk_batch = [];
                foreach ($res_atk_troops as $at) {
                    $insert_atk_batch[] = "($mine_id, $attacker_id, " . (int)$row["kingdomid"] . ", " . (int)$at["soldierid"] . ", " . (int)$at["soldiercount"] . ", " . (int)$at["attack"] . ", $now)";
                }
                if (!empty($insert_atk_batch)) {
                    $this->mysqli->query("INSERT INTO mine_stationed_troops (mine_id, user_id, kingdom_id, soldier_id, soldiercount, unit_atk, arrived_at) VALUES " . implode(',', $insert_atk_batch));
                }

                $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$event_id]);
                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);

                $atk_json = [
                    "template" => "mine_battle",
                    "role" => "attacker",
                    "outcome" => "conquered",
                    "target_x" => $tx,
                    "target_y" => $ty,
                    "def_list" => $def_list,
                    "atk_units" => $atk_cards,
                    "def_units" => $def_cards
                ];
                send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $atk_json);

                Logger::get_instance()->log_game("COMBAT", "MINE_OVERTAKEN", [
                    "mine_id" => $mine_id,
                    "coords" => "$tx:$ty",
                    "attacker" => $attacker_name
                ], (int)$row["kingdomid"]);
            } else {
                // DEFENDER WINS
                $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                    [ActionTypes::ACTION_RETURN_TROOPS, $now + $return_time, $event_id]);

                $atk_json = [
                    "template" => "mine_battle",
                    "role" => "attacker",
                    "outcome" => "repelled",
                    "target_x" => $tx,
                    "target_y" => $ty,
                    "def_list" => $def_list,
                    "atk_units" => $atk_cards,
                    "def_units" => $def_cards
                ];
                send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $atk_json);

                $def_uids = array_unique(array_column($defenders, "user_id"));
                foreach ($def_uids as $duid) {
                    $dname = $this->mysqli->execute_query("SELECT username FROM users WHERE id = ?", [$duid])->fetch_column();

                    $def_json = [
                        "template" => "mine_battle",
                        "role" => "defender",
                        "outcome" => "defended",
                        "target_x" => $tx,
                        "target_y" => $ty,
                        "attacker_name" => $attacker_name,
                        "def_units" => $def_cards,
                        "atk_units" => $atk_cards
                    ];
                    send_server_message($duid, $dname, MessageCategories::CATEGORY_WAR, $def_json);

                    send_user_push(
                        (int)$duid,
                        "🛡️ Mine verteidigt!",
                        "Ein Angriff von $attacker_name auf deine Erzmine bei ($tx:$ty) wurde erfolgreich abgewehrt!",
                        "combat",
                        "map.php?startx=$tx&starty=$ty"
                    );
                }

                Logger::get_instance()->log_game("COMBAT", "MINE_DEFENDED", [
                    "mine_id" => $mine_id,
                    "coords" => "$tx:$ty",
                    "attacker" => $attacker_name
                ], (int)$row["kingdomid"]);
            }

            Logger::get_instance()->log_game("ECONOMY", "MINE_OCCUPY", [
                "mine_id" => $mine_id,
                "coords" => "$tx:$ty"
            ], (int)$row["kingdomid"]);
            return;
        }

        if ($target_id == MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM) {
            if ($combat_units === 0 && $scout_count > 0) {
                $this->process_ruin_spy_mission($row, $scout_count, $attacker_user_obj, $return_time);
            } else {
                $this->process_ruin_battle($row, $home_kingdom, $attacker_user_obj, $return_time);
            }
            return;
        }

        if ($target_id == MapFieldTypes::MAP_FIELD_MONSTER_CAMP) {
            if ($combat_units === 0 && $scout_count > 0) {
                $this->process_monster_spy_mission($row, $scout_count, $attacker_user_obj, $return_time);
            } else {
                $this->process_monster_battle($row, $home_kingdom, $attacker_user_obj, $return_time);
            }
            return;
        }

        if ($target_id == MapFieldTypes::MAP_FIELD_RESOURCE_TILE) {
            if ($combat_units === 0 && $scout_count > 0) {
                $this->process_resource_spy_mission($row, $scout_count, $attacker_user_obj, $return_time);
            } else {
                $this->handle_raider_plunder($row, $attacker_user_obj);

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

        if ($target_id == MapFieldTypes::MAP_FIELD_EMPTY) {
            $this->process_empty_field_conquest($row, $attacker_user_obj);

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

        $enemy_kingdom = new Kingdom($target_id);

        // User only sent spies to scout
        if ($combat_units === 0 && $scout_count > 0 && $attacker_id != $enemy_kingdom->get_kingdom_owner_id()) {
            $this->process_spy_mission($row, $scout_count, $home_kingdom, $enemy_kingdom, $attacker_user_obj, $return_time);
            return;
        }

        $current_owner_id = $enemy_kingdom->get_kingdom_owner_id();

        if ($attacker_id == $current_owner_id) {
            $conquest->set_initial_soldiers();
            $stationed_units = $conquest->get_battle_result_data(true, true);

            $conquest->set_target_id($row["targetid"]);
            $conquest->deploy_soldiers_to_kingdom();

            $reinforce_json = [
                "template" => "reinforce_self",
                "target_name" => $enemy_kingdom->get_kingdom_name(),
                "target_x" => (int)$row["targetx"],
                "target_y" => (int)$row["targety"],
                "units" => $stationed_units
            ];

            send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $reinforce_json);
        } else {
            $this->process_battle($row, $conquest, $home_kingdom, $enemy_kingdom, $attacker_user_obj, $return_time);
        }
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

                $redirect_json = [
                    "template" => "home_kingdom_lost",
                    "old_k_name" => $old_k_name,
                    "main_k_name" => $main_k_name
                ];

                send_server_message($owner_id, $u_name, MessageCategories::CATEGORY_WAR, $redirect_json);

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

            if ($row["targetid"] == MapFieldTypes::MAP_FIELD_MONSTER_CAMP) {
                $field_name = "Monstercamp";
            } else if ($row["targetid"] == MapFieldTypes::MAP_FIELD_WORLD_EVENT) {
                $field_name = "Auge des Sturms";
            } else if ($row["targetid"] == MapFieldTypes::MAP_FIELD_MINE) {
                $field_name = "Erzmine";
            } else if ($row["targetid"] == MapFieldTypes::MAP_FIELD_RESOURCE_TILE) {
                $field_name = "Vorratslager";
            } else if ($row["targetid"] == MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM) {
                $field_name = "Ruine";
            } else {
                $field_name = $map_info["fieldname"] ?? "Unbekannt";
            }
        } else {
            $enemy_k = new Kingdom($row["targetid"]);
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

        // Special Loot
        $loot_coal = (int)($row["loot_coal"] ?? 0);
        $loot_iron = (int)($row["loot_iron"] ?? 0);
        $loot_sapphire = (int)($row["loot_sapphire"] ?? 0);
        $loot_diamond = (int)($row["loot_diamond"] ?? 0);

        if ($loot_coal > 0) $loot[ResourceTypes::RESOURCE_TYPE_COAL] = $loot_coal;
        if ($loot_iron > 0) $loot[ResourceTypes::RESOURCE_TYPE_IRON] = $loot_iron;
        if ($loot_sapphire > 0) $loot[ResourceTypes::RESOURCE_TYPE_SAPPHIRE] = $loot_sapphire;
        if ($loot_diamond > 0) $loot[ResourceTypes::RESOURCE_TYPE_DIAMOND] = $loot_diamond;

        // Generate troop cards
        $res_troops = $this->mysqli->execute_query(
            "SELECT soldierid, 
                    SUM(soldiercount) AS soldiercount, 
                    SUM(initial_count) AS initial_count 
             FROM sent_troops 
             WHERE eventid = ?
             GROUP BY soldierid
             ORDER BY soldierid",
            [$row["eventid"]]
        );

        $units_data = [];
        while ($t = $res_troops->fetch_assoc()) {
            $initial = (int)$t["initial_count"];
            $survivors = (int)$t["soldiercount"];
            $units_data[] = [
                "id" => (int)$t["soldierid"],
                "initial" => $initial,
                "losses" => max(0, $initial - $survivors)
            ];
        }

        $home_k = new Kingdom($row["kingdomid"]);
        $home_name = $home_k->get_kingdom_name();

        $return_json = [
            "template" => "troop_return",
            "home_name" => $home_name,
            "target_name" => $field_name,
            "target_x" => $target_x,
            "target_y" => $target_y,
            "loot" => $loot,
            "units" => $units_data
        ];

        // Set troops back to kingdom
        $res_update = $this->mysqli->execute_query("SELECT soldierid, soldiercount, source_kingdom_id FROM sent_troops WHERE eventid = ?",
            [$row["eventid"]]
        );
        while ($sol = $res_update->fetch_assoc()) {
            $target_kid = ($sol["source_kingdom_id"] > 0) ? (int)$sol["source_kingdom_id"] : (int)$row["kingdomid"];

            $this->mysqli->execute_query(
                "UPDATE soldiers SET soldiercount = soldiercount + ? WHERE kingdomid = ? AND soldierid = ?",
                [$sol["soldiercount"], $target_kid, $sol["soldierid"]]
            );
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

        // Special Resources for guild
        $res_owner = $this->mysqli->execute_query("SELECT guildid, username FROM users WHERE id = ?", [$owner_id])->fetch_assoc();
        $user_gid = (int)($res_owner["guildid"] ?? -1);
        $owner_username = $res_owner["username"] ?? $u_name;

        if ($user_gid > 0 && ($loot_coal > 0 || $loot_iron > 0 || $loot_sapphire > 0 || $loot_diamond > 0)) {
            $owner_user_obj = new User($owner_id, $owner_username);
            $guild_logic = new Guild($owner_user_obj, $user_gid);

            if ($loot_coal > 0) $guild_logic->modify_storage_resource("coal", $loot_coal);
            if ($loot_iron > 0) $guild_logic->modify_storage_resource("iron", $loot_iron);
            if ($loot_sapphire > 0) $guild_logic->modify_storage_resource("sapphire", $loot_sapphire);
            if ($loot_diamond > 0) $guild_logic->modify_storage_resource("diamond", $loot_diamond);

            $total_special_loot = $loot_coal + $loot_iron + $loot_sapphire + $loot_diamond;

            if ($total_special_loot > 0) {
                update_player_stat($owner_id, "special_resources_mined", $total_special_loot);

                $this->mysqli->execute_query(
                    "UPDATE guilds SET total_special_mined = total_special_mined + ? WHERE id = ?",
                    [$total_special_loot, $user_gid]
                );
            }
        }

        // Send server message to owner
        $should_send_message = true;

        if ((int)$row["targetid"] === MapFieldTypes::MAP_FIELD_WORLD_EVENT) {
            $is_hp_event = false;

            if (!empty($row["buildingname"]) && in_array($row["buildingname"], ["BOSS_HP", "DAMAGE"])) {
                $is_hp_event = ($row["buildingname"] === "BOSS_HP");
            }

            $should_send_message = $is_hp_event;
        }

        if ($should_send_message) {
            send_server_message($owner_id, $u_name, MessageCategories::CATEGORY_WAR, $return_json);
        }

        // Send push message
        if ((int)$row["targetid"] === MapFieldTypes::MAP_FIELD_WORLD_EVENT) {
            send_user_push(
                $owner_id,
                "👹 Weltevent: Truppen zurückgekehrt",
                "Deine Truppen sind aus dem Auge des Sturms zurückgekehrt und stehen für einen weiteren Angriff bereit!",
                "events",
                "events.php"
            );
        } else {
            $has_loot = !empty($loot);
            $return_text = $has_loot
                ? "Deine Truppen sind mit Beute nach $home_name zurückgekehrt!"
                : "Deine Einheiten sind wohlbehalten nach $home_name zurückgekehrt.";

            send_user_push(
                $owner_id,
                "🛡️ Heimkehr: $home_name",
                $return_text,
                "troops",
                "overview.php"
            );
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

                $abort_json = [
                    "template" => "trade_aborted_target_lost",
                    "reason" => "deleted"
                ];

                send_server_message($original_recipient_id, $u_data["username"], MessageCategories::CATEGORY_TRADE, $abort_json);
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

                $reroute_json = [
                    "template" => "trade_rerouted_main_kingdom"
                ];

                send_server_message($original_recipient_id, $u_data["username"], MessageCategories::CATEGORY_TRADE, $reroute_json);
            } else {
                $lost_json = [
                    "template" => "trade_lost_no_kingdoms"
                ];

                send_server_message($original_recipient_id, $u_data["username"] ?? "Spieler", MessageCategories::CATEGORY_TRADE, $lost_json);

                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
            }
            return;
        }

        $target_k = new Kingdom($target_kingdom_id);
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

        $delivery_json = [
            "template" => "trade_delivery",
            "target_name" => $target_k->get_kingdom_name(),
            "loot" => $loot_received
        ];

        $res_u = $this->mysqli->execute_query("SELECT username FROM users WHERE id = ?", [$original_recipient_id]);
        $u_name = $res_u->fetch_column() ?: "Spieler";

        send_server_message($original_recipient_id, $u_name, MessageCategories::CATEGORY_TRADE, $delivery_json);

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

        $kingdom = new Kingdom($kid);
        $kingdom->recalculate_production();
    }

    private function process_empty_field_conquest(array $row, User $attacker_user): void
    {
        $event_id = $row["eventid"];
        $target_x = $row["targetx"];
        $target_y = $row["targety"];
        $uid = $attacker_user->get_user_id();

        $check_field = $this->mysqli->execute_query("
            SELECT m.kingdomid, ft.fieldname 
            FROM map m 
            JOIN field_types ft ON m.fieldtype = ft.fieldid 
            WHERE m.mapx = ? AND m.mapy = ? FOR UPDATE",
            [$target_x, $target_y]
        )->fetch_assoc();

        $field_name = $check_field["fieldname"] ?? "Unbekannt";

        if (!$check_field || (int)$check_field["kingdomid"] !== MapFieldTypes::MAP_FIELD_EMPTY) {
            send_server_message($uid, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, [
                "template" => "settle_result",
                "status" => "blocked",
                "target_x" => $target_x,
                "target_y" => $target_y,
                "field_name" => $field_name
            ]);
            return;
        }

        $res_curr = $this->mysqli->execute_query("SELECT COUNT(*) FROM kingdoms WHERE userid = ? AND creation_method = 0", [$uid]);
        $current_count = (int)$res_curr->fetch_column();

        $res_imp = $this->mysqli->execute_query("
            SELECT IFNULL(MAX(t.techlevel), 0) FROM techs t JOIN kingdoms k ON t.kingdomid = k.id 
            WHERE k.userid = ? AND t.techid = ?
        ", [$uid, TechTypes::TECH_TYPE_IMPERIAL]);
        $imp_bonus = (int)$res_imp->fetch_column();
        $limit = min(GLOBAL_SETTLEMENT_MAX, BASE_SETTLEMENT_LIMIT + $imp_bonus);

        if ($current_count >= $limit) {
            send_server_message($uid, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, [
                "template" => "settle_result",
                "status" => "limit_reached",
                "target_x" => $target_x,
                "target_y" => $target_y,
                "field_name" => $field_name
            ]);
            return;
        }

        $res = $this->mysqli->execute_query(
            "SELECT soldiercount FROM sent_troops WHERE eventid = ? AND soldierid = ?",
            [$event_id, Soldiers::SOLDIER_SETTLER_WAGON]
        );
        $wagon_count = ($res->num_rows > 0) ? $res->fetch_column() : 0;

        if ($wagon_count > 0) {
            $chance = min(MAX_SETTLER_CHANCE, BASE_SETTLER_CHANCE + (($wagon_count - 1) * SETTLER_CHANCE_STEP));

            if (mt_rand(0, 100) <= ($chance * 100)) {
                $new_kingdom_obj = new Kingdom();
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

                    send_server_message($uid, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, [
                        "template" => "settle_result",
                        "status" => "success",
                        "founded_name" => $founded_name,
                        "target_x" => $target_x,
                        "target_y" => $target_y,
                        "field_name" => $field_name
                    ]);

                    Logger::get_instance()->log_game("ECONOMY", "KINGDOM_FOUNDED", [
                        "new_kingdom_id" => $new_kingdom_id,
                        "new_name" => $founded_name,
                        "x" => $target_x,
                        "y" => $target_y
                    ], $new_kingdom_id);
                } else {
                    send_server_message($uid, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, [
                        "template" => "settle_result",
                        "status" => "creation_error"
                    ]);
                }
            } else {
                send_server_message($uid, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, [
                    "template" => "settle_result",
                    "status" => "failed_roll",
                    "chance" => (int)($chance * 100),
                    "target_x" => $target_x,
                    "target_y" => $target_y,
                    "field_name" => $field_name
                ]);

                Logger::get_instance()->log_game("ECONOMY", "SETTLE_FAILED", [
                    "x" => $target_x,
                    "y" => $target_y,
                    "reason" => "Error or not free"
                ], $row["kingdomid"]);
            }
        } else {
            send_server_message($uid, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, [
                "template" => "settle_result",
                "status" => "no_settlers",
                "target_x" => $target_x,
                "target_y" => $target_y,
                "field_name" => $field_name
            ]);
        }
    }

    private function process_battle(array $row, Conquest $conquest, Kingdom $home_kingdom, Kingdom $enemy_kingdom, User $attacker_user, int $return_time): void
    {
        $attacker_id = $attacker_user->get_user_id();
        $attacker_name = $attacker_user->get_user_name();

        $enemy_user_id = $enemy_kingdom->get_kingdom_owner_id();
        $enemy_user_name = $enemy_kingdom->get_kingdom_owner_name();
        $enemy_user = new User($enemy_user_id, $enemy_user_name);

        $this->mysqli->execute_query("SELECT id FROM kingdoms WHERE id = ? FOR UPDATE",
            [$enemy_kingdom->get_kingdom_id()]
        );

        if ($conquest->has_noob_protection($attacker_user->get_user_score(), $enemy_user->get_user_score())) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);

            send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, [
                "template" => "noob_protection"
            ]);
            return;
        }

        $res_guilds = $this->mysqli->execute_query("
            SELECT u1.guildid AS atk_gid, u2.guildid AS def_gid 
            FROM users u1, users u2 
            WHERE u1.id = ? AND u2.id = ?",
            [$attacker_id, $enemy_user_id]
        )->fetch_assoc();

        $is_now_ally = (!empty($res_guilds["atk_gid"]) && $res_guilds["atk_gid"] > 0 && $res_guilds["atk_gid"] === $res_guilds["def_gid"]);

        if ($is_now_ally) {
            $this->mysqli->execute_query(
                "UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]
            );

            $atk_notice = [
                "template" => "attack_aborted_guild",
                "opponent_name" => $enemy_user_name,
                "target_kname" => $enemy_kingdom->get_kingdom_name(),
                "target_x" => (int)$row["targetx"],
                "target_y" => (int)$row["targety"]
            ];
            send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $atk_notice);
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
        update_player_stat($attacker_id, "units_defeated_pvp", $total_def_losses);
        update_player_stat($enemy_user_id, "units_defeated_pvp", $conquest->get_my_loss_count());

        // Variables for Battle Log
        $victory = ($total_def_losses == $total_def_initial);
        $wall_before = $enemy_kingdom->get_wall_hp();
        $wall_after = $conquest->calculate_wall_damage();

        // Battle Outcome Logic
        $no_defenders = ($total_def_initial == 0);
        $attacker_total_loss = ($conquest->get_initial_soldier_count() == $conquest->get_my_loss_count());
        $surviving_scouts = $conquest->get_surviving_count(Soldiers::SOLDIER_SCOUT);

        // Attacker Box Logic
        if ($no_defenders) {
            // CASE A: No Defenders -> Troops always survive
            $outcome_code = "unhindered";
        } else if ($attacker_total_loss && $surviving_scouts <= 0) {
            // CASE B: Normal Battle, but all troops lost
            $outcome_code = "total_defeat";
        } else if ($victory) {
            // CASE C: Normal Battle against troops
            $outcome_code = "victory";
        } else {
            // Defender won
            $outcome_code = "repelled";
        }

        // Conquering logic
        $conquest_code_attacker = null;
        $conquest_code_defender = null;
        $was_conquered = false;

        if ($victory && $conquest->has_conquerer()) {
            $was_conquered = $this->handle_post_battle_conquest(
                $row, $conquest, $enemy_kingdom, $enemy_user, $attacker_user,
                $conquest_code_attacker, $conquest_code_defender
            );
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
        $loot = [];

        if ($surviving_thieves > 0 && !$was_conquered) {
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

                    update_player_stat($attacker_id, "resources_stolen", $actual_carried);
                }
            }
        }

        $surviving_scouts = $conquest->get_surviving_count(Soldiers::SOLDIER_SCOUT);
        $scout_intel = null;

        if ($surviving_scouts > 0 && !$was_conquered) {
            $scout_intel = $this->get_scouted_kingdom_intel($enemy_kingdom, $surviving_scouts);
        }

        $total_losses_in_this_battle = $conquest->get_my_loss_count() + $conquest->get_enemy_loss_count();

        if ($total_losses_in_this_battle > 0) {
            update_global_stat("total_fallen_soldiers", $total_losses_in_this_battle);
        }

        // Send message to both sides
        $attacker_json = [
            "template" => "battle_pvp",
            "role" => "attacker",
            "opponent_name" => $enemy_user_name,
            "opponent_kname" => $enemy_kingdom->get_kingdom_name(),
            "opp_x" => (int)$row["targetx"],
            "opp_y" => (int)$row["targety"],
            "my_kname" => $home_kingdom->get_kingdom_name(),
            "my_x" => $home_kingdom->get_kingdom_map_x(),
            "my_y" => $home_kingdom->get_kingdom_map_y(),
            "atk_units" => $atk_units,
            "def_units" => $def_units,
            "wall_before" => $wall_before,
            "wall_after" => $wall_after,
            "outcome" => $outcome_code,
            "conquest" => $conquest_code_attacker,
            "loot" => $loot,
            "scout_intel" => $scout_intel
        ];

        $defender_json = [
            "template" => "battle_pvp",
            "role" => "defender",
            "opponent_name" => $attacker_name,
            "opponent_kname" => $home_kingdom->get_kingdom_name(),
            "opp_x" => $home_kingdom->get_kingdom_map_x(),
            "opp_y" => $home_kingdom->get_kingdom_map_y(),
            "my_kname" => $enemy_kingdom->get_kingdom_name(),
            "my_x" => (int)$row["targetx"],
            "my_y" => (int)$row["targety"],
            "atk_units" => $def_units,
            "def_units" => $atk_units,
            "wall_before" => $wall_before,
            "wall_after" => $wall_after,
            "outcome" => $outcome_code,
            "conquest" => $conquest_code_defender,
            "loot" => $loot
        ];

        // Send server messages to both sides
        send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $attacker_json);
        send_server_message($enemy_user_id, $enemy_user_name, MessageCategories::CATEGORY_WAR, $defender_json);

        // Send push message
        $def_kname = $enemy_kingdom->get_kingdom_name();
        $battle_title = $victory
            ? "🚨 Überrannt: $def_kname"
            : "🛡️ Verteidigt: $def_kname";

        $battle_text = $victory
            ? "Dein Königreich $def_kname wurde von $attacker_name überrannt!"
            : "Deine Verteidiger in $def_kname haben den Angriff von $attacker_name erfolgreich abgewehrt!";

        send_user_push(
            $enemy_user_id,
            $battle_title,
            $battle_text,
            "combat",
            "messages.php?servermsgs"
        );

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
                                                 User  $attacker_user, ?array &$conquest_data_out, ?array &$defender_conquest_out): bool
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
                SELECT SUM(
                    IF(b.buildingid IN (0, 3, 9), GREATEST(0, (b.buildinglevel * (b.buildinglevel + 1) / 2) - 1) * bl.buildingscore, 
                    (b.buildinglevel * (b.buildinglevel + 1) / 2) * bl.buildingscore)
                ) AS loss 
                FROM buildings b 
                JOIN building_list bl ON b.buildingid = bl.id 
                WHERE b.kingdomid = ? AND b.buildingid != " . BuildingTypes::BUILDING_EMBASSY, [$enemy_kingdom->get_kingdom_id()]);
            $village_building_score = (int)($res_b_score->fetch_assoc()["loss"] ?? 0);

            // Get alle Techs for score loss
            $res_t_score = $this->mysqli->execute_query("
                SELECT SUM((t.techlevel * (t.techlevel + 1) / 2) * tl.techscore) AS loss 
                FROM techs t 
                JOIN tech_list tl ON t.techid = tl.id 
                WHERE t.kingdomid = ?", [$enemy_kingdom->get_kingdom_id()]);
            $village_tech_score = (int)($res_t_score->fetch_assoc()["loss"] ?? 0);

            // Total of the kingdom
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

                        // Move Imperial Tech, if it exists
                        $check_imperium = $this->mysqli->execute_query(
                            "SELECT techlevel FROM techs WHERE kingdomid = ? AND techid = ?",
                            [$enemy_kingdom->get_kingdom_id(), TechTypes::TECH_TYPE_IMPERIAL]
                        );

                        if ($check_imperium->num_rows > 0) {
                            $this->mysqli->execute_query(
                                "UPDATE techs SET kingdomid = ? WHERE kingdomid = ? AND techid = ?",
                                [$new_main_id, $enemy_kingdom->get_kingdom_id(), TechTypes::TECH_TYPE_IMPERIAL]
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

                $res_stationed = $this->mysqli->execute_query("
                    SELECT st.target_kingdom_id, k.userid as host_uid, k.username as host_name, k.kingdomname as host_kname
                    FROM stationed_troops st
                    JOIN kingdoms k ON st.target_kingdom_id = k.id
                    WHERE st.owner_id = ?
                    GROUP BY st.target_kingdom_id, k.userid, k.username, k.kingdomname
                ", [$enemy_user->get_user_id()]);

                while ($host = $res_stationed->fetch_assoc()) {
                    $disband_json = [
                        "template" => "support_disbanded",
                        "host_kname" => $host["host_kname"],
                        "ally_name" => $enemy_user->get_user_name()
                    ];
                    send_server_message((int)$host["host_uid"], $host["host_name"], MessageCategories::CATEGORY_GUILD, $disband_json);
                }
                $this->mysqli->execute_query("DELETE FROM stationed_troops WHERE owner_id = ?", [$enemy_user->get_user_id()]);

                $new_k_id = new Kingdom()->create_kingdom($enemy_user->get_user_id(), $enemy_user->get_user_name());

                if ($new_k_id) {
                    $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$new_k_id, $enemy_user->get_user_id()]);
                }
            }

            // Safety Deletion for Embassy and Imperial for the conquered Kingdom
            $this->mysqli->execute_query(
                "DELETE FROM buildings WHERE kingdomid = ? AND buildingid = ?",
                [$enemy_kingdom->get_kingdom_id(), BuildingTypes::BUILDING_EMBASSY]
            );
            $this->mysqli->execute_query(
                "DELETE FROM techs WHERE kingdomid = ? AND techid = ?",
                [$enemy_kingdom->get_kingdom_id(), TechTypes::TECH_TYPE_IMPERIAL]
            );

            // Kingdom now belongs to the attacker
            $this->mysqli->execute_query("UPDATE kingdoms SET userid = ?, username = ?, creation_method = 1, created_at = ? WHERE id = ?",
                [$attacker_user->get_user_id(), $attacker_user->get_user_name(), time(), $enemy_kingdom->get_kingdom_id()]);

            // Message for Attacker
            $conquest_data_out = [
                "title" => "Eroberung erfolgreich",
                "main_text" => "<b>Glorreicher Sieg!</b> Das Königreich wurde eingenommen und gehört nun dir.",
                "sub_text" => "Für die Eroberung hat sich ein <b>Eroberer</b> geopfert.",
                "type" => "success"
            ];

            // Message for Defender
            $def_sub = !$has_more_kingdoms ? "Da dies dein letztes Dorf war, musst du an einem neuen Standort von vorne beginnen." : "";
            $defender_conquest_out = [
                "title" => "Königreich verloren",
                "main_text" => "<b>Das Schicksal hat sich gegen uns gewandt!</b> Unser Königreich wurde vom Gegner besetzt.",
                "sub_text" => $def_sub,
                "type" => "error"
            ];

            return true;
        } else {
            $conquest_data_out = [
                "title" => "Eroberungsversuch",
                "main_text" => "Die Eroberung ist gescheitert. Unsere Truppen konnten die Kontrolle über das Stadtzentrum nicht sichern.",
                "sub_text" => "Die Chance auf Erfolg lag bei " . $rate . "%. Die Soldaten ziehen sich zurück.",
                "type" => "error"
            ];

            $defender_conquest_out = null;
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

            $listing_fee = calculate_listing_fee((int)$amount);
            $user_obj = new User($u_id, $u_name);
            $user_obj->give_user_coins($listing_fee);

            $loot = [
                $res_type => $amount,
                ResourceTypes::RESOURCE_TYPE_COINS => $listing_fee
            ];

            $expired_json = [
                "template" => "market_offer_expired",
                "listing_fee" => $listing_fee,
                "loot" => $loot
            ];

            send_server_message($u_id, $u_name, MessageCategories::CATEGORY_TRADE, $expired_json);

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
            $s->fill_from_row($row);
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
                   k.userid, k.username, k.kingdomname, k.last_watchtower_push,
                   b.buildinglevel AS wt_level
            FROM events e
            JOIN kingdoms k ON e.targetid = k.id
            JOIN buildings b ON k.id = b.kingdomid 
                 AND b.buildingid = " . BuildingTypes::BUILDING_WATCHTOWER . " 
                 AND b.buildinglevel > 0
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

        $push_queue = [];

        foreach ($results as $row) {
            $target_kingdom = new Kingdom($row["targetid"]);
            $wt_level = (int)$row["wt_level"];

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
                $arrival_seconds = max(0, $row["arrivaltime"] - $current_time);
                $time_to_arrival = convert_sec_to_str($row["arrivaltime"] - $current_time);

                $source_data = null;
                if ($intel_level >= 2) {
                    $res_source = $this->mysqli->execute_query("SELECT kingdomname, mapx, mapy FROM kingdoms WHERE id = ?", [$row["source_id"]]);
                    if ($src = $res_source->fetch_assoc()) {
                        $source_data = [
                            "name" => $src["kingdomname"],
                            "x" => (int)$src["mapx"],
                            "y" => (int)$src["mapy"]
                        ];
                    }
                }

                $total_units = null;
                if ($intel_level >= 3) {
                    $res_count = $this->mysqli->execute_query("SELECT SUM(soldiercount) as total FROM sent_troops WHERE eventid = ?", [$row["eventid"]]);
                    $total_units = (int)($res_count->fetch_assoc()["total"] ?? 0);
                }

                $identified_units = null;
                $strength_data = null;

                if ($intel_level >= 4) {
                    $identified_units = [];
                    $total_atk = 0;
                    $total_def = 0;

                    $res_troops = $this->mysqli->execute_query("
                        SELECT st.soldierid, sl.attack, sl.defense, SUM(st.soldiercount) AS soldiercount 
                        FROM sent_troops st 
                        JOIN soldier_list sl ON st.soldierid = sl.id 
                        WHERE st.eventid = ?
                        GROUP BY st.soldierid, sl.attack, sl.defense", [$row["eventid"]]);

                    while ($t = $res_troops->fetch_assoc()) {
                        $count = (int)$t["soldiercount"];
                        $total_atk += (int)round($count * $t["attack"]);
                        $total_def += (int)round($count * $t["defense"]);

                        $identified_units[] = [
                            "id" => (int)$t["soldierid"],
                            "count" => $count
                        ];
                    }

                    if ($intel_level >= 5) {
                        $strength_data = [
                            "atk" => $total_atk,
                            "def" => $total_def
                        ];
                    }
                }

                $wt_json = [
                    "template" => "watchtower_alert",
                    "kingdom_name" => $row["kingdomname"],
                    "intel_level" => $intel_level,
                    "arrival_seconds" => $arrival_seconds,
                    "source" => $source_data,
                    "total_units" => $total_units,
                    "units" => $identified_units,
                    "strength" => $strength_data
                ];

                send_server_message((int)$row["userid"], $row["username"], MessageCategories::CATEGORY_WAR, $wt_json);

                $kid = (int)$row["targetid"];
                if (!isset($push_queue[$kid])) {
                    $push_queue[$kid] = [
                        "user_id" => (int)$row["userid"],
                        "kingdom_name" => $row["kingdomname"],
                        "last_push" => (int)$row["last_watchtower_push"],
                        "has_time_intel" => ($intel_level >= 1),
                        "earliest_arrival" => $time_to_arrival,
                        "count" => 0
                    ];
                }
                $push_queue[$kid]["count"]++;
            }
        }

        $push_cooldown = 300;

        foreach ($push_queue as $kid => $data) {
            if (($current_time - $data["last_push"]) >= $push_cooldown) {
                $arrival_text = $data["has_time_intel"] ? " Ankunft in ca. {$data["earliest_arrival"]}." : "";

                if ($data["count"] === 1) {
                    $push_title = "⚠️ Wachturm: " . $data["kingdom_name"];
                    $push_body = "Feindliche Truppen vor {$data["kingdom_name"]} gesichtet!$arrival_text";
                } else {
                    $push_title = "🚨 Alarm: " . $data["kingdom_name"];
                    $push_body = "{$data["count"]} feindliche Angriffswellen im Anmarsch auf {$data["kingdom_name"]}!$arrival_text";
                }

                send_user_push(
                    $data["user_id"],
                    $push_title,
                    $push_body,
                    "combat",
                    "overview.php"
                );

                $this->mysqli->execute_query(
                    "UPDATE kingdoms SET last_watchtower_push = ? WHERE id = ?",
                    [$current_time, $kid]
                );
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

        $e_atk_pool = $def_scouts * ($s_atk + ($wt_level * 0.5));
        $e_def_pool = $def_scouts * ($s_def + $wt_level);

        $p_loss_ratio = ($p_def_pool > 0) ? min(1.0, $e_atk_pool / ($p_def_pool * SCOUT_COMBAT_LETHALITY)) : 1.0;
        $e_loss_ratio = ($e_def_pool > 0) ? min(1.0, $p_atk_pool / ($e_def_pool * SCOUT_COMBAT_LETHALITY)) : 1.0;

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
            $attacker_json = [
                "template" => "spy_report",
                "success" => true,
                "target_kname" => $enemy_k->get_kingdom_name(),
                "opp_name" => $enemy_owner_name,
                "target_x" => $enemy_k->get_kingdom_map_x(),
                "target_y" => $enemy_k->get_kingdom_map_y(),
                "home_kname" => $home_k->get_kingdom_name(),
                "home_x" => $home_k->get_kingdom_map_x(),
                "home_y" => $home_k->get_kingdom_map_y(),
                "atk_scouts" => $atk_scouts,
                "atk_losses" => $atk_losses,
                "intel" => $this->get_scouted_kingdom_intel($enemy_k, $survivors, true)
            ];

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?", [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
        } else {
            $attacker_json = [
                "template" => "spy_report",
                "success" => false,
                "target_kname" => $enemy_k->get_kingdom_name(),
                "opp_name" => $enemy_owner_name,
                "target_x" => $enemy_k->get_kingdom_map_x(),
                "target_y" => $enemy_k->get_kingdom_map_y(),
                "home_kname" => $home_k->get_kingdom_name(),
                "home_x" => $home_k->get_kingdom_map_x(),
                "home_y" => $home_k->get_kingdom_map_y(),
                "atk_scouts" => $atk_scouts,
                "atk_losses" => $atk_losses
            ];

            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        }

        // Defender Message
        $defender_json = [
            "template" => "spy_detected",
            "attacker_name" => $attacker_name,
            "home_kname" => $home_k->get_kingdom_name(),
            "home_x" => $home_k->get_kingdom_map_x(),
            "home_y" => $home_k->get_kingdom_map_y(),
            "target_kname" => $enemy_k->get_kingdom_name(),
            "target_x" => $enemy_k->get_kingdom_map_x(),
            "target_y" => $enemy_k->get_kingdom_map_y(),
            "atk_scouts" => $atk_scouts,
            "atk_losses" => $atk_losses,
            "def_scouts" => $def_scouts,
            "def_losses" => $def_losses,
            "all_eliminated" => ($atk_losses >= $atk_scouts)
        ];

        send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $attacker_json);
        send_server_message($enemy_owner_id, $enemy_owner_name, MessageCategories::CATEGORY_WAR, $defender_json);

        send_user_push(
            $enemy_owner_id,
            "🕵️ Späher gesichtet: " . $enemy_k->get_kingdom_name(),
            "Späher von $attacker_name wurden in {$enemy_k->get_kingdom_name()} entdeckt!",
            "combat",
            "messages.php?servermsgs"
        );

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
        if ($def_losses > 0) {
            update_player_stat($attacker_id, "units_defeated_pvp", $def_losses);
        }
        if ($atk_losses > 0) {
            update_player_stat($enemy_owner_id, "units_defeated_pvp", $atk_losses);
        }
    }

    private function get_scouted_kingdom_intel(Kingdom $enemy_k, int $survivors, bool $include_garrison = false): array
    {
        $intel = [
            "resources" => [
                "food" => $enemy_k->get_kingdom_food(),
                "wood" => $enemy_k->get_kingdom_wood(),
                "stone" => $enemy_k->get_kingdom_stone(),
                "gold" => $enemy_k->get_kingdom_gold()
            ],
            "production" => [
                "food" => $enemy_k->get_kingdom_food_per_hour(),
                "wood" => $enemy_k->get_kingdom_wood_per_hour(),
                "stone" => $enemy_k->get_kingdom_stone_per_hour(),
                "gold" => $enemy_k->get_kingdom_gold_per_hour()
            ]
        ];

        // TIER 2: Buildings
        if ($survivors >= 5) {
            $buildings = [];

            if ($survivors >= 15) {
                $b_res = $this->mysqli->execute_query(
                    "SELECT buildingid, buildingname, buildinglevel FROM buildings WHERE kingdomid = ? ORDER BY buildinglevel DESC",
                    [$enemy_k->get_kingdom_id()]
                );
                while ($b = $b_res->fetch_assoc()) {
                    $buildings[] = ["id" => (int)$b["buildingid"], "name" => $b["buildingname"], "level" => (int)$b["buildinglevel"]];
                }
            } else {
                $buildings[] = ["id" => BuildingTypes::BUILDING_TOWNCENTER, "name" => "Dorfzentrum", "level" => $enemy_k->get_kingdom_building_level(BuildingTypes::BUILDING_TOWNCENTER)];
                $buildings[] = ["id" => BuildingTypes::BUILDING_WALL, "name" => "Mauer", "level" => $enemy_k->get_kingdom_building_level(BuildingTypes::BUILDING_WALL)];
                $buildings[] = ["id" => BuildingTypes::BUILDING_STORAGE, "name" => "Lager", "level" => $enemy_k->get_kingdom_building_level(BuildingTypes::BUILDING_STORAGE)];
            }

            $intel["buildings"] = $buildings;
        }

        // TIER 3: Troops
        if ($include_garrison && $survivors >= 15) {
            $troops = [];

            $t_res = $this->mysqli->execute_query(
                "SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ? AND soldiercount > 0",
                [$enemy_k->get_kingdom_id()]
            );
            while ($t = $t_res->fetch_assoc()) {
                $troops[] = [
                    "id" => (int)$t["soldierid"],
                    "count" => (int)$t["soldiercount"]
                ];
            }

            $intel["troops"] = $troops;
        }

        // TIER 4: Techs
        if ($survivors >= 20) {
            $techs = [];

            $t_res = $this->mysqli->execute_query(
                "SELECT techid, techname, techlevel FROM techs WHERE kingdomid = ? ORDER BY techlevel DESC",
                [$enemy_k->get_kingdom_id()]
            );
            while ($t = $t_res->fetch_assoc()) {
                $techs[] = ["id" => (int)$t["techid"], "name" => $t["techname"], "level" => (int)$t["techlevel"]];
            }

            $intel["techs"] = $techs;
        }

        return $intel;
    }

    private function handle_raider_plunder(array $row, User $attacker_user): void
    {
        $event_id = $row["eventid"];
        $target_x = $row["targetx"];
        $target_y = $row["targety"];
        $home_kingdom_id = $row["kingdomid"];

        // Check already plundered tile
        $res_data = $this->mysqli->execute_query("SELECT * FROM resource_tiles_data WHERE mapx = ? AND mapy = ? FOR UPDATE",
            [$target_x, $target_y]
        );
        $tile = $res_data->fetch_assoc();

        if (!$tile || (time() > $tile["expires_at"] && $tile["expires_at"] > 0)) {
            send_server_message($attacker_user->get_user_id(), $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, [
                "template" => "plunder_already_empty"
            ]);

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
            $home_k = new Kingdom($home_kingdom_id);
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
                $total_capacity = (int)($survivors * RAIDER_BASE_CAPACITY * (1 + ($plunder_lvl * PLUNDER_CAPACITY_BONUS)));

                if ($total_capacity >= $tile_total) {
                    $loot_f = (int)$tile["food"];
                    $loot_w = (int)$tile["wood"];
                    $loot_s = (int)$tile["stone"];
                    $loot_g = (int)$tile["gold"];
                } else {
                    $available = [];
                    foreach (["food", "wood", "stone", "gold"] as $res) {
                        if ((int)$tile[$res] > 0) {
                            $available[$res] = (int)$tile[$res];
                        }
                    }

                    $targeted = $available;

                    if (count($available) > 1) {
                        $res_keys = array_keys($available);
                        shuffle($res_keys);

                        foreach ($res_keys as $rk) {
                            if (mt_rand(1, 100) <= RAIDER_RESOURCE_IGNORE_CHANCE) {
                                $test_candidates = $targeted;
                                unset($test_candidates[$rk]);

                                if (!empty($test_candidates) && array_sum($test_candidates) >= $total_capacity) {
                                    $targeted = $test_candidates;
                                }
                            }
                        }
                    }

                    $weights = [];
                    $targeted_total_stock = array_sum($targeted);

                    foreach ($targeted as $res => $stock) {
                        $stock_ratio = $stock / $targeted_total_stock;
                        $random_bias = mt_rand(RAIDER_MIN_RESOURCE_VARIANCE, RAIDER_MAX_RESOURCE_VARIANCE) / 100;
                        $weights[$res] = $stock_ratio * $random_bias;
                    }
                    $weight_sum = array_sum($weights);

                    $loot = ["food" => 0, "wood" => 0, "stone" => 0, "gold" => 0];
                    $allocated_sum = 0;

                    foreach ($targeted as $res => $stock) {
                        $share = $weights[$res] / $weight_sum;
                        $amount = (int)floor($total_capacity * $share);
                        $amount = min($amount, $stock);

                        $loot[$res] = $amount;
                        $allocated_sum += $amount;
                    }

                    $remaining_space = $total_capacity - $allocated_sum;

                    while ($remaining_space > 0) {
                        $can_take_more = [];
                        foreach ($targeted as $res => $stock) {
                            if ($loot[$res] < $stock) {
                                $can_take_more[] = $res;
                            }
                        }

                        if (empty($can_take_more)) {
                            foreach ($available as $res => $stock) {
                                if ($loot[$res] < $stock) {
                                    $can_take_more[] = $res;
                                }
                            }
                            if (empty($can_take_more)) break;
                        }

                        $pick = $can_take_more[array_rand($can_take_more)];
                        $space_in_tile = $available[$pick] - $loot[$pick];
                        $add_amount = min($remaining_space, $space_in_tile);

                        $loot[$pick] += $add_amount;
                        $remaining_space -= $add_amount;
                    }

                    $loot_f = $loot["food"];
                    $loot_w = $loot["wood"];
                    $loot_s = $loot["stone"];
                    $loot_g = $loot["gold"];
                }

                $total_actually_looted = $loot_f + $loot_w + $loot_s + $loot_g;

                update_player_stat($attacker_user->get_user_id(), "resources_looted", $total_actually_looted);
            }

            // Build message
            $is_success = ($survivors > 0);
            $is_empty = (($tile_total - $total_actually_looted) <= 5);

            $loot_data = [];
            if ($loot_f > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_FOOD] = $loot_f;
            if ($loot_w > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_WOOD] = $loot_w;
            if ($loot_s > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_STONE] = $loot_s;
            if ($loot_g > 0) $loot_data[ResourceTypes::RESOURCE_TYPE_GOLD] = $loot_g;

            $plunder_json = [
                "template" => "plunder",
                "target_x" => $target_x,
                "target_y" => $target_y,
                "home_name" => $home_k->get_kingdom_name(),
                "raiders_sent" => $raider_count,
                "raiders_lost" => $losses,
                "loot" => $loot_data,
                "was_emptied" => $is_empty
            ];

            send_server_message($attacker_user->get_user_id(), $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, $plunder_json);

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
            send_server_message($attacker_user->get_user_id(), $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, [
                "template" => "plunder_no_raiders"
            ]);

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

        $detection_chance = BASE_DANGER_RATE_SCOUTING + min(5, (int)($atk_scouts / 5));

        $losses = 0;
        if (mt_rand(1, 100) <= $detection_chance) {
            $loss_pct = mt_rand(RAIDER_LOSS_MIN_PERC, RAIDER_LOSS_MAX_PERC) / 100;
            $losses = max(1, (int)ceil($atk_scouts * $loss_pct));
            $losses = min($losses, $atk_scouts);
        }

        $survivors = $atk_scouts - $losses;

        if ($losses > 0) {
            $res_scout_score = $this->mysqli->execute_query("SELECT scoregain FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_SCOUT]);
            $scout_score_val = (int)$res_scout_score->fetch_column() ?: 1;

            $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$losses * $scout_score_val, $u_id]);
            update_global_stat("total_fallen_soldiers", $losses);
            update_player_stat($u_id, "units_fallen_pve", $losses);

            $this->mysqli->execute_query("UPDATE sent_troops SET soldiercount = soldiercount - ? WHERE eventid = ? AND soldierid = ?",
                [$losses, $event_id, Soldiers::SOLDIER_SCOUT]);
        }

        $home_k = new Kingdom((int)$row["kingdomid"]);

        $spy_json = [
            "template" => "spy_resource_tile",
            "success" => ($survivors > 0),
            "target_x" => $tx,
            "target_y" => $ty,
            "home_name" => $home_k->get_kingdom_name(),
            "home_x" => $home_k->get_kingdom_map_x(),
            "home_y" => $home_k->get_kingdom_map_y(),
            "atk_scouts" => $atk_scouts,
            "losses" => $losses,
            "resources" => [
                "food" => (int)$res_tile["food"],
                "wood" => (int)$res_tile["wood"],
                "stone" => (int)$res_tile["stone"],
                "gold" => (int)$res_tile["gold"]
            ]
        ];

        send_server_message($u_id, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, $spy_json);

        if ($survivors > 0) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
        } else {
            $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$event_id]);
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        }

        update_player_stat($u_id, "spy_count");

        Logger::get_instance()->log_game("COMBAT", "TILE_SPY", [
            "target_coords" => "$tx:$ty",
            "scouts_sent" => $atk_scouts,
            "scouts_lost" => $losses,
            "success" => ($survivors > 0)
        ], $row["kingdomid"]);
    }

    private function execute_pve_combat_math(
        Conquest $conquest,
        Kingdom  $home_k,
        array    $enemy_monsters
    ): array
    {
        $soldier_types = $conquest->get_soldier_types();

        $atk_atk_pool = 0;
        $atk_def_pool = 0;
        $mon_atk_pool = 0;
        $mon_def_pool = 0;

        $shrine_mult = 1.0;
        if ($home_k->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
            $shrine_mult += $home_k->calculate_shrine_bonus($home_k->get_shrine_modifier());
        }

        $unit_stats = [];
        foreach ($soldier_types as $id => $s) {
            $initial_own = $conquest->get_initial_count_by_id($id, true);
            $cat = (int)$s["category"];

            $b_atk = 0;
            $b_def = 0;
            if ($cat === SoldierTypes::SOLDIER_TYPE_INFANTRY) {
                $b_atk = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES) * SMITHY_INF_ATK_BONUS;
                $b_def = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL) * SMITHY_INF_DEF_BONUS;
            } elseif ($cat === SoldierTypes::SOLDIER_TYPE_CAVALRY) {
                $b_atk = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING) * SMITHY_CAV_ATK_BONUS;
                $b_def = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS) * SMITHY_CAV_DEF_BONUS;
            } elseif ($cat === SoldierTypes::SOLDIER_TYPE_ARCHERS) {
                $b_atk = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS) * SMITHY_ARC_ATK_BONUS;
                $b_def = $home_k->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET) * SMITHY_ARC_DEF_BONUS;
            }

            $stat_atk = (int)round(($s["attack"] * $shrine_mult) + $b_atk);
            $stat_def = (int)round($s["defense"] + $b_def);
            $unit_stats[$id] = ["atk" => $stat_atk, "def" => $stat_def];

            if ($initial_own > 0) {
                $atk_atk_pool += ($initial_own * $stat_atk);
                $atk_def_pool += ($initial_own * $stat_def);
            }
        }

        foreach ($enemy_monsters as $m) {
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

        $report_attacker_units = [];
        $total_score_loss = 0;
        $surviving_attacker_units = 0;
        $total_atk_loss = 0;

        foreach ($soldier_types as $id => $s) {
            $initial = $conquest->get_initial_count_by_id($id, true);

            if ($initial > 0) {
                $loss = (int)round($initial * $atk_loss_ratio);
                $surviving_attacker_units += ($initial - $loss);
                $total_atk_loss += $loss;
                $total_score_loss += ($loss * $s["score"]);

                $report_attacker_units[] = [
                    "id" => $id,
                    "initial" => $initial,
                    "losses" => $loss,
                    "atk" => $unit_stats[$id]["atk"],
                    "def" => $unit_stats[$id]["def"]
                ];
            }
        }

        $report_monster_units = [];
        $monsters_slain = 0;
        $total_monsters_remaining = 0;

        foreach ($enemy_monsters as $m_id => $m) {
            $initial = (int)$m["count"];
            $loss = (int)round($initial * $mon_loss_ratio);

            if ($mon_loss_ratio < 1.0 && $loss >= $initial) {
                $loss = $initial - 1;
            }

            $survivors = max(0, $initial - $loss);
            $monsters_slain += $loss;
            $total_monsters_remaining += $survivors;

            $report_monster_units[] = [
                "id" => "m" . $m_id,
                "initial" => $initial,
                "losses" => $loss,
                "atk" => $m["atk"],
                "def" => $m["def"]
            ];
        }

        return [
            "report_attacker_units" => $report_attacker_units,
            "report_monster_units" => $report_monster_units,
            "surviving_attacker_units" => $surviving_attacker_units,
            "total_monsters_remaining" => $total_monsters_remaining,
            "total_score_loss" => $total_score_loss,
            "total_atk_loss" => $total_atk_loss,
            "monsters_slain" => $monsters_slain,
            "victory" => ($total_monsters_remaining <= 0)
        ];
    }

    public function process_monster_battle(array $row, Kingdom $home_k, User $attacker_user, int $return_time): void
    {
        $attacker_id = $attacker_user->get_user_id();
        $tx = (int)$row["targetx"];
        $ty = (int)$row["targety"];
        $event_id = (int)$row["eventid"];

        $res_expires = $this->mysqli->execute_query("SELECT expires_at FROM monster_camps WHERE mapx = ? AND mapy = ? FOR UPDATE",
            [$tx, $ty]
        );
        $row_camp = $res_expires->fetch_assoc();

        if (!$row_camp || (time() > $row_camp["expires_at"] && $row_camp["expires_at"] > 0)) {
            $cleared_json = [
                "template" => "camp_cleared",
                "target_x" => $tx,
                "target_y" => $ty
            ];

            send_server_message($attacker_id, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, $cleared_json);

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
            return;
        }

        $conquest = new Conquest();
        $conquest->set_event_id($event_id);
        $conquest->fetch_sent_troops();
        $conquest->initialize_soldier_types();
        $conquest->get_monster_defenders($tx, $ty);
        $conquest->initialize_soldier_values();
        $conquest->set_initial_monster_battle();

        $camp_res = $this->mysqli->execute_query("SELECT level FROM monster_camps WHERE mapx = ? AND mapy = ?", [$tx, $ty]);
        $camp_lvl = (int)($camp_res->fetch_column() ?: 1);

        // PvE Calculation
        $combat = $this->execute_pve_combat_math($conquest, $home_k, $conquest->get_monster_enemy_data());

        // Insert losses to database
        foreach ($combat["report_attacker_units"] as $au) {
            if ($au["losses"] > 0) {
                $this->mysqli->execute_query("UPDATE sent_troops SET soldiercount = soldiercount - ? WHERE eventid = ? AND soldierid = ?",
                    [$au["losses"], $event_id, $au["id"]]);
            }
        }

        update_player_stat($attacker_id, "monster_kills", $combat["monsters_slain"]);
        if ($combat["total_atk_loss"] > 0) {
            update_player_stat($attacker_id, "units_fallen_pve", $combat["total_atk_loss"]);
        }

        $looted_coins = 0;
        $loot_res = ["food" => 0, "wood" => 0, "stone" => 0, "gold" => 0];
        $victory = $combat["victory"];

        if (!$victory) {
            foreach ($combat["report_monster_units"] as $rep_m) {
                $current_m_id = $rep_m["id"];
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

        $loot_display = [];

        if ($victory) {
            $outcome_code = ($combat["surviving_attacker_units"] > 0) ? "victory" : "pyrrhic_victory";

            if ($combat["surviving_attacker_units"] > 0) {
                $loot_display = [
                    ResourceTypes::RESOURCE_TYPE_COINS => $looted_coins,
                    ResourceTypes::RESOURCE_TYPE_FOOD => $loot_res["food"],
                    ResourceTypes::RESOURCE_TYPE_WOOD => $loot_res["wood"],
                    ResourceTypes::RESOURCE_TYPE_STONE => $loot_res["stone"],
                    ResourceTypes::RESOURCE_TYPE_GOLD => $loot_res["gold"]
                ];
            }

            update_player_stat($attacker_id, "camps_cleared");
        } else {
            if ($combat["surviving_attacker_units"] > 0) {
                if ($combat["total_atk_loss"] === 0 && $combat["monsters_slain"] === 0) {
                    $outcome_code = "stalemate";
                } else if ($combat["total_atk_loss"] === 0 && $combat["monsters_slain"] > 0) {
                    $outcome_code = "flawless";
                } else if ($combat["monsters_slain"] >= $combat["total_atk_loss"]) {
                    $outcome_code = "tactical_retreat";
                } else {
                    $outcome_code = "hard_resistance";
                }
            } else {
                $outcome_code = "total_defeat";
            }
        }

        $battle_json = [
            "template" => "battle_monster",
            "target_x" => $tx,
            "target_y" => $ty,
            "home_name" => $home_k->get_kingdom_name(),
            "home_x" => $home_k->get_kingdom_map_x(),
            "home_y" => $home_k->get_kingdom_map_y(),
            "camp_level" => $camp_lvl,
            "atk_units" => $combat["report_attacker_units"],
            "def_units" => $combat["report_monster_units"],
            "outcome" => $outcome_code,
            "loot" => $loot_display
        ];

        send_server_message($attacker_id, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, $battle_json);

        if ($combat["total_score_loss"] > 0) {
            $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$combat["total_score_loss"], $attacker_id]);
        }
        update_global_stat("total_slain_monsters", $combat["monsters_slain"]);

        $total_loot = array_sum($loot_res);
        if ($total_loot > 0) {
            update_player_stat($attacker_id, "resources_looted", $total_loot);
        }

        if ($combat["surviving_attacker_units"] > 0) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, loot_coins = ?, 
                    loot_food = ?, loot_wood = ?, loot_stone = ?, loot_gold = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $looted_coins,
                    $loot_res["food"], $loot_res["wood"], $loot_res["stone"], $loot_res["gold"], $event_id]
            );
        } else {
            $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$event_id]);
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        }

        $troops_sent_details = [];
        $soldier_types = $conquest->get_soldier_types();

        foreach ($combat["report_attacker_units"] as $u) {
            $name = $soldier_types[$u["id"]]["soldiername"] ?? ("ID " . $u["id"]);
            $troops_sent_details[$name] = $u["initial"];
        }

        Logger::get_instance()->log_game("COMBAT", "MONSTER_BATTLE", [
            "target_coords" => "$tx:$ty",
            "victory" => $victory,
            "troops_sent" => $troops_sent_details,
            "attacker_losses" => $combat["total_atk_loss"],
            "monsters_slain" => $combat["monsters_slain"],
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
            SELECT mcu.monster_id, mc.level, mc.expires_at, mcu.count
            FROM monster_camps mc
            JOIN monster_camp_units mcu ON mc.mapx = mcu.mapx AND mc.mapy = mcu.mapy
            WHERE mc.mapx = ? AND mc.mapy = ?", [$tx, $ty]);

        $units = $res_camp->fetch_all(MYSQLI_ASSOC);

        if (empty($units) || (time() > $units[0]["expires_at"] && $units[0]["expires_at"] > 0)) {
            $cleared_json = [
                "template" => "outcome_box",
                "title" => "Spionage zwecklos",
                "main_text" => "Unsere Späher berichten, dass das Camp bei (<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>) bereits aufgelöst wurde.",
                "sub_text" => "Es gibt hier nichts mehr zu sehen. Die Späher kehren heim.",
                "result_type" => "neutral"
            ];
            send_server_message($attacker_id, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, $cleared_json);

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
            return;
        }

        $camp_lvl = $units[0]["level"] ?? 1;

        $detection_chance = BASE_DANGER_RATE_SCOUTING + ($camp_lvl - 1);

        $losses = 0;
        if (mt_rand(1, 100) <= $detection_chance) {
            $min_pct = RAIDER_LOSS_MIN_PERC;
            $max_pct = RAIDER_LOSS_MAX_PERC + (int)floor($camp_lvl / 2);
            $loss_pct = mt_rand($min_pct, $max_pct) / 100;

            $losses = max(1, (int)ceil($atk_scouts * $loss_pct));
            $losses = min($losses, $atk_scouts);
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

        $home_k = new Kingdom((int)$row["kingdomid"]);

        $monster_units = [];
        foreach ($units as $u) {
            $monster_units[] = [
                "id" => "m" . (int)$u["monster_id"],
                "count" => (int)$u["count"]
            ];
        }

        $reward_factor = 1.0;
        if ($camp_lvl >= 8) $reward_factor += LOOT_FACTOR_HIGH_CAMPS;
        else if ($camp_lvl >= 5) $reward_factor += LOOT_FACTOR_MID_CAMPS;

        $est_min_coins = (int)(MONSTER_CAMP_COIN_MIN_PER_LVL * $camp_lvl);
        $est_max_coins = (int)(MONSTER_CAMP_COIN_MAX_PER_LVL * $camp_lvl);
        $base_res_amount = $camp_lvl * MONSTER_CAMP_BASE_RESOURCE_LOOT * $reward_factor;

        $spy_json = [
            "template" => "spy_monster_camp",
            "success" => ($survivors > 0),
            "target_x" => $tx,
            "target_y" => $ty,
            "home_name" => $home_k->get_kingdom_name(),
            "home_x" => $home_k->get_kingdom_map_x(),
            "home_y" => $home_k->get_kingdom_map_y(),
            "camp_level" => $camp_lvl,
            "atk_scouts" => $atk_scouts,
            "losses" => $losses,
            "monsters" => $monster_units,
            "est_loot" => [
                "coins_min" => $est_min_coins,
                "coins_max" => $est_max_coins,
                "food_gold_min" => (int)($base_res_amount * (MIN_MONSTER_CAMP_RESOURCE_PERC / 100)),
                "food_gold_max" => (int)($base_res_amount * (MAX_MONSTER_CAMP_RESOURCE_PERC / 100)),
                "wood_stone_min" => (int)($base_res_amount * (MIN_MONSTER_CAMP_WOOD_AND_STONE_PERC / 100)),
                "wood_stone_max" => (int)($base_res_amount * (MAX_MONSTER_CAMP_WOOD_AND_STONE_PERC / 100))
            ]
        ];

        send_server_message($attacker_id, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, $spy_json);

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
            $this->turn_back_support($row, "target_lost", "Das Ziel-Königreich existiert nicht mehr.");
            return;
        }

        $is_still_ally = ($data["sender_gid"] > 0 && $data["sender_gid"] === $data["recipient_gid"]);

        if (!$is_still_ally) {
            $this->turn_back_support($row, "no_alliance", "Da ihr nicht mehr in derselben Gilde seid, wurde deinen Truppen der Einlass verwehrt.");
            return;
        }

        $target_k = new Kingdom($target_kid);
        $g_cap_lvl = Guild::get_user_guild_tech_level((int)$data["recipient_id"], GuildTechTypes::GUILD_TECH_SUPPORT_CAPACITY);
        $support_limit = SUPPORT_LIMIT_BASE + ($target_k->get_kingdom_building_level(BuildingTypes::BUILDING_BARRACKS) * SUPPORT_LIMIT_PER_BARRACKS) + ($g_cap_lvl * GUILD_BONUS_SUPPORT_CAP_PER_LVL);
        $current_support = (int)$this->mysqli->execute_query("SELECT IFNULL(SUM(soldiercount), 0) FROM stationed_troops WHERE target_kingdom_id = ?", [$target_kid])->fetch_column();

        $res_incoming = $this->mysqli->execute_query("SELECT soldierid, soldiercount, initial_count FROM sent_troops WHERE eventid = ?", [$event_id]);
        $incoming_troops = $res_incoming->fetch_all(MYSQLI_ASSOC);
        $incoming_count = array_sum(array_column($incoming_troops, 'soldiercount'));

        if (($current_support + $incoming_count) > $support_limit) {
            $this->turn_back_support($row, "storage_full", "Das Unterstützungslager in <b>{$data["target_name"]}</b> ist bereits voll belegt.");
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

        $units_data = [];
        foreach ($incoming_troops as $t) {
            $units_data[] = [
                "id" => (int)$t["soldierid"],
                "count" => (int)$t["soldiercount"]
            ];
        }

        $base_data = [
            "template" => "support_arrival",
            "sender_name" => $data["sender_name"],
            "recipient_name" => $data["recipient_name"],
            "target_name" => $data["target_name"],
            "target_x" => (int)$row["targetx"],
            "target_y" => (int)$row["targety"],
            "units" => $units_data
        ];

        $recv_json = array_merge($base_data, ["role" => "recipient"]);
        $send_json = array_merge($base_data, ["role" => "sender"]);

        send_server_message((int)$data["recipient_id"], $data["recipient_name"], MessageCategories::CATEGORY_GUILD, $recv_json);
        send_server_message($sender_id, $data["sender_name"], MessageCategories::CATEGORY_GUILD, $send_json);

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

        $turn_back_json = [
            "template" => "support_turned_back",
            "reason" => $reason_short,
            "target_name" => $long_text
        ];

        send_server_message((int)$row["userid"], $s_name, MessageCategories::CATEGORY_WAR, $turn_back_json);
    }

    public function process_orphaned_support(): void
    {
        $query = "
            SELECT st.*, u.username as owner_name, sl.soldiername, sl.icon
            FROM stationed_troops st
            LEFT JOIN kingdoms k ON st.target_kingdom_id = k.id
            JOIN users u ON st.owner_id = u.id
            JOIN soldier_list sl ON st.soldier_id = sl.id
            WHERE k.id IS NULL
        ";
        $res = $this->mysqli->query($query);

        $orphans = [];
        while ($row = $res->fetch_assoc()) {
            $key = $row["owner_id"] . '_' . $row["source_kingdom_id"];
            $orphans[$key][] = $row;
        }

        foreach ($orphans as $troops) {
            $first = $troops[0];
            $owner_id = (int)$first["owner_id"];
            $source_id = (int)$first["source_kingdom_id"];
            $owner_name = $first["owner_name"];

            $units_data = [];
            foreach ($troops as $t) {
                $this->mysqli->execute_query("
                    INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE soldiercount = soldiercount + VALUES(soldiercount)
                ", [
                    $source_id,
                    $t["soldier_id"],
                    $t["soldiername"],
                    $t["soldiercount"]
                ]);

                $units_data[] = [
                    "id" => (int)$t["soldier_id"],
                    "count" => (int)$t["soldiercount"]
                ];

                $this->mysqli->execute_query("DELETE FROM stationed_troops WHERE id = ?", [$t["id"]]);
            }

            $orphaned_json = [
                "template" => "support_orphaned",
                "units" => $units_data
            ];

            send_server_message($owner_id, $owner_name, MessageCategories::CATEGORY_WAR, $orphaned_json);

            Logger::get_instance()->log_game("COMBAT", "SUPPORT_ORPHANED_RETURN", [
                "source_kingdom" => $source_id,
                "message" => "Truppen wurden wegen Zielverlust sofort gutgeschrieben"
            ], $source_id);
        }
    }

    private function handle_guild_research(array $row): void
    {
        $guild_id = $row["guild_id"];
        $tech_id = $row["buildingid"];

        $this->mysqli->execute_query(
            "INSERT INTO guild_techs (guild_id, tech_id, level) VALUES (?, ?, 1) 
                    ON DUPLICATE KEY UPDATE level = level + 1",
            [$guild_id, $tech_id]
        );

        $this->mysqli->execute_query("DELETE FROM guild_member_contributions WHERE guild_id = ?", [$guild_id]);
        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

        // Notify guild members
        $tech_name = $row["buildingname"];
        $new_lvl = (int)$this->mysqli->execute_query("SELECT level FROM guild_techs WHERE guild_id = ? AND tech_id = ?", [$guild_id, $tech_id])->fetch_column();

        new Guild($this->user, $guild_id)->notify_guild("guild_research_completed", ["tech" => $tech_name, "level" => $new_lvl]);
    }

    public function process_ruin_battle(array $row, Kingdom $home_k, User $attacker_user, int $return_time): void
    {
        $attacker_id = $attacker_user->get_user_id();
        $tx = (int)$row["targetx"];
        $ty = (int)$row["targety"];
        $event_id = (int)$row["eventid"];

        $res_ruin = $this->mysqli->execute_query(
            "SELECT * FROM abandoned_kingdoms WHERE mapx = ? AND mapy = ? FOR UPDATE",
            [$tx, $ty]
        )->fetch_assoc();

        if (!$res_ruin || (time() > $res_ruin["expires_at"] && $res_ruin["expires_at"] > 0)) {
            $decayed_json = [
                "template" => "ruin_decayed",
                "target_x" => $tx,
                "target_y" => $ty
            ];
            send_server_message($attacker_id, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, $decayed_json);

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
            return;
        }

        $res_units = $this->mysqli->execute_query("
            SELECT aku.count, ml.id as monster_id, ml.monster_name, ml.attack, ml.defense, ml.icon
            FROM abandoned_kingdom_units aku
            JOIN monster_list ml ON aku.monster_id = ml.id
            WHERE aku.mapx = ? AND aku.mapy = ?", [$tx, $ty]);

        $enemy_monsters = [];
        foreach ($res_units as $m) {
            $enemy_monsters[$m["monster_id"]] = [
                "count" => (int)$m["count"],
                "name" => $m["monster_name"],
                "atk" => (int)$m["attack"],
                "def" => (int)$m["defense"],
                "icon" => $m["icon"]
            ];
        }

        $conquest = new Conquest();
        $conquest->set_event_id($event_id);
        $conquest->fetch_sent_troops();
        $conquest->initialize_soldier_types();
        $conquest->initialize_soldier_values();
        $conquest->set_initial_monster_battle();

        $combat = $this->execute_pve_combat_math($conquest, $home_k, $enemy_monsters);

        foreach ($combat["report_attacker_units"] as $au) {
            if ($au["losses"] > 0) {
                $this->mysqli->execute_query("UPDATE sent_troops SET soldiercount = soldiercount - ? WHERE eventid = ? AND soldierid = ?",
                    [$au["losses"], $event_id, $au["id"]]);
            }
        }

        if ($combat["monsters_slain"] > 0) {
            update_player_stat($attacker_id, "monster_kills", $combat["monsters_slain"]);
            update_global_stat("total_slain_monsters", $combat["monsters_slain"]);
        }
        if ($combat["total_atk_loss"] > 0) {
            update_player_stat($attacker_id, "units_fallen_pve", $combat["total_atk_loss"]);
        }

        $victory = $combat["victory"];
        $loot = ["food" => 0, "wood" => 0, "stone" => 0, "gold" => 0];

        if ($victory) {
            if ($combat["surviving_attacker_units"] > 0) {
                $loot["food"] = (int)$res_ruin["food"];
                $loot["wood"] = (int)$res_ruin["wood"];
                $loot["stone"] = (int)$res_ruin["stone"];
                $loot["gold"] = (int)$res_ruin["gold"];
            }

            $this->mysqli->execute_query("DELETE FROM abandoned_kingdoms WHERE mapx = ? AND mapy = ?", [$tx, $ty]);
            $this->mysqli->execute_query("UPDATE map SET kingdomid = -1 WHERE mapx = ? AND mapy = ?", [$tx, $ty]);
        } else {
            foreach ($combat["report_monster_units"] as $rm) {
                $mid = $rm["id"];
                $rem = $rm["initial"] - $rm["losses"];

                if ($rem <= 0) {
                    $this->mysqli->execute_query("DELETE FROM abandoned_kingdom_units WHERE mapx = ? AND mapy = ? AND monster_id = ?", [$tx, $ty, $mid]);
                } else {
                    $this->mysqli->execute_query("UPDATE abandoned_kingdom_units SET count = ? WHERE mapx = ? AND mapy = ? AND monster_id = ?", [$rem, $tx, $ty, $mid]);
                }
            }
        }

        $loot_display = [];
        if ($victory) {
            if ($combat["surviving_attacker_units"] > 0) {
                $loot_display = [
                    ResourceTypes::RESOURCE_TYPE_FOOD => $loot["food"],
                    ResourceTypes::RESOURCE_TYPE_WOOD => $loot["wood"],
                    ResourceTypes::RESOURCE_TYPE_STONE => $loot["stone"],
                    ResourceTypes::RESOURCE_TYPE_GOLD => $loot["gold"]
                ];
            }

            update_player_stat($attacker_id, "resources_looted", array_sum($loot));
        }

        $outcome_code = $victory ? (($combat["surviving_attacker_units"] > 0) ? "victory" : "pyrrhic_victory") : "defeat";

        $ruin_json = [
            "template" => "battle_ruin",
            "target_x" => $tx,
            "target_y" => $ty,
            "ruin_name" => $res_ruin["kingdom_name"],
            "atk_units" => $combat["report_attacker_units"],
            "def_units" => $combat["report_monster_units"],
            "outcome" => $outcome_code,
            "loot" => $loot_display
        ];

        send_server_message($attacker_id, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, $ruin_json);

        if ($combat["total_score_loss"] > 0) {
            $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$combat["total_score_loss"], $attacker_id]);
        }

        if ($combat["surviving_attacker_units"] > 0) {
            $this->mysqli->execute_query(
                "UPDATE events SET actionid = ?, arrivaltime = ?, loot_food = ?, loot_wood = ?, loot_stone = ?, loot_gold = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $loot["food"], $loot["wood"], $loot["stone"], $loot["gold"], $event_id]
            );
        } else {
            $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$event_id]);
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        }
    }

    public function process_ruin_spy_mission(array $row, int $atk_scouts, User $attacker_user, int $return_time): void
    {
        $attacker_id = $attacker_user->get_user_id();
        $tx = (int)$row["targetx"];
        $ty = (int)$row["targety"];
        $event_id = (int)$row["eventid"];

        $res_ruin = $this->mysqli->execute_query("SELECT * FROM abandoned_kingdoms WHERE mapx = ? AND mapy = ?", [$tx, $ty])->fetch_assoc();

        if (!$res_ruin) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
            return;
        }

        $units = $this->mysqli->execute_query("
            SELECT monster_id, count 
            FROM abandoned_kingdom_units 
            WHERE mapx = ? AND mapy = ?", [$tx, $ty])->fetch_all(MYSQLI_ASSOC);

        $monster_units = [];
        foreach ($units as $u) {
            $monster_units[] = [
                "id" => "m" . (int)$u["monster_id"],
                "count" => (int)$u["count"]
            ];
        }

        $spy_json = [
            "template" => "spy_ruin",
            "target_x" => $tx,
            "target_y" => $ty,
            "ruin_name" => $res_ruin["kingdom_name"],
            "atk_scouts" => $atk_scouts,
            "resources" => [
                "food" => (int)$res_ruin["food"],
                "wood" => (int)$res_ruin["wood"],
                "stone" => (int)$res_ruin["stone"],
                "gold" => (int)$res_ruin["gold"]
            ],
            "monsters" => $monster_units
        ];

        send_server_message($attacker_id, $attacker_user->get_user_name(), MessageCategories::CATEGORY_WAR, $spy_json);

        $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
            [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);

        update_player_stat($attacker_id, "spy_count");
    }

    public function process_mines(): void
    {
        $now = time();

        $this->mysqli->execute_query("DELETE FROM mines WHERE expires_at <= ? AND id NOT IN (SELECT DISTINCT mine_id FROM mine_stationed_troops)", [$now]);

        $active_mines = $this->mysqli->query("
            SELECT m.*, IFNULL(SUM(mst.soldiercount * mst.unit_atk), 0) AS current_atk 
            FROM mines m
            JOIN mine_stationed_troops mst ON m.id = mst.mine_id
            GROUP BY m.id
        ");

        while ($m = $active_mines->fetch_assoc()) {
            $last_update = (int)($m["last_update"] ?: $now);
            $elapsed = max(0, $now - $last_update);
            $current_atk = (float)$m["current_atk"];

            $work_total = (float)$m["work_total"];
            $max_rate = $work_total / MINE_MIN_DURATION_SECONDS;
            $effective_rate = min($current_atk * MINE_WORK_RATE_FACTOR, $max_rate);

            $work_delta = 0;
            if ($elapsed > 0 && $current_atk > 0) {
                $work_delta = $effective_rate * $elapsed;

                $this->mysqli->execute_query("
                    UPDATE mine_stationed_troops 
                    SET work_contributed = work_contributed + (? * ((soldiercount * unit_atk) / ?))
                    WHERE mine_id = ?
                ", [$work_delta, max(1, $current_atk), $m["id"]]);
            }

            $new_work_done = (float)$m["work_done"] + $work_delta;
            $is_completed = ($new_work_done >= $work_total);
            $is_expired = ((int)$m["expires_at"] <= $now);

            if ($is_completed || $is_expired) {
                $participants = $this->mysqli->execute_query("
                    SELECT user_id, kingdom_id, SUM(work_contributed) as my_work 
                    FROM mine_stationed_troops 
                    WHERE mine_id = ? 
                    GROUP BY user_id, kingdom_id
                ", [$m["id"]])->fetch_all(MYSQLI_ASSOC);

                $total_participant_work = (float)array_sum(array_column($participants, "my_work"));
                $num_participants = count($participants);

                $completion_ratio = min(1.0, $new_work_done / $work_total);

                $total_stone = (int)floor($m["stone"] * $completion_ratio);
                $total_gold = (int)floor($m["gold"] * $completion_ratio);
                $total_coal = (int)floor($m["coal"] * $completion_ratio);
                $total_iron = (int)floor($m["iron"] * $completion_ratio);
                $total_sapphire = (int)floor($m["sapphire"] * $completion_ratio);
                $total_diamond = (int)floor($m["diamond"] * $completion_ratio);

                $rem_stone = $total_stone;
                $rem_gold = $total_gold;
                $rem_coal = $total_coal;
                $rem_iron = $total_iron;
                $rem_sapphire = $total_sapphire;
                $rem_diamond = $total_diamond;

                $event_name = $is_completed ? "Minen-Ertrag" : "Minen-Ablauf";

                foreach ($participants as $idx => $p) {
                    $is_last = ($idx === $num_participants - 1);

                    if ($total_participant_work > 0) {
                        $share = (float)$p["my_work"] / $total_participant_work;
                    } else {
                        $share = 1.0 / max(1, $num_participants);
                    }

                    if ($is_last) {
                        $p_stone = $rem_stone;
                        $p_gold = $rem_gold;
                        $p_coal = $rem_coal;
                        $p_iron = $rem_iron;
                        $p_sapphire = $rem_sapphire;
                        $p_diamond = $rem_diamond;
                    } else {
                        $p_stone = (int)floor($total_stone * $share);
                        $p_gold = (int)floor($total_gold * $share);
                        $p_coal = (int)floor($total_coal * $share);
                        $p_iron = (int)floor($total_iron * $share);
                        $p_sapphire = (int)floor($total_sapphire * $share);
                        $p_diamond = (int)floor($total_diamond * $share);

                        $rem_stone -= $p_stone;
                        $rem_gold -= $p_gold;
                        $rem_coal -= $p_coal;
                        $rem_iron -= $p_iron;
                        $rem_sapphire -= $p_sapphire;
                        $rem_diamond -= $p_diamond;
                    }

                    $res_k = $this->mysqli->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$p["kingdom_id"]])->fetch_assoc();
                    $kx = (int)($res_k["mapx"] ?? 1);
                    $ky = (int)($res_k["mapy"] ?? 1);

                    $map_helper = new Map(new User((int)$p["user_id"], ""));
                    $travel_time = $map_helper->get_arrival_time($kx, $ky, (int)$m["mapx"], (int)$m["mapy"], (int)$p["kingdom_id"], MapFieldTypes::MAP_FIELD_MINE);

                    $this->mysqli->execute_query("
                        INSERT INTO events (actionid, userid, kingdomid, targetid, targetx, targety, arrivaltime, buildingtime, 
                                            loot_food, loot_wood, loot_stone, loot_gold, loot_coal, loot_iron, loot_sapphire, loot_diamond, buildingname)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?)
                    ", [
                        ActionTypes::ACTION_RETURN_TROOPS, $p["user_id"], $p["kingdom_id"],
                        MapFieldTypes::MAP_FIELD_MINE, $m["mapx"], $m["mapy"], $now + $travel_time, $now,
                        $p_stone, $p_gold, $p_coal, $p_iron, $p_sapphire, $p_diamond, $event_name
                    ]);
                    $return_eid = $this->mysqli->insert_id;

                    $troops = $this->mysqli->execute_query("
                        SELECT soldier_id, SUM(soldiercount) as soldiercount 
                        FROM mine_stationed_troops 
                        WHERE mine_id = ? AND user_id = ? AND kingdom_id = ?
                        GROUP BY soldier_id
                    ", [$m["id"], $p["user_id"], $p["kingdom_id"]]);

                    $insert_troops = [];
                    while ($t = $troops->fetch_assoc()) {
                        $sid = (int)$t["soldier_id"];
                        $scnt = (int)$t["soldiercount"];
                        $insert_troops[] = "($return_eid, $sid, $scnt, $scnt, " . (int)$p["kingdom_id"] . ")";
                    }

                    if (!empty($insert_troops)) {
                        $this->mysqli->query("INSERT INTO sent_troops (eventid, soldierid, soldiercount, initial_count, source_kingdom_id) VALUES " . implode(',', $insert_troops));
                    }

                    $k_name = $this->mysqli->execute_query("SELECT kingdomname FROM kingdoms WHERE id = ?", [$p["kingdom_id"]])->fetch_column() ?: "Dein Königreich";
                    $status_text = $is_completed ? "vollständig abgebaut" : "abgelaufen und geräumt";
                    send_user_push(
                        (int)$p["user_id"],
                        "⛏️ Mine geräumt: $k_name",
                        "Die Erzmine bei ({$m['mapx']}:{$m['mapy']}) ist $status_text. Deine Truppen treten mit ihrer Beute den Rückmarsch an.",
                        "troops",
                        "overview.php"
                    );

                    if ($is_completed) {
                        update_player_stat((int)$p["user_id"], "mines_depleted");
                    }
                }

                $res_incoming = $this->mysqli->execute_query("
                    SELECT e.*, u.username, k.kingdomname 
                    FROM events e
                    JOIN users u ON e.userid = u.id
                    JOIN kingdoms k ON e.kingdomid = k.id
                    WHERE e.targetid = ? 
                      AND e.targetx = ? 
                      AND e.targety = ? 
                      AND e.actionid = ?
                ", [
                    MapFieldTypes::MAP_FIELD_MINE,
                    (int)$m["mapx"],
                    (int)$m["mapy"],
                    ActionTypes::ACTION_SEND_TROOPS
                ]);

                while ($inc = $res_incoming->fetch_assoc()) {
                    $event_id = (int)$inc["eventid"];
                    $user_id = (int)$inc["userid"];

                    $already_marched = max(5, $now - (int)$inc["buildingtime"]);
                    $new_arrival = $now + $already_marched;

                    $this->mysqli->execute_query("
                        UPDATE events 
                        SET actionid = ?, 
                            arrivaltime = ?, 
                            buildingtime = ?, 
                            buildingname = 'Mine erschöpft', 
                            is_processing = 0 
                        WHERE eventid = ?
                    ", [
                        ActionTypes::ACTION_RETURN_TROOPS,
                        $new_arrival,
                        $now,
                        $event_id
                    ]);

                    $units_res = $this->mysqli->execute_query("
                        SELECT soldierid, soldiercount FROM sent_troops WHERE eventid = ?
                    ", [$event_id]);

                    $units_data = [];
                    while ($u = $units_res->fetch_assoc()) {
                        $units_data[] = [
                            "id" => (int)$u["soldierid"],
                            "count" => (int)$u["soldiercount"]
                        ];
                    }

                    $mine_turnback_json = [
                        "template" => "mine_depleted_early_return",
                        "target_x" => (int)$m["mapx"],
                        "target_y" => (int)$m["mapy"],
                        "home_name" => $inc["kingdomname"],
                        "units" => $units_data
                    ];
                    send_server_message($user_id, $inc["username"], MessageCategories::CATEGORY_WAR, $mine_turnback_json);

                    send_user_push(
                        $user_id,
                        "⛏️ Mine erschöpft: Truppen kehren um",
                        "Die Erzmine bei ({$m['mapx']}:{$m['mapy']}) ist weg. Deine Truppen kehren um.",
                        "troops",
                        "overview.php"
                    );
                }

                $this->mysqli->execute_query("DELETE FROM mine_stationed_troops WHERE mine_id = ?", [$m["id"]]);
                $this->mysqli->execute_query("DELETE FROM mines WHERE id = ?", [$m["id"]]);
                $this->mysqli->execute_query("UPDATE map SET kingdomid = -1 WHERE mapx = ? AND mapy = ?", [$m["mapx"], $m["mapy"]]);

                $log_action = $is_completed ? "MINE_DEPLETED" : "MINE_EXPIRED";
                Logger::get_instance()->log_game("ECONOMY", $log_action, [
                    "mine_id" => $m["id"],
                    "coords" => "{$m['mapx']}:{$m['mapy']}",
                    "level" => $m["level"],
                    "completion_percent" => round($completion_ratio * 100, 1)
                ]);
            } else {
                $this->mysqli->execute_query("UPDATE mines SET work_done = ?, last_update = ? WHERE id = ?", [$new_work_done, $now, $m["id"]]);
            }
        }
    }

    private function process_mine_spy_mission(array $row, int $atk_scouts, User $attacker_user, int $return_time): void
    {
        $attacker_id = $attacker_user->get_user_id();
        $attacker_name = $attacker_user->get_user_name();
        $tx = (int)$row["targetx"];
        $ty = (int)$row["targety"];
        $event_id = (int)$row["eventid"];

        $mine = $this->mysqli->execute_query("SELECT * FROM mines WHERE mapx = ? AND mapy = ?", [$tx, $ty])->fetch_assoc();

        if (!$mine) {
            $empty_mine_json = [
                "template" => "mine_depleted",
                "target_x" => $tx,
                "target_y" => $ty
            ];
            send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $empty_mine_json);

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
            return;
        }

        $mine_id = (int)$mine["id"];
        $now = time();

        // Sync live mining status
        $last_update = (int)($mine["last_update"] ?: $now);
        $elapsed = max(0, $now - $last_update);

        $current_atk = (float)$this->mysqli->execute_query(
            "SELECT IFNULL(SUM(soldiercount * unit_atk), 0) FROM mine_stationed_troops WHERE mine_id = ?",
            [$mine_id]
        )->fetch_column();

        $work_total = max(1.0, (float)$mine["work_total"]);
        $work_done = (float)$mine["work_done"];

        if ($elapsed > 0 && $current_atk > 0) {
            $max_rate = $work_total / MINE_MIN_DURATION_SECONDS;
            $effective_rate = min($current_atk * MINE_WORK_RATE_FACTOR, $max_rate);
            $work_delta = $effective_rate * $elapsed;
            $work_done = min($work_total, $work_done + $work_delta);

            $this->mysqli->execute_query("
                UPDATE mine_stationed_troops 
                SET work_contributed = work_contributed + (? * ((soldiercount * unit_atk) / ?))
                WHERE mine_id = ?
            ", [$work_delta, max(1, $current_atk), $mine_id]);

            $this->mysqli->execute_query(
                "UPDATE mines SET work_done = ?, last_update = ? WHERE id = ?",
                [$work_done, $now, $mine_id]
            );
        }

        // Calculate remaining loot
        $mined_ratio = min(1.0, $work_done / $work_total);
        $remaining_ratio = max(0.0, 1.0 - $mined_ratio);

        // Get stationed troops
        $defenders = $this->mysqli->execute_query("
            SELECT mst.*, u.username, u.guildid
            FROM mine_stationed_troops mst
            JOIN users u ON mst.user_id = u.id
            WHERE mst.mine_id = ?
        ", [$mine_id])->fetch_all(MYSQLI_ASSOC);

        // Calculate scout losses
        $losses = 0;
        if (!empty($defenders)) {
            $def_count = array_sum(array_column($defenders, "soldiercount"));
            $detection_chance = BASE_DANGER_RATE_SCOUTING + ((int)$mine["level"] * 3) + min(30, (int)($def_count / 10));

            if (mt_rand(1, 100) <= $detection_chance) {
                $min_pct = RAIDER_LOSS_MIN_PERC;
                $max_pct = RAIDER_LOSS_MAX_PERC + (int)$mine["level"];
                $loss_pct = mt_rand($min_pct, $max_pct) / 100;

                $losses = max(1, (int)ceil($atk_scouts * $loss_pct));
                $losses = min($losses, $atk_scouts);
            }
        }

        $survivors = $atk_scouts - $losses;

        if ($losses > 0) {
            $res_scout_score = $this->mysqli->execute_query("SELECT scoregain FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_SCOUT]);
            $scout_score_val = (int)$res_scout_score->fetch_column() ?: 1;
            $total_score_loss = $losses * $scout_score_val;

            $this->mysqli->execute_query(
                "UPDATE sent_troops SET soldiercount = soldiercount - ? WHERE eventid = ? AND soldierid = ?",
                [$losses, $event_id, Soldiers::SOLDIER_SCOUT]
            );

            update_global_stat("total_fallen_soldiers", $losses);
            update_player_stat($attacker_id, "units_fallen_pvp", $losses);
            $this->mysqli->execute_query("UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?", [$total_score_loss, $attacker_id]);
        }

        $home_k = new Kingdom((int)$row["kingdomid"]);

        $res_standard = [
            "stone" => (int)floor($mine["stone"] * $remaining_ratio),
            "gold" => (int)floor($mine["gold"] * $remaining_ratio)
        ];

        $res_special = [
            "coal" => (int)floor($mine["coal"] * $remaining_ratio),
            "iron" => (int)floor($mine["iron"] * $remaining_ratio),
            "sapphire" => (int)floor($mine["sapphire"] * $remaining_ratio),
            "diamond" => (int)floor($mine["diamond"] * $remaining_ratio)
        ];

        $occupier_names = array_values(array_unique(array_column($defenders, "username")));
        $defender_cards = [];
        foreach ($defenders as $d) {
            $defender_cards[] = [
                "id" => (int)$d["soldier_id"],
                "count" => (int)$d["soldiercount"]
            ];
        }

        $attacker_json = [
            "template" => "spy_mine",
            "success" => ($survivors > 0),
            "target_x" => $tx,
            "target_y" => $ty,
            "home_name" => $home_k->get_kingdom_name(),
            "home_x" => $home_k->get_kingdom_map_x(),
            "home_y" => $home_k->get_kingdom_map_y(),
            "mine_level" => (int)$mine["level"],
            "atk_scouts" => $atk_scouts,
            "losses" => $losses,
            "res_standard" => $res_standard,
            "res_special" => $res_special,
            "occupiers" => $occupier_names,
            "defenders" => $defender_cards
        ];

        send_server_message($attacker_id, $attacker_name, MessageCategories::CATEGORY_WAR, $attacker_json);

        $attacker_gid = $attacker_user->get_user_guild_id();
        $is_friendly_mine = ($attacker_id === (int)($mine["claimed_user_id"] ?? 0))
            || ($attacker_gid > 0 && $attacker_gid === (int)($mine["claimed_guild_id"] ?? 0));

        // Inform defenders of scouting
        if (!empty($defenders) && !$is_friendly_mine) {
            $def_users = [];
            foreach ($defenders as $d) {
                $def_users[(int)$d["user_id"]] = [
                    "username" => $d["username"],
                    "guildid" => (int)$d["guildid"]
                ];
            }

            $defender_json = [
                "template" => "spy_mine_detected",
                "attacker_name" => $attacker_name,
                "target_x" => $tx,
                "target_y" => $ty
            ];

            foreach ($def_users as $duid => $dinfo) {
                if (($duid === $attacker_id) || ($attacker_gid > 0 && $attacker_gid === $dinfo["guildid"])) {
                    continue;
                }

                send_server_message($duid, $dinfo["username"], MessageCategories::CATEGORY_WAR, $defender_json);
            }
        }

        if ($survivors > 0) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?, is_processing = 0 WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
        } else {
            $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$event_id]);
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        }

        update_player_stat($attacker_id, "spy_count");
    }
}
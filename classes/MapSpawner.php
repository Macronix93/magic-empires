<?php

class MapSpawner
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Database::get_instance()->get_connection();
    }

    public function spawn_resources(bool $fill_all = false, ?int $custom_limit = null): int
    {
        $now = time();

        $expired_tiles_res = $this->db->execute_query("SELECT mapx, mapy FROM resource_tiles_data WHERE expires_at < ?", [$now]);

        $tiles_to_delete = $expired_tiles_res->fetch_all(MYSQLI_ASSOC);
        if (!empty($tiles_to_delete)) {
            $em_dummy = new EventManager(new User(-1, "System"));

            $coords_queries = [];
            foreach ($tiles_to_delete as $tile) {
                $em_dummy->turn_back_incoming_resource_tile_troops((int)$tile['mapx'], (int)$tile['mapy']);
                $coords_queries[] = "(mapx = {$tile['mapx']} AND mapy = {$tile['mapy']})";
            }
            $where_clause = implode(' OR ', $coords_queries);

            $this->db->query("UPDATE map SET kingdomid = " . MapFieldTypes::MAP_FIELD_EMPTY . " WHERE kingdomid = " . MapFieldTypes::MAP_FIELD_RESOURCE_TILE . " AND ($where_clause)");
            $this->db->query("DELETE FROM resource_tiles_data WHERE $where_clause");
        }

        $this->db->query("
            UPDATE map m 
            LEFT JOIN resource_tiles_data r ON m.mapx = r.mapx AND m.mapy = r.mapy 
            SET m.kingdomid = " . MapFieldTypes::MAP_FIELD_EMPTY . " 
            WHERE m.kingdomid = " . MapFieldTypes::MAP_FIELD_RESOURCE_TILE . "  AND r.mapx IS NULL
        ");

        $res_count = $this->db->execute_query("SELECT COUNT(*) FROM map WHERE kingdomid = -2")->fetch_column();
        if ($res_count >= MAX_RESOURCE_TILES) {
            return 0;
        }

        $needed = MAX_RESOURCE_TILES - $res_count;
        if ($custom_limit !== null) {
            $limit = min($custom_limit, $needed);
        } elseif ($fill_all) {
            $limit = $needed;
        } else {
            $batch_limit = (int)ceil(RESOURCE_TILES_SPAWN_RATE / 24);
            $limit = min($batch_limit, $needed);
        }

        if ($limit <= 0) return 0;

        $query_free_res = "
            SELECT m.mapx, m.mapy FROM map m 
            WHERE m.kingdomid = ? 
            AND NOT EXISTS (
                SELECT 1 FROM events e 
                WHERE e.actionid = ? 
                AND e.targetid = ? 
                AND e.targetx = m.mapx 
                AND e.targety = m.mapy
            ) 
            ORDER BY RAND() LIMIT ?
        ";
        $fields = $this->db->execute_query($query_free_res,
            [MapFieldTypes::MAP_FIELD_EMPTY, ActionTypes::ACTION_SEND_TROOPS, MapFieldTypes::MAP_FIELD_EMPTY, $limit]);

        if ($fields->num_rows > 0) {
            $insert_values = [];
            $update_coords = [];

            foreach ($fields as $f) {
                $x = (int)$f["mapx"];
                $y = (int)$f["mapy"];

                $expires = time() + mt_rand(SPAWN_LIFETIME_MIN * 86400, SPAWN_LIFETIME_MAX * 86400);
                $total = mt_rand(MIN_RESOURCES_PER_TILE, MAX_RESOURCES_PER_TILE);

                $res_values = ["food" => 0, "wood" => 0, "stone" => 0, "gold" => 0];
                $active_keys = [];

                foreach ($res_values as $key => $val) {
                    if (mt_rand(1, 100) <= 70) $active_keys[] = $key;
                }

                if (empty($active_keys)) $active_keys[] = array_rand($res_values);

                if (in_array("gold", $active_keys)) {
                    // Gold generation is 20% "richer" than the rest
                    $total = (int)($total * 1.2);
                }

                $temp_total = $total;
                $count = count($active_keys);

                for ($i = 0; $i < $count; $i++) {
                    $key = $active_keys[$i];

                    if ($i == $count - 1) {
                        $res_values[$key] = $temp_total;
                    } else {
                        // Random portion
                        $min_share = 10;
                        $max_share = 80;

                        if ($key === "gold") {
                            $min_share = 50;
                            $max_share = 90;
                        }

                        $share = mt_rand($min_share, $max_share) / 100;
                        $val = (int)($temp_total * $share);

                        $res_values[$key] = $val;
                        $temp_total -= $val;
                    }
                }

                $insert_values[] = "($x, $y, {$res_values["food"]}, {$res_values["wood"]}, {$res_values["stone"]}, {$res_values["gold"]}, $expires)";
                $update_coords[] = "($x, $y)";
            }
            if (!empty($insert_values)) {
                $sql_insert = "INSERT INTO resource_tiles_data (mapx, mapy, food, wood, stone, gold, expires_at) VALUES " . implode(', ', $insert_values);
                $this->db->execute_query($sql_insert);
            }
            if (!empty($update_coords)) {
                $coords_string = implode(',', $update_coords);

                $sql_update = "UPDATE map SET kingdomid = ? WHERE kingdomid = ? AND (mapx, mapy) IN ($coords_string)";
                $this->db->execute_query($sql_update, [MapFieldTypes::MAP_FIELD_RESOURCE_TILE, MapFieldTypes::MAP_FIELD_EMPTY]);
            }
            return count($update_coords);
        }
        return 0;
    }

    public function spawn_mines(bool $fill_all = false, ?int $custom_limit = null): int
    {
        $now = time();

        // Delete mines that aren't on the map anymore (1:1 aus cron_spawn_entities.php)
        $this->db->execute_query("DELETE FROM mines WHERE expires_at < ? AND id NOT IN (SELECT DISTINCT mine_id FROM mine_stationed_troops)", [$now]);
        $this->db->execute_query(
            "UPDATE map SET kingdomid = ? WHERE kingdomid = ? AND (mapx, mapy) NOT IN (SELECT mapx, mapy FROM mines)",
            [MapFieldTypes::MAP_FIELD_EMPTY, MapFieldTypes::MAP_FIELD_MINE]
        );

        $current_mines = (int)$this->db->execute_query("SELECT COUNT(*) FROM map WHERE kingdomid = " . MapFieldTypes::MAP_FIELD_MINE)->fetch_column();
        if ($current_mines >= MAX_MINES) {
            return 0;
        }

        $needed = MAX_MINES - $current_mines;
        if ($custom_limit !== null) {
            $limit = min($custom_limit, $needed);
        } elseif ($fill_all) {
            $limit = $needed;
        } else {
            $batch_limit = (int)ceil(MINE_SPAWN_RATE / 24);
            $limit = min($batch_limit, $needed);
        }

        if ($limit <= 0) return 0;

        $count_mines_res = $this->db->query("
            SELECT 
                SUM(IF(level = 1, 1, 0)) AS lvl1, 
                SUM(IF(level = 2, 1, 0)) AS lvl2, 
                SUM(IF(level = 3, 1, 0)) AS lvl3, 
                SUM(IF(level = 4, 1, 0)) AS lvl4, 
                SUM(IF(level = 5, 1, 0)) AS lvl5 
            FROM mines 
        ")->fetch_assoc();

        $current_mine_counts = [
            1 => (int)($count_mines_res["lvl1"] ?? 0),
            2 => (int)($count_mines_res["lvl2"] ?? 0),
            3 => (int)($count_mines_res["lvl3"] ?? 0),
            4 => (int)($count_mines_res["lvl4"] ?? 0),
            5 => (int)($count_mines_res["lvl5"] ?? 0)
        ];
        $mine_targets = [
            1 => MAX_MINES * MINE_WEIGHT_LVL_1,
            2 => MAX_MINES * MINE_WEIGHT_LVL_2,
            3 => MAX_MINES * MINE_WEIGHT_LVL_3,
            4 => MAX_MINES * MINE_WEIGHT_LVL_4,
            5 => MAX_MINES * MINE_WEIGHT_LVL_5
        ];

        $free_fields = $this->db->execute_query("
            SELECT m.mapx, m.mapy FROM map m 
            WHERE m.kingdomid = ? 
            AND NOT EXISTS (
                SELECT 1 FROM events e 
                WHERE e.actionid = ? AND e.targetid = ? AND e.targetx = m.mapx AND e.targety = m.mapy
            ) 
            ORDER BY RAND() LIMIT ?
        ", [MapFieldTypes::MAP_FIELD_EMPTY, ActionTypes::ACTION_SEND_TROOPS, MapFieldTypes::MAP_FIELD_EMPTY, $limit]);

        if ($free_fields->num_rows > 0) {
            $insert_mines = [];
            $update_coords = [];

            foreach ($free_fields as $f) {
                $x = (int)$f["mapx"];
                $y = (int)$f["mapy"];

                $fill_grades = [];
                foreach ($mine_targets as $m_lvl => $target_val) {
                    $fill_grades[$m_lvl] = ($target_val > 0) ? $current_mine_counts[$m_lvl] / $target_val : 1;
                }
                asort($fill_grades);
                $lvl = (int)array_key_first($fill_grades);

                $current_mine_counts[$lvl]++;

                $max_troops = MINE_CAPACITY;
                $work_total = MINE_WORK_BY_LEVEL[$lvl];
                $base_res = MINE_BASE_RESOURCES_BY_LEVEL[$lvl];
                $guild_res = MINE_GUILD_RESOURCES_BY_LEVEL[$lvl];

                $variance = function (int $val) {
                    if ($val <= 0) return 0;
                    $pct = mt_rand(MINE_RESOURCE_MIN_RANGE, MINE_RESOURCE_MAX_RANGE) / 100;
                    return max(1, (int)round($val * $pct));
                };

                $stone = 0;
                $gold = 0;

                if (mt_rand(0, 1) === 0) {
                    $stone = $variance($base_res);
                } else {
                    $gold = $variance($base_res);
                }

                $all_specials = ["coal", "iron", "sapphire", "diamond"];

                $available_specials = [];
                foreach ($all_specials as $k) {
                    if (($guild_res[$k] ?? 0) > 0) $available_specials[] = $k;
                }

                if (count($available_specials) < 2) {
                    $available_specials = ["coal", "iron"];
                }
                shuffle($available_specials);

                $num_to_pick = min(count($available_specials), mt_rand(2, 4));
                $active_specials = array_slice($available_specials, 0, $num_to_pick);

                $coal = 0;
                $iron = 0;
                $sapphire = 0;
                $diamond = 0;

                foreach ($active_specials as $s_key) {
                    $base_val = $guild_res[$s_key] > 0 ? $guild_res[$s_key] : 150;
                    $$s_key = $variance($base_val);
                }

                $expires = $now + mt_rand(MINE_LIFETIME_MIN * 86400, MINE_LIFETIME_MAX * 86400);

                $insert_mines[] = "($x, $y, $lvl, $max_troops, $stone, $gold, $coal, $iron, $sapphire, $diamond, $work_total, $expires)";
                $update_coords[] = "($x, $y)";
            }

            if (!empty($insert_mines)) {
                $this->db->query("INSERT INTO mines (mapx, mapy, level, max_troops, stone, gold, coal, iron, sapphire, diamond, work_total, expires_at) VALUES " . implode(',', $insert_mines));
                
                $coords_string = implode(',', $update_coords);
                $this->db->execute_query(
                    "UPDATE map SET kingdomid = ? WHERE (mapx, mapy) IN ($coords_string)",
                    [MapFieldTypes::MAP_FIELD_MINE]
                );
            }

            return count($insert_mines);
        }
        return 0;
    }

    public function spawn_monster_camps(bool $fill_all = false, ?int $custom_limit = null): int
    {
        $now = time();

        // Delete camps that aren't on the map anymore (1:1 aus cron_daily.php)
        $expired_camps_res = $this->db->execute_query("SELECT mapx, mapy FROM monster_camps WHERE expires_at < ?", [$now]);
        $camps_to_delete = $expired_camps_res->fetch_all(MYSQLI_ASSOC);

        if (!empty($camps_to_delete)) {
            $coords_queries = [];
            foreach ($camps_to_delete as $camp) {
                $coords_queries[] = "(mapx = {$camp['mapx']} AND mapy = {$camp['mapy']})";
            }
            $where_clause = implode(' OR ', $coords_queries);
            $this->db->query("UPDATE map SET kingdomid = " . MapFieldTypes::MAP_FIELD_EMPTY . " WHERE kingdomid = " . MapFieldTypes::MAP_FIELD_MONSTER_CAMP . " AND ($where_clause)");
            $this->db->query("DELETE FROM monster_camps WHERE $where_clause");
        }

        $this->db->query("
            UPDATE map m 
            LEFT JOIN monster_camps mc ON m.mapx = mc.mapx AND m.mapy = mc.mapy 
            SET m.kingdomid = " . MapFieldTypes::MAP_FIELD_EMPTY . "  
            WHERE m.kingdomid = " . MapFieldTypes::MAP_FIELD_MONSTER_CAMP . " AND mc.mapx IS NULL
        ");

        $count_res = $this->db->execute_query("
            SELECT 
                SUM(IF(level BETWEEN 1 AND 3, 1, 0)) as low,
                SUM(IF(level BETWEEN 4 AND 6, 1, 0)) as mid,
                SUM(IF(level BETWEEN 7 AND 9, 1, 0)) as high,
                SUM(IF(level = 10, 1, 0)) as boss,
                COUNT(*) as total
            FROM monster_camps
        ")->fetch_assoc();

        $current_counts = [
            "low" => (int)($count_res["low"] ?? 0),
            "mid" => (int)($count_res["mid"] ?? 0),
            "high" => (int)($count_res["high"] ?? 0),
            "boss" => (int)($count_res["boss"] ?? 0)
        ];
        $total_on_map = (int)($count_res["total"] ?? 0);
        if ($total_on_map >= MAX_MONSTER_CAMPS) {
            return 0;
        }

        $needed = MAX_MONSTER_CAMPS - $total_on_map;
        if ($custom_limit !== null) {
            $limit = min($custom_limit, $needed);
        } else if ($fill_all) {
            $limit = $needed;
        } else {
            $limit = min(MONSTER_CAMP_SPAWN_RATE, $needed);
        }

        if ($limit <= 0) return 0;

        // Load Monster Data
        $monster_pool = [];
        $res_all_m = $this->db->execute_query("SELECT id, level FROM monster_list");
        while ($m = $res_all_m->fetch_assoc()) {
            $monster_pool[(int)$m["level"]][] = (int)$m["id"];
        }

        // Search free fields
        $query_free_camps = "
            SELECT m.mapx, m.mapy FROM map m 
            WHERE m.kingdomid = ? 
            AND NOT EXISTS (
                SELECT 1 FROM events e 
                WHERE e.actionid = ? 
                AND e.targetid = ? 
                AND e.targetx = m.mapx 
                AND e.targety = m.mapy
            ) 
            ORDER BY RAND() LIMIT ?
        ";
        $free_fields = $this->db->execute_query($query_free_camps,
            [MapFieldTypes::MAP_FIELD_EMPTY, ActionTypes::ACTION_SEND_TROOPS, MapFieldTypes::MAP_FIELD_EMPTY, $limit]);

        if ($free_fields->num_rows > 0) {
            $insert_camps = [];
            $insert_units = [];
            $update_map_coords = [];
            $targets = [
                "low" => MAX_MONSTER_CAMPS * MONSTER_CAMP_WEIGHT_LOW,
                "mid" => MAX_MONSTER_CAMPS * MONSTER_CAMP_WEIGHT_MID,
                "high" => MAX_MONSTER_CAMPS * MONSTER_CAMP_WEIGHT_HIGH,
                "boss" => MAX_MONSTER_CAMPS * MONSTER_CAMP_WEIGHT_BOSS
            ];

            foreach ($free_fields as $f) {
                $x = (int)$f["mapx"];
                $y = (int)$f["mapy"];

                $fill_grades = [];
                foreach ($targets as $key => $targetValue) {
                    $fill_grades[$key] = $current_counts[$key] / $targetValue;
                }
                asort($fill_grades);
                $chosen_group = array_key_first($fill_grades);

                if ($chosen_group == "low") $camp_level = mt_rand(1, 3);
                else if ($chosen_group == "mid") $camp_level = mt_rand(4, 6);
                else if ($chosen_group == "high") $camp_level = mt_rand(7, 9);
                else                              $camp_level = 10;
                $current_counts[$chosen_group]++;

                $expires = time() + mt_rand(SPAWN_LIFETIME_MIN * 86400, SPAWN_LIFETIME_MAX * 86400);
                $insert_camps[] = "($x, $y, $camp_level, $expires)";
                $update_map_coords[] = "($x, $y)";

                // Unit Generation
                if (!empty($monster_pool[$camp_level])) {
                    $this_camp_types = [];
                    $main_m_id = $monster_pool[$camp_level][array_rand($monster_pool[$camp_level])];
                    $this_camp_types[$main_m_id] = mt_rand(MIN_NUM_MONSTERS_PER_TYPE, MAX_NUM_MONSTERS_PER_TYPE);

                    if ($camp_level >= 10) {
                        $num_extra_types = 4;
                    } else if ($camp_level >= 7) {
                        $num_extra_types = mt_rand(3, 4);
                    } else if ($camp_level >= 5) {
                        $num_extra_types = mt_rand(2, 4);
                    } else {
                        $num_extra_types = ($camp_level <= 3)
                            ? mt_rand(MIN_MONSTER_CAMP_EXTRA_SLOTS_LOW, MAX_MONSTER_CAMP_EXTRA_SLOTS_LOW)
                            : mt_rand(MIN_MONSTER_CAMP_EXTRA_SLOTS_HIGH, MAX_MONSTER_CAMP_EXTRA_SLOTS_HIGH);
                    }

                    $min_allowed_lvl = max(1, $camp_level - MONSTER_CAMP_EXTRA_LEVEL_CAP);
                    $possible_levels = range($min_allowed_lvl, $camp_level);

                    for ($i = 0; $i < $num_extra_types; $i++) {
                        $rand_lvl = $possible_levels[array_rand($possible_levels)];

                        if (!empty($monster_pool[$rand_lvl])) {
                            $extra_m_id = $monster_pool[$rand_lvl][array_rand($monster_pool[$rand_lvl])];
                            $count_roll = mt_rand(MONSTER_CAMP_EXTRA_MONSTER - 4, MONSTER_CAMP_EXTRA_MONSTER + 4);

                            if (!isset($this_camp_types[$extra_m_id])) $this_camp_types[$extra_m_id] = 0;

                            $this_camp_types[$extra_m_id] += $count_roll;
                        }
                    }

                    foreach ($this_camp_types as $m_id => $m_count) {
                        $insert_units[] = "($x, $y, $m_id, $m_count, $m_count)";
                    }
                }
            }

            // Batch-Execution
            if (!empty($insert_camps)) {
                $this->db->query("INSERT INTO monster_camps (mapx, mapy, level, expires_at) VALUES " . implode(',', $insert_camps));

                $coords_string = implode(',', $update_map_coords);
                $this->db->execute_query(
                    "UPDATE map SET kingdomid = ? WHERE kingdomid = ? AND (mapx, mapy) IN ($coords_string)",
                    [MapFieldTypes::MAP_FIELD_MONSTER_CAMP, MapFieldTypes::MAP_FIELD_EMPTY]
                );

                if (!empty($insert_units)) {
                    $this->db->query("INSERT INTO monster_camp_units (mapx, mapy, monster_id, count, initial_count) VALUES " . implode(',', $insert_units) . " 
                                            ON DUPLICATE KEY UPDATE count = count + VALUES(count), initial_count = initial_count + VALUES(initial_count)");
                }
            }
            return count($insert_camps);
        }
        return 0;
    }
}
<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once(__DIR__ . "/../includes/core.php");

$db = Database::get_instance()->get_connection();
$now = time();

// Delete mines that aren't on the map anymore
$db->execute_query("DELETE FROM mines WHERE expires_at < ? AND id NOT IN (SELECT DISTINCT mine_id FROM mine_stationed_troops)", [$now]);
$db->query("UPDATE map SET kingdomid = -1 WHERE kingdomid = " . MapFieldTypes::MAP_FIELD_MINE . " AND (mapx, mapy) NOT IN (SELECT mapx, mapy FROM mines)");

$current_mines = (int)$db->execute_query("SELECT COUNT(*) FROM map WHERE kingdomid = " . MapFieldTypes::MAP_FIELD_MINE)->fetch_column();

if ($current_mines < MAX_MINES) {
    $needed = MAX_MINES - $current_mines;

    $batch_limit = (int)ceil(MINE_SPAWN_RATE / 24);
    $limit = min($batch_limit, $needed);

    $count_mines_res = $db->query("
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

    $free_fields = $db->execute_query("
        SELECT m.mapx, m.mapy FROM map m
        WHERE m.kingdomid = -1
        AND NOT EXISTS (SELECT 1 FROM events e WHERE e.actionid = ? AND e.targetid = -1 AND e.targetx = m.mapx AND e.targety = m.mapy)
        ORDER BY RAND() LIMIT ?", [ActionTypes::ACTION_SEND_TROOPS, $limit]);

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
            $db->query("INSERT INTO mines (mapx, mapy, level, max_troops, stone, gold, coal, iron, sapphire, diamond, work_total, expires_at) VALUES " . implode(',', $insert_mines));
            $db->query("UPDATE map SET kingdomid = " . MapFieldTypes::MAP_FIELD_MINE . " WHERE (mapx, mapy) IN (" . implode(',', $update_coords) . ")");
        }
        echo "[" . date("H:i:s") . "] " . count($insert_mines) . " Minen balance-optimiert platziert.\n";
    }
}

// Cleanup if a resource field doesn't have any resources left
$expired_tiles_res = $db->execute_query("SELECT mapx, mapy FROM resource_tiles_data WHERE expires_at < ?", [$now]);
$tiles_to_delete = $expired_tiles_res->fetch_all(MYSQLI_ASSOC);

if (!empty($tiles_to_delete)) {
    $coords_queries = [];
    foreach ($tiles_to_delete as $tile) {
        $coords_queries[] = "(mapx = {$tile['mapx']} AND mapy = {$tile['mapy']})";
    }
    $where_clause = implode(' OR ', $coords_queries);
    $db->query("UPDATE map SET kingdomid = -1 WHERE kingdomid = -2 AND ($where_clause)");
    $db->query("DELETE FROM resource_tiles_data WHERE $where_clause");
}

$db->query("
    UPDATE map m 
    LEFT JOIN resource_tiles_data r ON m.mapx = r.mapx AND m.mapy = r.mapy 
    SET m.kingdomid = -1 
    WHERE m.kingdomid = -2 AND r.mapx IS NULL
");

// CLEANUP FINISHED //

// Spawn resource tiles
$res_count = $db->execute_query("SELECT COUNT(*) FROM map WHERE kingdomid = -2")->fetch_column();

if ($res_count < MAX_RESOURCE_TILES) {
    $needed = MAX_RESOURCE_TILES - $res_count;
    $batch_limit = (int)ceil(RESOURCE_TILES_SPAWN_RATE / 24);
    $limit = min($batch_limit, $needed);

    $query_free_res = "
        SELECT m.mapx, m.mapy FROM map m 
        WHERE m.kingdomid = -1 
        AND NOT EXISTS (
            SELECT 1 FROM events e 
            WHERE e.actionid = 2 
            AND e.targetid = -1 
            AND e.targetx = m.mapx 
            AND e.targety = m.mapy
        )
        ORDER BY RAND() LIMIT ?
    ";
    $fields = $db->execute_query($query_free_res, [$limit]);

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
                // 70% chance that the resource exists
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
            $db->execute_query($sql_insert);
        }

        if (!empty($update_coords)) {
            $coords_string = implode(',', $update_coords);
            $sql_update = "UPDATE map SET kingdomid = -2 WHERE kingdomid = -1 AND (mapx, mapy) IN ($coords_string)";
            $db->execute_query($sql_update);
        }
    }

    echo "[" . date("H:i:s") . "] " . $limit . " neue Rohstofffelder per Batch generiert.\n";
}
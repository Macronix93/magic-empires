<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once("../includes/core.php");

$db = Database::get_instance()->get_connection();

$now = time();
$activity_threshold = $now - INACTIVITY_DELAY;

$query_active_user = "SELECT id, username FROM users 
                      WHERE status = 1 
                        AND is_banned = 0 
                        AND lastactivity > ? 
                      ORDER BY RAND() LIMIT 1";

$res = $db->execute_query($query_active_user, [$activity_threshold]);
$winner = $res->fetch_assoc();

if (!$winner) {
    $query_fallback = "SELECT id, username FROM users 
                       WHERE status = 1 AND is_banned = 0 
                       ORDER BY RAND() LIMIT 1";

    $res = $db->execute_query($query_fallback);
    $winner = $res->fetch_assoc();
}

if ($winner) {
    $uid = $winner["id"];
    $uname = $winner["username"];

    $res_k = $db->execute_query("SELECT id, kingdomname FROM kingdoms WHERE userid = ? ORDER BY RAND() LIMIT 1", [$uid]);
    $k_data = $res_k->fetch_assoc();

    if ($k_data) {
        $kid = $k_data["id"];
        $kname = $k_data["kingdomname"];

        $res_hero_name = $db->execute_query("SELECT soldiername FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_HERO]);
        $hero_db_name = $res_hero_name->fetch_column() ?: 'Held';

        $db->execute_query("INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
                            VALUES (?, ?, ?, 1) 
                            ON DUPLICATE KEY UPDATE soldiercount = soldiercount + 1",
            [$kid, Soldiers::SOLDIER_HERO, $hero_db_name]);

        $text = "<div style='margin: 10px; text-align: center;'><img src='images/icons/icon_hero.png' alt='Held'></div>";
        $text .= "Ein legendärer <b>Held</b> hat von deinen Taten gehört und sich entschlossen, deinem Königreich <b>" . e($kname) . "</b> beizutreten!";

        $msg = "<div class='battle-report'>";
        $msg .= BattleReportRenderer::render_outcome_box(
            "Göttliche Fügung",
            $text,
            0, 0,
            "Er steht ab sofort in deiner Garnison zur Verfügung.",
            "success"
        );
        $msg .= "</div>";

        send_server_message($uid, $uname, $msg);

        echo "[" . date("H:i:s") . "] Held vergeben an $uname im Königreich $kname (ID: $kid)\n";
    }
} else {
    echo "[" . date("H:i:s") . "] Abbruch: Kein einziger berechtigter Spieler mit Königreich in der DB.\n";
}

// Support Cleanup
$delete_limit = $now - (SUPPORT_TICKET_AUTO_DELETE_DAYS * 86400);
$db->execute_query("DELETE FROM support_tickets WHERE status = 0 AND closed_at < ?", [$delete_limit]);

$deleted_count = $db->affected_rows;
if ($deleted_count > 0) {
    echo "[" . date("H:i:s") . "] Support-Cleanup: $deleted_count alte Tickets gelöscht.\n";
}

//// Generate resource tiles
// Delete camps that aren't on the map anymore
$expired_camps_res = $db->execute_query("SELECT mapx, mapy FROM monster_camps WHERE expires_at < ?", [$now]);
$camps_to_delete = $expired_camps_res->fetch_all(MYSQLI_ASSOC);

if (!empty($camps_to_delete)) {
    $coords_queries = [];
    foreach ($camps_to_delete as $camp) {
        $coords_queries[] = "(mapx = {$camp['mapx']} AND mapy = {$camp['mapy']})";
    }
    $where_clause = implode(' OR ', $coords_queries);

    $db->query("UPDATE map SET kingdomid = -1 WHERE kingdomid = -3 AND ($where_clause)");
    $db->query("DELETE FROM monster_camps WHERE $where_clause");
}

$orphaned_camps = $db->query("
    SELECT m.mapx, m.mapy FROM map m 
    LEFT JOIN monster_camps mc ON m.mapx = mc.mapx AND m.mapy = mc.mapy 
    WHERE m.kingdomid = -3 AND mc.mapx IS NULL
");
$orphans_c = $orphaned_camps->fetch_all(MYSQLI_ASSOC);
if (!empty($orphans_c)) {
    foreach ($orphans_c as $oc) {
        $db->execute_query("UPDATE map SET kingdomid = -1 WHERE mapx = ? AND mapy = ?", [$oc['mapx'], $oc['mapy']]);
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

$orphaned_res = $db->query("
    SELECT m.mapx, m.mapy FROM map m 
    LEFT JOIN resource_tiles_data r ON m.mapx = r.mapx AND m.mapy = r.mapy 
    WHERE m.kingdomid = -2 AND r.mapx IS NULL
");
$orphans = $orphaned_res->fetch_all(MYSQLI_ASSOC);
if (!empty($orphans)) {
    foreach ($orphans as $o) {
        $db->execute_query("UPDATE map SET kingdomid = -1 WHERE mapx = ? AND mapy = ?", [$o['mapx'], $o['mapy']]);
    }
}

// CLEANUP FINISHED //

// Spawn resource tiles
$res_count = $db->execute_query("SELECT COUNT(*) FROM map WHERE kingdomid = -2")->fetch_column();

if ($res_count < MAX_RESOURCE_TILES) {
    $needed = MAX_RESOURCE_TILES - $res_count;
    $limit = min(RESOURCE_TILES_SPAWN_RATE, $needed);

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

            $temp_total = $total;
            $count = count($active_keys);

            for ($i = 0; $i < $count; $i++) {
                $key = $active_keys[$i];

                if ($i == $count - 1) {
                    $res_values[$key] = $temp_total;
                } else {
                    // Random portion
                    $share = mt_rand(10, 80) / 100;
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

//// Generate Monstercamps
$count_res = $db->execute_query("
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

if ($total_on_map < MAX_MONSTER_CAMPS) {
    $needed = MAX_MONSTER_CAMPS - $total_on_map;
    $limit = min(MONSTER_CAMP_SPAWN_RATE, $needed);

    // Load Monster Data
    $monster_pool = [];
    $res_all_m = $db->execute_query("SELECT id, level FROM monster_list");
    while ($m = $res_all_m->fetch_assoc()) {
        $monster_pool[(int)$m["level"]][] = (int)$m["id"];
    }

    // Search free fields
    $query_free_camps = "
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
    $free_fields = $db->execute_query($query_free_camps, [$limit]);

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
                    $num_extra_types = 4; // At least 5 groups
                } else if ($camp_level >= 7) {
                    $num_extra_types = mt_rand(3, 4); // At least 4 groups
                } else if ($camp_level >= 5) {
                    $num_extra_types = mt_rand(2, 4); // At least 3 groups
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
                    $insert_units[] = "($x, $y, $m_id, $m_count)";
                }
            }
        }

        // Batch-Execution
        if (!empty($insert_camps)) {
            $db->query("INSERT INTO monster_camps (mapx, mapy, level, expires_at) VALUES " . implode(',', $insert_camps));

            $coords_string = implode(',', $update_map_coords);
            $db->query("UPDATE map SET kingdomid = -3 WHERE kingdomid = -1 AND (mapx, mapy) IN ($coords_string)");

            if (!empty($insert_units)) {
                $db->query("INSERT INTO monster_camp_units (mapx, mapy, monster_id, count) VALUES " . implode(',', $insert_units) . " 
                    ON DUPLICATE KEY UPDATE count = count + VALUES(count)");
            }
        }
        echo "[" . date("H:i:s") . "] " . count($insert_camps) . " Monstercamps balance-optimiert generiert.\n";
    }
}

//// World Event Logic
$we_logic = new WorldEvent($db);

$finished_events = $db->execute_query("SELECT * FROM world_events WHERE is_rewarded = 0 AND end_time <= ?", [time()]);

while ($ev = $finished_events->fetch_assoc()) {
    $pool = $we_logic->get_monster_pool();
    $monster = $pool[$ev["monster_index"]] ?? $pool[0];

    $participants = $db_instance->execute_query("
        SELECT p.*, u.username, u.mainkingdom 
        FROM world_event_participants p 
        JOIN users u ON p.userid = u.id 
        WHERE p.event_id = ?", [$ev["id"]]);

    while ($p = $participants->fetch_assoc()) {
        $u_id = (int)$p["userid"];
        $u_name = $p["username"];
        $is_boss_fail = false;

        $actual_target_kid = $we_logic->get_valid_delivery_kingdom($u_id, (int)$p["top_kingdom_id"]);

        if ($ev["event_type"] === "BOSS_HP") {
            if ($ev["current_hp"] <= 0) {
                if ($actual_target_kid > 0) {
                    $target_k_obj = new Kingdom($db_instance, $actual_target_kid);
                    $loot = $we_logic->generate_hp_boss_loot($u_id);

                    // Resources
                    foreach ($loot["resources"] as $res_id => $amount) {
                        $target_k_obj->modify_resource($res_id, $amount);
                    }

                    // Soldiers & Score
                    $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";
                    $total_score_gain = 0;

                    foreach ($loot["soldiers"] as $s) {
                        $db_instance->execute_query("
                            INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount)
                            SELECT ?, id, soldiername, ? FROM soldier_list WHERE id = ?
                            ON DUPLICATE KEY UPDATE soldiercount = soldiercount + VALUES(soldiercount)
                        ", [$actual_target_kid, $s["count"], $s["id"]]);

                        $unit_data = $db_instance->execute_query("SELECT soldiername, icon, scoregain FROM soldier_list WHERE id = ?", [$s["id"]])->fetch_assoc();
                        $total_score_gain += ($s["count"] * (int)$unit_data["scoregain"]);

                        $units_html .= BattleReportRenderer::render_unit_card($unit_data["soldiername"], $s["count"], 0, $unit_data["icon"], true);
                    }
                    $units_html .= "</div>";

                    if ($total_score_gain > 0) {
                        $db_instance->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$total_score_gain, $u_id]);
                    }

                    $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                            "Event-Abschluss",
                            "Deine Truppen aus <b>" . e($target_k_obj->get_kingdom_name()) . "</b> waren am Sieg beteiligt! Du erhältst folgende Belohnungen:" . $units_html,
                            0, 0,
                            "",
                            "success",
                            $loot["resources"]
                        ) . "</div>";

                    send_server_message($u_id, $u_name, $msg, MessageCategories::CATEGORY_EVENT);
                }
            } else {
                $is_boss_fail = true;
            }

        } else if ($ev["event_type"] === "DAMAGE") {
            // DAMAGE EVENT
            $loot = $we_logic->generate_dmg_event_loot((int)$p["total_damage"]);

            $recipient = new User($u_id, $u_name);

            if ($actual_target_kid > 0) {
                $target_k_obj = new Kingdom($db_instance, $actual_target_kid);

                if ($loot["coins"] > 0) $recipient->give_user_coins($loot["coins"]);
                if ($loot["gold_res"] > 0) $target_k_obj->modify_resource(ResourceTypes::RESOURCE_TYPE_GOLD, $loot["gold_res"]);

                $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                        "Event-Abschluss",
                        "Für deinen Gesamtschaden von <b>" . fnum($p["total_damage"]) . "</b> erhältst du Belohnungen:",
                        0, 0,
                        "",
                        "neutral",
                        [ResourceTypes::RESOURCE_TYPE_GOLD => $loot["gold_res"], ResourceTypes::RESOURCE_TYPE_COINS => $loot["coins"]]
                    ) . "</div>";

                send_server_message($u_id, $u_name, $msg, MessageCategories::CATEGORY_EVENT);
            } else {
                send_server_message($u_id, $u_name, "Deine Belohnungsmünzen ({$loot["coins"]}) wurden gutgeschrieben. Ressourcen-Loot verfiel mangels Königreich.",
                    MessageCategories::CATEGORY_EVENT);
            }
        }

        if ($is_boss_fail) {
            $fail_text = "Das Zeitlimit ist abgelaufen und das Monster <b>" . e($monster["name"]) . "</b> konnte in die Schatten entkommen!";
            $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Event beendet",
                    $fail_text,
                    0, 0,
                    "Ohne den finalen Schlag konnten keine Schätze geborgen werden. Bereitet euch besser auf das nächste Mal vor!",
                    "error"
                ) . "</div>";

            send_server_message($u_id, $u_name, $msg, MessageCategories::CATEGORY_EVENT);
        }
    }

    $db_instance->execute_query("UPDATE world_events SET is_rewarded = 1, is_active = 0 WHERE id = ?", [$ev["id"]]);
}

// Cleanup old events
$deleted_events = $we_logic->cleanup_old_events();
if ($deleted_events > 0) {
    echo "[" . date("H:i:s") . "] Cleanup: $deleted_events alte Welt-Events aus der Datenbank entfernt.\n";
}
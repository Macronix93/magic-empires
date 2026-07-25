<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once("../includes/core.php");

$db = Database::get_instance()->get_connection();

$activity_threshold = time() - INACTIVITY_DELAY;

$query_active = "SELECT u.id, u.username, u.mainkingdom, k.kingdomname 
          FROM users u 
          JOIN kingdoms k ON u.mainkingdom = k.id 
          WHERE u.status = 1 
            AND u.is_banned = 0 
            AND u.lastactivity > ? 
          ORDER BY RAND() LIMIT 1";

$res = $db->execute_query($query_active, [$activity_threshold]);
$winner = $res->fetch_assoc();

if (!$winner) {
    echo "Kein aktiver Spieler gefunden. Wähle aus allen berechtigten Spielern...\n";

    $query_fallback = "SELECT u.id, u.username, u.mainkingdom, k.kingdomname 
          FROM users u 
          JOIN kingdoms k ON u.mainkingdom = k.id 
          WHERE u.status = 1 
            AND u.is_banned = 0 
          ORDER BY RAND() LIMIT 1";

    $res = $db->execute_query($query_fallback);
    $winner = $res->fetch_assoc();
}

if ($winner) {
    $uid = $winner["id"];
    $kid = $winner["mainkingdom"];
    $kname = $winner["kingdomname"];
    $uname = $winner["username"];

    $res_hero_name = $db->execute_query("SELECT soldiername FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_HERO]);
    $hero_db_name = $res_hero_name->fetch_column() ?: 'Held';

    $db->execute_query("INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
                        VALUES (?, ?, ?, 1) 
                        ON DUPLICATE KEY UPDATE soldiercount = soldiercount + 1",
        [$kid, Soldiers::SOLDIER_HERO, $hero_db_name]);

    $msg = "✨ <b>Göttliche Fügung!</b> ✨<br>Ein legendärer Held hat von deinen Taten gehört und sich entschlossen, deinem Königreich <b>" . e($kname) . "</b> beizutreten!";
    send_server_message($uid, $uname, $msg);

    echo "[" . date("H:i:s") . "] Held vergeben an $uname im Königreich $kname (ID: $kid)\n";
} else {
    echo "[" . date("H:i:s") . "] Abbruch: Kein einziger berechtigter Spieler mit Königreich in der DB.\n";
}

// Support Cleanup
$delete_limit = time() - (SUPPORT_TICKET_AUTO_DELETE_DAYS * 86400);
$db->execute_query("DELETE FROM support_tickets WHERE status = 0 AND closed_at < ?", [$delete_limit]);

$deleted_count = $db->affected_rows;
if ($deleted_count > 0) {
    echo "[" . date("H:i:s") . "] Support-Cleanup: $deleted_count alte Tickets gelöscht.\n";
}

//// Generate resource tiles

// First cleanup if a resource field doesn't have any resources left
$db->execute_query("
    UPDATE map m
    LEFT JOIN resource_tiles_data r ON m.mapx = r.mapx AND m.mapy = r.mapy
    SET m.kingdomid = -1
    WHERE m.kingdomid = -2 AND r.mapx IS NULL
");

$res_count = $db->execute_query("SELECT COUNT(*) FROM map WHERE kingdomid = -2")->fetch_column();

if ($res_count < MAX_RESOURCE_TILES) {
    $needed = MAX_RESOURCE_TILES - $res_count;
    $limit = min(RESOURCE_TILES_SPAWN_RATE, $needed);

    $fields = $db->execute_query("SELECT mapx, mapy FROM map WHERE kingdomid = -1 ORDER BY RAND() LIMIT ?", [$limit]);

    if ($fields->num_rows > 0) {
        $insert_values = [];
        $update_coords = [];

        foreach ($fields as $f) {
            $x = (int)$f["mapx"];
            $y = (int)$f["mapy"];

            $total = mt_rand(MIN_RESOURCES_FOR_TILE, MAX_RESOURCES_FOR_TILE);
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

            $insert_values[] = "($x, $y, {$res_values["food"]}, {$res_values["wood"]}, {$res_values["stone"]}, {$res_values["gold"]})";
            $update_coords[] = "(mapx = $x AND mapy = $y)";
        }

        if (!empty($insert_values)) {
            $sql_insert = "INSERT INTO resource_tiles_data (mapx, mapy, food, wood, stone, gold) VALUES " . implode(', ', $insert_values);
            $db->execute_query($sql_insert);
        }

        if (!empty($update_coords)) {
            $sql_update = "UPDATE map SET kingdomid = -2 WHERE " . implode(' OR ', $update_coords);
            $db->execute_query($sql_update);
        }
    }

    echo "[" . date("H:i:s") . "] " . $limit . " neue Rohstofffelder per Batch generiert.\n";
}
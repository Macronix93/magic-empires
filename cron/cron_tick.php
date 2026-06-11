<?php
// Simulation for CLI
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once("../includes/core.php");

$db = Database::get_instance()->get_connection();
$now = time();


//// Check for troop events
$query = "SELECT * FROM events 
          WHERE arrivaltime <= ? 
          AND is_processing = 0 
          AND (actionid = ? OR actionid = ?)";
$result = $db->execute_query($query, [$now, ActionTypes::ACTION_SEND_TROOPS, ActionTypes::ACTION_RETURN_TROOPS]);


$count = 0;
foreach ($result as $row) {
    $db->execute_query("UPDATE events SET is_processing = 1 WHERE eventid = ?", [$row["eventid"]]);

    if ($db->affected_rows > 0) {
        try {
            $system_user = new User(0, "System");
            $em = new EventManager($system_user);

            if ($row["actionid"] == ActionTypes::ACTION_SEND_TROOPS) {
                $em->handle_combat($row);
            } else {
                $em->handle_troop_return($row);
            }
        } catch (Throwable $t) {
            $db->execute_query("UPDATE events SET is_processing = 0 WHERE eventid = ?", [$row["eventid"]]);
            error_log("Cronjob Error in Event " . $row['eventid'] . ": " . $t->getMessage());
        }
        $count++;
    }
}

if ($count > 0) {
    echo "[" . date("H:i:s") . "] $count Kämpfe verarbeitet.\n";
} else {
    echo "[" . date("H:i:s") . "] Keine fälligen Kämpfe gefunden.\n";
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
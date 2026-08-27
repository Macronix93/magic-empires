<?php /** @noinspection ALL */
require_once("includes/core.php");

check_user_login($user);

$user_list = "";
$user_id = -1;

if (!$user->is_admin()) {
    $error = "Du bist kein Administrator!";
} else {
    $system_log_section = "";
    $chat_backup_section = "";
    $game_logs_table = "";
    $user_info_html = "";
    $user_list = "";

    $active_tab = $_GET['tab'] ?? 'system';
    if (isset($_GET['logpage'])) $active_tab = 'gamelogs';
    if (isset($_GET['userid'])) $active_tab = 'users';

    if (isset($_POST["reset_round"])) {
        $logger->admin("ROUND RESET STARTED by Admin " . $user->get_user_name() . " (ID " . $user->get_user_id() . ")");

        // Set maintenance mode
        $db_instance->execute_query("UPDATE system_settings SET value = '1' WHERE name = 'maintenance_mode'");

        // Clear tables (Order is important dude to foreign keys)
        $db_instance->query("DELETE FROM marketplace");
        $db_instance->query("DELETE FROM events");
        $db_instance->query("DELETE FROM sent_troops");
        $db_instance->query("DELETE FROM kingdom_boosts");
        $db_instance->query("DELETE FROM resource_tiles_data");
        $db_instance->query("DELETE FROM kingdoms"); // Cascades Buildings, Soldiers, Techs
        $db_instance->query("DELETE FROM game_logs");
        $db_instance->query("DELETE FROM server_messages");

        // Reset Auto Increments
        $tables = ["kingdoms", "events", "marketplace", "server_messages", "game_logs"];
        foreach ($tables as $t) {
            $db_instance->query("ALTER TABLE $t AUTO_INCREMENT = 1");
        }

        // Reset User Scores and Main Kingdom
        $db_instance->execute_query("UPDATE users SET score = ?, mainkingdom = -1, coins = 0", [STARTING_SCORE]);

        // Generate a new random map
        $db_instance->query("DELETE FROM map");
        $db_instance->query("ALTER TABLE map AUTO_INCREMENT = 1");

        $seed = rand(1000, 99999);
        //$map_helper = new Map($db_instance, $user);

        // Noise Functions
        function noise_rand($x, $y, $s): float|int
        {
            $n = ($x * 374761393 + $y * 668265263 + $s * 1446645) & 0xFFFFFFFF;
            $n = (($n ^ ($n >> 13)) * 1274126177) & 0xFFFFFFFF;
            return (($n ^ ($n >> 16)) & 0x7FFFFFFF) / 2147483647;
        }

        function noise_lerp($a, $b, $t): float|int
        {
            return $a + $t * ($b - $a);
        }

        function noise_fade($t): float|int
        {
            return $t * $t * $t * ($t * ($t * 6 - 15) + 10);
        }

        function get_val_noise($x, $y, $s): float|int
        {
            $x0 = floor($x);
            $y0 = floor($y);
            $sx = noise_fade($x - $x0);
            $sy = noise_fade($y - $y0);
            $n0 = noise_rand($x0, $y0, $s);
            $n1 = noise_rand($x0 + 1, $y0, $s);
            $ix0 = noise_lerp($n0, $n1, $sx);
            $n0 = noise_rand($x0, $y0 + 1, $s);
            $n1 = noise_rand($x0 + 1, $y0 + 1, $s);
            $ix1 = noise_lerp($n0, $n1, $sx);
            return noise_lerp($ix0, $ix1, $sy);
        }

        function get_fractal($x, $y, $s, $oct, $pers, $scale): float|int
        {
            $total = 0;
            $freq = $scale;
            $amp = 1;
            $maxV = 0;
            for ($i = 0; $i < $oct; $i++) {
                $total += get_val_noise($x * $freq, $y * $freq, $s + $i * 100) * $amp;
                $maxV += $amp;
                $amp *= $pers;
                $freq *= 2;
            }
            return $total / $maxV;
        }

        // Write Map to DB
        for ($y = 1; $y <= MAX_Y; $y++) {
            for ($x = 1; $x <= MAX_X; $x++) {
                $mt = get_fractal($x, $y, $seed + 8888, 5, 0.25, 0.15);
                if ($mt > 0.70) $ft = 1; // Gebirge
                else {
                    $e = get_fractal($x, $y, $seed, 5, 0.5, 0.35);
                    if ($e < 0.35) $ft = 2; // Küste
                    else {
                        $m = get_fractal($x, $y, $seed + 5555, 4, 0.5, 0.2);
                        if ($m < 0.38) $ft = 4; // Wüste
                        elseif ($m > 0.62) $ft = 3; // Wald
                        else $ft = 5; // Hochland
                    }
                }

                $db_instance->execute_query("INSERT INTO map (mapx, mapy, fieldtype, kingdomid) VALUES (?, ?, ?, -1)", [$x, $y, $ft]);
            }
        }

        // Create new kingdom for every registered and activated user
        $res_users = $db_instance->query("SELECT id, username FROM users WHERE status = 1");
        $kingdom_manager = new Kingdom($db_instance);

        while ($u = $res_users->fetch_assoc()) {
            $new_k_id = $kingdom_manager->create_kingdom($u["id"], $u["username"]);

            if ($new_k_id) {
                $db_instance->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$new_k_id, $u["id"]]);

                // Send server message to every user
                $msg = "📢 <b>Runden-Reset erfolgt!</b><br>Ein Administrator hat die Welt neugestartet. Alle Gebäude, Truppen und Ressourcen wurden zurückgesetzt. Viel Erfolg in der neuen Runde!";
                send_server_message($u["id"], $u["username"], $msg);
            }
        }

        // Create initial resource tiles for map
        $fields = $db_instance->execute_query("SELECT mapx, mapy FROM map WHERE kingdomid = -1 ORDER BY RAND() LIMIT ?", [MAX_RESOURCE_TILES]);

        if ($fields->num_rows > 0) {
            $insert_values = [];
            $update_coords = [];

            foreach ($fields as $f) {
                $x = (int)$f["mapx"];
                $y = (int)$f["mapy"];

                $total = mt_rand(MIN_RESOURCES_PER_TILE, MAX_RESOURCES_PER_TILE);
                $res_values = ["food" => 0, "wood" => 0, "stone" => 0, "gold" => 0];
                $active_keys = [];

                foreach ($res_values as $key => $val) {
                    if (mt_rand(1, 100) <= 70) $active_keys[] = $key;
                }

                if (empty($active_keys)) $active_keys[] = array_rand($res_values);

                $temp_total = $total;
                $count_keys = count($active_keys);

                for ($i = 0; $i < $count_keys; $i++) {
                    $key = $active_keys[$i];
                    if ($i == $count_keys - 1) {
                        $res_values[$key] = $temp_total;
                    } else {
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
                $db_instance->query($sql_insert);
            }

            if (!empty($update_coords)) {
                $sql_update = "UPDATE map SET kingdomid = -2 WHERE " . implode(' OR ', $update_coords);
                $db_instance->query($sql_update);
            }
        }

        // Remove maintenance mode
        $db_instance->execute_query("UPDATE system_settings SET value = '0' WHERE name = 'maintenance_mode'");

        $_SESSION["admin_flash_msg"] = show_passed_box("Runden-Reset erfolgreich durchgeführt! Alle Spieler haben ein neues Königreich erhalten.");

        change_location("adminpanel.php");
        exit;
    }

    if (isset($_POST["spawn_map_entities"])) {
        $spawn_type = $_POST["spawn_type"];
        $report = [];
        $now = time();

        // --- RESSOURCES ---
        if ($spawn_type === "all" || $spawn_type === "resources") {
            $res_count = $db_instance->execute_query("SELECT COUNT(*) FROM map WHERE kingdomid = -2")->fetch_column();
            if ($res_count < MAX_RESOURCE_TILES) {
                $limit = min(RESOURCE_TILES_SPAWN_RATE, MAX_RESOURCE_TILES - $res_count);
                $fields = $db_instance->execute_query("
                SELECT m.mapx, m.mapy FROM map m 
                WHERE m.kingdomid = -1 
                AND NOT EXISTS (SELECT 1 FROM events e WHERE e.actionid = 2 AND e.targetid = -1 AND e.targetx = m.mapx AND e.targety = m.mapy)
                ORDER BY RAND() LIMIT ?", [$limit]);

                if ($fields->num_rows > 0) {
                    $insert_values = [];
                    $update_coords = [];

                    foreach ($fields as $f) {
                        $x = (int)$f["mapx"];
                        $y = (int)$f["mapy"];
                        $expires = $now + mt_rand(SPAWN_LIFETIME_MIN * 86400, SPAWN_LIFETIME_MAX * 86400);
                        $total = mt_rand(MIN_RESOURCES_PER_TILE, MAX_RESOURCES_PER_TILE);
                        $res_values = ["food" => 0, "wood" => 0, "stone" => 0, "gold" => 0];
                        $active_keys = [];

                        foreach ($res_values as $key => $val) {
                            if (mt_rand(1, 100) <= 70) $active_keys[] = $key;
                        }

                        if (empty($active_keys)) $active_keys[] = array_rand($res_values);

                        $temp_total = $total;
                        $count_keys = count($active_keys);

                        for ($i = 0; $i < $count_keys; $i++) {
                            $key = $active_keys[$i];
                            if ($i == $count_keys - 1) {
                                $res_values[$key] = $temp_total;
                            } else {
                                $share = mt_rand(10, 80) / 100;
                                $val = (int)($temp_total * $share);
                                $res_values[$key] = $val;
                                $temp_total -= $val;
                            }
                        }

                        $insert_values[] = "($x, $y, {$res_values["food"]}, {$res_values["wood"]}, {$res_values["stone"]}, {$res_values["gold"]}, $expires)";
                        $update_coords[] = "($x, $y)";
                    }
                    $db_instance->query("INSERT INTO resource_tiles_data (mapx, mapy, food, wood, stone, gold, expires_at) VALUES " . implode(',', $insert_values));
                    $db_instance->query("UPDATE map SET kingdomid = -2 WHERE (mapx, mapy) IN (" . implode(',', $update_coords) . ")");

                    $report[] = count($update_coords) . " Ressourcenfelder generiert.";
                }
            } else {
                $report[] = "Ressourcenlimit bereits erreicht.";
            }
        }

        // --- MONSTER CAMPS ---
        if ($spawn_type === "all" || $spawn_type === "monsters") {
            $db_instance->execute_query("DELETE FROM monster_camps WHERE expires_at < ?", [$now]);
            $db_instance->query("UPDATE map SET kingdomid = -1 WHERE kingdomid = -3 AND (mapx, mapy) NOT IN (SELECT mapx, mapy FROM monster_camps)");
            $count_res = $db_instance->query("SELECT SUM(IF(level BETWEEN 1 AND 3, 1, 0)) AS low, SUM(IF(level BETWEEN 4 AND 6, 1, 0)) AS mid, 
                                                           SUM(IF(level BETWEEN 7 AND 9, 1, 0)) AS high, 
                                                           SUM(IF(level = 10, 1, 0)) AS boss, 
                                                           COUNT(*) AS total FROM monster_camps")->fetch_assoc();
            $total_on_map = (int)($count_res["total"] ?? 0);

            if ($total_on_map < MAX_MONSTER_CAMPS) {
                $limit = min(MONSTER_CAMP_SPAWN_RATE, MAX_MONSTER_CAMPS - $total_on_map);
                $current_counts = ["low" => (int)$count_res["low"], "mid" => (int)$count_res["mid"], "high" => (int)$count_res["high"], "boss" => (int)$count_res["boss"]];
                $targets = ["low" => MAX_MONSTER_CAMPS * MONSTER_CAMP_WEIGHT_LOW, "mid" => MAX_MONSTER_CAMPS * MONSTER_CAMP_WEIGHT_MID, "high" => MAX_MONSTER_CAMPS * MONSTER_CAMP_WEIGHT_HIGH, "boss" => MAX_MONSTER_CAMPS * MONSTER_CAMP_WEIGHT_BOSS];

                $monster_pool = [];
                $res_all_m = $db_instance->query("SELECT id, level FROM monster_list");
                while ($m = $res_all_m->fetch_assoc()) {
                    $monster_pool[(int)$m["level"]][] = (int)$m["id"];
                }

                $free_fields = $db_instance->execute_query("SELECT m.mapx, m.mapy FROM map m WHERE m.kingdomid = -1 AND NOT 
                                                                    EXISTS (SELECT 1 FROM events e WHERE e.actionid = 2 AND e.targetid = -1 AND e.targetx = m.mapx AND e.targety = m.mapy) 
                                                                    ORDER BY RAND() LIMIT ?", [$limit]);

                if ($free_fields->num_rows > 0) {
                    $insert_camps = [];
                    $insert_units = [];
                    $update_map_coords = [];

                    foreach ($free_fields as $f) {
                        $x = (int)$f["mapx"];
                        $y = (int)$f["mapy"];
                        $fill_grades = [];

                        foreach ($targets as $key => $targetV) {
                            $fill_grades[$key] = ($targetV > 0) ? $current_counts[$key] / $targetV : 1;
                        }

                        asort($fill_grades);

                        $chosen_group = array_key_first($fill_grades);
                        $camp_level = ($chosen_group == "low") ? mt_rand(1, 3) : (($chosen_group == "mid") ? mt_rand(4, 6) : (($chosen_group == "high") ? mt_rand(7, 9) : 10));
                        $current_counts[$chosen_group]++;
                        $expires = $now + mt_rand(SPAWN_LIFETIME_MIN * 86400, SPAWN_LIFETIME_MAX * 86400);
                        $insert_camps[] = "($x, $y, $camp_level, $expires)";
                        $update_map_coords[] = "($x, $y)";

                        if (!empty($monster_pool[$camp_level])) {
                            $main_m_id = $monster_pool[$camp_level][array_rand($monster_pool[$camp_level])];
                            $insert_units[] = "($x, $y, $main_m_id, " . mt_rand(MIN_NUM_MONSTERS_PER_TYPE, MAX_NUM_MONSTERS_PER_TYPE) . ")";

                            if ($camp_level >= 10) {
                                $num_extra = 4; // 5 groups
                            } else if ($camp_level >= 7) {
                                $num_extra = mt_rand(3, 4); // At least 4 groups
                            } else if ($camp_level >= 5) {
                                $num_extra = mt_rand(2, 4); // At least 3 groups
                            } else {
                                $num_extra = ($camp_level <= 3)
                                    ? mt_rand(MIN_MONSTER_CAMP_EXTRA_SLOTS_LOW, MAX_MONSTER_CAMP_EXTRA_SLOTS_LOW)
                                    : mt_rand(MIN_MONSTER_CAMP_EXTRA_SLOTS_HIGH, MAX_MONSTER_CAMP_EXTRA_SLOTS_HIGH);
                            }

                            for ($i = 0; $i < $num_extra; $i++) {
                                $rand_lvl = mt_rand(max(1, $camp_level - MONSTER_CAMP_EXTRA_LEVEL_CAP), $camp_level);

                                if (!empty($monster_pool[$rand_lvl])) {
                                    $ex_id = $monster_pool[$rand_lvl][array_rand($monster_pool[$rand_lvl])];
                                    $insert_units[] = "($x, $y, $ex_id, " . mt_rand(MONSTER_CAMP_EXTRA_MONSTER - 4, MONSTER_CAMP_EXTRA_MONSTER + 4) . ")";
                                }
                            }
                        }
                    }
                    $db_instance->query("INSERT INTO monster_camps (mapx, mapy, level, expires_at) VALUES " . implode(',', $insert_camps));
                    $db_instance->query("UPDATE map SET kingdomid = -3 WHERE (mapx, mapy) IN (" . implode(',', $update_map_coords) . ")");
                    $db_instance->query("INSERT INTO monster_camp_units (mapx, mapy, monster_id, count) VALUES " . implode(',', $insert_units) . " ON DUPLICATE KEY UPDATE count = count + VALUES(count)");

                    $report[] = count($insert_camps) . " Monstercamps balance-optimiert generiert.";
                }
            } else {
                $report[] = "Monsterlimit ($total_on_map/" . MAX_MONSTER_CAMPS . ") bereits erreicht.";
            }
        }

        $logger->admin("MANUAL MAP SPAWN: $spawn_type");
        $_SESSION["admin_flash_msg"] = show_passed_box(implode("<br>", $report));

        change_location("adminpanel.php?tab=system");
        exit;
    }

    if (isset($_POST["toggle_maintenance"])) {
        $new_val = (MAINTENANCE_MODE ? "0" : "1");
        $reason = $_POST["maintenance_reason"] ?? "Geplante Wartungsarbeiten";

        $db_instance->execute_query("UPDATE system_settings SET value = ? WHERE name = 'maintenance_mode'", [$new_val]);
        $db_instance->execute_query("UPDATE system_settings SET value = ? WHERE name = 'maintenance_reason'", [$reason]);
        $logger->admin("MAINTENANCE MODE changed to: " . ($new_val == "1" ? "ON ($reason)" : "OFF"));

        change_location("adminpanel.php");
        exit;
    }

    if (isset($_GET["banuser"]) || isset($_GET["unbanuser"])) {
        $uid = (int)($_GET["banuser"] ?? $_GET["unbanuser"]);

        if (isset($_GET["banuser"])) {
            $uid = (int)$_GET["banuser"];
            $reason = $_GET["reason"] ?? "Verstoß gegen die Regeln";

            $db_instance->execute_query("UPDATE users SET is_banned = 1, ban_reason = ? WHERE id = ?", [$reason, $uid]);
            $logger->admin("BANNED USER ID $uid. Reason: $reason");

            $_SESSION["admin_flash_msg"] = show_passed_box("Benutzer ID $uid wurde gebannt!");
        } else if (isset($_GET["unbanuser"])) {
            $uid = (int)$_GET["unbanuser"];

            $db_instance->execute_query("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE id = ?", [$uid]);
            $logger->admin("UNBANNED USER ID $uid");

            $_SESSION["admin_flash_msg"] = show_passed_box("Benutzer ID $uid wurde entsperrt!");
        }

        change_location("adminpanel.php?userid=" . $uid);
        exit;
    }

    if (isset($_GET["deletelog"])) {
        $log_id = (int)$_GET["deletelog"];
        $page = (int)($_GET["logpage"] ?? 1);

        $db_instance->execute_query("DELETE FROM game_logs WHERE id = ?", [$log_id]);
        $logger->admin("Deleted log entry ID $log_id");

        $_SESSION["admin_flash_msg"] = show_passed_box("Log-Eintrag wurde gelöscht!");

        change_location("adminpanel.php?tab=gamelogs&logpage=" . $page);
        exit;
    }

    if (isset($_GET["deleteevent"])) {
        $event_id = (int)$_GET["deleteevent"];
        $db_instance->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
        $logger->admin("Deleted event ID $event_id");

        $_SESSION["admin_flash_msg"] = show_passed_box("Event wurde manuell abgebrochen/gelöscht!");

        $redir = isset($_GET["userid"]) ? "?userid=" . (int)$_GET["userid"] : "";

        change_location("adminpanel.php" . $redir);
        exit;
    }

    if (isset($_POST["clear_log"]) && isset($_POST["log_to_clear"])) {
        $f = $_POST["log_to_clear"];
        $allowed_files = ["admin.log", "error.log", "security.log"];

        if (in_array($f, $allowed_files) && $user->get_user_admin_level() >= ADMIN_LEVEL_FULL_ADMIN) {
            file_put_contents(__DIR__ . "/logs/" . $f, "");

            $logger->admin("Log-Datei $f wurde über das Adminpanel geleert.");

            $_SESSION["admin_flash_msg"] = show_passed_box("Datei $f wurde erfolgreich geleert.");
        }
        change_location("adminpanel.php?tab=logs");
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["field"])) {
        $field = $_POST["field"];
        $old_value = $_POST["old_value"];
        $new_value = $_POST["new_value"];
        $user_id = $_POST["user_id"];

        $result = false;

        if ($field == "avatar") {
            $old_filename = basename($old_value);
            $new_filename = basename($new_value);

            $file_path = UPLOADS_FILE_PATH . $old_filename;
            $new_file_path = UPLOADS_FILE_PATH . $new_filename;

            if (!empty($old_filename) && file_exists($file_path)) {
                if (rename($file_path, $new_file_path)) {
                    $result = true;
                } else {
                    $view .= show_error_box("Fehler beim Umbenennen der Datei!");
                }
            } else if (file_exists($new_file_path)) {
                $result = true;
            } else {
                $view .= show_error_box("Datei '$new_filename' wurde im Ordner " . UPLOADS_FILE_PATH . " nicht gefunden!");
            }
        } else {
            if ($field == "password") {
                $new_value_db = password_hash(make_secure($new_value ?? ""), PASSWORD_BCRYPT);
            } else {
                $new_value_db = $new_value;
            }

            $result = $db_instance->execute_query("UPDATE users SET $field = ? WHERE id = ?", [$new_value_db, $user_id]);
        }

        if ($result) {
            $log_display = ($field === "password") ? "***" : $new_value;
            $logger->admin("Edited field '$field' for user ID $user_id. New Value: " . $log_display);

            $_SESSION["admin_flash_msg"] = show_passed_box("Daten erfolgreich aktualisiert! Feld: $field");

            change_location("adminpanel.php?userid=$user_id&tab=users");
            exit;
        } else {
            if (empty($view)) {
                $view .= show_error_box("Fehler beim Aktualisieren! Feld: $field");
            }
        }
    }

    // Show user related info if clicked on a user
    if (isset($_GET["userid"])) {
        $user_id = $_GET["userid"];

        $query = "SELECT 
                    users.*, 
                    kingdoms.id AS kingdom_id, 
                    kingdoms.kingdomname, 
                    events.eventid AS event_id, 
                    events.actionid AS action_id
                FROM 
                    users 
                LEFT JOIN 
                    kingdoms ON users.id = kingdoms.userid 
                LEFT JOIN 
                    events ON kingdoms.id = events.kingdomid
                WHERE 
                    users.id = ?";
        $result = $db_instance->execute_query($query, [$user_id]);

        if ($result && $result->num_rows <= 0) {
            $error = "Der Benutzer existiert nicht!";
        } else {
            $kingdoms = [];
            $events = [];
            $user_info = [];
            $found_kingdom = -1;

            foreach ($result as $row) {
                $kingdom_id = $row["kingdom_id"];
                $event_id = $row["event_id"];
                $adm_user = new User($row["id"], $row["username"]);

                // Process user information only once (for display purposes)
                if (empty($user_info)) {
                    $user_info = [
                        'Name' => ['field' => 'username', 'value' => $row['username']],
                        'Bann-Status' => ['field' => 'is_banned', 'value' => $row['is_banned']],
                        'Bann-Grund' => ['field' => 'ban_reason', 'value' => $row['ban_reason']],
                        'Passwort' => ['field' => 'password', 'value' => "Passwort"],
                        'Avatar' => ['field' => 'avatar', 'value' => $adm_user->get_avatar()],
                        'Account-Status' => ['field' => 'status', 'value' => $row['status']],
                        'IP' => ['field' => 'ip', 'value' => $row['ip']],
                        'Admin-Level' => ['field' => 'adminlevel', 'value' => $row['adminlevel']],
                        'Registriert am' => ['field' => 'registerdate', 'value' => $row['registerdate']],
                        'E-Mail' => ['field' => 'email', 'value' => $row['email']],
                        'Letzter Login' => ['field' => 'lastlogin', 'value' => $row['lastlogin']],
                        'Letzte Aktivität' => ['field' => 'lastactivity', 'value' => $row['lastactivity']],
                        'Rang um 0 Uhr' => ['field' => 'lastrank', 'value' => $row['lastrank']],
                        'Punkte' => ['field' => 'score', 'value' => $row['score']],
                        'Haupt-Königreich' => ['field' => 'mainkingdom', 'value' => $row['mainkingdom']],
                        'Gilde' => ['field' => 'guildid', 'value' => $row['guildid']],
                        'Münzen' => ['field' => 'coins', 'value' => $row['coins']],
                        'Nachrichten-Zähler' => ['field' => 'msgcount', 'value' => $row['msgcount']],
                        'Rate-Limit Ende' => ['field' => 'lastsentmsgend', 'value' => $row['lastsentmsgend']]
                    ];
                }

                // Add the kingdom data if not already added
                if ($kingdom_id !== null) {
                    if (!isset($kingdoms[$kingdom_id])) {
                        $kingdoms[$kingdom_id] = [
                            'kingdomname' => $row['kingdomname'],
                            'events' => []
                        ];
                    }

                    // Add event data to the kingdom's events array if event exists
                    if ($event_id !== null) {
                        $kingdoms[$kingdom_id]['events'][] = [
                            'event_id' => $row['event_id'],
                            'action_id' => $row['action_id']
                        ];
                    }
                }
            }

            // Display user info using a loop
            $user_info_html .= '<h3>Spieler-Info</h3>';
            $user_info_html .= '<table class="table">';

            foreach ($user_info as $label => $data) {
                $field_id = $data["field"];
                $raw_value = $data["value"];
                $display_value = "";

                if ($label === "Avatar") {
                    $display_value = '<img class="user-image" src="' . e($raw_value) . '" alt="Nutzerbild">';
                } else if (in_array($label, ["Registriert am", "Letzter Login", "Letzte Aktivität", "Letzte Nachricht", "Rate-Limit Ende"])) {
                    $display_value = date("d.m.Y", $raw_value) . ' um ' . date("H:i:s", $raw_value);
                } else if ($label === "Punkte") {
                    $display_value = fnum($raw_value);
                } else {
                    $display_value = e($raw_value);
                }

                if ($label === "Name") {
                    $display_value .= ' [ID: ' . $user_id . ']';
                }

                $user_info_html .= '<tr>
                            <td style="width: 30%;">' . $label . ':</td>
                            <td id="td_' . $field_id . '">' . $display_value . '</td>
                            <td class="td-center" style="border-bottom: 1px solid var(--box-header);">
                                <a href="#" 
                                   data-on-click="editUserField" 
                                   data-userid="' . e($user_id) . '" 
                                   data-fieldid="' . e($field_id) . '" 
                                   data-raw="' . e($raw_value) . '" 
                                   data-formatted="' . e($display_value) . '">
                                    <img src="images/icons/icon_edit.png" class="ressource-icons" alt="Editieren">
                                </a>'
                    . ($label === "Name" ? '
                            <a href="#" 
                               data-on-click="banUserDialog" 
                               data-userid="' . e($user_id) . '" 
                               data-username="' . e($row["username"]) . '"
                               data-status="' . e($row["is_banned"]) . '">
                                <img src="images/icons/' . ($row["is_banned"] ? 'icon_checked.png' : 'icon_error.png') . '" class="ressource-icons" alt="Bannen" title="Sperren/Entsperren">
                            </a>
                            <a href="#" 
                               data-on-click="userDeletionDialog" 
                               data-userid="' . e($user_id) . '" 
                               data-username="' . e($row["username"]) . '">
                                <img src="images/icons/icon_delete.png" class="ressource-icons" alt="Löschen" >
                            </a>'
                        : '') . '
                        </td>
                      </tr>';
            }

            $user_info_html .= '</table>';

            // --- MULTI-ACCOUNT CHECK ---
            $user_info_html .= '<h3>Multi-Account Check</h3>';
            $user_info_html .= '<table class="table">';

            // Calculate Root IP
            $ip_parts = explode('.', $row["ip"]);
            $subnet = "";
            if (count($ip_parts) === 4) {
                $subnet = $ip_parts[0] . '.' . $ip_parts[1] . '.%';
            } else {
                // Fallback for IPv6
                $ipv6_parts = explode(':', $row["ip"]);
                if (count($ipv6_parts) > 4) {
                    $subnet = $ipv6_parts[0] . ':' . $ipv6_parts[1] . ':' . $ipv6_parts[2] . ':' . $ipv6_parts[3] . ':%';
                } else {
                    $subnet = $row["ip"]; // Fallback
                }
            }

            // Check for Subnet (Root IP)
            $multi_ip = $db_instance->execute_query(
                "SELECT id, username, ip, linked_user FROM users WHERE ip LIKE ? AND id != ?",
                [$subnet, $user_id]
            );

            $multi_device = $db_instance->execute_query(
                "SELECT id, username FROM users WHERE device_id = ? AND id != ? AND device_id IS NOT NULL",
                [$row["device_id"], $user_id]
            );

            // Show Subnet Result
            $user_info_html .= '<tr><td style="width: 30%;">Gleiches Subnetz:</td><td>';

            if ($multi_ip->num_rows > 0) {
                foreach ($multi_ip as $m) {
                    $is_exact = ($m["ip"] === $row["ip"]) ? ' <b>(Gleiche IP)</b>' : ' (Subnetz)';

                    $consider_linked = ($row["linked_user"] === $m["username"]);
                    $back_linked = ($m["linked_user"] === $row["username"]);

                    if ($consider_linked && $back_linked) {
                        $link_status = '<span class="passed"> [Gegenseitig angemeldet]</span>';
                    } elseif ($consider_linked || $back_linked) {
                        $link_status = '<span class="event-warning"> [Einseitig angemeldet!]</span>';
                    } else {
                        $link_status = '<b class="error"> [NICHT ANGEMELDET!]</b>';
                    }

                    $user_info_html .= '<a href="adminpanel.php?userid=' . $m['id'] . '" class="error">' . e($m["username"]) . '</a>'
                        . $is_exact . $link_status . '<br>';
                }
            } else {
                $user_info_html .= '<span class="passed">Keine Treffer</span>';
            }
            $user_info_html .= '<tr><td style="width: 30%;">Gleiche Device-ID:</td><td>';

            if ($multi_device->num_rows > 0) {
                foreach ($multi_device as $d) {
                    $user_info_html .= '<a href="adminpanel.php?userid=' . $d['id'] . '" class="error">' . e($d["username"]) . '</a>'
                        . ' <b class="error">[HARDWARE MATCH!]</b><br>';
                }
            } else {
                $user_info_html .= '<span class="passed">Keine Treffer</span>';
            }
            $user_info_html .= '</td></tr>';
            $user_info_html .= '<tr><td style="width: 30%;">Proxy/VPN Info:</td><td>';

            $proxy_data = check_ip_proxy($row["ip"]);

            if ($proxy_data) {
                if ($proxy_data["proxy"] === "yes") {
                    $user_info_html .= '<b class="error">WARNUNG: Proxy/VPN erkannt!</b><br>';
                    $user_info_html .= 'Typ: ' . e($proxy_data["type"]) . '<br>';
                } else {
                    $user_info_html .= '<span class="passed">Kein Proxy erkannt</span><br>';
                }
                $user_info_html .= '<small>Provider: ' . e($proxy_data["isp"]) . '</small>';
            } else {
                $user_info_html .= '<i>Check momentan nicht verfügbar.</i>';
            }
            $user_info_html .= '</td></tr>';
            $user_info_html .= "</table>";

            // Display kingdoms
            $user_info_html .= "<h3>Königreiche</h3>";

            if (!empty($kingdoms)) {
                $user_info_html .= '<div class="box-container" style="max-height: 200px; width: 300px; overflow: auto; margin: 0 auto;">';

                foreach ($kingdoms as $kingdom_id => $kingdom_data) {
                    $target_url = "adminpanel.php?userid=" . e($user_id) . "&kingdomid=" . e($kingdom_id);

                    $user_info_html .= '<div class="box' . (isset($_GET["kingdomid"]) && $_GET["kingdomid"] == $kingdom_id ? ' active' : '') . '" 
                                   data-on-click="navigate" 
                                   data-url="' . $target_url . '">
                    <div style="width: 50px; text-align: center;">
                        ' . $kingdom_id . '
                    </div>
                    <div>
                        ' . $kingdom_data['kingdomname'] . '
                    </div>
                  </div>';
                }

                $user_info_html .= '</div>';
            } else {
                $user_info_html .= 'Keine Königreiche gefunden.';
            }

            // Display kingdom data and event data for kingdom
            if (isset($_GET['kingdomid'])) {
                $found_kingdom = null;

                foreach ($kingdoms as $kingdom_id => $kingdom_data) {
                    if ($kingdom_id == $_GET['kingdomid']) {
                        $user_info_html .= '<h3>Königreich-Info</h3>';
                        $user_info_html .= '<table class="table">
                                <tr>
                                    <td>Königreich:</td>
                                    <td>' . $kingdom_data['kingdomname'] . ' [ID: ' . $kingdom_id . ']</td>
                                </tr>
                              </table>';

                        $found_kingdom = $kingdom_data;
                        break;
                    }
                }

                if ($found_kingdom !== null) {
                    if (!empty($found_kingdom['events'])) {
                        $user_info_html .= '<h3>Event-Info</h3>';
                        $user_info_html .= '<table class="table">
                                    <tr>
                                        <td class="td-gradient"><b>Aktion</b></td>
                                        <td class="td-gradient"><b>ID</b></td>
                                        <td class="td-gradient"><b>Aktion</b></td>
                                    </tr>';

                        foreach ($found_kingdom['events'] as $event) {
                            $user_info_html .= '<tr>
                                        <td>' . $event['action_id'] . '</td>
                                        <td>' . $event['event_id'] . '</td>
                                        <td class="td-center">
                                            <a href="#" 
                                               data-on-click="confirmDeleteEvent" 
                                               data-id="' . $event['event_id'] . '" 
                                               data-userid="' . $user_id . '">
                                                <img src="images/icons/icon_delete.png" class="ressource-icons" alt="Löschen">
                                            </a>
                                        </td>
                                    </tr>';
                        }
                        $user_info_html .= '</table>';
                    } else {
                        $user_info_html .= '<br>Keine Events für das Königreich gefunden.';
                    }
                }
            }
        }
    } else if (isset($_GET["deleteuser"])) {
        $user_id = (int)$_GET["deleteuser"];

        // Check if user id was found and get all kingdoms for updating the map
        $query = "
            SELECT users.username, kingdoms.id AS kingdomid
            FROM users
            LEFT JOIN kingdoms ON users.id = kingdoms.userid
            WHERE users.id = ?
        ";
        $result = $db_instance->execute_query($query, [$user_id]);

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $username = $row['username'];

            // Remove users avatar(s)
            delete_user_avatar_files($user_id);

            // Delete the user
            $db_instance->execute_query("DELETE FROM users WHERE id = ?", [$user_id]);

            // Reset map spots that were taken by the users kingdoms
            foreach ($result as $row) {
                if ($row['kingdomid'] !== null) {
                    $db_instance->execute_query("UPDATE map SET kingdomid = -1 WHERE kingdomid = ?", [$row["kingdomid"]]);
                }
            }

            $logger->admin("DELETED USER: $username (ID: $user_id)");

            $view .= show_passed_box("Benutzer erfolgreich gelöscht!");
        } else {
            $error .= "Der Benutzer existiert nicht!";
        }
    }

    // Show Server Settings
    $m_status = MAINTENANCE_MODE ? "<span class='error'>AKTIV</span>" : "<span class='passed'>Inaktiv</span>";
    $m_button = MAINTENANCE_MODE ? "Deaktivieren" : "Aktivieren";

    $settings_list = "<div class='box-container' style='margin-bottom: 20px;'>
                    <div class='box-header'>System-Steuerung</div>
                        <div class='box-content box-content-bg' style='padding: 15px;'>
                            <b>Wartungsmodus:</b> $m_status 
                            <form method='POST' id='maint_form' style='display:inline; margin-left: 20px;'>
                                <input type='hidden' name='toggle_maintenance' value='1'>
                                <input type='hidden' name='maintenance_reason' id='maint_reason_input' value=''>
                                <input type='button' data-on-click='confirmMaintenance' value='" . (MAINTENANCE_MODE ? 'Deaktivieren' : 'Aktivieren') . "'>
                            </form>
                        </div>
                    </div>";
    $settings_list .= "<div class='box-container' style='margin-top: 20px; border-color: #a62121;'>
                        <div class='box-header' style='background: #a62121; color: white; border-bottom: #340202 2px solid;'>Welt-Reset</div>
                        <div class='box-content box-content-bg-danger' style='padding: 15px; text-align: center;'>
                            <p class='error'><b>ACHTUNG:</b> Ein Runden-Reset löscht alle Königreiche, Truppen, Fortschritte und generiert eine komplett neue Karte!</p>
                            <form method='POST'>
                                <input type='button' data-on-click='confirmResetRound' value='RUNDEN-RESET' style='color: white;'>
                                <input type='hidden' name='reset_round' id='hidden_reset_submit'>
                            </form>
                        </div>
                    </div>";
    $settings_list .= "<div class='box-container' style='margin-top: 20px;'>
                    <div class='box-header'>Karten-Wartung</div>
                    <div class='box-content box-content-bg' style='padding: 15px; text-align: center;'>
                        <p style='font-size: 14px;'>Manuelle Generierung von Objekten auf freien Feldern.</p>
                        <form method='POST' style='display: flex; gap: 10px; justify-content: center;'>
                            <select name='spawn_type' style='width: 200px;'>
                                <option value='all'>Alles füllen</option>
                                <option value='resources'>Nur Ressourcen</option>
                                <option value='monsters'>Nur Monster</option>
                            </select>
                            <input type='submit' name='spawn_map_entities' value='Generieren'>
                        </form>
                    </div>
                </div>";

    $system_log_section .= "<div class='title-border'>System-Logfiles (.log)</div>";
    $system_log_section .= "<div style='display: flex; gap: 15px; justify-content: center; flex-wrap: wrap; margin-bottom: 30px;'>";

    $files = ["admin.log", "error.log", "security.log"];
    foreach ($files as $f) {
        $url = "ajax/admin_log_view.php?file=$f";

        $system_log_section .= "<div class='box-container' style='width: 200px; margin: 0;'>
                    <div class='box-header' style='font-size: 14px;'>$f</div>
                    <div class='box-content box-content-bg' style='padding: 10px; text-align: center;'>
                        <button data-on-click='openOverlay' data-url='$url' data-title='Log-Viewer' style='width: 100%; margin-bottom: 5px;'>Ansehen</button>
                        
                        <form method='POST' id='form-clear-$f'>
                            <input type='hidden' name='log_to_clear' value='$f'>
                            <input type='hidden' name='clear_log' value='1'>
                            <input type='button' 
                                   data-on-click='confirmClearLog' 
                                   data-filename='$f' 
                                   value='Leeren' 
                                   style='width: 100%; font-size: 11px; background: #4b140a;'>
                        </form>
                    </div>
                </div>";
    }
    $system_log_section .= "</div>";

    $chat_backup_section .= "<div class='title-border'>Gelöschte Chats</div>";

    $backup_dir = __DIR__ . "/logs/chat_backups/";
    $backups = [];

    if (is_dir($backup_dir)) {
        $files = glob($backup_dir . "chat_log_*.log");

        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        foreach ($files as $f) {
            $backups[] = basename($f);
        }
    }

    if (empty($backups)) {
        $chat_backup_section .= "<p style='text-align:center; opacity:0.6;'>Keine Backups vorhanden.</p>";
    } else {
        $chat_backup_section .= "<div class='box-container' style='max-height: 300px; overflow-y: auto; margin: 0 auto; width: 90%;'>";
        $chat_backup_section .= "<table class='table' style='width: 100%; border:none;'>";

        foreach ($backups as $b_file) {
            $parts = explode('_', $b_file);
            $display_info = "Log: " . ($parts[2] ?? "?") . " vs " . ($parts[4] ?? "?");
            $date_part = str_replace('.log', '', $parts[5] ?? "");

            $url = "ajax/admin_log_view.php?file=$b_file&sub=chat_backups";

            $chat_backup_section .= "<tr>
                    <td style='font-size: 13px;'>$display_info</td>
                    <td style='font-size: 11px; opacity:0.7;'>$b_file</td>
                    <td class='td-center'>
                        <button data-on-click='openOverlay' data-url='$url' data-title='Chat-Backup' style='font-size: 11px; padding: 2px 8px;'>Ansehen</button>
                    </td>
                  </tr>";
        }
        $chat_backup_section .= "</table></div>";
    }

    // Display all users
    $result = $db_instance->execute_query("SELECT id, username FROM users");

    $user_list .= '<div class="box-container" style="max-height: 250px; width: 300px; overflow: auto; margin: 0 auto;">';

    foreach ($result as $row) {
        $user_url = "adminpanel.php?userid=" . e($row["id"]);
        $user_list .= '<div class="box' . (isset($_GET["userid"]) && $_GET["userid"] == $row["id"] ? ' active' : '') . '" 
                            data-on-click="navigate" 
                            data-url="' . $user_url . '">
                    <div style="width: 50px;">
                        ' . $row["id"] . '
                    </div>
                    <div>
                        ' . $row["username"] . '
                    </div>
                  </div>';
    }
    $user_list .= '</div>';

    $game_logs_table .= "<div id='logs' class='title-border'>Game Logs</div>";

    $rows_per_page_logs = 20;
    $current_page_logs = max(1, (int)($_GET["logpage"] ?? 1));

    // Get total number of logs
    $total_logs = $db_instance->execute_query("SELECT COUNT(*) FROM game_logs")->fetch_row()[0];
    $total_pages_logs = ceil($total_logs / $rows_per_page_logs);
    $offset_logs = ($current_page_logs - 1) * $rows_per_page_logs;

    // Load Data for current page
    $logs = $db_instance->execute_query(
        "SELECT l.*, u.username 
     FROM game_logs l 
     LEFT JOIN users u ON l.userid = u.id 
     ORDER BY l.id DESC LIMIT ?, ?",
        [$offset_logs, $rows_per_page_logs]
    );

    $game_logs_table .= "<table class='table logs-table'>
            <thead>
            <tr>
                <th class='td-gradient'>ID</th>
                <th class='td-gradient'>Spieler</th>
                <th class='td-gradient'>Info / Aktion</th>
                <th class='td-gradient'>Datum</th>
                <th class='td-gradient'></th>
            </tr>
            </thead>";
    if ($logs->num_rows > 0) {
        foreach ($logs as $l) {
            $details = json_decode($l['details'], true);

            if ($l['username']) {
                $user_display = e($l['username']) . " <small>({$l['userid']})</small>";
            } else if (isset($details['username'])) {
                $user_display = e($details['username']);
            } else {
                $user_display = "<i>System</i>";
            }

            $action_clean = str_replace('_', ' ', $l['action']);

            $game_logs_table .= "<tr>
                <td class='log-id'>{$l['id']}</td>
                <td class='log-player'>$user_display</td>
                <td class='log-content'>
                    <div class='log-stack'>
                        <span class='log-cat'>[" . e($l['category']) . "]</span>
                        <span class='log-action-text'>$action_clean</span>
                    </div>
                </td>
                <td class='log-date'>" . date("d.m.Y H:i:s", $l['created_at']) . "</td>
                <td class='td-center'>
                    <a href='#' data-on-click='confirmDeleteLog' data-id='{$l['id']}' data-page='$current_page_logs'>
                        <img src='images/icons/icon_delete.png' class='ressource-icons' alt='X'>
                    </a>
                </td>
              </tr>";
        }
    } else {
        $game_logs_table .= "<tr><td colspan='6' class='td-center'>Keine Einträge gefunden.</td></tr>";
    }
    $game_logs_table .= "</table>";

    // Pagination Bar
    if ($total_pages_logs > 1) {
        $game_logs_table .= '<div class="pagination-container"><div class="pagination-bar">';

        $get_params = $_GET;
        $get_params['tab'] = 'gamelogs';

        if ($current_page_logs > 1) {
            $get_params['logpage'] = 1;
            $game_logs_table .= "<a href='adminpanel.php?" . http_build_query($get_params) . "#logs' class='page-link'>&laquo;</a>";
            $get_params['logpage'] = $current_page_logs - 1;
            $game_logs_table .= "<a href='adminpanel.php?" . http_build_query($get_params) . "#logs' class='page-link'>&lsaquo;</a>";
        }

        $range = 2;
        for ($i = ($current_page_logs - $range); $i <= ($current_page_logs + $range); $i++) {
            if ($i > 0 && $i <= $total_pages_logs) {
                $get_params['logpage'] = $i;
                $active = ($i == $current_page_logs) ? "active" : "";
                if ($i == $current_page_logs) {
                    $game_logs_table .= "<span class='page-link active'>$i</span>";
                } else {
                    $game_logs_table .= "<a href='adminpanel.php?" . http_build_query($get_params) . "#logs' class='page-link'>$i</a>";
                }
            }
        }

        if ($current_page_logs < $total_pages_logs) {
            $get_params['logpage'] = $current_page_logs + 1;
            $game_logs_table .= "<a href='adminpanel.php?" . http_build_query($get_params) . "#logs' class='page-link'>&rsaquo;</a>";
            $get_params['logpage'] = $total_pages_logs;
            $game_logs_table .= "<a href='adminpanel.php?" . http_build_query($get_params) . "#logs' class='page-link'>&raquo;</a>";
        }

        $game_logs_table .= "</div></div>";
    }
}

$flash_html = "";
if (isset($_SESSION["admin_flash_msg"])) {
    $flash_html = $_SESSION["admin_flash_msg"];

    unset($_SESSION["admin_flash_msg"]);
}

$tab_menu = "<div class='tab'>
    <div class='tablinks " . ($active_tab == 'system' ? 'active' : '') . "' data-on-click='switchAdminTab' data-tab='system'>System</div>
    <div class='tablinks " . ($active_tab == 'users' ? 'active' : '') . "' data-on-click='switchAdminTab' data-tab='users'>Nutzerverwaltung</div>
    <div class='tablinks " . ($active_tab == 'logs' ? 'active' : '') . "' data-on-click='switchAdminTab' data-tab='logs'>Server-Logs</div>
    <div class='tablinks " . ($active_tab == 'gamelogs' ? 'active' : '') . "' data-on-click='switchAdminTab' data-tab='gamelogs'>Spiel-Logs</div>
</div>";

$view = $flash_html . $tab_menu;

if (!empty($error)) {
    $view .= show_error_box($error);
}

// TAB: System
$view .= "<div id='tab_system' class='admin-tab' style='display: " . ($active_tab == 'system' ? 'block' : 'none') . ";'>
    $settings_list
</div>";

// TAB: User
$view .= "<div id='tab_users' class='admin-tab' style='display: " . ($active_tab == 'users' ? 'block' : 'none') . ";'>
    $user_list
    " . ($user_info_html ?? "") . " 
</div>";

// TAB: Logs (Files & Chat Backups)
$view .= "<div id='tab_logs' class='admin-tab' style='display: " . ($active_tab == 'logs' ? 'block' : 'none') . ";'>
    " . ($system_log_section ?? "") . "
    " . ($chat_backup_section ?? "") . "
</div>";

// TAB: Game Logs
$view .= "<div id='tab_gamelogs' class='admin-tab' style='display: " . ($active_tab == 'gamelogs' ? 'block' : 'none') . ";'>
    " . ($game_logs_table ?? "") . "
</div>";

/*
 * HTML Section
 */
$title = "Admin-Bereich";
$header = "Admin-Bereich";
$script_files = ["adminpanel", "userinfo"];
$head_extra = '<style>html { scroll-behavior: auto !important; }</style>';

include("layout/base.php");
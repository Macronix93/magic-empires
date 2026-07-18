<?php
require_once("includes/core.php");

check_user_login($user);

$user_list = "";
$user_id = -1;

if (!$user->is_admin()) {
    $error = "Du bist kein Administrator!";
} else {
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

                $total = mt_rand(MIN_RESOURCES_FOR_TILE, MAX_RESOURCES_FOR_TILE);
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

    if (isset($_POST["toggle_maintenance"])) {
        $new_val = (MAINTENANCE_MODE ? "0" : "1");
        $db_instance->execute_query("UPDATE system_settings SET value = ? WHERE name = 'maintenance_mode'", [$new_val]);
        $logger->admin("MAINTENANCE MODE changed to: " . ($new_val == "1" ? "ON" : "OFF"));

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
        $db_instance->execute_query("DELETE FROM game_logs WHERE id = ?", [$log_id]);
        $logger->admin("Deleted log entry ID $log_id");

        $_SESSION["admin_flash_msg"] = show_passed_box("Log-Eintrag wurde gelöscht!");

        change_location("adminpanel.php");
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

            $view .= show_passed_box("Daten erfolgreich aktualisiert! Feld: $field");
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

            if (isset($_SESSION["admin_flash_msg"])) {
                $view .= $_SESSION["admin_flash_msg"];

                unset($_SESSION["admin_flash_msg"]);
            }

            // Display user info using a loop
            $view .= '<h3>Spieler-Info</h3>';
            $view .= '<table class="table">';

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

                $view .= '<tr>
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

            $view .= '</table>';

            // --- MULTI-ACCOUNT CHECK ---
            $view .= '<h3>Multi-Account Check</h3>';
            $view .= '<table class="table">';

            // Calculate Root IP
            $ip_parts = explode('.', $row["ip"]);
            $subnet = "";
            if (count($ip_parts) === 4) {
                $subnet = $ip_parts[0] . '.' . $ip_parts[1] . '.' . $ip_parts[2] . '.%';
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
            $view .= '<tr><td style="width: 30%;">Gleiches Subnetz:</td><td>';

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

                    $view .= '<a href="adminpanel.php?userid=' . $m['id'] . '" class="error">' . e($m["username"]) . '</a>'
                        . $is_exact . $link_status . '<br>';
                }
            } else {
                $view .= '<span class="passed">Keine Treffer</span>';
            }
            $view .= '</td></tr>';
            $view .= "</table>";

            // Display kingdoms
            $view .= "<h3>Königreiche</h3>";

            if (!empty($kingdoms)) {
                $view .= '<div class="box-container" style="max-height: 200px; width: 300px; overflow: auto; margin: 0 auto;">';

                foreach ($kingdoms as $kingdom_id => $kingdom_data) {
                    $target_url = "adminpanel.php?userid=" . e($user_id) . "&kingdomid=" . e($kingdom_id);

                    $view .= '<div class="box' . (isset($_GET["kingdomid"]) && $_GET["kingdomid"] == $kingdom_id ? ' active' : '') . '" 
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

                $view .= '</div>';
            } else {
                $view .= 'Keine Königreiche gefunden.';
            }

            // Display kingdom data and event data for kingdom
            if (isset($_GET['kingdomid'])) {
                $found_kingdom = null;

                foreach ($kingdoms as $kingdom_id => $kingdom_data) {
                    if ($kingdom_id == $_GET['kingdomid']) {
                        $view .= '<h3>Königreich-Info</h3>';
                        $view .= '<table class="table">
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
                    $view .= '<h3>Event-Info</h3>';

                    if (!empty($found_kingdom['events'])) {
                        $view .= '<h3>Event-Info</h3>';
                        $view .= '<table class="table">
                                    <tr>
                                        <td class="td-gradient"><b>Aktion</b></td>
                                        <td class="td-gradient"><b>ID</b></td>
                                        <td class="td-gradient"><b>Aktion</b></td>
                                    </tr>';

                        foreach ($found_kingdom['events'] as $event) {
                            $view .= '<tr>
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
                        $view .= '</table>';
                    } else {
                        $view .= 'Keine Events für das Königreich gefunden.';
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
                            <form method='POST' style='display:inline; margin-left: 20px;'>
                                <input type='submit' name='toggle_maintenance' value='$m_button'>
                            </form>
                        </div>
                    </div>";
    $settings_list .= "<div class='box-container' style='margin-top: 20px; border-color: #a62121;'>
                        <div class='box-header' style='background: #a62121; color: white;'>Gefahrenzone: Welt-Reset</div>
                        <div class='box-content box-content-bg' style='padding: 15px; text-align: center;'>
                            <p class='error'><b>ACHTUNG:</b> Ein Runden-Reset löscht alle Königreiche, Truppen, Fortschritte und generiert eine komplett neue Karte!</p>
                            <form method='POST'>
                                <input type='button' data-on-click='confirmResetRound' value='RUNDEN-RESET' style='background: #a62121; color: white;'>
                                <input type='hidden' name='reset_round' id='hidden_reset_submit'>
                            </form>
                        </div>
                    </div>";

    // Display all users
    $result = $db_instance->execute_query("SELECT * FROM users");

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
}

$view .= "<br><hr><div class='title-border'>System Logs</div>";

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

$view .= "<table class='table'>
            <tr>
                <td class='td-gradient'><b>ID</b></td>
                <td class='td-gradient'><b>Spieler</b></td>
                <td class='td-gradient'><b>Kat.</b></td>
                <td class='td-gradient'><b>Aktion</b></td>
                <td class='td-gradient'><b>Datum</b></td>
                <td class='td-gradient'><b></b></td>
            </tr>";

if ($logs->num_rows > 0) {
    foreach ($logs as $l) {
        $user_display = $l['username'] ? e($l['username']) . " <small>({$l['userid']})</small>" : "<i>System / Gast</i>";

        $view .= "<tr>
                    <td>{$l['id']}</td>
                    <td>$user_display</td>
                    <td><small>{$l['category']}</small></td>
                    <td style='word-break: break-all'>{$l['action']}</td>
                    <td style='font-size: 13px;'>" . date("d.m. H:i:s", $l['created_at']) . "</td>
                    <td class='td-center'>
                        <a href='#' 
                           data-on-click='confirmDeleteLog' 
                           data-id='{$l['id']}'>
                            <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'>
                        </a>
                    </td>
                  </tr>";
    }
} else {
    $view .= "<tr><td colspan='6' class='td-center'>Keine Einträge gefunden.</td></tr>";
}
$view .= "</table>";

// Pagination Bar
if ($total_pages_logs > 1) {
    $view .= '<div class="pagination-container"><div class="pagination-bar">';

    $get_params = $_GET;

    if ($current_page_logs > 1) {
        $get_params['logpage'] = 1;
        $view .= "<a href='adminpanel.php?" . http_build_query($get_params) . "' class='page-link'>&laquo;</a>";
        $get_params['logpage'] = $current_page_logs - 1;
        $view .= "<a href='adminpanel.php?" . http_build_query($get_params) . "' class='page-link'>&lsaquo;</a>";
    }

    $range = 2;
    for ($i = ($current_page_logs - $range); $i <= ($current_page_logs + $range); $i++) {
        if ($i > 0 && $i <= $total_pages_logs) {
            $get_params['logpage'] = $i;
            $active = ($i == $current_page_logs) ? "active" : "";
            if ($i == $current_page_logs) {
                $view .= "<span class='page-link active'>$i</span>";
            } else {
                $view .= "<a href='adminpanel.php?" . http_build_query($get_params) . "' class='page-link'>$i</a>";
            }
        }
    }

    if ($current_page_logs < $total_pages_logs) {
        $get_params['logpage'] = $current_page_logs + 1;
        $view .= "<a href='adminpanel.php?" . http_build_query($get_params) . "' class='page-link'>&rsaquo;</a>";
        $get_params['logpage'] = $total_pages_logs;
        $view .= "<a href='adminpanel.php?" . http_build_query($get_params) . "' class='page-link'>&raquo;</a>";
    }

    $view .= "</div></div>";
}


/*
 * HTML Section
 */
$title = "Admin-Bereich";
$header = "Admin-Bereich";
$script_files = ["adminpanel"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
} else {
    $view = $settings_list . $user_list . $view;
}

include("layout/base.php");
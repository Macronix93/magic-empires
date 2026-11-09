<?php /** @noinspection ALL */
require_once("includes/core.php");

$user->check_user_login();

$user_list = "";
$user_id = -1;

if (!$user->is_admin()) {
    $error = "Du bist kein Administrator!";
} else {
    $system_log_section = "";
    $chat_backup_section = "";
    $game_logs_table = "";
    $user_info_html = "";

    $allowed_tabs = ['system', 'users', 'logs', 'gamelogs'];
    $active_tab = $_GET['tab'] ?? null;

    if ($active_tab === null) {
        if (isset($_GET['userid'])) {
            $active_tab = 'users';
        } else if (isset($_GET['logpage'])) {
            $active_tab = 'gamelogs';
        } else {
            $active_tab = $_COOKIE['me_admin_tab'] ?? 'system';
        }
    }

    if (!in_array($active_tab, $allowed_tabs)) {
        $active_tab = 'system';
    }

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if (!verify_csrf_token()) {
            $_SESSION["game_error"] = "Ungültiger Sicherheits-Token!";

            change_location("adminpanel.php");
        }

        if (isset($_POST["start_advent_calendar"])) {
            $cal = new AdventCalendar();
            $spawned = $cal->start_for_all_users();

            $logger->admin("Adventskalender gestartet von " . $user->get_user_name() . " ($spawned Geschenke für Tag 1 verteilt)");

            $_SESSION["game_success"] = "Adventskalender für die nächsten 24 Tage gestartet! Tag 1 wurde an <b>$spawned</b> Spieler verteilt.";

            change_location("adminpanel.php?tab=system");
        }

        if (isset($_POST["spawn_test_advent_gifts"])) {
            $cal = new AdventCalendar();
            $spawned = $cal->spawn_all_test_gifts($user->get_user_id());

            $logger->admin("Admin Test-Geschenke (Tag 1 bis 24) gespawnt für " . $user->get_user_name());

            $_SESSION["game_success"] = "Erfolg: Es wurden <b>$spawned Test-Geschenke (Tag 1 bis 24)</b> exklusiv für deinen Account auf der Karte verteilt!";

            change_location("adminpanel.php?tab=system");
        }

        if (isset($_POST["spawn_test_advent_user"])) {
            $tname = trim($_POST["test_username"] ?? "");

            $target_u = $db_instance->execute_query("SELECT id FROM users WHERE username = ? LIMIT 1", [$tname])->fetch_assoc();

            if ($target_u) {
                $t_id = (int)$target_u["id"];
                $cal = new AdventCalendar();
                $spawned = $cal->spawn_all_test_gifts($t_id);

                $db_instance->execute_query("UPDATE users SET advent_intro_seen = 0 WHERE id = ?", [$t_id]);

                $_SESSION["game_success"] = "Erfolg: <b>$spawned Test-Geschenke</b> für User <b>" . e($tname) . "</b> gespawnt und Modal-Intro aktiviert!";
            } else {
                $_SESSION["game_error"] = "Spieler '$tname' wurde nicht gefunden.";
            }

            change_location("adminpanel.php?tab=system");
        }

        if (isset($_POST["stop_advent_calendar"])) {
            $cal = new AdventCalendar();
            $cal->stop();

            $logger->admin("Adventskalender beendet von " . $user->get_user_name());

            $_SESSION["game_success"] = "Adventskalender wurde beendet und alle Geschenke entfernt.";

            change_location("adminpanel.php?tab=system");
        }

        if (isset($_POST["reset_arena_season"])) {
            $rewarded_count = Arena::check_and_process_season_reset(true, false);

            $logger->admin("Arena Saison manuell beendet & neugestartet von " . $user->get_user_name() . " ($rewarded_count Spieler belohnt)");
            $_SESSION["game_success"] = "Arena-Saison erfolgreich beendet & direkt neu gestartet! <b>{$rewarded_count}</b> Spieler wurden belohnt.";

            change_location("adminpanel.php?tab=system");
        }

        if (isset($_POST["sync_forum_accounts"])) {
            $result = PhpBBBridge::sync_all_missing_forum_users();

            if (!empty($result['errors'])) {
                $_SESSION["game_error"] = "Fehler beim Foren-Sync:<br>" . implode("<br>", $result['errors']);
            } else {
                $_SESSION["game_success"] = "Foren-Sync abgeschlossen: <b>{$result['created']}</b> neu angelegt, <b>{$result['skipped']}</b> existierten bereits.";
            }

            $logger->admin("Forum User Sync durchgeführt: {$result['created']} neu erstellt.");

            change_location("adminpanel.php?tab=system");
        }

        if (isset($_POST["reset_fixed_march"])) {
            $_POST["fixed_seconds"] = "0";
            $_POST["update_fixed_march"] = "1";
        }

        if (isset($_POST["update_fixed_march"])) {
            $seconds = max(0, min(86400, (int)$_POST["fixed_seconds"]));

            $db_instance->execute_query(
                "UPDATE system_settings SET value = ? WHERE name = 'debug_fixed_march_seconds'",
                [(string)$seconds]
            );

            $logger->admin("Marschzeit gesetzt auf: " . ($seconds > 0 ? "{$seconds}s (Fest)" : "Deaktiviert (Normal)"));
            $_SESSION["game_success"] = ($seconds > 0) ? "Feste Marschzeit auf <b>{$seconds} Sekunden</b> gesetzt!" : "Marschzeit deaktiviert. Es gelten wieder normale Entfernungszeiten.";

            change_location("adminpanel.php?tab=system");
        }

        if (isset($_POST["reset_round"])) {
            $logger->admin("ROUND RESET STARTED by Admin " . $user->get_user_name() . " (ID " . $user->get_user_id() . ")");

            // Set maintenance mode
            $db_instance->execute_query("UPDATE system_settings SET value = '1' WHERE name = 'maintenance_mode'");

            // Clear tables (Order is important dude to foreign keys)
            $tables_to_clear = [
                "marketplace",
                "events",
                "mine_stationed_troops",
                "mines",
                "monster_camps",
                "abandoned_kingdoms",
                "resource_tiles_data",
                "kingdoms",
                "guilds",
                "world_events",
                "server_messages",
                "player_stats",
                "reactions",
                "kingdom_alchemy",
                "arena_battles",
                "arena_hero_skills",
                "arena_players",
                "arena_season_history",
                "shared_battle_reports",
                "advent_calendar_gifts"
            ];

            foreach ($tables_to_clear as $t) {
                $db_instance->query("DELETE FROM $t");
                $db_instance->query("ALTER TABLE $t AUTO_INCREMENT = 1");
            }

            // Reset global counters
            $db_instance->execute_query("
            UPDATE system_settings 
            SET value = '0' 
            WHERE name IN ('total_fallen_soldiers', 'total_slain_monsters', 'total_battles', 'total_trades')
        ");

            // Reset Users
            $db_instance->execute_query("
            UPDATE users SET 
                score = ?, 
                ranking_points = ?, 
                mainkingdom = -1, 
                coins = 0, 
                guildid = -1, 
                guild_rank_id = NULL, 
                is_vacation = 0, 
                vacation_until = 0, 
                daily_trades_count = 0, 
                last_trade_reset = 0,
                last_world_chat_id = 0,
                last_guild_chat_id = 0
        ", [STARTING_SCORE, STARTING_SCORE]);

            // Generate a new random map
            $spawner = new MapSpawner($db_instance);
            $spawner->generate_base_map();

            // Create new kingdom for every registered and activated user
            $res_users = $db_instance->query("SELECT id, username FROM users WHERE status = 1");
            $kingdom_manager = new Kingdom();

            while ($u = $res_users->fetch_assoc()) {
                $new_k_id = $kingdom_manager->create_kingdom($u["id"], $u["username"]);

                if ($new_k_id) {
                    $db_instance->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$new_k_id, $u["id"]]);

                    // Send server message to every user
                    $reset_json = [
                        "template" => "round_reset"
                    ];
                    Messages::send_server_message((int)$u["id"], $u["username"], MessageCategories::CATEGORY_DEFAULT, $reset_json);
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
                    $res_values = MapSpawner::generate_resource_tile_values();

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

            $_SESSION["game_success"] = "Runden-Reset erfolgreich durchgeführt! Alle Spieler haben ein neues Königreich erhalten.";

            change_location("adminpanel.php");
        }

        if (isset($_POST["spawn_map_entities"])) {
            $spawn_type = $_POST["spawn_type"];
            $spawner = new MapSpawner($db_instance);
            $report = [];

            if ($spawn_type === "all" || $spawn_type === "resources") {
                $count = $spawner->spawn_resources(true);
                $report[] = $count > 0 ? "$count Ressourcenfelder generiert." : "Ressourcenlimit bereits erreicht.";
            }
            if ($spawn_type === "all" || $spawn_type === "monsters") {
                $count = $spawner->spawn_monster_camps(true);
                $report[] = $count > 0 ? "$count Monstercamps balance-optimiert generiert." : "Monsterlimit bereits erreicht.";
            }
            if ($spawn_type === "all" || $spawn_type === "mines") {
                $count = $spawner->spawn_mines(true);
                $report[] = $count > 0 ? "$count Erzminen generiert (Voll: " . MAX_MINES . ")." : "Minenlimit bereits erreicht.";
            }

            $logger->admin("MANUAL MAP SPAWN: $spawn_type");

            $_SESSION["game_success"] = implode("<br>", $report);

            change_location("adminpanel.php?tab=system");
        }

        if (isset($_POST["toggle_maintenance"])) {
            $new_val = (MAINTENANCE_MODE ? "0" : "1");
            $reason = $_POST["maintenance_reason"] ?? "Geplante Wartungsarbeiten";

            $db_instance->execute_query("UPDATE system_settings SET value = ? WHERE name = 'maintenance_mode'", [$new_val]);
            $db_instance->execute_query("UPDATE system_settings SET value = ? WHERE name = 'maintenance_reason'", [$reason]);
            $logger->admin("MAINTENANCE MODE changed to: " . ($new_val == "1" ? "ON ($reason)" : "OFF"));

            change_location("adminpanel.php");
        }

        if (isset($_POST["toggle_winter_theme"])) {
            $curr_server_val = ($sys_settings["winter_theme"] ?? '0') === '1';
            $new_val = ($curr_server_val ? "0" : "1");

            $db_instance->execute_query(
                "UPDATE system_settings SET value = ? WHERE name = 'winter_theme'", [$new_val]
            );

            $logger->admin("WINTER THEME geändert auf: " . ($new_val == "1" ? "ON" : "OFF"));

            $_SESSION["game_success"] = "Winter-Theme wurde <b>" . ($new_val == "1" ? "aktiviert" : "deaktiviert") . "</b>!";

            change_location("adminpanel.php?tab=system");
        }

        if (isset($_POST["toggle_ban"])) {
            $uid = (int)($_POST["user_id"] ?? 0);
            $ban_status = (int)($_POST["ban_status"] ?? 0);

            if ($ban_status === 1) {
                $reason = trim($_POST["reason"] ?? "Verstoß gegen die Regeln");

                $db_instance->execute_query("UPDATE users SET is_banned = 1, ban_reason = ? WHERE id = ?", [$reason, $uid]);
                $logger->admin("BANNED USER ID $uid. Reason: $reason");

                $_SESSION["game_success"] = "Benutzer ID $uid wurde gebannt!";
            } else {
                $db_instance->execute_query("UPDATE users SET is_banned = 0, ban_reason = NULL WHERE id = ?", [$uid]);
                $logger->admin("UNBANNED USER ID $uid");

                $_SESSION["game_success"] = "Benutzer ID $uid wurde entsperrt!";
            }

            change_location("adminpanel.php?userid=" . $uid . "&tab=users");
        }

        if (isset($_POST["toggle_ip_ban"])) {
            $target_ip = trim($_POST["ip"] ?? "");
            $ban_status = (int)($_POST["ip_ban_status"] ?? 0);
            $target_uid = (int)($_POST["user_id"] ?? 0);

            if (filter_var($target_ip, FILTER_VALIDATE_IP)) {
                if ($ban_status === 1) {
                    $reason = trim($_POST["reason"] ?? "Verstoß gegen die Regeln");

                    $db_instance->execute_query(
                        "INSERT INTO banned_ips (ip, reason, banned_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason)",
                        [$target_ip, $reason, time()]
                    );

                    $logger->admin("BANNED IP $target_ip. Reason: $reason");

                    $_SESSION["game_success"] = "Die IP <b>$target_ip</b> wurde gesperrt!";
                } else {
                    $db_instance->execute_query("DELETE FROM banned_ips WHERE ip = ?", [$target_ip]);

                    $logger->admin("UNBANNED IP $target_ip");

                    $_SESSION["game_success"] = "Die IP <b>$target_ip</b> wurde entsperrt!";
                }
            } else {
                $_SESSION["game_error"] = "Ungültige IP-Adresse!";
            }

            change_location("adminpanel.php?userid=" . $target_uid . "&tab=users");
        }

        if (isset($_POST["delete_log"])) {
            $log_id = (int)($_POST["log_id"] ?? 0);
            $page = (int)($_POST["logpage"] ?? 1);

            $db_instance->execute_query("DELETE FROM game_logs WHERE id = ?", [$log_id]);
            $logger->admin("Deleted log entry ID $log_id");

            $_SESSION["game_success"] = "Log-Eintrag wurde gelöscht!";

            change_location("adminpanel.php?tab=gamelogs&logpage=" . $page);
        }

        if (isset($_POST["delete_event"])) {
            $event_id = (int)($_POST["event_id"] ?? 0);
            $target_uid = (int)($_POST["user_id"] ?? 0);

            $db_instance->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
            $logger->admin("Deleted event ID $event_id");

            $_SESSION["game_success"] = "Event wurde manuell abgebrochen/gelöscht!";

            $redir = ($target_uid > 0) ? "?userid=" . $target_uid . "&tab=users" : "?tab=users";
            change_location("adminpanel.php" . $redir);
        }

        if (isset($_POST["clear_log"]) && isset($_POST["log_to_clear"])) {
            $f = $_POST["log_to_clear"];
            $allowed_files = ["admin.log", "error.log", "security.log"];

            if (in_array($f, $allowed_files) && $user->get_user_admin_level() >= ADMIN_LEVEL_FULL_ADMIN) {
                file_put_contents(__DIR__ . "/logs/" . $f, "");

                $logger->admin("Log-Datei $f wurde über das Adminpanel geleert.");

                $_SESSION["game_success"] = "Datei $f wurde erfolgreich geleert.";
            }

            change_location("adminpanel.php?tab=logs");
        }

        if (isset($_POST["delete_user"])) {
            $user_id = (int)($_POST["user_id"] ?? 0);

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
                $username = $row["username"];

                // Check if user was in a guild and leader
                $guild_manager = new Guild($user);
                $guild_manager->handle_leader_deletion($user_id);

                Kingdom::convert_user_kingdoms_to_ruins($user_id);
                User::delete_user_avatar_files($user_id);
                PhpBBBridge::delete_forum_user($username);

                // Delete the user
                $db_instance->execute_query("DELETE FROM users WHERE id = ?", [$user_id]);

                $em = new EventManager($user);
                $em->process_orphaned_support();

                $logger->admin("DELETED USER: $username (ID: $user_id)");

                $flash_box = show_passed_box("Benutzer erfolgreich gelöscht!");
            } else {
                $error .= "Der Benutzer existiert nicht!";
            }
        }

        if (isset($_POST["field"])) {
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
                        $error = "Fehler beim Umbenennen der Datei!";
                    }
                } else if (file_exists($new_file_path)) {
                    $result = true;
                } else {
                    $error = "Datei '$new_filename' wurde im Ordner " . UPLOADS_FILE_PATH . " nicht gefunden!";
                }
            } else {
                $allowed_editable_fields = [
                    "username", "email", "status", "adminlevel", "score",
                    "coins", "mainkingdom", "guildid", "password", "avatar",
                    "is_banned", "ban_reason", "gender", "active_title_id",
                    "is_vacation", "vacation_until", "linked_user_id",
                    "msgcount", "lastsentmsgend", "daily_trades_count"
                ];

                if (!in_array($field, $allowed_editable_fields, true)) {
                    $error = "Ungültiges oder geschütztes Feld!";
                } else {
                    if ($field === "password") {
                        $new_value_db = password_hash(trim($new_value ?? ""), PASSWORD_BCRYPT);

                        $result = $db_instance->execute_query("UPDATE users SET password = ? WHERE id = ?", [$new_value_db, $user_id]);
                        $target_uname = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$user_id])->fetch_column();

                        if ($target_uname) {
                            PhpBBBridge::update_password($target_uname, $new_value_db);
                        }
                    } else if ($field === "score") {
                        $score_val = (int)$new_value;

                        $result = $db_instance->execute_query("UPDATE users SET score = ?, ranking_points = ? WHERE id = ?", [$score_val, $score_val, $user_id]);
                    } else if ($field === "is_vacation" && (int)$new_value === 0) {
                        $result = $db_instance->execute_query("UPDATE users SET is_vacation = 0, vacation_until = 0 WHERE id = ?", [$user_id]);
                    } else if ($field === "linked_user_id" && (empty($new_value) || (int)$new_value <= 0)) {
                        $result = $db_instance->execute_query("UPDATE users SET linked_user_id = NULL WHERE id = ?", [$user_id]);
                    } else {
                        $result = $db_instance->execute_query("UPDATE users SET `$field` = ? WHERE id = ?", [$new_value, $user_id]);
                    }
                }
            }

            if ($result) {
                $log_display = ($field === "password") ? "***" : $new_value;
                $logger->admin("Edited field '$field' from '$old_value' to '$log_display' for user ID $user_id.");

                $_SESSION["game_success"] = "Daten erfolgreich aktualisiert! Feld: $field";

                change_location("adminpanel.php?userid=$user_id&tab=users");
            } else {
                if (empty($view)) {
                    $error = "Fehler beim Aktualisieren! Feld: $field";
                }
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
            $found_kingdom = null;

            foreach ($result as $row) {
                $kingdom_id = $row["kingdom_id"];
                $event_id = $row["event_id"];

                // Process user information only once (for display purposes)
                if (empty($user_info)) {
                    $adm_user = new User($row["id"], $row["username"]);

                    $user_info = [
                        'Name' => ['field' => 'username', 'value' => $row['username'], 'editable' => true],
                        'Geschlecht' => ['field' => 'gender', 'value' => $row['gender'] ?? 'm', 'editable' => true],
                        'Titel-ID' => ['field' => 'active_title_id', 'value' => $row['active_title_id'] ?? 0, 'editable' => true],
                        'Bann-Status' => ['field' => 'is_banned', 'value' => $row['is_banned'], 'editable' => true],
                        'Bann-Grund' => ['field' => 'ban_reason', 'value' => $row['ban_reason'], 'editable' => true],
                        'Passwort' => ['field' => 'password', 'value' => "Passwort", 'editable' => true],
                        'Avatar' => ['field' => 'avatar', 'value' => $adm_user->get_avatar(), 'editable' => true],
                        'Account-Status' => ['field' => 'status', 'value' => $row['status'], 'editable' => true],
                        'Admin-Level' => ['field' => 'adminlevel', 'value' => $row['adminlevel'], 'editable' => true],
                        'E-Mail' => ['field' => 'email', 'value' => $row['email'], 'editable' => true],
                        'Punkte' => ['field' => 'score', 'value' => $row['score'], 'editable' => true],
                        'Münzen' => ['field' => 'coins', 'value' => $row['coins'], 'editable' => true],
                        'Haupt-Königreich' => ['field' => 'mainkingdom', 'value' => $row['mainkingdom'], 'editable' => true],
                        'Gilde-ID' => ['field' => 'guildid', 'value' => $row['guildid'], 'editable' => true],
                        'Urlaubs-Status' => ['field' => 'is_vacation', 'value' => $row['is_vacation'] ?? 0, 'editable' => true],
                        'Urlaub bis' => ['field' => 'vacation_until', 'value' => $row['vacation_until'] ?? 0, 'editable' => true],
                        'Partner-ID' => ['field' => 'linked_user_id', 'value' => $row['linked_user_id'] ?? '', 'editable' => true],
                        'Handels-Zähler' => ['field' => 'daily_trades_count', 'value' => $row['daily_trades_count'] ?? 0, 'editable' => true],
                        'Nachrichten-Zähler' => ['field' => 'msgcount', 'value' => $row['msgcount'], 'editable' => true],
                        'Rate-Limit Ende' => ['field' => 'lastsentmsgend', 'value' => $row['lastsentmsgend'], 'editable' => true],
                        // REINE ANZEIGEFELDER (Nicht editierbar):
                        'IP' => ['field' => 'ip', 'value' => $row['ip'], 'editable' => false],
                        'Device-ID' => ['field' => 'device_id', 'value' => $row['device_id'] ?? '-', 'editable' => false],
                        'Registriert am' => ['field' => 'registerdate', 'value' => $row['registerdate'], 'editable' => false],
                        'Letzter Login' => ['field' => 'lastlogin', 'value' => $row['lastlogin'], 'editable' => false],
                        'Letzte Aktivität' => ['field' => 'lastactivity', 'value' => $row['lastactivity'], 'editable' => false],
                        'Rang um 0 Uhr' => ['field' => 'lastrank', 'value' => $row['lastrank'], 'editable' => false]
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
                $is_editable = $data["editable"] ?? true;
                $display_value = "";

                if ($label === "Avatar") {
                    $display_value = '<img class="user-image" src="' . e($raw_value) . '" alt="Nutzerbild">';
                } else if (in_array($label, ["Registriert am", "Letzter Login", "Letzte Aktivität", "Rate-Limit Ende", "Urlaub bis"])) {
                    $display_value = ((int)$raw_value > 0) ? date("d.m.Y H:i:s", (int)$raw_value) : "-";
                } else if ($label === "Punkte") {
                    $display_value = fnum($raw_value);
                } else {
                    $display_value = e((string)$raw_value);
                }

                if ($label === "Name") {
                    $display_value .= ' [ID: ' . $user_id . ']';
                }

                $edit_action = '';
                if ($is_editable) {
                    $edit_action = ' <a href="#" data-on-click="editUserField" 
                                                    data-userid="' . e($user_id) . '" 
                                                    data-fieldid="' . e($field_id) . '" 
                                                    data-raw="' . e((string)$raw_value) . '" 
                                                    data-formatted="' . e($display_value) . '">
                                        <img src="images/icons/icon_edit.png" class="ressource-icons" alt="Editieren">
                                    </a>';
                }

                $ip_action = '';
                if ($label === "IP" && !empty($raw_value)) {
                    $is_ip_banned = (bool)$db_instance->execute_query(
                        "SELECT 1 FROM banned_ips WHERE ip = ? LIMIT 1",
                        [$raw_value]
                    )->fetch_column();

                    $display_value .= $is_ip_banned
                        ? " <b class='error'>[GESPERRT]</b>"
                        : " <span class='passed'>[Aktiv]</span>";

                    $ip_icon = $is_ip_banned ? 'icon_checked.png' : 'icon_error.png';
                    $ip_title = $is_ip_banned ? 'IP entsperren' : 'IP sperren';
                    $ip_status_flag = $is_ip_banned ? '0' : '1';

                    $ip_action = ' <a href="#" data-on-click="banIpDialog" data-userid="' . e($user_id) . '" data-ip="' . e($raw_value) . '" data-status="' . $ip_status_flag . '">
                                    <img src="images/icons/' . $ip_icon . '" class="ressource-icons" alt="IP Ban" title="' . $ip_title . '">
                                </a>';
                }

                $user_info_html .= '<tr>
                                    <td style="width: 30%;">' . $label . ':</td>
                                    <td id="td_' . $field_id . '">' . $display_value . '</td>
                                    <td class="td-center" style="border-bottom: 1px solid var(--box-header);">'
                    . $edit_action
                    . ($label === "Name" ? ' <a href="#" 
                                                    data-on-click="banUserDialog" 
                                                    data-userid="' . e($user_id) . '" 
                                                    data-username="' . e($row["username"]) . '" 
                                                    data-status="' . e($row["is_banned"]) . '">
                        <img src="images/icons/' . ($row["is_banned"] ? 'icon_checked.png' : 'icon_error.png') . '" class="ressource-icons" alt="Bannen" title="Account sperren/entsperren">
                    </a> <a href="#" data-on-click="userDeletionDialog" data-userid="' . e($user_id) . '" data-username="' . e($row["username"]) . '">
                        <img src="images/icons/icon_delete.png" class="ressource-icons" alt="Löschen">
                    </a>' : '')
                    . $ip_action . '
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
                "SELECT id, username, ip, linked_user_id FROM users WHERE ip LIKE ? AND id != ?",
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

                    $consider_linked = ((int)($row["linked_user_id"] ?? 0) === (int)$m["id"]);
                    $back_linked = ((int)($m["linked_user_id"] ?? 0) === (int)$user_id);

                    if ($consider_linked && $back_linked) {
                        $link_status = ' <b class="passed">[Gegenseitig angemeldet]</b>';
                    } else if ($consider_linked || $back_linked) {
                        $link_status = ' <b class="event-warning">[Einseitig angemeldet!]</b>';
                    } else {
                        $link_status = ' <b class="error">[NICHT ANGEMELDET!]</b>';
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
    }

    // Show Server Settings
    $m_status = MAINTENANCE_MODE ? "<span class='error'>AKTIV</span>" : "<span class='passed'>Inaktiv</span>";
    $m_button = MAINTENANCE_MODE ? "Deaktivieren" : "Aktivieren";
    $w_status = $server_winter ? "<span class='passed' style='font-weight: bold;'>AKTIV</span>" : "<span class='error'>Inaktiv</span>";
    $w_button = $server_winter ? "Deaktivieren" : "Aktivieren";

    $settings_list = "<div class='box-container' style='margin-bottom: 20px;'>
        <div class='box-header'>System-Steuerung</div>
            <div class='box-content box-content-bg' style='padding: 15px;'>
                <div style='display: flex; flex-direction: column; width: 100%; max-width: 450px; margin: 0 auto; gap: 10px;'>
                    <div style='display: flex; justify-content: space-between; align-items: center;'>
                        <div>
                            <b>Wartungsmodus:</b> $m_status
                        </div>
                        <form method='POST' id='maint_form' style='margin: 0;'>
                            <input type='hidden' name='toggle_maintenance' value='1'>
                            <input type='hidden' name='maintenance_reason' id='maint_reason_input' value=''>
                            <input type='button' data-on-click='confirmMaintenance' value='" . (MAINTENANCE_MODE ? "Deaktivieren" : "Aktivieren") . "'>
                        </form>
                    </div>
                    <div style='display: flex; justify-content: space-between; align-items: center;'>
                        <div>
                            <b>Winter-Theme & Schnee:</b> $w_status
                        </div>
                        <form method='POST' style='margin: 0;'>
                            <input type='hidden' name='toggle_winter_theme' value='1'>
                            <input type='submit' value='$w_button'>
                        </form>
                    </div>
                </div>
            </div>
    </div>";
    $settings_list .= "<div class='box-container' style='margin-top: 20px;'>
                    <div class='box-header'>Karten-Wartung</div>
                    <div class='box-content box-content-bg' style='padding: 15px; text-align: center;'>
                        <p style='margin-top: 0;'>Manuelle Generierung von Objekten auf freien Feldern.</p>
                        <form method='POST' style='display: flex; gap: 10px; justify-content: center;'>
                            <select name='spawn_type' style='width: 200px;'>
                                <option value='all'>Alles füllen</option>
                                <option value='resources'>Nur Ressourcen</option>
                                <option value='monsters'>Nur Monster</option>
                                <option value='mines'>Nur Minen</option>
                            </select>
                            <input type='submit' name='spawn_map_entities' value='Generieren'>
                        </form>
                    </div>
                </div>";
    $current_fixed = DEBUG_FIXED_MARCH_SECONDS;
    $status_badge = ($current_fixed > 0)
        ? "<span class='error' style='font-weight: bold;'>AKTIV ({$current_fixed} Sek.)</span>"
        : "<span class='passed'>Inaktiv (Normal)</span>";

    $reset_disabled = ($current_fixed === 0) ? "disabled" : "";
    $settings_list .= "
     <div class='box-container' style='margin-top: 20px;'>
         <div class='box-header'>Marschzeiten-Steuerung</div>
         <div class='box-content box-content-bg' style='padding: 15px; text-align: center;'>
             <span>Status: $status_badge</span>
             <p style='opacity: 0.8;'>Überschreibt alle Marschgeschwindigkeiten mit einer festen Sekundenzahl.<br>Trage <b>0</b> ein, um die normale Berechnung zu aktivieren.</p>
             <form method='POST' style='display: flex; gap: 10px; justify-content: center; align-items: center; flex-wrap: wrap;'>
                 <label for='fixed_seconds'>Dauer (Sekunden):</label>
                 <input type='text' inputmode='numeric' pattern='[0-9]*' name='fixed_seconds' id='fixed_seconds'  value='$current_fixed'  style='width: 60px;' required>
                 <input type='submit' name='update_fixed_march' value='Speichern'>
                 <input type='submit' name='reset_fixed_march' value='Reset' $reset_disabled>
             </form>
         </div>
     </div>";
    $settings_list .= "
    <div class='box-container' style='margin-top: 20px;'>
        <div class='box-header'>Forum-Synchronisation</div>
        <div class='box-content box-content-bg' style='padding: 15px; text-align: center;'>
            <p style='margin-top: 0;'>Erstellt fehlende Benutzerkonten im phpBB-Forum nach.</p>
            <form method='POST'>
                <input type='submit' name='sync_forum_accounts' value='Fehlende Foren-User synchronisieren'>
            </form>
        </div>
    </div>";
    $settings_list .= "
    <div class='box-container' style='margin-top: 20px;'>
        <div class='box-header'>Arena-Steuerung</div>
        <div class='box-content box-content-bg' style='padding: 15px; text-align: center;'>
            <p style='margin-top: 0;'>Setzt alle Arena-Wertungen und Helden zurück.</p>
            <form method='POST'>
                <input type='submit' name='reset_arena_season' value='Arena Saison-Reset jetzt durchführen' onclick='return confirm(\"Bist du sicher? Alle Belohnungen werden ausgeschüttet und eine neue Saison gestartet!\");'>
            </form>
        </div>
    </div>";

    $cal_active = AdventCalendar::is_active();
    $cal_status = $cal_active
        ? "<span class='passed' style='font-weight:bold;'>AKTIV (Tag " . AdventCalendar::get_current_day() . " von 24)</span>"
        : "<span class='error'>Inaktiv</span>";
    $settings_list .= "
    <div class='box-container' style='margin-top: 20px;'>
        <div class='box-header'>Adventskalender-Steuerung (Testmodus)</div>
        <div class='box-content box-content-bg' style='padding: 15px; text-align: center;'>
            <p style='margin-top: 0;'>Status: $cal_status</p>
            <div style='display: flex; gap: 10px; flex-direction: column; justify-content: center; flex-wrap: wrap; align-items: center;'>
                <form method='POST'>
                    <input type='submit' name='spawn_test_advent_gifts' value='24 Test-Geschenke für MICH'>
                </form>
                <form method='POST' style='display:flex; gap:5px; align-items:center;'>
                    <input type='text' name='test_username' placeholder='Test-User Name...' style='width: 140px;' required>
                    <input type='submit' name='spawn_test_advent_user' value='Für Test-User'>
                </form>
                " . (!$cal_active ? "
                <form method='POST'>
                    <input type='button' data-on-click='confirmStartAdvent' value='🎄 Für alle starten (24 Tage)'>
                </form>" : "
                <form method='POST'>
                    <input type='button' data-on-click='confirmStopAdvent' value='Kalender stoppen & leeren' class='btn-delete'>
                </form>") . "
            </div>
        </div>
    </div>";
    $settings_list .= "
    <div class='box-container' style='margin-top: 20px; border-color: #a62121;'>
        <div class='box-header' style='background: #a62121; color: white; border-bottom: #340202 2px solid;'>Welt-Reset</div>
        <div class='box-content box-content-bg-danger' style='padding: 15px; text-align: center;'>
            <p class='error'><b>ACHTUNG:</b> Ein Runden-Reset löscht alle Königreiche, Truppen, Fortschritte und generiert eine komplett neue Karte!</p>
            <form method='POST'>
                <input type='button' data-on-click='confirmResetRound' value='RUNDEN-RESET' style='color: white;'>
                <input type='hidden' name='reset_round' id='hidden_reset_submit'>
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
                        <button data-on-click='openOverlay' data-url='$url' data-title='Log-Viewer' data-width='1050px' style='width: 100%; margin-bottom: 5px;'>Ansehen</button>
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

    $rows_per_page_logs = ADMIN_LOGS_PER_PAGE;
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

$tab_menu = "<div class='tab'>
    <div class='tablinks " . ($active_tab == 'system' ? 'active' : '') . "' data-on-click='switchAdminTab' data-tab='system'>System</div>
    <div class='tablinks " . ($active_tab == 'users' ? 'active' : '') . "' data-on-click='switchAdminTab' data-tab='users'>Nutzerverwaltung</div>
    <div class='tablinks " . ($active_tab == 'logs' ? 'active' : '') . "' data-on-click='switchAdminTab' data-tab='logs'>Server-Logs</div>
    <div class='tablinks " . ($active_tab == 'gamelogs' ? 'active' : '') . "' data-on-click='switchAdminTab' data-tab='gamelogs'>Spiel-Logs</div>
</div>";

$view = $tab_menu;

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
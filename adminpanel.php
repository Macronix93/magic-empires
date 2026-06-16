<?php
require_once("includes/core.php");

check_user_login($user);

$user_list = "";
$user_id = -1;

if ($user->get_user_admin_level() == 0) {
    $error = "Du bist kein Administrator!";
} else {
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

            $multi_ip = $db_instance->execute_query("SELECT id, username FROM users WHERE ip = ? AND id != ?", [$row['ip'], $user_id]);
            $multi_device = $db_instance->execute_query("SELECT id, username FROM users WHERE device_id = ? AND id != ? AND device_id IS NOT NULL", [$row['device_id'], $user_id]);

            // Show IP matching
            $view .= '<tr><td style="width: 30%;">Gleiche IP:</td><td>';

            if ($multi_ip->num_rows > 0) {
                foreach ($multi_ip as $m) {
                    $view .= '<a href="adminpanel.php?userid=' . $m['id'] . '" class="error">' . e($m['username']) . '</a> ';
                }
            } else {
                $view .= '<span class="passed">Keine Treffer</span>';
            }

            $view .= '</td></tr>';

            // Show Device ID matching
            $view .= "<tr><td>Gleiches Gerät:</td><td>";

            if ($multi_device->num_rows > 0) {
                foreach ($multi_device as $m) {
                    $view .= '<a href="adminpanel.php?userid=' . $m['id'] . '" class="error">' . e($m['username']) . ' (Fingerabdruck)</a> ';
                }
            } else {
                $view .= '<span class="passed">Keine Treffer</span>';
            }

            $view .= "</td></tr>";
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
                        $view .= '<table class="table">';

                        foreach ($found_kingdom['events'] as $event) {
                            $view .= '<tr>
                                        <td>Aktion:</td>
                                        <td>' . $event['action_id'] . ' [ID: ' . $event['event_id'] . ']</td>
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
        $user_id = htmlspecialchars($_GET["deleteuser"]);

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


/*
 * HTML Section
 */
$title = "Admin-Bereich";
$header = "Admin-Bereich";
$script_files = ["adminpanel"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
} else {
    $view = $user_list . $view;
}

include("layout/base.php");
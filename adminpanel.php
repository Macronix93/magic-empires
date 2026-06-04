<?php
require_once("includes/core.php");

check_user_login($user);

$user_list = "";
$user_id = -1;

if ($user->get_user_admin_level() == 0) {
    $error = "Du bist kein Administrator!";
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['field'])) {
        $field = $_POST['field'];
        $old_value = $_POST['old_value'];
        $new_value = $_POST['new_value'];
        $user_id = $_POST['user_id'];

        // Update avatar file
        if ($field == "avatar") {
            $file_path = UPLOADS_FILE_PATH . $old_value;
            $temp_file_path = UPLOADS_FILE_PATH . $user->get_user_name() . '_temp.' . pathinfo($new_value, PATHINFO_EXTENSION); // Create temp file path
            $new_file_path = UPLOADS_FILE_PATH . $new_value;

            if (file_exists($file_path)) {
                if (!rename($file_path, $new_file_path)) {
                    $view .= show_error_box("Fehler beim Aktualisieren des Avatars!");
                }
            }
        } else {
            if ($field == "password") {
                $new_value = make_secure($new_value ?? "");
                $new_value = password_hash($new_value, PASSWORD_BCRYPT);
            }

            $result = $db_instance->execute_query("UPDATE users SET $field = ? WHERE id = ?", [$new_value, $user_id]);
        }

        if ($result) {
            if ($field == "password") {
                $new_value = $_POST['new_value'];
            }

            $view .= show_passed_box("Daten erfolgreich aktualisiert! Field: $field Value: $new_value");
        } else {
            $view .= show_error_box("Fehler beim Aktualisieren! Field: $field Value: $new_value");
        }
    }

    // Show user related info if clicked on a user
    if (isset($_GET['userid'])) {
        $user_id = $_GET['userid'];

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
                $kingdom_id = $row['kingdom_id'];
                $event_id = $row['event_id'];
                $adm_user = new User($row['id'], $row['username']);

                // Process user information only once (for display purposes)
                if (empty($user_info)) {
                    $user_info = [
                        'Name' => ['field' => 'username', 'value' => $row['username']],
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
                            <td class="td-center">
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

            // Display kingdoms
            $view .= '<h3>Königreiche</h3>';

            if (!empty($kingdoms)) {
                $view .= '<div class="box-container" style="max-height: 200px; width: 300px; overflow: auto;">';

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

            $view .= show_passed_box("Benutzer erfolgreich gelöscht!");
        } else {
            $error .= "Der Benutzer existiert nicht!";
        }
    }

    // Display all users
    $result = $db_instance->execute_query("SELECT * FROM users");

    $user_list .= '<div class="box-container" style="max-height: 250px; width: 300px; overflow: auto;">';

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
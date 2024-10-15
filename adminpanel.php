<?php
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$view = "";
$error = "";
$user_id = -1;

if ($user->get_user_admin_level() == 0) {
    $error = "Du bist kein Administrator!";
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['field'])) {
        $field = $_POST['field'];
        $old_value = $_POST['old_value'];
        $new_value = $_POST['new_value'];
        $user_id = $_POST['user_id'];

        $result = $db_instance->execute_query("UPDATE users SET $field = ? WHERE id = ?", [$new_value, $user_id]);

        // Update avatar file
        if ($field == "username") {
            $file_path = UPLOADS_FILE_PATH . $old_value;
            $new_file_path = UPLOADS_FILE_PATH . $new_value;

            if (file_exists($file_path)) {
                if (!rename($file_path, $new_file_path)) {
                    $view .= '<div class="info-box">Fehler beim Aktualisieren des Avatars!</div>';
                }
            }
        }

        if ($result) {
            $view .= '<div class="info-box">Daten erfolgreich aktualisiert! Field: ' . $field . ' Value: ' . $new_value . '</div>';
        } else {
            $view .= '<div class="info-box">Fehler beim Aktualisieren! Field: ' . $field . ' Value: ' . $new_value . '</div>';
        }
    }

    // Display all users
    $result = $db_instance->execute_query("SELECT * FROM users");

    $view .= '<div class="box-container" style="max-height: 250px; width: 300px; overflow: auto;">';
    foreach ($result as $row) {
        $view .= '<div class="box' . (isset($_GET["userid"]) && $_GET["userid"] == $row["id"] ? ' active' : '') . '" onclick="navigateTo(\'adminpanel.php?userid=' . $row["id"] . '\', this)">
                    <div style="width: 50px;">
                        ' . $row["id"] . '
                    </div>
                    <div>
                        ' . $row["username"] . '
                    </div>
                  </div>';
    }
    $view .= '</div>';

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

        $kingdoms = [];
        $events = [];
        $user_info = [];
        $found_kingdom = -1;

        foreach ($result as $row) {
            $kingdom_id = $row['kingdom_id'];
            $event_id = $row['event_id'];

            // Process user information only once (for display purposes)
            if (empty($user_info)) {
                $user_info = [
                    'Name' => ['field' => 'username', 'value' => $row['username']],
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
                    'Münzen' => ['field' => 'gems', 'value' => $row['gems']],
                    'Letzte Nachricht' => ['field' => 'lastsentmsg', 'value' => $row['lastsentmsg']]
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
            $field_id = $data['field'];
            $value = $data['value'];

            if ($label === "Name") {
                $value = ' ' . $value . ' [ID: ' . $user_id . ']';
            } else if (in_array($label, ["Registriert am", "Letzter Login", "Letzte Aktivität", "Letzte Nachricht"])) {
                $value = date("d.m.Y", $value) . ' um ' . date("H:i:s", $value);
            } else if ($label === "Punkte") {
                $value = fnum($value);
            }

            $view .= '<tr>
                        <td style="width: 30%;">' . $label . ':</td>
                        <td id="td_' . $field_id . '">
                            ' . $value . '
                        </td>
                        <td class="td-center">
                            <a onclick="editField(\'' . $field_id . '\', \'' . htmlspecialchars($data['value']) . '\', \'' . htmlspecialchars($value) . '\')">
                                <img src="images/icons/icon_edit.png" class="ressource-icons" alt="Editieren">
                            </a>
                            ' . ($label === "Name" ? '
                            <a onclick="confirmAndRedirect(\'adminpanel.php?deleteuser=' . $user_id . '\')">
                                <img src="images/icons/icon_delete.png" class="ressource-icons" alt="Löschen">
                            </a>
                            ' : '') . '
                        </td>
                      </tr>';
        }

        $view .= '</table>';

        // Display kingdoms
        $view .= '<h3>Königreiche</h3>';

        if (!empty($kingdoms)) {
            $view .= '<div class="box-container" style="max-height: 200px; width: 300px; overflow: auto;">';

            foreach ($kingdoms as $kingdom_id => $kingdom_data) {
                $view .= '<div class="box' . (isset($_GET["kingdomid"]) && $_GET["kingdomid"] == $kingdom_id ? ' active' : '') . '" onclick="navigateTo(\'adminpanel.php?userid=' . $user_id . '&kingdomid=' . $kingdom_id . '\', this)">
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
    } else if (isset($_GET["deleteuser"])) {
        $view .= "<h3>Delete User</h3>";
    }
}


/*
 * HTML Section
 */
$title = "Admin-Bereich";
$header = "Admin-Bereich";

if (!empty($error)) {
    $view = "<div class='info-box'>" . $error . "</div>" . $view;
}

include('layout/base.php');
?>
<script>
    function editField(fieldId, currentValue, formattedValue) {
        // Get the table cell by ID
        const td = document.getElementById('td_' + fieldId);

        // Create a new input element
        const input = document.createElement('input');
        input.type = 'text';
        input.value = currentValue;

        // Create a hidden form to submit the new value
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';

        // Create hidden inputs for the field, user ID and current/new values for the fields
        const hiddenField = document.createElement('input');
        hiddenField.type = 'hidden';
        hiddenField.name = 'field';
        hiddenField.value = fieldId;

        const hiddenUserId = document.createElement('input');
        hiddenUserId.type = 'hidden';
        hiddenUserId.name = 'user_id';
        hiddenUserId.value = '<?= htmlspecialchars($user_id); ?>';

        const hiddenNewValue = document.createElement('input');
        hiddenNewValue.type = 'hidden';
        hiddenNewValue.name = 'new_value';
        hiddenNewValue.value = currentValue;

        const hiddenCurrentValue = document.createElement('input');
        hiddenCurrentValue.type = 'hidden';
        hiddenCurrentValue.name = 'old_value';
        hiddenCurrentValue.value = currentValue;

        form.appendChild(hiddenField);
        form.appendChild(hiddenUserId);
        form.appendChild(hiddenNewValue);
        form.appendChild(hiddenCurrentValue);
        form.appendChild(input);

        // Clear the current cell and append the form
        td.innerHTML = '';
        td.appendChild(form);

        // Add event listeners for the input field
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                hiddenNewValue.value = input.value;
                form.submit();
            } else if (event.key === 'Escape') {
                cancelEdit(td, formattedValue);
            }
        });

        // When focus is lost: cancel the edit
        input.addEventListener('blur', function () {
            cancelEdit(td, formattedValue);
        });

        input.focus();
    }

    function cancelEdit(td, originalValue) {
        td.innerHTML = originalValue;
    }

    function confirmAndRedirect(url) {
        const confirmed = confirm("Are you sure you want to proceed?");

        if (confirmed) {
            window.location.href = url;
        }
    }
</script>
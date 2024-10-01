<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$view = "";
$error = "";

if ($user->get_user_admin_level() == 0) {
    $error = "Du bist kein Administrator!";
} else {
    $result = $db_instance->execute_query("SELECT * FROM users");

    $view .= '<div class="box-container" style="height: 200px; width: 50%; overflow: auto;">';

    foreach ($result as $row) {
        $view .= '<div class="box' . (isset($_GET["userid"]) && $_GET["userid"] == $row["id"] ? ' active' : '') . '" onclick="navigateTo(\'adminpanel.php?userid=' . $row["id"] . '\', this)">
                    <div style="width: 50px; text-align: center;">
                        ' . $row["id"] . '
                    </div>
                    <div>
                        ' . $row["username"] . '
                    </div>
                  </div>';
    }

    $view .= '</div>';

    // Show user related info
    if (isset($_GET['userid'])) {
        $user_id = $_GET['userid'];

        $query = "SELECT 
                    users.id AS user_id, 
                    users.username, 
                    users.registerdate, 
                    users.lastlogin, 
                    users.lastactivity, 
                    users.lastsentmsg, 
                    users.status, 
                    users.adminlevel, 
                    users.email, 
                    users.ip, 
                    users.lastrank, 
                    users.score, 
                    users.mainkingdom, 
                    users.guildid, 
                    users.gems, 
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
        $userInfo = [];

        foreach ($result as $row) {
            $kingdom_id = $row['kingdom_id'];

            // Process user information only once (for display purposes)
            if (empty($userInfo)) {
                $userInfo = [
                    'username' => $row['username'],
                    'registerdate' => $row['registerdate'],
                    'lastlogin' => $row['lastlogin'],
                    'lastactivity' => $row['lastactivity'],
                    'lastsentmsg' => $row['lastsentmsg'],
                    'status' => $row['status'],
                    'adminlevel' => $row['adminlevel'],
                    'email' => $row['email'],
                    'ip' => $row['ip'],
                    'lastrank' => $row['lastrank'],
                    'score' => $row['score'],
                    'mainkingdom' => $row['mainkingdom'],
                    'guildid' => $row['guildid'],
                    'gems' => $row['gems']
                ];
            }

            // Add the kingdom data if not already added
            if (!isset($kingdoms[$kingdom_id])) {
                $kingdoms[$kingdom_id] = [
                    'kingdomname' => $row['kingdomname'],
                    'events' => []
                ];
            }

            // Add events if they exist
            if (!empty($row['event_id'])) {
                $kingdoms[$kingdom_id]['events'][] = [
                    'event_id' => $row['event_id'],
                    'action_id' => $row['action_id']
                ];
            }
        }

        $view .= '<h3>Benutzer-Info</h3>';
        $view .= '<table class="table">
            <tr>
                <td>Name:</td>
                <td>' . $userInfo['username'] . ' [ID: ' . $user_id . '] <button onclick="confirmAndRedirect(\'adminpanel.php?deleteuser=' . $user_id . '\')">Delete User</button></td>

            </tr>
            <tr>
                <td>Account-Status:</td>
                <td>' . ($userInfo["status"] == 1 ? '<span class="passed">Aktiviert!</span>' : '<span class="error">Nicht aktiviert!</span>') . '</td>
            </tr>
            <tr>
                <td>Passwort:</td>
                <td>******</td>
            </tr>
            <tr>
                <td>IP:</td>
                <td>' . $userInfo['ip'] . '</td>
            </tr>
            <tr>
                <td>Admin-Level:</td>
                <td>' . $userInfo['adminlevel'] . '</td>
            </tr>
            <tr>
                <td>Registriert am:</td>
                <td>' . date("d.m.Y", $userInfo['registerdate']) . ' um ' . date("H:i:s", $userInfo['registerdate']) . '</td>
            </tr>
            <tr>
                <td>E-Mail:</td>
                <td>' . $userInfo['email'] . '</td>
            </tr>
            <tr>
                <td>Letzter Login:</td>
                <td>' . date("d.m.Y", $userInfo['lastlogin']) . ' um ' . date("H:i:s", $userInfo['lastlogin']) . '</td>
            </tr>
            <tr>
                <td>Letzte Aktivität:</td>
                <td>' . date("d.m.Y", $userInfo['lastactivity']) . ' um ' . date("H:i:s", $userInfo['lastactivity']) . '</td>
            </tr>
            <tr>
                <td>Rang um 0 Uhr:</td>
                <td>' . $userInfo['lastrank'] . '</td>
            </tr>
            <tr>
                <td>Punkte:</td>
                <td>' . fnum($userInfo['score']) . '</td>
            </tr>
            <tr>
                <td>Haupt-Königreich:</td>
                <td>' . $userInfo['mainkingdom'] . '</td>
            </tr>
            <tr>
                <td>Gilde:</td>
                <td>' . $userInfo['guildid'] . '</td>
            </tr>
            <tr>
                <td>Münzen:</td>
                <td>' . $userInfo['gems'] . '</td>
            </tr>
            <tr>
                <td>Letzte Nachricht:</td>
                <td>' . date("d.m.Y", $userInfo['lastsentmsg']) . ' um ' . date("H:i:s", $userInfo['lastsentmsg']) . '</td>
            </tr>
        </table>';

        // Display kingdoms and their events
        $view .= '<h3>Königreich-Info</h3>';
        $view .= '<table class="table">';

        foreach ($kingdoms as $kingdom_id => $kingdom_data) {
            $view .= '<tr>
                        <td>Königreich:</td>
                        <td>' . $kingdom_data['kingdomname'] . ' [ID: ' . $kingdom_id . ']</td>
                      </tr>';

            if (!empty($kingdom_data['events'])) {
                $view .= '</table>
                            <h3>Events:</h3>
                            <table class="table">';

                foreach ($kingdom_data['events'] as $event) {
                    $view .= '<tr>
                                <td>Event:</td>
                                <td>Aktion: ' . $event['action_id'] . ' [ID: ' . $event['event_id'] . ']</td>
                              </tr>';
                }

                $view .= '</table>';
            } else {
                $view .= '<tr>
                    <td colspan="2">Keine Events für dieses Königreich vorhanden.</td>
                  </tr>';
            }
        }

        $view .= '</table>';
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
    function confirmAndRedirect(url) {
        // Show confirmation dialog
        const confirmed = confirm("Are you sure you want to proceed?");

        // If user confirms, redirect to the URL
        if (confirmed) {
            window.location.href = url; // Redirect to the specified URL
        }
    }
</script>

<?php
require_once("includes/core.php");

check_user_login($user);


// Fetch all send troop events from the user
$view .= '<div style="border-bottom: 2px solid rgba(0, 0, 0, 0.5); width: 50%; line-height: 40px; margin: 0 auto 15px auto;">Gesendete Truppen</div>';

$event_ids = [];
$result = $db_instance->execute_query(
    "SELECT eventid FROM events WHERE userid = ? AND (actionid = ? OR actionid = ?)",
    [$user->get_user_id(), ACTION_SEND_TROOPS, ACTION_SEND_TROOPS]
);
foreach ($result as $row) {
    $event_ids[] = $row["eventid"];
}

// Only proceed if there are any event IDs
if (!empty($event_ids)) {
    // Dynamically create placeholders (?, ?, ?, ...) for the IN clause
    $placeholders = implode(',', array_fill(0, count($event_ids), "?"));

    $query = "
            SELECT st.soldierid AS st_soldierid,
                   st.soldiercount AS soldiercount,
                   e.*,
                   k.userid AS sender_userid,
                   k.mapx, k.mapy,
                   kt.userid AS target_userid
            FROM senttroops st
            JOIN events e ON st.eventid = e.eventid
            JOIN kingdoms k ON e.kingdomid = k.id
            LEFT JOIN kingdoms kt ON e.targetid = kt.id
            WHERE st.eventid IN ($placeholders)
    ";
    $result = $db_instance->execute_query($query, $event_ids);

    // Now $result contains all the joined data
    $view .= "<table class='table' style='width: 100%;'>";
    $view .= "<tr>
            <td class='td-center td-gradient'>Art</td>
            <td class='td-center td-gradient'>Truppen</td>
            <td class='td-center td-gradient'>Koordinaten</td>
            <td class='td-center td-gradient'>Ankunftszeit</td>
        </tr>";

    $action_type = "Angriff";
    $grouped_events = [];

    // Group each sent soldier type, so that no extra rows are created with multiple soldiers
    foreach ($result as $row) {
        $event_id = $row["eventid"];

        // Initialize the event group if it doesn't exist yet
        if (!isset($grouped_events[$event_id])) {
            $grouped_events[$event_id] = [
                'actionid' => $row["actionid"],
                'targetid' => $row["targetid"],
                'target_userid' => $row["target_userid"],
                'mapx' => $row["mapx"],
                'mapy' => $row["mapy"],
                'targetx' => $row["targetx"],
                'targety' => $row["targety"],
                'arrivaltime' => $row["arrivaltime"],
                'soldiers' => []
            ];
        }

        // Append this troop type to the troops list
        $grouped_events[$event_id]['soldiers'][] = [
            'soldierid' => $row["st_soldierid"],
            'soldiercount' => $row["soldiercount"]
        ];
    }

    foreach ($grouped_events as $event_id => $event_data) {
        $action_id = $event_data["actionid"];
        $is_target_my_kingdom = ($event_data["target_userid"] == $user->get_user_id());
        $arrival_time = (new Map($db_instance))->get_arrival_time($event_data["mapx"], $event_data["mapy"], $event_data["targetx"], $event_data["targety"]);
        $difference_time = max(0, $event_data["arrivaltime"] - time());
        $counter_id = "counter_" . $event_id;
        $coords_str = "{$event_data["mapx"]}:{$event_data["mapy"]} zu {$event_data["targetx"]}:{$event_data["targety"]}";

        if ($action_id != ACTION_RETURN_TROOPS) {
            $action_button = "<b><span id='$counter_id'></span></b><br>
            <script type='text/javascript'>
                document.addEventListener('DOMContentLoaded', function () {
                    let diff = $difference_time;
                    startCountdown('$counter_id', diff || 0);
                });
            </script>
            <form action='index.php' method='GET'>
                <input type='submit' value='Abbruch' style='margin-top: 5px;' disabled>
            </form>";
        } else {
            $action_button = "-";
        }

        if ($action_id == ACTION_SEND_TROOPS && $event_data["targetid"] == -1) {
            $action_type = "Eroberung";
        } else if ($action_id == ACTION_RETURN_TROOPS) {
            $action_type = "Rückkehr";
            $coords_str = "{$event_data["targetx"]}:{$event_data["targety"]} zu {$event_data["mapx"]}:{$event_data["mapy"]}";
        } else if ($action_id == ACTION_SEND_TROOPS && $is_target_my_kingdom) {
            $action_type = "Truppen stationieren";
        }

        // Build soldiers string
        $soldier_array = [];
        foreach ($event_data["soldiers"] as $soldier) {
            $soldier_obj = new Soldier();
            $soldier_obj->set_soldier_id($soldier["soldierid"]);

            $soldier_array[] = $soldier_obj->get_soldier_icon("ressource-icons") . " {$soldier['soldiercount']}x";
        }
        $soldiers_str = implode(" ", $soldier_array);

        $view .= "<tr>
                <td class='td-center'>$action_type</td>
                <td class='td-center'>$soldiers_str</td>
                <td class='td-center'>$coords_str</td>
                <td class='td-center'>$action_button</td>
            </tr>";
    }

    $view .= "</table>";
} else {
    $view .= "Derzeit sind keine Truppen unterwegs.";
}

// Get some user data to show...
/*$result = $db_instance->execute_query("SELECT ip, email, score, guildid, registerdate, mainkingdom FROM users WHERE id = ?", [$_SESSION["userid"]]);
$row = $result->fetch_assoc();
$ip = $row["ip"];
$email = $row["email"];
$score = $row["score"];
$guild_id = $row["guildid"];
$register_date = $row["registerdate"];
$main_kingdom = $row["mainkingdom"];

$view .= '<img src="images/icons/icon_right_slow.png" class="popup" id="test1" alt="" style="width:24px;"/>
            <div id="test1_box" class="popupbox">Testbox hahaha <br>hahahah</div>
            <br>
            <a class="popup" id="test2">This is a test</a>
            <div id="test2_box" class="popupbox">E-Mail: ' . htmlspecialchars($email) . '</div>
            <br><br>';

$time_diff = time() - $_SESSION["currlogin"];

// Check for existing IP
$ipPattern = explode('.', $_SERVER["REMOTE_ADDR"]);
$ipToCheck = $ipPattern[0] . "." . $ipPattern[1] . ".%";

$sql = "SELECT COUNT(*) FROM users WHERE ip LIKE ?";
$stmt = $db_instance->prepare($sql);
$stmt->bind_param('s', $ipToCheck);
$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();

if ($count > 0) {
    // The database contains at least one IP address matching the pattern xxx.xxx.*.*
    echo 'IP found.';
} else {
    // No matching IP address found
    echo 'IP not found.';
}

$view .= "Login-Zeit: <span id='counter'></span><br>Current IP Address: " . $_SERVER["REMOTE_ADDR"] . "<br>Stored IP Adress: " . $ip . "<br><br>";
$view .= "Haupt-KönigreichID: $main_kingdom<br>";
$view .= "E-Mail: $email<br>";
$view .= "Registriert seit: " . date('d.m.Y H:i:s', $register_date) . "<br>";
$view .= "Letzter Login: " . date('d.m.Y H:i:s', $_SESSION["lastlogin"]) . "<br>";
$view .= "Score: $score<br>";
$view .= "Gilde: $guild_id<br>";
$view .= "Admin-Level: " . $user->get_user_admin_level();*/


/*
 * HTML Section
 */
$title = "Übersicht";
$header = "Übersicht";
$script_files = ["counter"];

/*$view .= "
    <script type='text/javascript'>
        document.addEventListener('DOMContentLoaded', function() {
            startCountup(\"$time_diff\");
        });
    </script>
";*/

include('layout/base.php');
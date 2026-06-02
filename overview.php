<?php
require_once("includes/core.php");

check_user_login($user);

// Fetch all sent troops events from the user
$view .= '<div class="title-border">Gesendete Truppen</div>';

if (isset($_GET["action"]) && $_GET["action"] == "cancel" && isset($_GET["eid"])) {
    $event_id = (empty($_GET["eid"]) ? 0 : (int)$_GET["eid"]);
    $result = $db_instance->execute_query("SELECT * FROM events WHERE eventid = ? AND userid = ?",
        [$event_id, $user->get_user_id()]);

    if ($result && $result->num_rows > 0) {
        $event = $result->fetch_assoc();

        if ($event["actionid"] != ActionTypes::ACTION_SEND_TROOPS) {
            $error = "Diese Aktion ist ungültig!";
        } else {
            $now = time();
            $total_duration = $event["arrivaltime"] - $event["buildingtime"];
            $already_marched = max(0, min($now - $event["buildingtime"], $total_duration));
            $new_arrival_time = $now + $already_marched;

            $update = $db_instance->execute_query("UPDATE events SET actionid = ?, arrivaltime = ? WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, $new_arrival_time, $event_id]
            );
        }
    } else {
        $error = "Diese Aktion ist ungültig!";
    }
}

$map = new Map($db_instance, $user);

// --- TROOP OVERVIEW ---
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
    WHERE e.userid = ? AND (e.actionid = ? OR e.actionid = ?)
";

$result = $db_instance->execute_query($query, [$user->get_user_id(), ActionTypes::ACTION_SEND_TROOPS, ActionTypes::ACTION_RETURN_TROOPS]);

if ($result && $result->num_rows > 0) {
    $view .= "<table class='table' style='width: 100%;'>";
    $view .= "<colgroup>
                <col style='width: 18%;'> <!-- Art -->
                <col style='width: 25%;'> <!-- Truppen -->
                <col style='width: 28%;'> <!-- Koordinaten -->
                <col style='width: 22%;'> <!-- Ankunft -->
                <col style='width: 7%;'>  <!-- Button -->
              </colgroup>";
    $view .= "<tr>
            <td class='td-center td-gradient'>Art</td>
            <td class='td-center td-gradient'>Truppen</td>
            <td class='td-center td-gradient'>Koordinaten</td>
            <td class='td-center td-gradient' colspan='2'>Ankunftszeit</td>
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
        $action_button = "";
        $is_target_my_kingdom = ($event_data["target_userid"] == $user->get_user_id());
        $arrival_time = $map->get_arrival_time($event_data["mapx"], $event_data["mapy"], $event_data["targetx"], $event_data["targety"]);
        $difference_time = max(0, $event_data["arrivaltime"] - time());
        $counter_id = "counter_" . $event_id;
        $my_coords = "<a href='#' onclick='redirectToMap(\"{$event_data["mapx"]}\", \"{$event_data["mapy"]}\")'>{$event_data["mapx"]}:{$event_data["mapy"]}</a>";
        $target_coords = "<a href='#' onclick='redirectToMap(\"{$event_data["targetx"]}\", \"{$event_data["targety"]}\")'>{$event_data["targetx"]}:{$event_data["targety"]}</a>";
        $coords_str = "$my_coords → $target_coords";

        $action_counter = "<b><span id='$counter_id'></span></b><br>
            <script type='text/javascript'>
                document.addEventListener('DOMContentLoaded', function () {
                    let diff = $difference_time;
                    startCountdown('$counter_id', diff || 0, 0, null, false, true);
                });
            </script>";

        if ($action_id != ActionTypes::ACTION_RETURN_TROOPS) {
            $action_button = "<form action='overview.php' method='GET'>
                                    <input type='hidden' name='action' value='cancel'>
                                    <input type='hidden' name='eid' value='" . $event_id . "'>
                                    <input type='submit' value='' style='margin-top: 5px;' class='btn-delete'>
                                </form>";
        }

        if ($action_id == ActionTypes::ACTION_SEND_TROOPS && $event_data["targetid"] == -1) {
            $action_type = "Eroberung";
        } else if ($action_id == ActionTypes::ACTION_RETURN_TROOPS) {
            $action_type = "Rückkehr";
            $coords_str = "$target_coords → $my_coords";
        } else if ($action_id == ActionTypes::ACTION_SEND_TROOPS && $is_target_my_kingdom) {
            $action_type = "Truppen stationieren";
        }

        // Build soldiers string
        $soldiers_str = "";
        foreach ($event_data["soldiers"] as $soldier) {
            $soldier_obj = new Soldier();
            $soldier_obj->set_soldier_id($soldier["soldierid"]);

            $soldiers_str .= "<div class='legend-item'>" . $soldier_obj->get_soldier_icon("ressource-icons") . "{$soldier['soldiercount']}x</div>";
        }

        $view .= "<tr>
                <td class='td-center'>$action_type</td>
                <td class='td-center'>$soldiers_str</td>
                <td class='td-center'>$coords_str</td>";

        if ($action_button !== "") {
            // Normalfall: Counter und Button nebeneinander
            $view .= "<td class='td-center'>$action_counter</td>";
            $view .= "<td class='td-center'>$action_button</td>";
        } else {
            // Spezialfall: Kein Button -> Counter nimmt den Platz ein (colspan 2)
            $view .= "<td class='td-center' colspan='2'>$action_counter</td>";
        }

        $view .= "</tr>";
    }

    $view .= "</table>";
} else {
    $view .= "Derzeit sind keine Truppen unterwegs.";
}

// --- BAU, FORSCHUNG & REKRUTIERUNG ÜBERSICHT ---
$view .= '<div class="title-border" style="margin-top: 30px;">Bau & Entwicklung</div>';

$query_events = "
    SELECT e.*, k.kingdomname, k.mapx, k.mapy 
    FROM events e 
    JOIN kingdoms k ON e.kingdomid = k.id 
    WHERE e.userid = ? AND e.actionid IN (?, ?, ?)
    ORDER BY COALESCE(NULLIF(e.buildingtime, 0), e.recruittime)
";

$result_events = $db_instance->execute_query($query_events, [
    $user->get_user_id(),
    ActionTypes::ACTION_BUILD_BUILDING,
    ActionTypes::ACTION_BUILD_TROOPS,
    ActionTypes::ACTION_RESEARCH_TECH
]);

if ($result_events && $result_events->num_rows > 0) {
    $view .= "<table class='table' style='width: 100%;'>";
    $view .= "<colgroup>
                <col style='width: 25%;'> <!-- Art -->
                <col style='width: 20%;'> <!-- Projekt -->
                <col style='width: 30%;'> <!-- Königreich -->
                <col style='width: 25%;'> <!-- Fertigstellung -->
              </colgroup>";
    $view .= "<tr>
            <td class='td-center td-gradient'>Art</td>
            <td class='td-center td-gradient'>Projekt</td>
            <td class='td-center td-gradient'>Standort</td>
            <td class='td-center td-gradient'>Fertigstellung</td>
        </tr>";

    foreach ($result_events as $row) {
        $event_id = $row["eventid"];
        $action_id = $row["actionid"];
        $k_name = $row["kingdomname"];
        $k_coords = "{$row["mapx"]}:{$row["mapy"]}";

        $type_text = "";
        $project_text = "";
        $finish_time = 0;

        switch ($action_id) {
            case ActionTypes::ACTION_BUILD_BUILDING:
                $type_text = "Bauauftrag";
                $next_lvl = $row["buildinglevel"] + 1;
                $project_text = "<strong>{$row["buildingname"]}</strong> (Stufe $next_lvl)";
                $finish_time = $row["buildingtime"];
                break;

            case ActionTypes::ACTION_RESEARCH_TECH:
                $type_text = "Forschung";
                $next_lvl = $row["buildinglevel"] + 1;
                $project_text = "<strong>{$row["buildingname"]}</strong> (Stufe $next_lvl)";
                $finish_time = $row["buildingtime"];
                break;

            case ActionTypes::ACTION_BUILD_TROOPS:
                $type_text = "Rekrutierung";
                $sol_obj = new Soldier();
                $sol_obj->set_soldier_id($row["soldierid"]);
                $project_text = $sol_obj->get_soldier_icon("ressource-icons") . " {$row["soldiergoal"]}x";
                $finish_time = $row["recruittime"];
                break;
        }

        $arrival_diff = max(0, $finish_time - time());
        $counter_id = "event_counter_" . $event_id;

        $view .= "<tr>
                <td class='td-center'>$type_text</td>
                <td class='td-center'>$project_text</td>
                <td class='td-center'>
                    $k_name 
                    <a href='#' onclick='switchKingdomAndReload({$row["kingdomid"]}); return false;'>($k_coords)</a>
                </td>
                <td class='td-center'>
                    <b><span id='$counter_id'></span></b>
                    <script type='text/javascript'>
                        document.addEventListener('DOMContentLoaded', function () {
                            startCountdown('$counter_id', $arrival_diff, 0, null, false, true);
                        });
                    </script>
                </td>
            </tr>";
    }
    $view .= "</table>";
} else {
    $view .= "Derzeit gibt es keine Bauaufträge, Forschungen oder Rekrutierungen.";
}

// --- MARKETPLACE AND TRANSPORTS OVERVIEW ---
$view .= '<div class="title-border" style="margin-top: 30px;">Warenlieferungen</div>';

$query_trades = "
    SELECT e.*, k.kingdomname, k.mapx, k.mapy 
    FROM events e 
    JOIN kingdoms k ON e.kingdomid = k.id 
    WHERE e.userid = ? AND e.actionid = ?
    ORDER BY e.arrivaltime
";
$result_trades = $db_instance->execute_query($query_trades, [$user->get_user_id(), ActionTypes::ACTION_RECEIVE_RESOURCES]);

if ($result_trades && $result_trades->num_rows > 0) {
    $view .= "<table class='table' style='width: 100%;'>";
    $view .= "<colgroup>
                <col style='width: 20%;'> <!-- Art -->
                <col style='width: 25%;'> <!-- Ressourcen -->
                <col style='width: 25%;'> <!-- Ziel -->
                <col style='width: 30%;'> <!-- Ankunft -->
              </colgroup>";
    $view .= "<tr>
            <td class='td-center td-gradient'>Art</td>
            <td class='td-center td-gradient'>Ressourcen</td>
            <td class='td-center td-gradient'>Ziel</td>
            <td class='td-center td-gradient'>Ankunft</td>
        </tr>";

    foreach ($result_trades as $row) {
        $event_id = $row["eventid"];
        $res_type = $row["buildingid"];
        $amount = $row["buildinglevel"];
        $target_name = $row["kingdomname"];
        $target_coords = "{$row["mapx"]}:{$row["mapy"]}";

        $arrival_diff = max(0, $row["arrivaltime"] - time());
        $counter_id = "trade_counter_" . $event_id;

        $view .= "<tr>
                <td class='td-center'>{$row["buildingname"]}</td>
                <td class='td-center'>" . get_resource_icon($res_type) . " " . fnum($amount) . "</td>
                <td class='td-center'>
                    $target_name
                    <a href='#' onclick='switchKingdomAndReload({$row["kingdomid"]}); return false;'>($target_coords)</a>
                </td>
                <td class='td-center'>
                    <b><span id='$counter_id'></span></b>
                    <script type='text/javascript'>
                        document.addEventListener('DOMContentLoaded', function () {
                            startCountdown('$counter_id', $arrival_diff, 0, null, false, true);
                        });
                    </script>
                </td>
            </tr>";
    }
    $view .= "</table>";
} else {
    $view .= "Derzeit sind keine Warenlieferungen unterwegs.";
}

// Get some user data to show...
$result = $db_instance->execute_query("SELECT ip, email, score, guildid, registerdate, mainkingdom FROM users WHERE id = ?", [$_SESSION["userid"]]);
$row = $result->fetch_assoc();
$ip = $row["ip"];
$email = $row["email"];
$score = $row["score"];
$guild_id = $row["guildid"];
$register_date = $row["registerdate"];
$main_kingdom = $row["mainkingdom"];
$time_diff = time() - $_SESSION["currlogin"];
$kingdom = new Kingdom($db_instance, $main_kingdom);

$view .= "<div class='title-border' style='margin-top: 30px;'>Allgemeine Daten</div>";
$view .= "<table class='table' style='max-width: 450px;'>";
$view .= "<tr><td style='width: 170px;'>Login-Zeit:</td><td><span id='counter'></span></td></tr>";
$view .= "<tr><td>IP-Adresse:</td><td>" . $_SERVER["REMOTE_ADDR"] . "</td></tr>";
//$view .= "<tr><td>Stored IP Adress:</td><td>" . $ip . "</td></tr>";
$view .= "<tr><td>Haupt-Königreich:</td><td>" . $kingdom->get_kingdom_name() . " (" . $kingdom->get_kingdom_map_x() . ":" . $kingdom->get_kingdom_map_y() . ")</td></tr>";
$view .= "<tr><td>E-Mail:</td><td>$email</td></tr>";
$view .= "<tr><td>Registriert seit:</td><td>" . date('d.m.Y H:i:s', $register_date) . "</td></tr>";
$view .= "<tr><td>Letzter Login:</td><td>" . date('d.m.Y H:i:s', $_SESSION["lastlogin"]) . "</td></tr>";
$view .= "<tr><td>Score:</td><td>$score</td></tr>";
$view .= "<tr><td>Gilde:</td><td>" . ($guild_id == -1 ? "Keine Gilde" : $guild_id) . "</td></tr>";
$view .= "<tr><td>Admin-Level:</td><td>" . $user->get_user_admin_level() . "</td></tr>";
$view .= "</table>";

/*$view .= '<img src="images/icons/icon_right_slow.png" class="popup" id="test1" alt="" style="width:24px;"/>
            <div id="test1_box" class="popupbox">Testbox hahaha <br>hahahah</div>
            <br>
            <a class="popup" id="test2">This is a test</a>
            <div id="test2_box" class="popupbox">E-Mail: ' . htmlspecialchars($email) . '</div>
            <br><br>';*/


// Check for existing IP
/*$ipPattern = explode('.', $_SERVER["REMOTE_ADDR"]);
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
}*/


/*
 * HTML Section
 */
$title = "Übersicht";
$header = "Übersicht";
$script_files = ["counter", "userinfo"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

$view .= "
    <script type='text/javascript'>
        document.addEventListener('DOMContentLoaded', function() {
            startCountup(\"$time_diff\");
        });
    </script>
";

include("layout/base.php");
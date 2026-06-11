<?php
require_once("includes/core.php");

check_user_login($user);

// Get some user data to show...
$result = $db_instance->execute_query("SELECT ip, email, score, guildid, registerdate, mainkingdom FROM users WHERE id = ?", [$_SESSION["userid"]]);
$row = $result->fetch_assoc();
$ip = $row["ip"];
$email = $row["email"];
$score = $row["score"];
$guild_id = $row["guildid"];
$register_date = $row["registerdate"];
$main_kingdom = $row["mainkingdom"];
$now = time();
$time_diff = $now - $_SESSION["currlogin"];
$kingdom = new Kingdom($db_instance, $main_kingdom);

if (!isset($_SESSION["acknowledged_attacks"])) {
    $_SESSION["acknowledged_attacks"] = [];
}

$q_ack = "
    SELECT e.eventid, b.buildinglevel, e.arrivaltime
    FROM events e
    JOIN kingdoms k ON e.targetid = k.id
    JOIN buildings b ON k.id = b.kingdomid AND b.buildingid = 12
    WHERE k.userid = ? AND e.actionid = 2 AND e.arrivaltime > ? AND is_processing = 0
";
$current_visible = $db_instance->execute_query($q_ack, [$user->get_user_id(), $now]);

foreach ($current_visible as $row) {
    $vis = $row["buildinglevel"] * WATCHTOWER_DETECTION_PER_LEVEL;

    if (($row["arrivaltime"] - $now) <= $vis) {
        if (!in_array($row["eventid"], $_SESSION["acknowledged_attacks"])) {
            $_SESSION["acknowledged_attacks"][] = $row["eventid"];
        }
    }
}

// Fetch all sent troops events from the user
if (isset($_GET["action"]) && $_GET["action"] == "cancel" && isset($_GET["eid"])) {
    $event_id = (empty($_GET["eid"]) ? 0 : (int)$_GET["eid"]);
    $result = $db_instance->execute_query("SELECT * FROM events WHERE eventid = ? AND userid = ?",
        [$event_id, $user->get_user_id()]);

    if ($result && $result->num_rows > 0) {
        $event = $result->fetch_assoc();


        if ($event["actionid"] == ActionTypes::ACTION_SEND_TROOPS) {
            $total_duration = $event["arrivaltime"] - $event["buildingtime"];
            $already_marched = max(0, min($now - $event["buildingtime"], $total_duration));
            $new_arrival_time = $now + $already_marched;

            $update = $db_instance->execute_query("UPDATE events SET actionid = ?, arrivaltime = ? WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, $new_arrival_time, $event_id]
            );

            $logger->log_game("COMBAT", "ATTACK_RECALL", [
                "event_id" => $event_id,
                "target_x" => $event["targetx"],
                "target_y" => $event["targety"]
            ], $event["kingdomid"]);
        } else if ($event["actionid"] == ActionTypes::ACTION_RECEIVE_RESOURCES && $event["buildingname"] == "Interner Transport") {
            $total_duration = $event["arrivaltime"] - $event["buildingtime"];
            $already_marched = max(0, min($now - $event["buildingtime"], $total_duration));
            $new_arrival_time = $now + $already_marched;

            $db_instance->execute_query(
                "UPDATE events SET actionid = ?, kingdomid = ?, targetid = ?, arrivaltime = ?, buildingname = ? WHERE eventid = ?",
                [
                    ActionTypes::ACTION_RETURN_RESOURCES,
                    $event["targetid"],
                    $event["kingdomid"],
                    $new_arrival_time,
                    "Transport-Rückkehr",
                    $event_id
                ]
            );

            $logger->log_game("TRADE", "TRANSPORT_CANCEL", ["res" => $event["buildingid"], "amount" => $event["buildinglevel"]], $event["targetid"]);
        }
    } else {
        $error = "Diese Aktion ist ungültig!";
    }
}

$map = new Map($db_instance, $user);
$limit = 7;


// -- INCOMING ENEMIES OVERVIEW ---
$my_uid = $user->get_user_id();
$query_wt = "SELECT k.id, k.kingdomname, b.buildinglevel 
             FROM kingdoms k 
             JOIN buildings b ON k.id = b.kingdomid 
             WHERE k.userid = ? AND b.buildingid = ? AND b.buildinglevel > 0";

$my_watchtowers = $db_instance->execute_query($query_wt, [$my_uid, BuildingTypes::BUILDING_WATCHTOWER]);

if ($my_watchtowers->num_rows > 0) {
    $incoming_html = "";

    foreach ($my_watchtowers as $wt) {
        $visibility_window = $wt["buildinglevel"] * WATCHTOWER_DETECTION_PER_LEVEL;

        $query_incoming = "
            SELECT arrivaltime 
            FROM events 
            WHERE targetid = ? 
              AND actionid = ? 
              AND arrivaltime - ? <= ?
              AND arrivaltime > ?
            ORDER BY arrivaltime
        ";
        $incoming = $db_instance->execute_query($query_incoming, [
            $wt["id"],
            ActionTypes::ACTION_SEND_TROOPS,
            $now,
            $visibility_window,
            $now
        ]);

        while ($attack = $incoming->fetch_assoc()) {
            $diff = $attack["arrivaltime"] - $now;

            $incoming_html .= "<tr>
                <td style='color: var(--link-color);'>Alarm in <b>" . e($wt["kingdomname"]) . "</b>!</td>
                <td class='td-center'><b>Ankunft in: 
                        <span class='js-countdown' 
                              data-seconds='$diff' 
                              data-no-reload='true'>
                              " . convert_sec_to_str($diff) . "
                        </span>
                    </b>
                </td>
            </tr>";
        }
    }

    if ($incoming_html !== "") {
        $view .= '<div class="title-border error">Feindliche Truppenbewegung</div>';
        $view .= '<table class="table">' . $incoming_html . '</table><br>';
    }
}

// --- TROOP OVERVIEW ---
$count_tp = $db_instance->execute_query("SELECT COUNT(*) FROM events WHERE userid = ? AND (actionid = ? OR actionid = ?)",
    [$user->get_user_id(), ActionTypes::ACTION_SEND_TROOPS, ActionTypes::ACTION_RETURN_TROOPS])->fetch_row()[0];
$pages_tp = ceil($count_tp / $limit);
$curr_tp = isset($_GET["tp"]) ? max(1, (int)$_GET["tp"]) : 1;
$offset_tp = ($curr_tp - 1) * $limit;

$view .= '<div class="title-border">Gesendete Truppen</div>';

$query = "
    SELECT st.soldierid AS st_soldierid, st.soldiercount AS soldiercount, sl.icon AS soldier_icon, sl.soldiername AS s_name,
           e.*, k.mapx, k.mapy, kt.userid AS target_userid
    FROM (SELECT * FROM events WHERE userid = ? AND (actionid = ? OR actionid = ?) ORDER BY arrivaltime LIMIT $offset_tp, $limit) e
    JOIN senttroops st ON st.eventid = e.eventid
    JOIN kingdoms k ON e.kingdomid = k.id
    LEFT JOIN kingdoms kt ON e.targetid = kt.id
    JOIN soldierlist sl ON st.soldierid = sl.id
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
            <td class='td-center td-gradient' colspan='2'>Ankunft</td>
        </tr>";

    $action_type = "Angriff";
    $grouped_events = [];

    // Group each sent soldier type, so that no extra rows are created with multiple soldiers
    foreach ($result as $row) {
        $event_id = $row["eventid"];

        // Initialize the event group if it doesn't exist yet
        if (!isset($grouped_events[$event_id])) {
            $grouped_events[$event_id] = [
                "actionid" => $row["actionid"],
                "targetid" => $row["targetid"],
                "target_userid" => $row["target_userid"],
                "mapx" => $row["mapx"],
                "mapy" => $row["mapy"],
                "targetx" => $row["targetx"],
                "targety" => $row["targety"],
                "arrivaltime" => $row["arrivaltime"],
                "soldiers" => []
            ];
        }

        // Append this troop type to the troops list
        $grouped_events[$event_id]['soldiers'][] = [
            "soldierid" => $row["st_soldierid"],
            "soldiercount" => $row["soldiercount"],
            "icon" => $row["soldier_icon"],
            "name" => $row["s_name"]
        ];
    }

    foreach ($grouped_events as $event_id => $event_data) {
        $action_id = $event_data["actionid"];
        $action_button = "";
        $is_target_my_kingdom = ($event_data["target_userid"] == $user->get_user_id());
        $arrival_time = $map->get_arrival_time($event_data["mapx"], $event_data["mapy"], $event_data["targetx"], $event_data["targety"]);
        $difference_time = max(0, $event_data["arrivaltime"] - $now);
        $counter_id = "counter_" . $event_id;
        $my_coords = "<a href='#' data-on-click='mapJump' data-x='" . e($event_data["mapx"]) . "' data-y='" . e($event_data["mapy"]) . "'>" . e($event_data["mapx"]) . ":" . e($event_data["mapy"]) . "</a>";
        $target_coords = "<a href='#' data-on-click='mapJump' data-x='" . e($event_data["targetx"]) . "' data-y='" . e($event_data["targety"]) . "'>" . e($event_data["targetx"]) . ":" . e($event_data["targety"]) . "</a>";
        $coords_str = "$my_coords → $target_coords";

        $action_counter = "<b><span class='js-countdown' 
                               id='$counter_id' 
                               data-seconds='$difference_time' 
                               data-no-reload='true'></span></b>";

        if ($action_id != ActionTypes::ACTION_RETURN_TROOPS) {
            $action_button = "<form action='overview.php' method='GET'>
                                    <input type='hidden' name='action' value='cancel'>
                                    <input type='hidden' name='eid' value='" . $event_id . "'>
                                    <input type='submit' value='' class='btn-delete'>
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
            $soldier_obj->set_soldier_icon($soldier["icon"]);
            $soldier_obj->set_soldier_name($soldier["name"]);

            $soldiers_str .= "<div class='legend-item'>" . $soldier_obj->get_soldier_icon("ressource-icons") . "{$soldier['soldiercount']}x</div>";
        }

        $view .= "<tr>
                <td class='td-center'>$action_type</td>
                <td class='td-center'>$soldiers_str</td>
                <td class='td-center'>$coords_str</td>";

        if ($action_button !== "") {
            $view .= "<td class='td-center'>$action_counter</td>";
            $view .= "<td class='td-center'>$action_button</td>";
        } else {
            $view .= "<td class='td-center' colspan='2'>$action_counter</td>";
        }

        $view .= "</tr>";
    }

    $view .= "</table>";

    if ($pages_tp > 1) {
        $view .= '<div class="pagination-container"><div class="pagination-bar">';

        if ($curr_tp > 1) {
            $params = $_GET;
            $params["tp"] = 1;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Erste Seite'>&laquo;</a>";

            $params["tp"] = $curr_tp - 1;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Zurück'>&lsaquo;</a>";
        }

        $range = 2;
        for ($i = ($curr_tp - $range); $i <= ($curr_tp + $range); $i++) {
            if ($i > 0 && $i <= $pages_tp) {
                $params = $_GET;
                $params["tp"] = $i;

                if ($i == $curr_tp) {
                    $view .= "<span class='page-link active'>$i</span>";
                } else {
                    $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link'>$i</a>";
                }
            }
        }

        if ($curr_tp < $pages_tp) {
            $params = $_GET;
            $params["tp"] = $curr_tp + 1;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Weiter'>&rsaquo;</a>";

            $params["tp"] = $pages_tp;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Letzte Seite'>&raquo;</a>";
        }

        $view .= '</div></div>';
    }
} else {
    $view .= "Derzeit sind keine Truppen unterwegs.";
}

// --- BUILDING, TECH & RECRUIT OVERVIEW ---
$count_bp = $db_instance->execute_query("SELECT COUNT(*) FROM events WHERE userid = ? AND actionid IN (?, ?, ?, ?)",
    [$user->get_user_id(), ActionTypes::ACTION_BUILD_BUILDING, ActionTypes::ACTION_BUILD_TROOPS, ActionTypes::ACTION_RESEARCH_TECH,
        ActionTypes::ACTION_UPGRADE_TROOPS])->fetch_row()[0];
$pages_bp = ceil($count_bp / $limit);
$curr_bp = isset($_GET["bp"]) ? max(1, (int)$_GET["bp"]) : 1;
$offset_bp = ($curr_bp - 1) * $limit;

$view .= '<div class="title-border" style="margin-top: 30px;">Bau & Entwicklung</div>';

$query_events = "
    SELECT e.*, k.kingdomname, k.mapx, k.mapy, sl.icon AS soldier_icon, sl.soldiername AS soldiername
    FROM events e 
    JOIN kingdoms k ON e.kingdomid = k.id
    LEFT JOIN soldierlist sl ON sl.id = e.soldierid
    WHERE e.userid = ? AND e.actionid IN (?, ?, ?, ?)
    ORDER BY COALESCE(NULLIF(e.buildingtime, 0), e.recruittime)
    LIMIT $offset_bp, $limit
";

$result_events = $db_instance->execute_query($query_events, [
    $user->get_user_id(),
    ActionTypes::ACTION_BUILD_BUILDING,
    ActionTypes::ACTION_BUILD_TROOPS,
    ActionTypes::ACTION_RESEARCH_TECH,
    ActionTypes::ACTION_UPGRADE_TROOPS
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

                $icon = "<img src='images/icons/icon_building" . (int)$row["buildingid"] . ".png' class='ressource-icons' alt=''>";
                $project_text = "$icon ($next_lvl)";
                $finish_time = $row["buildingtime"];
                break;
            case ActionTypes::ACTION_RESEARCH_TECH:
                $type_text = "Forschung";
                $next_lvl = $row["buildinglevel"] + 1;

                $icon = "<img src='images/icons/icon_tech" . (int)$row["buildingid"] . ".png' class='ressource-icons' alt=''>";
                $project_text = "$icon ($next_lvl)";
                $finish_time = $row["buildingtime"];
                break;
            case ActionTypes::ACTION_BUILD_TROOPS:
                $type_text = "Rekrutierung";
                $sol_obj = new Soldier();
                $sol_obj->set_soldier_id($row["soldierid"]);
                $sol_obj->set_soldier_icon($row["soldier_icon"]);
                $sol_obj->set_soldier_name($row["soldiername"]);

                $project_text = $sol_obj->get_soldier_icon("ressource-icons") . " {$row["soldiergoal"]}x";
                $finish_time = $row["recruittime"];
                break;
            case ActionTypes::ACTION_UPGRADE_TROOPS:
                $type_text = "Aufwertung";
                $sol_obj = new Soldier();
                $sol_obj->set_soldier_id($row["soldierid"]);
                $sol_obj->set_soldier_icon($row["soldier_icon"]);
                $sol_obj->set_soldier_name($row["soldiername"]);

                $project_text = $sol_obj->get_soldier_icon("ressource-icons") . " {$row["soldiergoal"]}x";

                $res_s = $db_instance->execute_query("SELECT requiredtime FROM soldierlist WHERE id = ?", [$row["soldierid"]]);
                $u_time = $res_s->fetch_assoc()["requiredtime"];

                $finish_time = $row["recruittime"];
                break;
        }

        $arrival_diff = max(0, $finish_time - $now);
        $counter_id = "event_counter_" . $event_id;

        $view .= "<tr>
                <td class='td-center'>$type_text</td>
                <td class='td-center'>$project_text</td>
                <td class='td-center'>
                    $k_name 
                    <a href='#' data-on-click='switchKingdom' data-id='" . e($row["kingdomid"]) . "'>(" . e($k_coords) . ")</a>
                </td>
                <td class='td-center'>
                    <b><span class='js-countdown' 
                       id='$counter_id' 
                       data-seconds='$arrival_diff' 
                       data-no-reload='true'></span></b>
                </td>
            </tr>";
    }

    $view .= "</table>";

    if ($pages_bp > 1) {
        $view .= '<div class="pagination-container"><div class="pagination-bar">';

        if ($curr_bp > 1) {
            $params = $_GET;
            $params["bp"] = 1;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Erste Seite'>&laquo;</a>";

            $params["bp"] = $curr_bp - 1;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Zurück'>&lsaquo;</a>";
        }

        $range = 2;
        for ($i = ($curr_bp - $range); $i <= ($curr_bp + $range); $i++) {
            if ($i > 0 && $i <= $pages_bp) {
                $params = $_GET;
                $params["bp"] = $i;

                if ($i == $curr_bp) {
                    $view .= "<span class='page-link active'>$i</span>";
                } else {
                    $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link'>$i</a>";
                }
            }
        }

        if ($curr_bp < $pages_bp) {
            $params = $_GET;
            $params["bp"] = $curr_bp + 1;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Weiter'>&rsaquo;</a>";

            $params["bp"] = $pages_bp;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Letzte Seite'>&raquo;</a>";
        }

        $view .= '</div></div>';
    }
} else {
    $view .= "Derzeit gibt es keine Bauaufträge, Forschungen oder Rekrutierungen.";
}

// --- MARKETPLACE AND TRANSPORTS OVERVIEW ---
$count_wp = $db_instance->execute_query("SELECT COUNT(*) FROM events WHERE userid = ? AND (actionid = ? OR actionid = ?)",
    [$user->get_user_id(), ActionTypes::ACTION_RECEIVE_RESOURCES, ActionTypes::ACTION_RETURN_RESOURCES])->fetch_row()[0];
$pages_wp = ceil($count_wp / $limit);
$curr_wp = isset($_GET["wp"]) ? max(1, (int)$_GET["wp"]) : 1;
$offset_wp = ($curr_wp - 1) * $limit;

$view .= '<div class="title-border" style="margin-top: 30px;">Warenlieferungen</div>';

$query_trades = "
    SELECT e.*, k.kingdomname, k.mapx, k.mapy 
    FROM events e 
    JOIN kingdoms k ON e.kingdomid = k.id 
    WHERE e.userid = ? AND (e.actionid = ? OR e.actionid = ?)
    ORDER BY e.arrivaltime
    LIMIT $offset_wp, $limit
";
$result_trades = $db_instance->execute_query($query_trades, [$user->get_user_id(),
    ActionTypes::ACTION_RECEIVE_RESOURCES, ActionTypes::ACTION_RETURN_RESOURCES]);

if ($result_trades && $result_trades->num_rows > 0) {
    $view .= "<table class='table' style='width: 100%;'>";
    $view .= "<colgroup>
                <col style='width: 18%;'> <!-- Art -->
                <col style='width: 25%;'> <!-- Ressourcen -->
                <col style='width: 27%;'> <!-- Ziel -->
                <col style='width: 22%;'> <!-- Ankunft -->
                <col style='width: 8%;'>  <!-- Button -->
              </colgroup>";
    $view .= "<tr>
            <td class='td-center td-gradient'>Art</td>
            <td class='td-center td-gradient'>Ressourcen</td>
            <td class='td-center td-gradient'>Ziel</td>
            <td class='td-center td-gradient' colspan='2'>Ankunft</td>
        </tr>";

    foreach ($result_trades as $row) {
        $event_id = $row["eventid"];
        $res_type = $row["buildingid"];
        $amount = $row["buildinglevel"];
        $target_name = $row["kingdomname"];
        $target_coords = "{$row["mapx"]}:{$row["mapy"]}";

        $arrival_diff = max(0, $row["arrivaltime"] - $now);
        $counter_id = "trade_counter_" . $event_id;

        $is_cancelable = ($row["actionid"] == ActionTypes::ACTION_RECEIVE_RESOURCES && $row["buildingname"] == "Interner Transport");

        $view .= "<tr>
                <td class='td-center'>{$row["buildingname"]}</td>
                <td class='td-center'>" . get_resource_icon($res_type) . " " . fnum($amount) . "</td>
                <td class='td-center'>
                    $target_name
                    <a href='#' data-on-click='switchKingdom' data-id='" . e($row["kingdomid"]) . "'>(" . e($target_coords) . ")</a>
                </td>";

        if ($is_cancelable) {
            $view .= "<td class='td-center'>
                        <b><span id='$counter_id'></span></b>
                      </td>
                      <td class='td-center'>
                        <form action='overview.php' method='GET'>
                            <input type='hidden' name='action' value='cancel'>
                            <input type='hidden' name='eid' value='$event_id'>
                            <input type='submit' value='' class='btn-delete' style='margin-top: 5px;'>
                        </form>
                      </td>";
        } else {
            $view .= "<td class='td-center' colspan='2'>
                        <b><span id='$counter_id'></span></b>
                      </td>";
        }

        $view .= "<span class='js-countdown' 
                       id='$counter_id' 
                       data-seconds='$arrival_diff' 
                       data-no-reload='true'></span>
            </tr>";
    }

    $view .= "</table>";

    if ($pages_wp > 1) {
        $view .= '<div class="pagination-container"><div class="pagination-bar">';

        if ($curr_wp > 1) {
            $params = $_GET;
            $params["wp"] = 1;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Erste Seite'>&laquo;</a>";

            $params["wp"] = $curr_wp - 1;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Zurück'>&lsaquo;</a>";
        }

        $range = 2;
        for ($i = ($curr_wp - $range); $i <= ($curr_wp + $range); $i++) {
            if ($i > 0 && $i <= $pages_wp) {
                $params = $_GET;
                $params["wp"] = $i;

                if ($i == $curr_wp) {
                    $view .= "<span class='page-link active'>$i</span>";
                } else {
                    $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link'>$i</a>";
                }
            }
        }

        if ($curr_wp < $pages_wp) {
            $params = $_GET;
            $params["wp"] = $curr_wp + 1;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Weiter'>&rsaquo;</a>";

            $params["wp"] = $pages_wp;
            $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Letzte Seite'>&raquo;</a>";
        }

        $view .= '</div></div>';
    }
} else {
    $view .= "Derzeit sind keine Warenlieferungen unterwegs.";
}

// Show user data...
$view .= "<div class='title-border' style='margin-top: 30px;'>Allgemeine Daten</div>";
$view .= "<table class='table' style='max-width: 450px;'>";
$view .= "<tr><td>Login-Zeit:</td><td><span id='login-counter' data-start='$time_diff'></span></td></tr>";
$view .= "<tr><td>IP-Adresse:</td><td>" . $_SERVER["REMOTE_ADDR"] . "</td></tr>";
$view .= "<tr><td>Haupt-Königreich:</td><td>" . $kingdom->get_kingdom_name() . " (" . $kingdom->get_kingdom_map_x() . ":" . $kingdom->get_kingdom_map_y() . ")</td></tr>";
$view .= "<tr><td>E-Mail:</td><td>$email</td></tr>";
$view .= "<tr><td>Registriert seit:</td><td>" . date('d.m.Y H:i:s', $register_date) . "</td></tr>";
$view .= "<tr><td>Letzter Login:</td><td>" . date('d.m.Y H:i:s', $_SESSION["lastlogin"]) . "</td></tr>";
$view .= "<tr><td>Score:</td><td>$score</td></tr>";
$view .= "<tr><td>Gilde:</td><td>" . ($guild_id == -1 ? "Keine Gilde" : $guild_id) . "</td></tr>";
$view .= "<tr><td>Admin-Level:</td><td>" . $user->get_user_admin_level() . "</td></tr>";
$view .= "</table>";

/*
 * HTML Section
 */
$title = "Übersicht";
$header = "Übersicht";
$script_files = ["counter", "userinfo"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
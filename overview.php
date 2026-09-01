<?php
require_once("includes/core.php");

check_user_login($user);

// Get main kingdom of user
$result = $db_instance->execute_query("SELECT mainkingdom FROM users WHERE id = ?", [$_SESSION["userid"]]);
$row_main = $result->fetch_assoc();
$active_k_id = $user->get_current_kingdom();
$now = time();
$kingdom = new Kingdom($db_instance, $active_k_id);

$tp_actions = [
    ActionTypes::ACTION_SEND_TROOPS,
    ActionTypes::ACTION_RETURN_TROOPS,
    ActionTypes::ACTION_STATION_TROOPS,
    ActionTypes::ACTION_SUPPORT_RETURN
];
$bp_actions = [
    ActionTypes::ACTION_BUILD_BUILDING,
    ActionTypes::ACTION_BUILD_TROOPS,
    ActionTypes::ACTION_RESEARCH_TECH,
    ActionTypes::ACTION_UPGRADE_TROOPS,
    ActionTypes::ACTION_SMITHY_UPGRADE
];
$wp_actions = [
    ActionTypes::ACTION_RECEIVE_RESOURCES,
    ActionTypes::ACTION_RETURN_RESOURCES
];

$tp_list = implode(',', $tp_actions);
$bp_list = implode(',', $bp_actions);
$wp_list = implode(',', $wp_actions);

$counts = $db_instance->execute_query("
    SELECT 
        COUNT(CASE WHEN (userid = ? AND kingdomid = ? AND actionid IN ($tp_list)) 
                     OR (targetid = ? AND actionid = " . ActionTypes::ACTION_STATION_TROOPS . ") THEN 1 END) AS count_tp,
        COUNT(CASE WHEN userid = ? AND actionid IN ($bp_list) THEN 1 END) AS count_bp,
        COUNT(CASE WHEN userid = ? AND actionid IN ($wp_list) THEN 1 END) AS count_wp
    FROM events",
    [
        $user->get_user_id(), $active_k_id, $active_k_id, // für count_tp
        $user->get_user_id(),                             // für count_bp
        $user->get_user_id()                              // für count_wp
    ]
)->fetch_assoc();

$current_k_tp_count = (int)($counts["count_tp"] ?? 0);
$count_bp = (int)($counts["count_bp"] ?? 0);
$count_wp = (int)($counts["count_wp"] ?? 0);

if (!isset($_SESSION["acknowledged_attacks"])) {
    $_SESSION["acknowledged_attacks"] = [];
}

if (!empty($_SESSION["active_attacks"])) {
    foreach ($_SESSION["active_attacks"] as $attack) {
        if (!in_array($attack["eventid"], $_SESSION["acknowledged_attacks"])) {
            $_SESSION["acknowledged_attacks"][] = $attack["eventid"];
        }
    }
}

if (!isset($_SESSION["acknowledged_supports"])) {
    $_SESSION["acknowledged_supports"] = [];
}

if (!empty($_SESSION["active_supports"])) {
    foreach ($_SESSION["active_supports"] as $sup) {
        if (!in_array($sup["eventid"], $_SESSION["acknowledged_supports"])) {
            $_SESSION["acknowledged_supports"][] = $sup["eventid"];
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

        if ($event["actionid"] == ActionTypes::ACTION_SEND_TROOPS || $event["actionid"] == ActionTypes::ACTION_STATION_TROOPS) {
            if ($event["is_processing"] == 1) {
                $error = "Truppen sind bereits in ein Gefecht verwickelt oder am Ziel angekommen!";
            } else {
                $total_duration = $event["arrivaltime"] - $event["buildingtime"];
                $already_marched = max(0, min($now - $event["buildingtime"], $total_duration));
                $new_arrival_time = $now + $already_marched;

                $db_instance->execute_query(
                    "UPDATE events SET 
                                actionid = ?, 
                                arrivaltime = ?, 
                                loot_food = 0, loot_wood = 0, loot_stone = 0, loot_gold = 0,
                                is_processing = 0
                             WHERE eventid = ? AND userid = ?",
                    [ActionTypes::ACTION_RETURN_TROOPS, $new_arrival_time, $event_id, $user->get_user_id()]
                );

                $logger->log_game("COMBAT", "ATTACK_RECALL", [
                    "event_id" => $event_id,
                    "target_x" => $event["targetx"],
                    "target_y" => $event["targety"]
                ], $event["kingdomid"]);

                change_location("overview.php");
                exit;
            }
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

            change_location("overview.php");
            exit;
        }
    } else {
        $error = "Diese Aktion ist ungültig!";
    }
}

$map = new Map($db_instance, $user);
$limit = 7;

// -- INCOMING ENEMIES OVERVIEW ---
$incoming_data = $_SESSION["active_attacks"] ?? [];

if (!empty($_SESSION["active_attacks"])) {
    $incoming_html = "";

    foreach ($_SESSION["active_attacks"] as &$attack) {
        if (!in_array($attack["eventid"], $_SESSION["acknowledged_attacks"])) {
            $_SESSION["acknowledged_attacks"][] = $attack["eventid"];

            $attack["is_new"] = false;
        }

        $diff = $attack["arrivaltime"] - $now;
        $incoming_html .= "<tr>
            <td style='color: var(--link-color);'>Alarm in <b>" . e($attack["kingdomname"]) . "</b>!</td>
            <td class='td-center'><b>Ankunft in: 
                    <span class='js-countdown' data-seconds='$diff' data-no-reload='true'>
                          " . format_time_for_js($diff) . "
                    </span>
                </b>
            </td>
        </tr>";
    }
    unset($attack);

    $view .= '<div class="title-border error">Feindliche Truppenbewegungen</div>';
    $view .= '<table class="table" style="max-width: 550px">' . $incoming_html . '</table><br>';
}

// --- TROOP OVERVIEW ---
$tp_actions = [ActionTypes::ACTION_SEND_TROOPS, ActionTypes::ACTION_RETURN_TROOPS];
$tp_list = implode(',', $tp_actions);

$res_count_tp = $db_instance->execute_query("
    SELECT COUNT(*) as total 
    FROM events 
    WHERE userid = ? AND kingdomid = ? AND actionid IN ($tp_list)
", [$user->get_user_id(), $active_k_id]);
$count_tp_active_k = (int)$res_count_tp->fetch_assoc()['total'];

$pages_tp = ceil($count_tp_active_k / $limit);
$curr_tp = isset($_GET["tp"]) ? max(1, (int)$_GET["tp"]) : 1;
$offset_tp = ($curr_tp - 1) * $limit;

$tc_lvl = $kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_TOWNCENTER);
$max_tp = BASE_SEND_TROOPS_LIMIT + $tc_lvl;

$view .= '<div class="title-border">Truppenbewegungen (' . $count_tp_active_k . '/' . $max_tp . ')</div>';

$query = "
    SELECT 
        e.eventid, e.actionid, e.userid, e.kingdomid, e.targetid, 
        e.targetx, e.targety, e.arrivaltime, e.buildingtime, e.is_processing,
        e.loot_food, e.loot_wood, e.loot_stone, e.loot_gold, e.loot_coins,
        st.soldierid AS st_soldierid, 
        st.soldiercount AS soldiercount, 
        sl.icon AS soldier_icon, 
        sl.soldiername AS s_name,
        k.mapx, k.mapy,
        kt.userid AS target_userid, 
        kt.username AS target_username,
        u_sender.username AS sender_username
    FROM (
        SELECT 
            eventid, actionid, userid, kingdomid, targetid, 
            targetx, targety, arrivaltime, buildingtime, is_processing,
            loot_food, loot_wood, loot_stone, loot_gold, loot_coins
        FROM events 
        WHERE 
            (userid = ? AND kingdomid = ? AND actionid IN (?, ?, ?, ?)) 
            OR 
            (targetid = ? AND actionid = ?) 
        ORDER BY arrivaltime, eventid 
        LIMIT ?, ?
    ) e
    LEFT JOIN sent_troops st ON st.eventid = e.eventid
    LEFT JOIN soldier_list sl ON st.soldierid = sl.id
    LEFT JOIN kingdoms k ON e.kingdomid = k.id 
    LEFT JOIN kingdoms kt ON e.targetid = kt.id
    LEFT JOIN users u_sender ON e.userid = u_sender.id
";

$result = $db_instance->execute_query($query, [
    $user->get_user_id(),
    $active_k_id,
    ActionTypes::ACTION_SEND_TROOPS,
    ActionTypes::ACTION_RETURN_TROOPS,
    ActionTypes::ACTION_STATION_TROOPS,
    ActionTypes::ACTION_SUPPORT_RETURN,
    $active_k_id,
    ActionTypes::ACTION_STATION_TROOPS,
    $offset_tp,
    $limit
]);

if ($result && $result->num_rows > 0) {
    $view .= "<table class='table sent-troops-table' style='width: 100%;'>";
    $view .= "<colgroup>
                <col style='width: 18%;'> <!-- Art -->
                <col style='width: 32%;'> <!-- Truppen -->
                <col style='width: 21%;'> <!-- Koordinaten -->
                <col style='width: 29%;'> <!-- Ankunft -->
              </colgroup>";
    $view .= "<tr>
            <td class='td-center td-gradient'>Art</td>
            <td class='td-center td-gradient'>Truppen</td>
            <td class='td-center td-gradient'>Koordinaten</td>
            <td class='td-center td-gradient'>Ankunft</td>
        </tr>";

    $grouped_events = [];

    // Group each sent soldier type, so that no extra rows are created with multiple soldiers
    foreach ($result as $row) {
        $event_id = $row["eventid"];

        // Initialize the event group if it doesn't exist yet
        if (!isset($grouped_events[$event_id])) {
            $grouped_events[$event_id] = [
                "actionid" => $row["actionid"],
                "userid" => $row["userid"],
                "sender_username" => $row["sender_username"],
                "targetid" => $row["targetid"],
                "target_userid" => $row["target_userid"],
                "target_username" => $row["target_username"],
                "mapx" => $row["mapx"],
                "mapy" => $row["mapy"],
                "targetx" => $row["targetx"],
                "targety" => $row["targety"],
                "arrivaltime" => $row["arrivaltime"],
                "is_processing" => $row["is_processing"],
                "loot_food" => $row["loot_food"],
                "loot_wood" => $row["loot_wood"],
                "loot_stone" => $row["loot_stone"],
                "loot_gold" => $row["loot_gold"],
                "loot_coins" => $row["loot_coins"],
                "is_scouting" => true,
                "soldiers" => []
            ];
        }

        if ((int)$row["st_soldierid"] !== Soldiers::SOLDIER_SCOUT) {
            $grouped_events[$event_id]["is_scouting"] = false;
        }

        // Append this troop type to the troops list
        $grouped_events[$event_id]["soldiers"][] = [
            "soldierid" => $row["st_soldierid"],
            "soldiercount" => $row["soldiercount"],
            "icon" => $row["soldier_icon"],
            "name" => $row["s_name"]
        ];
    }

    foreach ($grouped_events as $event_id => $event_data) {
        $action_id = $event_data["actionid"];
        $is_return = ($action_id === ActionTypes::ACTION_RETURN_TROOPS || $action_id === ActionTypes::ACTION_SUPPORT_RETURN);
        $is_me = ((int)$event_data["userid"] === $user->get_user_id());

        $action_type = "Angriff";
        $action_button = "";
        $is_target_my_kingdom = ($event_data["target_userid"] == $user->get_user_id());
        $difference_time = max(0, $event_data["arrivaltime"] - $now);
        $counter_id = "counter_" . $event_id;

        $my_coords = "<a href='#' data-on-click='mapJump' data-x='" . e($event_data["mapx"]) . "' data-y='" . e($event_data["mapy"]) . "'>" . e($event_data["mapx"]) . ":" . e($event_data["mapy"]) . "</a>";
        $target_coords = "<a href='#' data-on-click='mapJump' data-x='" . e($event_data["targetx"]) . "' data-y='" . e($event_data["targety"]) . "'>" . e($event_data["targetx"]) . ":" . e($event_data["targety"]) . "</a>";

        $target_name_info = "";
        if ($event_data["targetid"] > 0 && !empty($event_data["target_username"])) {
            $target_name_info = " <small>(" . e($event_data["target_username"]) . ")</small>";
        }

        $coords_str = "$my_coords → $target_coords" . $target_name_info;

        $action_counter = "<b><span class='js-countdown' 
                               id='$counter_id' 
                               data-seconds='$difference_time' 
                               data-no-reload='true'>" . format_time_for_js($difference_time) . "</span></b>";

        if ($is_me && !$is_return && ((int)($event_data["is_processing"] ?? 0) === 0)) {
            $action_button = "<form action='overview.php' method='GET' style='display: inline;'>
                            <input type='hidden' name='action' value='cancel'>
                            <input type='hidden' name='eid' value='" . $event_id . "'>
                            <input type='submit' value='' class='btn-delete' title='Abbrechen'>
                        </form>";
        }

        $is_pure_scout = $event_data["is_scouting"];

        if ($action_id === ActionTypes::ACTION_STATION_TROOPS) {
            $action_type = "Unterstützung";

            if (!$is_me) {
                $sender_name = e($event_data["sender_username"] ?? "Unbekannt");

                $coords_str = "$target_coords ← $my_coords <small>($sender_name)</small>";
            }
        } else if ($action_id === ActionTypes::ACTION_RETURN_TROOPS || $action_id === ActionTypes::ACTION_SUPPORT_RETURN) {
            $action_type = ($action_id === ActionTypes::ACTION_SUPPORT_RETURN) ? "Support-Rückzug" : "Rückkehr";
            $coords_str = "$target_coords → $my_coords";
        } else if ($action_id === ActionTypes::ACTION_SEND_TROOPS) {
            if ($event_data["targetid"] == -1) {
                $action_type = "Gründung";
            } else if ($event_data["targetid"] == -2) {
                $action_type = $is_pure_scout ? "Spionage" : "Plündern";
            } else if ($event_data["targetid"] == -3) {
                $action_type = $is_pure_scout ? "Spionage" : "Monstercamp";
            } else if ($is_target_my_kingdom) {
                $action_type = "Stationieren";
            } else {
                $action_type = $is_pure_scout ? "Spionage" : "Angriff";
            }
        }

        // Build soldiers string
        $badge_count = 0;

        $soldiers_str = "<div class='badge-container' style='display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;'>";
        foreach ($event_data["soldiers"] as $soldier) {
            $badge_count++;

            $s_id = (int)$soldier["soldierid"];
            $soldier_name = e($soldier["name"]);
            $icon_path = "images/icons/" . e($soldier["icon"]) . ".png";

            $has_loot = ($event_data["loot_food"] > 0 || $event_data["loot_wood"] > 0 || $event_data["loot_stone"] > 0 || $event_data["loot_gold"] > 0);
            $is_carrier = ($soldier["soldierid"] == Soldiers::SOLDIER_THIEF || $soldier["soldierid"] == Soldiers::SOLDIER_RAIDER);

            $popup_class = "";
            $popup_content = "";

            if ($action_id == ActionTypes::ACTION_RETURN_TROOPS && $has_loot) {
                $popup_class = " popup";
                $p_id = "loot_" . $event_id . "_" . $soldier["soldierid"];

                $popup_content = "<div id='{$p_id}_box' class='popupbox' style='text-align:left;'>";
                $popup_content .= "<b>Beute:</b><br>";
                if ($event_data["loot_food"] > 0) $popup_content .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " " . fnum($event_data["loot_food"]) . " ";
                if ($event_data["loot_wood"] > 0) $popup_content .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " " . fnum($event_data["loot_wood"]) . " ";
                if ($event_data["loot_stone"] > 0) $popup_content .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . fnum($event_data["loot_stone"]) . " ";
                if ($event_data["loot_gold"] > 0) $popup_content .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . fnum($event_data["loot_gold"]) . " ";
                if ($event_data["loot_coins"] > 0) $popup_content .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " " . fnum($event_data["loot_coins"]) . " ";
                $popup_content .= "</div>";
            }

            $responsive_class = "";
            if ($badge_count > MAX_UNIT_BADGES_PER_ROW_MOBILE) {
                $responsive_class .= " badge-hide-mobile";
            }
            if ($badge_count > MAX_UNIT_BADGES_PER_ROW_DESKTOP) {
                $responsive_class .= " badge-hide-desktop";
            }

            $soldiers_str .= "<div class='unit-badge$popup_class $responsive_class' id='" . ($has_loot ? $p_id : "") . "' title='" . (empty($popup_class) ? $soldier_name : "") . "'>";
            $soldiers_str .= "<img src='$icon_path' class='ressource-icons' alt='$soldier_name'>
                                <b>" . fnum($soldier["soldiercount"]) . "</b>
                                $popup_content
                            </div>";
        }

        if ($badge_count > MAX_UNIT_BADGES_PER_ROW_MOBILE) {
            $btn_extra = ($badge_count <= MAX_UNIT_BADGES_PER_ROW_DESKTOP) ? " hide-toggle-desktop" : "";
            $soldiers_str .= "<span data-on-click='toggleBadges' class='badge-toggle$btn_extra' style='cursor: pointer; font-weight: bold; padding: 5px;'> (...)</span>";
        }
        $soldiers_str .= "</div>";

        $view .= "<tr>
                <td class='td-center'>$action_type</td>
                <td class='td-center'>$soldiers_str</td>
                <td class='td-center'>$coords_str</td>";
        $view .= "<td class='td-center td-timer-cell' style='position: relative;'>
            <b>$action_counter</b>";

        if ($action_button !== "") {
            $view .= "<div class='delete-btn'>
                $action_button
              </div>";
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
$pages_bp = ceil($count_bp / $limit);
$curr_bp = isset($_GET["bp"]) ? max(1, (int)$_GET["bp"]) : 1;
$offset_bp = ($curr_bp - 1) * $limit;

$view .= '<div class="title-border" style="margin-top: 30px;">Bau & Entwicklung</div>';

$query_events = "
    SELECT e.*, k.kingdomname, k.mapx, k.mapy, sl.icon AS soldier_icon, sl.soldiername AS soldiername
    FROM events e 
    JOIN kingdoms k ON e.kingdomid = k.id
    LEFT JOIN soldier_list sl ON sl.id = e.soldierid
    WHERE e.userid = ? AND e.actionid IN (?, ?, ?, ?, ?)
    ORDER BY k.kingdomname, COALESCE(NULLIF(e.buildingtime, 0), e.recruittime)
    LIMIT $offset_bp, $limit
";

$result_events = $db_instance->execute_query($query_events, [
    $user->get_user_id(),
    ActionTypes::ACTION_BUILD_BUILDING,
    ActionTypes::ACTION_BUILD_TROOPS,
    ActionTypes::ACTION_RESEARCH_TECH,
    ActionTypes::ACTION_UPGRADE_TROOPS,
    ActionTypes::ACTION_SMITHY_UPGRADE
]);

if ($result_events && $result_events->num_rows > 0) {
    $view .= "<table class='table overview-info-table' style='width: 100%;'>";
    $view .= "<colgroup>
                <col class='col-build-type'> <!-- Art -->
                <col class='col-build-project'> <!-- Projekt -->
                <col class='col-build-kingdom'> <!-- Königreich -->
                <col class='col-build-timer'> <!-- Fertigstellung -->
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
        $hover_name = "";

        switch ($action_id) {
            case ActionTypes::ACTION_BUILD_BUILDING:
                $type_text = "Bauauftrag";
                $next_lvl = $row["buildinglevel"] + 1;

                $icon = "<img src='images/icons/icon_building" . (int)$row["buildingid"] . ".png' class='ressource-icons' alt=''>";
                $project_text = "$icon ($next_lvl)";
                $finish_time = $row["buildingtime"];
                $hover_name = $row["buildingname"];
                break;
            case ActionTypes::ACTION_RESEARCH_TECH:
            case ActionTypes::ACTION_SMITHY_UPGRADE:
                $type_text = ($action_id == ActionTypes::ACTION_RESEARCH_TECH) ? "Forschung" : "Verbesserung";
                $next_lvl = $row["buildinglevel"] + 1;

                $icon = "<img src='images/icons/icon_tech" . (int)$row["buildingid"] . ".png' class='ressource-icons' alt=''>";
                $project_text = "$icon ($next_lvl)";
                $finish_time = $row["buildingtime"];
                $hover_name = $row["buildingname"];
                break;
            case ActionTypes::ACTION_BUILD_TROOPS:
                $type_text = "Rekrutierung";
                $sol_obj = new Soldier();
                $sol_obj->set_soldier_id($row["soldierid"]);
                $sol_obj->set_soldier_icon($row["soldier_icon"]);
                $sol_obj->set_soldier_name($row["soldiername"]);

                $project_text = "<div class='unit-badge' title='" . e($row["soldiername"]) . "'>
                            " . $sol_obj->get_soldier_icon("ressource-icons") . "
                            <b>" . fnum($row["soldiergoal"]) . "</b>
                         </div>";
                $finish_time = $row["recruittime"];
                $hover_name = $row["soldiername"];
                break;
            case ActionTypes::ACTION_UPGRADE_TROOPS:
                $type_text = "Aufwertung";
                $sol_obj = new Soldier();
                $sol_obj->set_soldier_id($row["soldierid"]);
                $sol_obj->set_soldier_icon($row["soldier_icon"]);
                $sol_obj->set_soldier_name($row["soldiername"]);

                $project_text = "<div class='unit-badge' title='Upgrade zu " . e($row["soldiername"]) . "'>
                            " . $sol_obj->get_soldier_icon("ressource-icons") . "
                            <b>" . fnum($row["soldiergoal"]) . "</b>
                         </div>";

                $res_s = $db_instance->execute_query("SELECT requiredtime FROM soldier_list WHERE id = ?", [$row["soldierid"]]);
                $u_time = $res_s->fetch_assoc()["requiredtime"];

                $finish_time = $row["recruittime"];
                $hover_name = $row["soldiername"];
                break;
        }

        $arrival_diff = max(0, $finish_time - $now);
        $counter_id = "event_counter_" . $event_id;

        $view .= "<tr>
                <td class='td-center'>$type_text</td>
                <td class='td-center'>
                    <div class='popup' id='event_pop_$event_id'>
                        $project_text
                        <div id='event_pop_{$event_id}_box' class='popupbox'>
                            <b>" . e($hover_name) . "</b>
                        </div>
                    </div>
                </td>
                <td class='td-center'>
                    <div class='location-wrapper'>
                        <div class='kingdom-name-break' style='min-width: 0;'>$k_name</div>
                        <a href='#' style='flex-shrink: 0; white-space: nowrap; margin-left: 4px;' data-on-click='switchKingdom' data-id='" . e($row["kingdomid"]) . "'>" . e($k_coords) . "</a>
                    </div>
                </td>
                <td class='td-center td-timer-cell' style='position: relative;'>
                    <b><span class='js-countdown' 
                       id='$counter_id' 
                       data-seconds='$arrival_diff' 
                       data-no-reload='true'>" . format_time_for_js($arrival_diff) . "</span></b>
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
    $view .= "<table class='table overview-info-table' style='width: 100%;'>";
    $view .= "<colgroup>
                <col class='col-rss-type'> <!-- Art -->
                <col class='col-rss'>     <!-- Ressourcen -->
                <col class='col-rss-kingdom'> <!-- Ziel -->
                <col class='col-rss-timer'> <!-- Ankunft -->
              </colgroup>";
    $view .= "<tr>
            <td class='td-center td-gradient'>Art</td>
            <td class='td-center td-gradient'>Ressourcen</td>
            <td class='td-center td-gradient'>Ziel</td>
            <td class='td-center td-gradient'>Ankunft</td>
        </tr>";

    foreach ($result_trades as $row) {
        $event_id = $row["eventid"];
        $target_name = $row["kingdomname"];
        $target_coords = "{$row["mapx"]}:{$row["mapy"]}";

        $arrival_diff = max(0, $row["arrivaltime"] - $now);
        $counter_id = "trade_counter_" . $event_id;

        $is_cancelable = ($row["actionid"] == ActionTypes::ACTION_RECEIVE_RESOURCES && $row["buildingname"] == "Interner Transport");

        $res_display = "";

        if ($row["buildinglevel"] > 0) {
            $res_display .= get_resource_icon((int)$row["buildingid"]) . " " . fnum($row["buildinglevel"]) . " ";
        }

        $multi_cols = [
            ResourceTypes::RESOURCE_TYPE_FOOD => $row["loot_food"],
            ResourceTypes::RESOURCE_TYPE_WOOD => $row["loot_wood"],
            ResourceTypes::RESOURCE_TYPE_STONE => $row["loot_stone"],
            ResourceTypes::RESOURCE_TYPE_GOLD => $row["loot_gold"]
        ];

        foreach ($multi_cols as $res_type => $amount) {
            if ($amount > 0) {
                $res_display .= "<div>" . get_resource_icon($res_type) . " " . fnum($amount) . "</div>";
            }
        }

        $view .= "<tr>
                <td class='td-center'><div class='type-name-break' title='{$row["buildingname"]}'>{$row["buildingname"]}</div></td>
                <td class='td-center'>$res_display</td>
                <td class='td-center'>
                    <div class='location-wrapper'>
                        <div class='kingdom-name-break' style='min-width: 0;'>$target_name</div>
                        <a href='#' style='flex-shrink: 0; white-space: nowrap; margin-left: 4px;' data-on-click='switchKingdom' data-id='" . e($row["kingdomid"]) . "'>" . e($target_coords) . "</a>
                    </div>
                </td>
                <td class='td-center td-timer-cell' style='position: relative;'>
                    <b><span class='js-countdown' 
                             id='$counter_id' 
                             data-seconds='$arrival_diff' 
                             data-no-reload='true'>
                             " . format_time_for_js($arrival_diff) . "
                    </span></b>";

        if ($is_cancelable) {
            $view .= "<div class='delete-btn'>
                        <form action='overview.php' method='GET' style='display: inline;'>
                            <input type='hidden' name='action' value='cancel'>
                            <input type='hidden' name='eid' value='$event_id'>
                            <input type='submit' value='' class='btn-delete'>
                        </form>
                      </div>";
        }

        $view .= "</td></tr>";
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

// Tutorial Check
if (isset($_SESSION["tutorial_done"]) && $_SESSION["tutorial_done"] === 0) {
    $k_info = $db_instance->execute_query("
        SELECT ft.fieldname, ft.foodrate, ft.woodrate, ft.stonerate, ft.goldrate 
        FROM map m 
        JOIN field_types ft ON m.fieldtype = ft.fieldid 
        WHERE m.kingdomid = ?", [$user->get_current_kingdom()])->fetch_assoc();

    $good_res = "";
    if ($k_info["foodrate"] > 1) $good_res .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " Nahrung ";
    if ($k_info["woodrate"] > 1) $good_res .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " Holz ";
    if ($k_info["stonerate"] > 1) $good_res .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " Stein ";
    if ($k_info["goldrate"] > 1) $good_res .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " Gold ";

    if (empty($good_res)) {
        $good_res = "<i>Dieses Land ist ein Allrounder (ausgeglichene Erträge).</i>";
    }

    $view .= "
    <div id='tutorial-overlay' class='info-box-bg' style='display:flex;'>
        <div class='big-box-container' style='max-width: 500px; margin: auto; z-index: 1001; padding: 15px 15px 0;'>
            <div class='big-box-header'>Willkommen, Eure Hoheit!</div>
            <div class='big-box-content' style='text-align: left;'>
                <p style='margin-top: 0;'>Eure Siedlung im <b class='passed'>{$k_info["fieldname"]}</b> ist bereit. Beachtet diese 3 Grundregeln:</p>
                <div style='margin-bottom: 15px;'>
                    <b style='color: var(--link-color);'>1. Ressourcen sichern</b><br>
                    <p>Baut zuerst <b>Mühle, Sägewerk</b> oder <b>Steinmine</b>. Euer Land liefert extra viel:</p>
                    <p>$good_res</p>
                </div>
                <div style='margin-bottom: 15px;'>
                    <b style='color: var(--link-color);'>2. Das Dorfzentrum</b><br>
                    <p>Das Herz eures Reiches. Seine Stufe begrenzt das Level <b>aller</b> anderen Gebäude 
                    (außer <b>Lager</b>. Dieses kann eine Stufe höher als das aktuelle Dorfzentrum gebaut werden).</p>
                </div>
                <div style='margin-bottom: 15px;'>
                    <b style='color: var(--link-color);'>3. Schutz & Reparatur</b><br>
                    <p>Eure <b>Mauer</b> gibt einen Verteidigungsbonus, um Angreifer abzuschrecken. Haltet sie stets repariert!</p>
                </div>
                <div style='text-align: center; margin-top: 20px;'>
                    <button id='close-tutorial' data-on-click='finishTutorial' style='padding: 10px 40px;'>Alles klar!</button>
                </div>
            </div>
        </div>
    </div>";
}


/*
 * HTML Section
 */
$title = "Übersicht";
$header = "Übersicht";
$script_files = ["timer", "userinfo"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
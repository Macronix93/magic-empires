<?php
require_once("includes/core.php");

check_user_login($user);

// Get main kingdom of user
$active_k_id = $user->get_current_kingdom();
$uid = $user->get_user_id();
$my_guild_id = $user->get_user_guild_id();
$now = time();
$kingdom = new Kingdom($active_k_id);

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
        COUNT(CASE WHEN (userid = ? AND actionid IN ($bp_list)) 
                     OR (guild_id = ? AND actionid = " . ActionTypes::ACTION_RESEARCH_TECH . ") THEN 1 END) AS count_bp,
        COUNT(CASE WHEN userid = ? AND actionid IN ($wp_list) THEN 1 END) AS count_wp
    FROM events",
    [
        $uid, $active_k_id, $active_k_id,
        $uid, $my_guild_id,
        $uid
    ]
)->fetch_assoc();

$current_k_tp_count = (int)($counts["count_tp"] ?? 0);
$count_bp = (int)($counts["count_bp"] ?? 0);
$count_wp = (int)($counts["count_wp"] ?? 0);

if (!isset($_SESSION["acknowledged_attacks"])) {
    $_SESSION["acknowledged_attacks"] = [];
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

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["recall_mine_troops"])) {
    $x = (int)($_POST["mine_x"] ?? 0);
    $y = (int)($_POST["mine_y"] ?? 0);

    if ($kingdom->recall_mine_troops($x, $y)) {
        $_SESSION["game_success"] = "Deine Schürfer treten mit der Beute den Rückweg an!";
    }

    change_location("overview.php");
    exit;
}

// Fetch all sent troops events from the user
if (isset($_GET["action"]) && $_GET["action"] == "cancel" && isset($_GET["eid"])) {
    $event_id = (int)$_GET["eid"];

    $db_instance->begin_transaction();

    $result = $db_instance->execute_query(
        "SELECT * FROM events WHERE eventid = ? AND userid = ? FOR UPDATE",
        [$event_id, $user->get_user_id()]
    );

    if ($result && $result->num_rows > 0) {
        $event = $result->fetch_assoc();

        if ($event["is_processing"] > 0) {
            $db_instance->rollback();
            $error = "Truppen sind bereits am Ziel angekommen oder in ein Gefecht verwickelt!";
        } else if ($event["actionid"] == ActionTypes::ACTION_SEND_TROOPS || $event["actionid"] == ActionTypes::ACTION_STATION_TROOPS) {
            $total_duration = $event["arrivaltime"] - $event["buildingtime"];
            $already_marched = max(0, min($now - $event["buildingtime"], $total_duration));
            $new_arrival_time = $now + $already_marched;

            $db_instance->execute_query(
                "UPDATE events SET 
                    actionid = ?, 
                    arrivaltime = ?, 
                    loot_food = 0, loot_wood = 0, loot_stone = 0, loot_gold = 0,
                    is_processing = 0
                 WHERE eventid = ? AND is_processing = 0",
                [ActionTypes::ACTION_RETURN_TROOPS, $new_arrival_time, $event_id]
            );

            if ($db_instance->affected_rows === 1) {
                $db_instance->commit();

                $logger->log_game("COMBAT", "ATTACK_RECALL", [
                    "event_id" => $event_id,
                    "target_x" => $event["targetx"],
                    "target_y" => $event["targety"]
                ], $event["kingdomid"]);

                change_location("overview.php");
                exit;
            } else {
                $db_instance->rollback();
                $error = "Truppen konnten nicht zurückgerufen werden (bereits am Ziel angekommen).";
            }
        } else if ($event["actionid"] == ActionTypes::ACTION_RECEIVE_RESOURCES && $event["buildingname"] == TransportTypes::TRANSPORT_TYPE_INTERNAL) {
            $total_duration = $event["arrivaltime"] - $event["buildingtime"];
            $already_marched = max(0, min($now - $event["buildingtime"], $total_duration));
            $new_arrival_time = $now + $already_marched;

            $db_instance->execute_query(
                "UPDATE events SET actionid = ?, kingdomid = ?, targetid = ?, arrivaltime = ?, buildingname = ? 
                 WHERE eventid = ? AND is_processing = 0",
                [
                    ActionTypes::ACTION_RETURN_RESOURCES,
                    $event["targetid"],
                    $event["kingdomid"],
                    $new_arrival_time,
                    TransportTypes::TRANSPORT_TYPE_TRADE_RETURN,
                    $event_id
                ]
            );

            if ($db_instance->affected_rows === 1) {
                $db_instance->commit();

                change_location("overview.php");
                exit;
            } else {
                $db_instance->rollback();
                $error = "Warenlieferung ist bereits eingetroffen!";
            }
        } else {
            $db_instance->rollback();
            $error = "Diese Aktion kann nicht abgebrochen werden!";
        }
    } else {
        $db_instance->rollback();
        $error = "Diese Aktion ist ungültig!";
    }
}

$map = new Map($user);

$limit = OVERVIEW_PAGESIZE_DEFAULT;
if (isset($_COOKIE["me_overview_pagesize"]) && is_numeric($_COOKIE["me_overview_pagesize"])) {
    $limit = max(OVERVIEW_PAGESIZE_MIN, min(OVERVIEW_PAGESIZE_MAX, (int)$_COOKIE["me_overview_pagesize"]));
}

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

        $time_display = ($attack["arrivaltime"] > 0)
            ? "Ankunft in <span class='js-countdown' data-seconds='$diff' data-no-reload='true' data-zero-text='00:00'>" . format_time_for_js($diff) . "</span>"
            : "Ankunft unbekannt!";

        $tx = (int)($attack["targetx"] ?? 0);
        $ty = (int)($attack["targety"] ?? 0);
        $coords_link = ($tx > 0 && $ty > 0)
            ? " (<a href='#' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>)"
            : "";

        $incoming_html .= "<tr>
            <td style='color: var(--link-color);'>Alarm in <b>" . e($attack["kingdomname"]) . "</b>$coords_link!</td>
            <td class='td-center'><b>$time_display</b></td>
        </tr>";
    }
    unset($attack);

    $view .= '<div class="title-border error">Feindliche Truppenbewegungen</div>';
    $view .= '<table class="table" style="max-width: 550px">' . $incoming_html . '</table><br>';
}

// --- BUILDING, TECH & RECRUIT OVERVIEW ---
$count_kp_res = $db_instance->execute_query("SELECT COUNT(*) as total FROM kingdoms WHERE userid = ?", [$uid]);
$count_kp = (int)$count_kp_res->fetch_assoc()["total"];

$pages_kp = max(1, (int)ceil($count_kp / $limit));
$curr_kp = isset($_GET["kp"]) ? max(1, min($pages_kp, (int)$_GET["kp"])) : 1;
$offset_kp = ($curr_kp - 1) * $limit;

$user_kingdoms = $db_instance->execute_query(
    "SELECT id, kingdomname, mapx, mapy,
            food, maxfood, foodperhour,
            wood, maxwood, woodperhour,
            stone, maxstone, stoneperhour,
            gold, maxgold, goldperhour,
            villager, maxvillager, villagerperhour
     FROM kingdoms WHERE userid = ? ORDER BY created_at, id LIMIT ?, ?",
    [$uid, $offset_kp, $limit]
)->fetch_all(MYSQLI_ASSOC);

$k_events_res = $db_instance->execute_query("
    SELECT e.*, sl.icon AS soldier_icon, sl.soldiername AS soldiername 
    FROM events e 
    LEFT JOIN soldier_list sl ON sl.id = e.soldierid 
    WHERE e.userid = ? AND e.actionid IN (?, ?, ?, ?, ?)
", [
    $uid,
    ActionTypes::ACTION_BUILD_BUILDING,
    ActionTypes::ACTION_BUILD_TROOPS,
    ActionTypes::ACTION_UPGRADE_TROOPS,
    ActionTypes::ACTION_RESEARCH_TECH,
    ActionTypes::ACTION_SMITHY_UPGRADE
]);

$events_by_kingdom = [];
foreach ($k_events_res as $ev) {
    $kid = (int)$ev["kingdomid"];
    $act = (int)$ev["actionid"];

    if ($act === ActionTypes::ACTION_BUILD_BUILDING) {
        $events_by_kingdom[$kid]["build"] = $ev;
    } else if ($act === ActionTypes::ACTION_RESEARCH_TECH || $act === ActionTypes::ACTION_SMITHY_UPGRADE) {
        $events_by_kingdom[$kid]["research"][] = $ev;
    } else if ($act === ActionTypes::ACTION_BUILD_TROOPS || $act === ActionTypes::ACTION_UPGRADE_TROOPS) {
        $events_by_kingdom[$kid]["recruit"] = $ev;
    }
}

$total_projects = $k_events_res->num_rows;
$view .= '<div class="title-border">Bau & Entwicklung (' . $total_projects . ')</div>';
$view .= "<table class='table overview-table'>
    <colgroup>
        <col class='col-overview-kingdom'>
        <col class='col-overview-building'>
        <col class='col-overview-tech'>
        <col class='col-overview-recruit'>
    </colgroup>
    <tr>
        <td class='td-center td-gradient'><b>Königreich</b></td>
        <td class='td-center td-gradient'><b>Bau</b></td>
        <td class='td-center td-gradient'><b>Forschung</b></td>
        <td class='td-center td-gradient'><b>Ausbildung</b></td>
    </tr>";

foreach ($user_kingdoms as $k) {
    $kid = (int)$k["id"];
    $k_name = e($k["kingdomname"]);
    $k_coords = e($k["mapx"] . ":" . $k["mapy"]);
    $is_active_k = ($kid === (int)$active_k_id);
    $row_style = $is_active_k ? "style='background: rgba(212, 175, 55, 0.08);'" : "";

    $storage_warnings = [];
    $storage_is_full = false;
    $storage_is_warning = false;

    $res_check = [
        ["cur" => (int)$k["food"], "max" => (int)$k["maxfood"], "prod" => (int)$k["foodperhour"], "icon" => ResourceTypes::RESOURCE_TYPE_FOOD],
        ["cur" => (int)$k["wood"], "max" => (int)$k["maxwood"], "prod" => (int)$k["woodperhour"], "icon" => ResourceTypes::RESOURCE_TYPE_WOOD],
        ["cur" => (int)$k["stone"], "max" => (int)$k["maxstone"], "prod" => (int)$k["stoneperhour"], "icon" => ResourceTypes::RESOURCE_TYPE_STONE],
        ["cur" => (int)$k["gold"], "max" => (int)$k["maxgold"], "prod" => (int)$k["goldperhour"], "icon" => ResourceTypes::RESOURCE_TYPE_GOLD],
    ];

    foreach ($res_check as $rc) {
        if ($rc["cur"] >= $rc["max"]) {
            $storage_is_full = true;
            $storage_warnings[] = get_resource_icon($rc["icon"]) . " <span class='error'>" . fnum($rc["cur"]) . " / " . fnum($rc["max"]) . "</span>";
        } elseif ($rc["prod"] > 0 && ($rc["cur"] + $rc["prod"] >= $rc["max"] || $rc["cur"] >= $rc["max"] * KINGDOM_OVERFLOW_FACTOR)) {
            $storage_is_warning = true;
            $storage_warnings[] = get_resource_icon($rc["icon"]) . " <span class='warning'>" . fnum($rc["cur"]) . " / " . fnum($rc["max"]) . "</span>";
        }
    }

    $vill_cur = (int)$k["villager"];
    $vill_max = (int)$k["maxvillager"];
    $vill_prod = (int)$k["villagerperhour"];
    $vill_is_full = ($vill_cur >= $vill_max && $vill_max > 0);
    $vill_is_warning = (!$vill_is_full && $vill_prod > 0 && ($vill_cur + $vill_prod >= $vill_max || $vill_cur >= $vill_max * KINGDOM_OVERFLOW_FACTOR));

    $vill_warnings = [];
    if ($vill_is_full) {
        $vill_warnings[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_VILLAGER) . " <span class='error'>" . fnum($vill_cur) . " / " . fnum($vill_max) . "</span>";
    } elseif ($vill_is_warning) {
        $vill_warnings[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_VILLAGER) . " <span class='warning'>" . fnum($vill_cur) . " / " . fnum($vill_max) . "</span>";
    }

    $indicators_html = "";
    if ($storage_is_full || $storage_is_warning || $vill_is_full || $vill_is_warning) {
        $indicators_html .= "<div class='kingdom-overflow-indicators'>";

        if ($storage_is_full || $storage_is_warning) {
            $s_class = $storage_is_full ? "danger" : "warning";
            $s_title = $storage_is_full ? "Lager voll!" : "Lager droht vollzulaufen!";
            $indicators_html .= "
                <span class='popup' id='pop_overflow_storage_$kid'>
                    <img src='images/icons/icon_building9.png' class='overflow-icon $s_class' alt='Lager-Status'>
                    <div id='pop_overflow_storage_{$kid}_box' class='popupbox' style='text-align: left; min-width: 180px;'>
                        <b>$s_title</b><br>
                        " . implode("<br>", $storage_warnings) . "
                    </div>
                </span>";
        }
        if ($vill_is_full || $vill_is_warning) {
            $v_class = $vill_is_full ? "danger" : "warning";
            $v_title = $vill_is_full ? "Wohnraum voll!" : "Wohnraum droht vollzulaufen!";

            $indicators_html .= "
                <span class='popup' id='pop_overflow_vill_$kid'>
                    <img src='images/icons/icon_villager.png' class='overflow-icon $v_class' alt='Bewohner-Status'>
                    <div id='pop_overflow_vill_{$kid}_box' class='popupbox' style='text-align: left; min-width: 180px;'>
                        <b>$v_title</b><br>
                        " . implode("<br>", $vill_warnings) . "
                    </div>
                </span>";
        }
        $indicators_html .= "</div>";
    }

    $k_pop_id = "pop_k_preview_" . $kid;
    $k_res_popup = "
        <div id='{$k_pop_id}_box' class='popupbox' style='text-align: left; min-width: 200px;'>
            <b>$k_name</b> <small>($k_coords)</small>
            <hr style='margin: 6px 0; border: 0; border-top: 1px solid rgba(212, 175, 55, 0.4);'>
            <div style='display: flex; justify-content: space-between; gap: 12px; margin-bottom: 2px;'>
                <span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " Nahrung:</span>
                <span style='white-space: nowrap;'>" . fnum((int)$k["food"]) . " / " . fnum((int)$k["maxfood"]) . "</span>
            </div>
            <div style='display: flex; justify-content: space-between; gap: 12px; margin-bottom: 2px;'>
                <span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " Holz:</span>
                <span style='white-space: nowrap;'>" . fnum((int)$k["wood"]) . " / " . fnum((int)$k["maxwood"]) . "</span>
            </div>
            <div style='display: flex; justify-content: space-between; gap: 12px; margin-bottom: 2px;'>
                <span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " Stein:</span>
                <span style='white-space: nowrap;'>" . fnum((int)$k["stone"]) . " / " . fnum((int)$k["maxstone"]) . "</span>
            </div>
            <div style='display: flex; justify-content: space-between; gap: 12px; margin-bottom: 2px;'>
                <span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " Gold:</span>
                <span style='white-space: nowrap;'>" . fnum((int)$k["gold"]) . " / " . fnum((int)$k["maxgold"]) . "</span>
            </div>
            <hr style='margin: 6px 0; border: 0; border-top: 1px solid rgba(255, 255, 255, 0.1);'>
            <div style='display: flex; justify-content: space-between; gap: 12px;'>
                <span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_VILLAGER) . " Bewohner:</span>
                <span style='white-space: nowrap;'>" . fnum((int)$k["villager"]) . " / " . fnum((int)$k["maxvillager"]) . "</span>
            </div>
        </div>
    ";

    $col_kingdom = "
        <div class='kingdom-cell-wrapper'>
            <div class='popup' id='$k_pop_id' style='display: flex; align-items: center; min-width: 0; flex: 1;'>
                <a href='#' class='kingdom-link kingdom-name-break' data-on-click='switchKingdom' data-id='$kid'>
                    $k_name
                </a>
                $k_res_popup
            </div>
            $indicators_html
        </div>";

    $col_build = "<div class='td-center'>-</div>";
    if (isset($events_by_kingdom[$kid]["build"])) {
        $b_ev = $events_by_kingdom[$kid]["build"];
        $next_lvl = $b_ev["buildinglevel"] + 1;
        $b_diff = max(0, $b_ev["buildingtime"] - $now);
        $b_icon = "<img src='images/icons/icon_building" . (int)$b_ev["buildingid"] . ".png' class='ressource-icons' alt=''>";

        $col_build = "
            <div class='col-wrapper'>
                <div class='popup project-info' id='pop_tb_{$b_ev["eventid"]}'>
                    $b_icon <span class='project-lvl'>($next_lvl)</span>
                    <div id='pop_tb_{$b_ev["eventid"]}_box' class='popupbox'><b>" . e($b_ev["buildingname"]) . "</b> <span class='project-lvl-popup'>($next_lvl)</span></div>
                </div>
                <div class='timer'>
                    <b><span class='js-countdown' data-seconds='$b_diff' data-no-reload='true'>" . format_time_for_js($b_diff) . "</span></b>
                </div>
            </div>";
    }

    $col_research = "<div class='td-center'>-</div>";
    if (!empty($events_by_kingdom[$kid]["research"])) {
        $research_items = [];
        foreach ($events_by_kingdom[$kid]["research"] as $t_ev) {
            $next_lvl = $t_ev["buildinglevel"] + 1;
            $t_diff = max(0, $t_ev["buildingtime"] - $now);
            $t_icon = "<img src='images/icons/icon_tech" . (int)$t_ev["buildingid"] . ".png' class='ressource-icons' alt=''>";

            $research_items[] = "
                <div class='col-wrapper'>
                    <div class='popup project-info' id='pop_tr_{$t_ev["eventid"]}'>
                        $t_icon <span class='project-lvl'>($next_lvl)</span>
                        <div id='pop_tr_{$t_ev["eventid"]}_box' class='popupbox'><b>" . e($t_ev["buildingname"]) . "</b> <span class='project-lvl-popup'>($next_lvl)</span></div>
                    </div>
                    <div class='timer'>
                        <b><span class='js-countdown' data-seconds='$t_diff' data-no-reload='true'>" . format_time_for_js($t_diff) . "</span></b>
                    </div>
                </div>";
        }
        $col_research = "<div class='stack-wrapper'>" . implode("", $research_items) . "</div>";
    }

    $col_recruit = "<div class='td-center'>-</div>";
    if (isset($events_by_kingdom[$kid]["recruit"])) {
        $r_ev = $events_by_kingdom[$kid]["recruit"];
        $r_diff = max(0, $r_ev["recruittime"] - $now);
        $r_icon = "<img src='images/icons/" . e($r_ev["soldier_icon"]) . ".png' class='ressource-icons' alt=''>";
        $is_upg = ((int)$r_ev["actionid"] === ActionTypes::ACTION_UPGRADE_TROOPS);
        $badge_title = ($is_upg ? "Aufwertung: " : "") . e($r_ev["soldiername"]);
        $pop_id = "pop_rec_" . $r_ev["eventid"];

        $upgrade_arrow = $is_upg ? "<img src='images/icons/icon_arrow_up.png' class='badge-arrow-up' alt='▲' title='Aufwertung'>" : "";

        $col_recruit = "
            <div class='col-wrapper'>
                <div class='unit-badge popup' id='$pop_id'>
                    $r_icon<b>" . fnum($r_ev["soldiergoal"]) . "$upgrade_arrow</b>
                    <div id='{$pop_id}_box' class='popupbox'><b>$badge_title</b></div>
                </div>
                <div class='timer'>
                    <b><span class='js-countdown' data-seconds='$r_diff' data-no-reload='true'>" . format_time_for_js($r_diff) . "</span></b>
                </div>
            </div>";
    }

    $view .= "<tr $row_style>
        <td class='col-overview-kingdom-cell'>$col_kingdom</td>
        <td>$col_build</td>
        <td>$col_research</td>
        <td>$col_recruit</td>
    </tr>";
}

$view .= "</table>";

// Pagination Bar
if ($pages_kp > 1) {
    $view .= '<div class="pagination-container"><div class="pagination-bar">';

    if ($curr_kp > 1) {
        $params = $_GET;
        $params["kp"] = 1;
        $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Erste Seite'>&laquo;</a>";

        $params["kp"] = $curr_kp - 1;
        $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Zurück'>&lsaquo;</a>";
    }

    $range = 2;
    for ($i = ($curr_kp - $range); $i <= ($curr_kp + $range); $i++) {
        if ($i > 0 && $i <= $pages_kp) {
            $params = $_GET;
            $params["kp"] = $i;

            if ($i == $curr_kp) {
                $view .= "<span class='page-link active'>$i</span>";
            } else {
                $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link'>$i</a>";
            }
        }
    }

    if ($curr_kp < $pages_kp) {
        $params = $_GET;
        $params["kp"] = $curr_kp + 1;
        $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Weiter'>&rsaquo;</a>";

        $params["kp"] = $pages_kp;
        $view .= "<a href='overview.php?" . http_build_query($params) . "' class='page-link' title='Letzte Seite'>&raquo;</a>";
    }

    $view .= '</div></div>';
}

// --- TROOP OVERVIEW ---
$res_tp_combined = $db_instance->execute_query("
    SELECT 
        (SELECT COUNT(*) FROM events 
         WHERE userid = ? AND kingdomid = ? AND actionid IN (?, ?)) AS count_events,
        (SELECT COUNT(DISTINCT mine_id) FROM mine_stationed_troops 
         WHERE kingdom_id = ?) AS count_mines
", [
    $user->get_user_id(),
    $active_k_id,
    ActionTypes::ACTION_SEND_TROOPS,
    ActionTypes::ACTION_RETURN_TROOPS,
    $active_k_id
]);

$tp_data = $res_tp_combined->fetch_assoc();
$count_tp_events = (int)($tp_data["count_events"] ?? 0);
$count_active_mines = (int)($tp_data["count_mines"] ?? 0);

$count_tp_active_k = $count_tp_events + $count_active_mines;

$pages_tp = ceil($count_tp_active_k / $limit);
$curr_tp = isset($_GET["tp"]) ? max(1, min(max(1, (int)$pages_tp), (int)$_GET["tp"])) : 1;
$offset_tp = ($curr_tp - 1) * $limit;

$tc_lvl = $kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_TOWNCENTER);
$max_tp = BASE_SEND_TROOPS_LIMIT + $tc_lvl;

$view .= '<div class="title-border" style="margin-top: 30px;">Truppenbewegungen (' . $count_tp_active_k . '/' . $max_tp . ')</div>';

$query = "
    SELECT 
        e.eventid, e.actionid, e.userid, e.kingdomid, e.targetid, 
        e.targetx, e.targety, e.arrivaltime, e.buildingtime, e.is_processing,
        e.loot_food, e.loot_wood, e.loot_stone, e.loot_gold, e.loot_coins,
        e.loot_coal, e.loot_iron, e.loot_sapphire, e.loot_diamond,
        st.soldierid AS st_soldierid, 
        SUM(st.soldiercount) AS soldiercount,
        sl.icon AS soldier_icon, 
        sl.soldiername AS s_name,
        k.mapx, k.mapy,
        k.username AS source_owner_username,
        k.kingdomname AS source_kingdom_name,
        kt.kingdomname AS target_kingdom_name,
        kt.userid AS target_userid, 
        kt.username AS target_username,
        u_sender.username AS sender_username
    FROM (
        SELECT 
            eventid, actionid, userid, kingdomid, targetid, 
            targetx, targety, arrivaltime, buildingtime, is_processing,
            loot_food, loot_wood, loot_stone, loot_gold, loot_coins,
            loot_coal, loot_iron, loot_sapphire, loot_diamond
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
    GROUP BY e.arrivaltime, e.eventid, st.soldierid
    ORDER BY e.arrivaltime, e.eventid
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

$grouped_events = [];
if ($result && $result->num_rows > 0) {
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
                "source_kingdom_name" => $row["source_kingdom_name"],
                "source_owner_username" => $row["source_owner_username"],
                "target_kingdom_name" => $row["target_kingdom_name"],
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
                "loot_coal" => (int)($row["loot_coal"] ?? 0),
                "loot_iron" => (int)($row["loot_iron"] ?? 0),
                "loot_sapphire" => (int)($row["loot_sapphire"] ?? 0),
                "loot_diamond" => (int)($row["loot_diamond"] ?? 0),
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
}

$res_active_miners = $db_instance->execute_query("
    SELECT 
        mn.id as mine_id, mn.mapx, mn.mapy, mn.level, mn.work_done, mn.work_total, mn.last_update,
        mn.stone, mn.gold, mn.coal, mn.iron, mn.sapphire, mn.diamond,
        (SELECT IFNULL(SUM(mst2.soldiercount * mst2.unit_atk), 0) FROM mine_stationed_troops mst2 WHERE mst2.mine_id = mn.id) as total_mine_atk,
        (SELECT IFNULL(SUM(mst3.soldiercount * mst3.unit_atk), 0) FROM mine_stationed_troops mst3 WHERE mst3.mine_id = mn.id AND mst3.user_id = st.user_id AND mst3.kingdom_id = st.kingdom_id) as my_mine_atk,
        (SELECT IFNULL(SUM(mst4.work_contributed), 0) FROM mine_stationed_troops mst4 WHERE mst4.mine_id = mn.id AND mst4.user_id = st.user_id AND mst4.kingdom_id = st.kingdom_id) as my_work_stored,
        st.soldier_id, SUM(st.soldiercount) as soldiercount, sl.soldiername, sl.icon
    FROM mine_stationed_troops st
    JOIN mines mn ON st.mine_id = mn.id
    JOIN soldier_list sl ON st.soldier_id = sl.id
    WHERE st.user_id = ? AND st.kingdom_id = ?
    GROUP BY mn.id, mn.mapx, mn.mapy, mn.level, mn.work_done, mn.work_total, mn.last_update,
             mn.stone, mn.gold, mn.coal, mn.iron, mn.sapphire, mn.diamond, st.soldier_id, sl.soldiername, sl.icon
    ORDER BY mn.id, st.soldier_id
", [$uid, $active_k_id]);

$miners_by_mine = [];
while ($m_row = $res_active_miners->fetch_assoc()) {
    $mid = (int)$m_row["mine_id"];

    if (!isset($miners_by_mine[$mid])) {
        $elapsed = max(0, $now - (int)($m_row["last_update"] ?: $now));
        $total_mine_atk = (float)$m_row["total_mine_atk"];
        $my_mine_atk = (float)$m_row["my_mine_atk"];
        $work_total = max(1, (int)$m_row["work_total"]);
        $work_done = (float)$m_row["work_done"];
        $my_work = (float)$m_row["my_work_stored"];

        if ($elapsed > 0 && $total_mine_atk > 0) {
            $max_rate = $work_total / MINE_MIN_DURATION_SECONDS;
            $effective_rate = min($total_mine_atk * MINE_WORK_RATE_FACTOR, $max_rate);
            $work_delta = $effective_rate * $elapsed;
            $work_done = min($work_total, $work_done + $work_delta);

            $my_work += ($work_delta * ($my_mine_atk / $total_mine_atk));
        }

        $mined_ratio = min(1.0, $work_done / $work_total);
        $share = ($work_done > 0) ? min(1.0, $my_work / $work_done) : 0;

        $miners_by_mine[$mid] = [
            "mapx" => $m_row["mapx"],
            "mapy" => $m_row["mapy"],
            "work_done" => (int)round($work_done),
            "work_total" => $work_total,
            "total_atk" => (int)$total_mine_atk,
            "loot" => [
                "stone" => (int)floor($m_row["stone"] * $mined_ratio * $share),
                "gold" => (int)floor($m_row["gold"] * $mined_ratio * $share),
                "coal" => (int)floor($m_row["coal"] * $mined_ratio * $share),
                "iron" => (int)floor($m_row["iron"] * $mined_ratio * $share),
                "sapphire" => (int)floor($m_row["sapphire"] * $mined_ratio * $share),
                "diamond" => (int)floor($m_row["diamond"] * $mined_ratio * $share)
            ],
            "troops" => []
        ];
    }
    $miners_by_mine[$mid]["troops"][] = $m_row;
}

if (!empty($grouped_events) || !empty($miners_by_mine)) {
    $view .= "<table class='table sent-troops-table' style='width: 100%;'>";
    $view .= "<colgroup>
                <col style='width: 18%;'> <!-- Art -->
                <col style='width: 32%;'> <!-- Truppen -->
                <col style='width: 21%;'> <!-- Koordinaten -->
                <col style='width: 29%;'> <!-- Ankunft -->
              </colgroup>";
    $view .= "<tr>
            <td class='td-center td-gradient'><b>Art</b></td>
            <td class='td-center td-gradient'><b>Truppen</b></td>
            <td class='td-center td-gradient'><b>Koordinaten</b></td>
            <td class='td-center td-gradient'><b>Zeit</b></td>
        </tr>";

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

        $coords_str = "$target_coords" . $target_name_info; // $my_coords →

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

            $src_name = e($event_data["source_kingdom_name"]);
            $tgt_name = e($event_data["target_kingdom_name"] ?? "Unbekannt");
            $names_str = "$src_name → $tgt_name";

            $player_info = "";
            if (!$is_me) {
                $player_info = " <small>(" . e($event_data["sender_username"]) . ")</small>";
            } elseif ($event_data["target_userid"] != $user->get_user_id() && $event_data["targetid"] > 0) {
                $player_info = " <small>(" . e($event_data["target_username"]) . ")</small>";
            }

            $coords_str = "$names_str $player_info<br><small>$target_coords</small>"; // $my_coords →
        } else if ($action_id === ActionTypes::ACTION_RETURN_TROOPS || $action_id === ActionTypes::ACTION_SUPPORT_RETURN) {
            $action_type = ($action_id === ActionTypes::ACTION_SUPPORT_RETURN) ? "Support-Rückzug" : "Rückkehr";
            $coords_str = "$target_coords"; // $target_coords →
        } else if ($action_id === ActionTypes::ACTION_SEND_TROOPS) {
            if ($event_data["targetid"] == MapFieldTypes::MAP_FIELD_EMPTY) {
                $action_type = "Gründung";
            } else if ($event_data["targetid"] == MapFieldTypes::MAP_FIELD_RESOURCE_TILE) {
                $action_type = $is_pure_scout ? "Spionage" : "Plündern";
            } else if ($event_data["targetid"] == MapFieldTypes::MAP_FIELD_MONSTER_CAMP) {
                $action_type = $is_pure_scout ? "Spionage" : "Monstercamp";
            } else if ($event_data["targetid"] == MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM) {
                $action_type = $is_pure_scout ? "Spionage" : "Ruine";
            } else if ($event_data["targetid"] == MapFieldTypes::MAP_FIELD_MINE) {
                $action_type = $is_pure_scout ? "Spionage" : "Mine";
            } else if ($is_target_my_kingdom) {
                $action_type = "Stationieren";
            } else {
                $action_type = ($event_data["targetid"] == MapFieldTypes::MAP_FIELD_WORLD_EVENT ? "Event" : ($is_pure_scout ? "Spionage" : "Angriff"));
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

            $has_loot = ($event_data["loot_food"] > 0 || $event_data["loot_wood"] > 0 || $event_data["loot_stone"] > 0 || $event_data["loot_gold"] > 0 || ($event_data["loot_coal"] ?? 0) > 0
                || ($event_data["loot_iron"] ?? 0) > 0 || ($event_data["loot_sapphire"] ?? 0) > 0 || ($event_data["loot_diamond"] ?? 0) > 0);
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
                if (($event_data["loot_coal"] ?? 0) > 0) $popup_content .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_COAL) . " " . fnum($event_data["loot_coal"]) . " ";
                if (($event_data["loot_iron"] ?? 0) > 0) $popup_content .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_IRON) . " " . fnum($event_data["loot_iron"]) . " ";
                if (($event_data["loot_sapphire"] ?? 0) > 0) $popup_content .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_SAPPHIRE) . " " . fnum($event_data["loot_sapphire"]) . " ";
                if (($event_data["loot_diamond"] ?? 0) > 0) $popup_content .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_DIAMOND) . " " . fnum($event_data["loot_diamond"]) . " ";
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

    foreach ($miners_by_mine as $mid => $mine_data) {
        $mx = $mine_data["mapx"];
        $my = $mine_data["mapy"];
        $m_coords = "<a href='#' data-on-click='mapJump' data-x='$mx' data-y='$my'>$mx:$my</a>";

        $rate = $mine_data["total_atk"] * MINE_WORK_RATE_FACTOR;
        $rem_work = max(0, $mine_data["work_total"] - $mine_data["work_done"]);
        $rem_sec = $rate > 0 ? (int)ceil($rem_work / $rate) : 0;
        $percent_val = ($mine_data["work_total"] > 0) ? ($mine_data["work_done"] / $mine_data["work_total"]) * 100 : 0;
        $percent_display = fdec($percent_val);

        $loot_items = [];
        if (($mine_data["loot"]["stone"] ?? 0) > 0) $loot_items[] = "<div class='loot-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " <span>" . fnum($mine_data["loot"]["stone"]) . "</span></div>";
        if (($mine_data["loot"]["gold"] ?? 0) > 0) $loot_items[] = "<div class='loot-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " <span>" . fnum($mine_data["loot"]["gold"]) . "</span></div>";
        if (($mine_data["loot"]["coal"] ?? 0) > 0) $loot_items[] = "<div class='loot-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COAL) . " <span>" . fnum($mine_data["loot"]["coal"]) . "</span></div>";
        if (($mine_data["loot"]["iron"] ?? 0) > 0) $loot_items[] = "<div class='loot-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_IRON) . " <span>" . fnum($mine_data["loot"]["iron"]) . "</span></div>";
        if (($mine_data["loot"]["sapphire"] ?? 0) > 0) $loot_items[] = "<div class='loot-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_SAPPHIRE) . " <span>" . fnum($mine_data["loot"]["sapphire"]) . "</span></div>";
        if (($mine_data["loot"]["diamond"] ?? 0) > 0) $loot_items[] = "<div class='loot-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DIAMOND) . " <span>" . fnum($mine_data["loot"]["diamond"]) . "</span></div>";

        if (!empty($loot_items)) {
            $loot_popup_content = "<div style='display: flex; gap: 4px; margin-top: 5px;'>" . implode("", $loot_items) . "</div>";
        } else {
            $loot_popup_content = "<div style='margin-top: 5px; opacity: 0.8;'><i>Noch keine Erze abgebaut.</i></div>";
        }

        $badge_html = "<div class='badge-container' style='display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;'>";
        foreach ($mine_data["troops"] as $t) {
            $s_id = (int)$t["soldier_id"];
            $p_id = "pop_mine_loot_{$mid}_$s_id";

            $badge_html .= "<div class='unit-badge popup' id='$p_id'>
                                <img src='images/icons/{$t["icon"]}.png' class='ressource-icons' alt=''>
                                <b>" . fnum($t["soldiercount"]) . "</b>
                                <div id='{$p_id}_box' class='popupbox' style='text-align: left; min-width: 130px;'>
                                    <b>Geschürfte Ressourcen:</b><br>$loot_popup_content
                                </div>
                            </div>";
        }
        $badge_html .= "</div>";

        $view .= "<tr>
            <td class='td-center'><span style='color: #E6C15A;'>Minen-Abbau</span></td>
            <td class='td-center'>$badge_html</td>
            <td class='td-center'>$m_coords</td>
            <td class='td-center td-timer-cell' style='position: relative;'>
                <b><span class='js-countdown' data-seconds='$rem_sec' data-no-reload='true'>" . format_time_for_js($rem_sec) . "</span></b><br>
                <small style='opacity: 0.8;'>
                    <b class='js-mine-progress' data-work-done='{$mine_data["work_done"]}' data-work-total='{$mine_data["work_total"]}' data-rate='$rate'>$percent_display %</b> abgebaut
                </small>
                <div class='delete-btn'>
                    <form action='overview.php' method='POST' style='display: inline;'>
                        <input type='hidden' name='recall_mine_troops' value='1'>
                        <input type='hidden' name='mine_x' value='$mx'>
                        <input type='hidden' name='mine_y' value='$my'>
                        <input type='submit' value='' class='btn-delete' title='Truppen mit Beute heimrufen'>
                    </form>
                </div>
            </td>
        </tr>";
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
    $view .= "<span class='no-event'>Derzeit sind keine Truppen unterwegs.</span>";
}

// --- MARKETPLACE AND TRANSPORTS OVERVIEW ---
$pages_wp = ceil($count_wp / $limit);
$curr_wp = isset($_GET["wp"]) ? max(1, min(max(1, (int)$pages_wp), (int)$_GET["wp"])) : 1;
$offset_wp = ($curr_wp - 1) * $limit;

$view .= '<div class="title-border" style="margin-top: 30px;">Warenlieferungen</div>';

$query_trades = "
    SELECT e.*, k.kingdomname, k.mapx, k.mapy 
    FROM events e 
    LEFT JOIN kingdoms k ON e.kingdomid = k.id 
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
            <td class='td-center td-gradient'><b>Art</b></td>
            <td class='td-center td-gradient'><b>Ressourcen</b></td>
            <td class='td-center td-gradient'><b>Ziel</b></td>
            <td class='td-center td-gradient'><b>Ankunft</b></td>
        </tr>";

    foreach ($result_trades as $row) {
        $event_id = $row["eventid"];
        $target_name = $row["kingdomname"];
        $target_coords = "{$row["mapx"]}:{$row["mapy"]}";

        $arrival_diff = max(0, $row["arrivaltime"] - $now);
        $counter_id = "trade_counter_" . $event_id;

        $is_cancelable = ($row["actionid"] == ActionTypes::ACTION_RECEIVE_RESOURCES && $row["buildingname"] == TransportTypes::TRANSPORT_TYPE_INTERNAL);

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
    $view .= "<span class='no-event'>Derzeit sind keine Warenlieferungen unterwegs.</span>";
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
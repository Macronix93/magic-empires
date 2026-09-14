<?php
// Simulation für CLI
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once("../includes/core.php");

$db = Database::get_instance()->get_connection();
$now = time();

echo "[" . date("Y-m-d H:i:s") . "] Starte Cron-Tick...\n";

$system_user = new User(-1, "System");
$global_em = new EventManager($system_user);
$global_em->process_mines();
$global_em->cleanup_marketplace();
$global_em->check_watchtower_notifications();

$action_building_list = implode(',', [
    ActionTypes::ACTION_BUILD_BUILDING,
    ActionTypes::ACTION_RESEARCH_TECH,
    ActionTypes::ACTION_SMITHY_UPGRADE
]);

$action_movement_list = implode(',', [
    ActionTypes::ACTION_SEND_TROOPS,
    ActionTypes::ACTION_RETURN_TROOPS,
    ActionTypes::ACTION_RECEIVE_RESOURCES,
    ActionTypes::ACTION_RETURN_RESOURCES,
    ActionTypes::ACTION_STATION_TROOPS,
    ActionTypes::ACTION_SUPPORT_RETURN
]);


$query = "SELECT e.*, u.username 
          FROM events e
          JOIN users u ON e.userid = u.id
          WHERE (e.is_processing = 0 OR e.is_processing < " . (time() - 60) . ")
          AND (
              (e.actionid IN ($action_building_list) AND e.buildingtime > 0 AND e.buildingtime <= ?) 
              OR
              (e.actionid IN ($action_movement_list) AND e.arrivaltime > 0 AND e.arrivaltime <= ?)
              OR
              (e.actionid = " . ActionTypes::ACTION_BUILD_TROOPS . " AND e.buildingtime <= ?)
              OR
              (e.actionid = " . ActionTypes::ACTION_UPGRADE_TROOPS . " AND e.recruittime > 0 AND e.recruittime <= ?)
          )";

$result = $db_instance->execute_query($query, [$now, $now, $now, $now]);

$count = 0;
foreach ($result as $row) {
    // Locking
    $db->execute_query(
        "UPDATE events SET is_processing = ? WHERE eventid = ? AND (is_processing = 0 OR is_processing = ? OR is_processing < ?)",
        [$now, $row["eventid"], $row["is_processing"], ($now - 60)]
    );

    if ($db->affected_rows > 0) {
        try {
            $event_owner = new User((int)$row["userid"], $row["username"]);
            $GLOBALS["user"] = $event_owner;

            $db->begin_transaction();
            $em = new EventManager($event_owner);
            $em->handle_event($row);
            $db->commit();

            $count++;
        } catch (Throwable $t) {
            $db->rollback();
            $db->execute_query("UPDATE events SET is_processing = 0 WHERE eventid = ?", [$row["eventid"]]);

            error_log("Cronjob Error in Event " . $row["eventid"] . ": " . $t->getMessage());
        }
    }
}

if ($count > 0) {
    echo "[" . date("H:i:s") . "] $count Events processed.\n";
}

//// World Event Logic
$we_logic = new WorldEvent($db);

$finished_events = $db->execute_query("SELECT * FROM world_events WHERE is_rewarded = 0 AND end_time <= ?", [time()]);

while ($ev = $finished_events->fetch_assoc()) {
    $pool = $we_logic->get_monster_pool();
    $monster = $pool[$ev["monster_index"]] ?? $pool[0];

    $participants = $db_instance->execute_query("
        SELECT p.*, u.username, u.mainkingdom 
        FROM world_event_participants p 
        JOIN users u ON p.userid = u.id 
        WHERE p.event_id = ?", [$ev["id"]]);

    while ($p = $participants->fetch_assoc()) {
        $u_id = (int)$p["userid"];
        $u_name = $p["username"];
        $is_boss_fail = false;

        $actual_target_kid = $we_logic->get_valid_delivery_kingdom($u_id, (int)$p["top_kingdom_id"]);

        if ($ev["event_type"] === "BOSS_HP") {
            if ($ev["current_hp"] <= 0) {
                if ($actual_target_kid > 0) {
                    $target_k_obj = new Kingdom($db_instance, $actual_target_kid);
                    $loot = $we_logic->generate_hp_boss_loot($u_id);

                    // Resources
                    foreach ($loot["resources"] as $res_id => $amount) {
                        $target_k_obj->modify_resource($res_id, $amount);
                    }

                    // Soldiers & Score
                    $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";
                    $total_score_gain = 0;

                    foreach ($loot["soldiers"] as $s) {
                        $db_instance->execute_query("
                            INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount)
                            SELECT ?, id, soldiername, ? FROM soldier_list WHERE id = ?
                            ON DUPLICATE KEY UPDATE soldiercount = soldiercount + VALUES(soldiercount)
                        ", [$actual_target_kid, $s["count"], $s["id"]]);

                        $unit_data = $db_instance->execute_query("SELECT soldiername, icon, scoregain FROM soldier_list WHERE id = ?", [$s["id"]])->fetch_assoc();
                        $total_score_gain += ($s["count"] * (int)$unit_data["scoregain"]);

                        $units_html .= BattleReportRenderer::render_unit_card($unit_data["soldiername"], $s["count"], 0, $unit_data["icon"], true);
                    }
                    $units_html .= "</div>";

                    if ($total_score_gain > 0) {
                        $db_instance->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$total_score_gain, $u_id]);
                    }

                    $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                            "Event-Abschluss",
                            "Deine Truppen aus <b>" . e($target_k_obj->get_kingdom_name()) . "</b> waren am Sieg beteiligt! Du erhältst folgende Belohnungen:" . $units_html,
                            0, 0,
                            "",
                            "success",
                            $loot["resources"]
                        ) . "</div>";

                    send_server_message($u_id, $u_name, $msg, MessageCategories::CATEGORY_EVENT);
                }
            } else {
                $is_boss_fail = true;
            }
        } else if ($ev["event_type"] === "DAMAGE") {
            // DAMAGE EVENT
            $user_damage = (int)$p["total_damage"];
            $total_coins = 0;
            $total_gold = 0;

            foreach (WORLD_EVENT_DAMAGE_TIERS as $threshold => $rewards) {
                if ($user_damage >= $threshold) {
                    $total_coins += $rewards["coins"];
                    $total_gold += $rewards["gold"];
                }
            }

            $loot_display = [];
            if ($total_coins > 0) {
                $loot_display[ResourceTypes::RESOURCE_TYPE_COINS] = $total_coins;
            }
            if ($total_gold > 0) {
                $loot_display[ResourceTypes::RESOURCE_TYPE_GOLD] = $total_gold;
            }

            $sub_text = ($user_damage >= WORLD_EVENT_REWARD_MIN_TRESHOLD)
                ? "Alle Belohnungen wurden deinen Lagern und deiner Schatzkammer bereits während deiner Angriffe gutgeschrieben."
                : "Du hast die Mindest-Schadensschwelle für Belohnungen leider nicht erreicht.";

            $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Event-Abschluss",
                    "Das Schadens-Event im <b>Auge des Sturms</b> ist beendet!<br>Für deinen Gesamtschaden von <b>" . fnum($user_damage, true) . "
                             </b> hast du folgende Gesamt-Prämien erzielt:",
                    0, 0,
                    $sub_text,
                    "neutral",
                    $loot_display
                ) . "</div>";

            send_server_message($u_id, $u_name, $msg, MessageCategories::CATEGORY_EVENT);
        }

        if ($is_boss_fail) {
            $fail_text = "Das Zeitlimit ist abgelaufen und das Monster <b>" . e($monster["name"]) . "</b> konnte in die Schatten entkommen!";
            $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                    "Event beendet",
                    $fail_text,
                    0, 0,
                    "Ohne den finalen Schlag konnten keine Schätze geborgen werden. Bereitet euch besser auf das nächste Mal vor!",
                    "error"
                ) . "</div>";

            send_server_message($u_id, $u_name, $msg, MessageCategories::CATEGORY_EVENT);
        }
    }

    $db_instance->execute_query("UPDATE world_events SET is_rewarded = 1, is_active = 0 WHERE id = ?", [$ev["id"]]);
}

//// Storage Push Message
$full_storage = $db_instance->query("
    SELECT k.userid, k.kingdomname 
    FROM kingdoms k 
    WHERE k.food >= k.maxfood OR k.wood >= k.maxwood OR k.stone >= k.maxstone OR k.gold >= k.maxgold
");

// Only when the storage freshly reached the limit
while ($fs = $full_storage->fetch_assoc()) {
    send_user_push(
        (int)$fs["userid"],
        "🌾 Lager voll: " . $fs["kingdomname"],
        "Die Lagerkapazität in {$fs["kingdomname"]} ist erschöpft! Überschüssige Ressourcen verfallen.",
        "storage",
        "storage.php"
    );
}
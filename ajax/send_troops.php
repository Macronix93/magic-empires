<?php

use JetBrains\PhpStorm\NoReturn;

require_once("../includes/ajax_core.php");

#[NoReturn]
function exit_send_troops_error(string $message): void
{
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        send_json(["success" => false, "error" => $message]);
    }

    echo "<div id='modal-title-data' data-title='Truppenentsendung' data-width='550px' style='display: none;'></div>";
    echo "<div style='text-align: center; max-width: 520px; margin: 0 auto;'>";
    echo show_error_box($message);
    echo "<p style='font-size: 13px; opacity: 0.8; margin-top: 15px;'><i>Wähle oben ein anderes Königreich, um Truppen zu diesem Ziel zu entsenden.</i></p>";
    echo "</div>";
    exit;
}

$kingdom = new Kingdom($user->get_current_kingdom());
$map = new Map($user);
$barracks_level = $kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_BARRACKS);
$cmd_stats = $kingdom->get_command_stats();
$troop_limit = $kingdom->get_troop_limit();
$is_self_station = false;

$raw_x = get_str("x");
$raw_y = get_str("y");
$target_x = ctype_digit($raw_x) ? (int)$raw_x : 1;
$target_y = ctype_digit($raw_y) ? (int)$raw_y : 1;

if ($target_x > MAX_X || $target_x < 1 || $target_y > MAX_Y || $target_y < 1) {
    exit_send_troops_error("Diese Koordinaten gibt es nicht!");
}

if ($target_x == $kingdom->get_kingdom_map_x() && $target_y == $kingdom->get_kingdom_map_y()) {
    exit_send_troops_error("Das ist dein aktuelles Königreich!");
}

$kingdom_id = $map->get_field_kingdom_id($target_x, $target_y);

$res_gift = $db_instance->execute_query(
    "SELECT id, day FROM advent_calendar_gifts WHERE user_id = ? AND mapx = ? AND mapy = ? AND is_collected = 0 LIMIT 1",
    [$user->get_user_id(), $target_x, $target_y]
)->fetch_assoc();

$is_gift = ($res_gift !== null);
$gift_day = $res_gift ? (int)$res_gift["day"] : 1;

if ($is_gift) {
    $kingdom_id = MapFieldTypes::MAP_FIELD_GIFT;
    $send_title = "Advents-Geschenk bergen";
    $is_founding = false;
} else {
    $send_title = "Erobern";
    $is_founding = ($kingdom_id == MapFieldTypes::MAP_FIELD_EMPTY);
}

$warning_box = "";

if ($kingdom_id == MapFieldTypes::MAP_FIELD_WORLD_EVENT) {
    $world_event_manager = new WorldEvent();
    $active_event = $world_event_manager->get_active_event();

    if (!$active_event) {
        exit_send_troops_error("Dieses Gebiet ist durch einen magischen Bann versiegelt!");
    }

    $arrival_delay = $world_event_manager->get_current_duration();
    $time_left = $active_event["end_time"] - time();
    if ($time_left < $arrival_delay) {
        exit_send_troops_error("Befehl verweigert: Der Anmarsch dauert " . convert_sec_to_str($arrival_delay) . ", aber das Event endet bereits in " . convert_sec_to_str($time_left) . "!");
    }

    if ($active_event["event_type"] === "DAMAGE") {
        $res_check = $db_instance->execute_query(
            "SELECT attempts_used FROM world_event_participants WHERE event_id = ? AND userid = ?",
            [$active_event["id"], $user->get_user_id()]
        );
        $attempts = (int)($res_check->fetch_assoc()["attempts_used"] ?? 0);

        $in_flight = (int)$db_instance->execute_query(
            "SELECT COUNT(*) FROM events WHERE userid = ? AND targetid = ? AND actionid = ?",
            [$user->get_user_id(), MapFieldTypes::MAP_FIELD_WORLD_EVENT, ActionTypes::ACTION_SEND_TROOPS]
        )->fetch_column();

        if (($attempts + $in_flight) >= WORLD_EVENT_MAX_ATTEMPTS) {
            exit_send_troops_error("Du hast bereits alle " . WORLD_EVENT_MAX_ATTEMPTS . " Versuche für dieses Event verbraucht (inkl. laufender Märsche)!");
        }
    }

    if ($active_event["event_type"] === "BOSS_HP" && (int)$active_event["current_hp"] <= 0) {
        exit_send_troops_error("Der Boss wurde bereits besiegt!");
    }
}

// Get users kingdom and score + noob check
$enemy_score = 0;
$enemy_user_id = -1;
$enemy_guild_id = -1;

$result_enemy = $db_instance->execute_query("
        SELECT k.userid, u.score, u.guildid 
        FROM kingdoms k
        JOIN users u ON k.userid = u.id 
        WHERE k.mapx = ? AND k.mapy = ?", [$target_x, $target_y]);
$row_enemy = $result_enemy->fetch_assoc();

if ($row_enemy) {
    $enemy_score = (int)$row_enemy["score"];
    $enemy_user_id = (int)$row_enemy["userid"];
    $enemy_guild_id = (int)($row_enemy["guildid"] ?? -1);
}

if ($kingdom_id > 0 && $enemy_user_id === $user->get_user_id() && ($target_x != $kingdom->get_kingdom_map_x()
        || $target_y != $kingdom->get_kingdom_map_y())) {
    $target_k_check = new Kingdom($kingdom_id);

    if ($target_k_check->get_kingdom_building_level(BuildingTypes::BUILDING_BARRACKS) <= 0) {
        exit_send_troops_error("Das Ziel-Königreich besitzt noch keine Kaserne!");
    }
}

$my_guild_id = $user->get_user_guild_id();
$is_ally = ($my_guild_id > 0 && $my_guild_id === $enemy_guild_id && $enemy_user_id !== $user->get_user_id());

$only_scouts_allowed = false;
$is_noob_protected = false;
$is_new_kingdom_protected = false;

$current_support_load = 0;
$total_support_limit = 0;

if ($is_ally) {
    $total_support_limit = new Kingdom($kingdom_id)->get_support_limit();

    $res_load = $db_instance->execute_query("
        SELECT (
            (SELECT IFNULL(SUM(soldiercount), 0) FROM stationed_troops WHERE target_kingdom_id = ?) +
            (SELECT IFNULL(SUM(st.soldiercount), 0) FROM sent_troops st JOIN events e ON st.eventid = e.eventid WHERE e.targetid = ? AND e.actionid = ?)
        ) AS total", [$kingdom_id, $kingdom_id, ActionTypes::ACTION_STATION_TROOPS]);

    $current_support_load = (int)$res_load->fetch_column();
} else {
    if ($enemy_user_id > 0 && $enemy_user_id != $user->get_user_id()) {
        $res_vac = $db_instance->execute_query("SELECT is_vacation, vacation_until FROM users WHERE id = ?", [$enemy_user_id])->fetch_assoc();

        if (!empty($res_vac["is_vacation"]) && (int)$res_vac["vacation_until"] > time()) {
            exit_send_troops_error("Dieser Spieler befindet sich im Urlaubsmodus und kann weder angegriffen noch ausspioniert werden!");
        }

        $target_k_obj = new Kingdom($kingdom_id);
        $is_new_kingdom_protected = $target_k_obj->has_new_kingdom_protection();
        $is_noob_protected = Conquest::has_noob_protection($user->get_user_score(), $enemy_score);
        $only_scouts_allowed = (!$is_ally && ($is_noob_protected || $is_new_kingdom_protected) && $enemy_user_id > 0 && $enemy_user_id != $user->get_user_id());
    }
}

// Check if the user already sent troops to that kingdom
$already_sent = 0;

if ($kingdom_id != MapFieldTypes::MAP_FIELD_WORLD_EVENT) {
    $result = $db_instance->execute_query("SELECT COUNT(*) AS alreadysent FROM events 
                               WHERE actionid = ? AND userid = ? AND targetx = ? AND targety = ? AND kingdomid = ?",
        [ActionTypes::ACTION_SEND_TROOPS, $user->get_user_id(), $target_x, $target_y, $user->get_current_kingdom()]);
    $already_sent = $result->fetch_assoc()["alreadysent"];
}

if ($already_sent > 0) {
    exit_send_troops_error("Du hast bereits Truppen zu diesen Koordinaten geschickt!");
}

// Get soldier data
$soldiers = [];
$result = $db_instance->execute_query("SELECT id, soldiername, category, attack, defense, icon FROM soldier_list");

foreach ($result as $row) {
    $soldier = new Soldier();
    $soldier->fill_from_row($row);

    $soldiers[$soldier->get_soldier_id()] = $soldier;
    $kingdom_soldiers[$soldier->get_soldier_id()] = 0;
}

// Get soldiers from kingdom
$result = $db_instance->execute_query("SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?", [$user->get_current_kingdom()]);

foreach ($result as $row) {
    $soldier_id = $row["soldierid"] ?? -1;
    $sol_count = $row["soldiercount"] ?? 0;
    $kingdom_soldiers[$soldier_id] = $sol_count;
}

// Get target kingdom
$result = $db_instance->execute_query("SELECT * FROM kingdoms WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
$row = $result->fetch_assoc();

$scout_count = (int)($kingdom_soldiers[Soldiers::SOLDIER_SCOUT] ?? 0);
$has_raiders = ((int)($kingdom_soldiers[Soldiers::SOLDIER_RAIDER] ?? 0) > 0);

$mining_units_count = 0;
for ($i = Soldiers::SOLDIER_MILITIA; $i <= Soldiers::SOLDIER_ELVENARCHER; $i++) {
    $mining_units_count += (int)($kingdom_soldiers[$i] ?? 0);
}

$has_camp_combat_troops = false;
foreach ($kingdom_soldiers as $s_id => $cnt) {
    if ((int)$s_id !== Soldiers::SOLDIER_SCOUT && $cnt > 0) {
        $has_camp_combat_troops = true;
        break;
    }
}

$is_spying = false;

if ($scout_count > 0) {
    if ($only_scouts_allowed) {
        $is_spying = true;
    } else if ($kingdom_id == MapFieldTypes::MAP_FIELD_RESOURCE_TILE) {
        $is_spying = !$has_raiders;
    } else if ($kingdom_id == MapFieldTypes::MAP_FIELD_MONSTER_CAMP || $kingdom_id == MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM) {
        $is_spying = !$has_camp_combat_troops;
    } else if ($kingdom_id == MapFieldTypes::MAP_FIELD_MINE) {
        $is_spying = ($mining_units_count === 0);
    } else if ($enemy_user_id > 0 && $enemy_user_id != $user->get_user_id()) {
        $is_spying = !$has_camp_combat_troops;
    }
}

// Get field info
$query = "
            SELECT 
                m.fieldtype, 
                f.fieldname,
                COALESCE(r.expires_at, mc.expires_at, ak.expires_at, mn.expires_at, 0) AS expires_at
            FROM map m
            JOIN field_types f ON m.fieldtype = f.fieldid
            LEFT JOIN resource_tiles_data r ON m.mapx = r.mapx AND m.mapy = r.mapy
            LEFT JOIN monster_camps mc ON m.mapx = mc.mapx AND m.mapy = mc.mapy
            LEFT JOIN abandoned_kingdoms ak ON m.mapx = ak.mapx AND m.mapy = ak.mapy
            LEFT JOIN mines mn ON m.mapx = mn.mapx AND m.mapy = mn.mapy
            WHERE m.mapx = ? AND m.mapy = ?
        ";
$result2 = $db_instance->execute_query($query, [$target_x, $target_y]);
$field_data = $result2->fetch_assoc();
$field_name = $field_data["fieldname"];
$expires_at = (int)$field_data["expires_at"];

$arrival_time = $map->get_arrival_time($kingdom->get_kingdom_map_x(), $kingdom->get_kingdom_map_y(),
    $target_x, $target_y, $user->get_current_kingdom(), $kingdom_id, $is_spying, false, $is_ally);

if ($expires_at > 0) {
    $remaining_time = $expires_at - time();

    if ($arrival_time > $remaining_time) {
        $warning_box = show_warning_box("<b>Warnung:</b> Deine Truppen werden nicht rechtzeitig ankommen<br>(Restzeit: " . convert_sec_to_str($remaining_time) . ").");
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($barracks_level <= 0) {
        exit_send_troops_error("Befehl verweigert: Du besitzt keine Kaserne!");
    }

    $raw_soldiers = is_array($_POST["soldiers"] ?? null) ? $_POST["soldiers"] : [];
    $clean_soldiers = [];

    foreach ($raw_soldiers as $raw_id => $raw_count) {
        if (!is_scalar($raw_count)) {
            exit_send_troops_error("Ungültige Einheitenanzahl übermittelt!");
        }

        $raw_id_str = (string)$raw_id;
        if (!ctype_digit($raw_id_str) || (string)(int)$raw_id_str !== $raw_id_str) {
            exit_send_troops_error("Ungültige Einheiten-ID!");
        }

        $sid = (int)$raw_id_str;
        if (!isset($soldiers[$sid]) || isset($clean_soldiers[$sid])) {
            exit_send_troops_error("Ungültige oder doppelte Einheit!");
        }

        $raw_cnt_str = trim((string)$raw_count);
        if ($raw_cnt_str === "" || $raw_cnt_str === "0") {
            continue;
        }

        if (str_starts_with($raw_cnt_str, "-")) {
            exit_send_troops_error("Ungültige negative Truppenanzahl!");
        }

        if (!ctype_digit($raw_cnt_str) || strlen($raw_cnt_str) > 9) {
            exit_send_troops_error("Ungültige Einheitenanzahl übermittelt!");
        }

        $cnt = (int)$raw_cnt_str;
        if ($cnt > 0) {
            $clean_soldiers[$sid] = $cnt;
        }
    }

    $has_soldiers = false;
    $has_non_scout_units = false;
    $has_scouts = false;
    $has_other_special = false;
    $has_normal_units = false;
    $total_units_in_request = 0;

    foreach ($clean_soldiers as $soldier_id => $soldier_count) {
        $has_soldiers = true;
        $total_units_in_request += $soldier_count;
        $s_obj = $soldiers[$soldier_id];
        $unit_cat = $s_obj->get_soldier_category();

        if ($soldier_id === Soldiers::SOLDIER_SCOUT) {
            $has_scouts = true;
        } else {
            $has_non_scout_units = true;

            if ($unit_cat == SoldierTypes::SOLDIER_TYPE_SPECIAL) {
                $has_other_special = true;
            } else {
                $has_normal_units = true;
            }
        }

        if ($is_founding && $soldier_id !== Soldiers::SOLDIER_SETTLER_WAGON) {
            send_json(["success" => false, "error" => "Für eine Neugründung können ausschließlich Gründungskarren entsendet werden!"]);
        }
    }

    if (!$has_soldiers) {
        send_json(["success" => false, "error" => "Du musst mindestens einen Soldaten auswählen!"]);
    }

    if ($total_units_in_request > $troop_limit) {
        send_json(["success" => false, "error" => "Deine entsendete Armee darf das Kasernenlimit von " . fnum($troop_limit, true) . " Einheiten nicht überschreiten!"]);
    }

    foreach (SPECIAL_UNIT_LIMITS as $sp_id => $sp_info) {
        $sent_sp = (int)($clean_soldiers[$sp_id] ?? 0);

        if ($sent_sp > 0) {
            $sp_limit = $kingdom->get_special_unit_limit($sp_id);

            if ($sp_limit !== null && $sent_sp > $sp_limit) {
                send_json([
                    "success" => false,
                    "error" => "Du darfst maximal " . fnum($sp_limit) . " {$sp_info["name"]} aus diesem Königreich entsenden (aktuell gewählt: " . fnum($sent_sp) . ")!"
                ]);
            }
        }
    }

    if ($is_gift && $total_units_in_request > ADVENT_CALENDAR_MAX_UNITS) {
        send_json(["success" => false, "error" => "Zu einem Geschenk kann maximal eine Einheit entsandt werden!"]);
    }

    if ($kingdom_id == MapFieldTypes::MAP_FIELD_MINE) {
        if ($has_other_special) {
            send_json(["success" => false, "error" => "Spezialeinheiten (außer Späher) können nicht in Minen eingesetzt werden!"]);
        } else if ($has_normal_units && $has_scouts) {
            send_json(["success" => false, "error" => "Späher können nicht mit normalen Kampfeinheiten in Minen gemischt werden!"]);
        }
    } else if ($kingdom_id == MapFieldTypes::MAP_FIELD_RESOURCE_TILE) {
        foreach ($clean_soldiers as $soldier_id => $cnt) {
            if ($soldier_id !== Soldiers::SOLDIER_RAIDER && $soldier_id !== Soldiers::SOLDIER_SCOUT) {
                send_json(["success" => false, "error" => "Für Vorratslager können nur Räuber (zum Plündern) oder Späher (zur Spionage) entsendet werden!"]);
            }
        }
    }

    if ($has_non_scout_units) {
        if ($is_new_kingdom_protected) {
            $target_k_obj = new Kingdom($kingdom_id);
            send_json(["success" => false, "error" => "Dieses Königreich steht noch für " . convert_sec_to_str($target_k_obj->get_protection_time_left()) . " unter Gründungsschutz vor Angriffen!"]);
        } else if ($is_noob_protected) {
            send_json(["success" => false, "error" => "Aufgrund des Noob-Schutzes dürfen nur reine Spionage-Trupps (Späher) entsendet werden!"]);
        }
    }

    $my_uid = $user->get_user_id();
    $my_kid = $user->get_current_kingdom();
    $my_guild_id = $user->get_user_guild_id();
    $is_support = ($my_guild_id > 0 && $my_guild_id === $enemy_guild_id && $enemy_user_id !== $my_uid);
    $is_self_station = ($kingdom_id > 0 && $enemy_user_id === $my_uid);
    $action_id = $is_support ? ActionTypes::ACTION_STATION_TROOPS : ActionTypes::ACTION_SEND_TROOPS;
    $settler_wagon_count = (int)($clean_soldiers[Soldiers::SOLDIER_SETTLER_WAGON] ?? 0);

    $is_mine_empty = false;
    $is_friendly = false;
    $target_k_obj = null;
    $arrival_time = 0;
    $lost_protection = false;

    $db_instance->begin_transaction();
    try {
        if ($kingdom_id == MapFieldTypes::MAP_FIELD_WORLD_EVENT
            || ($kingdom_id == MapFieldTypes::MAP_FIELD_EMPTY && $settler_wagon_count > 0)) {
            $db_instance->execute_query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$my_uid]);
        }

        $kids_to_lock = [$my_kid];

        if ($kingdom_id > 0 && $kingdom_id !== $my_kid) {
            $kids_to_lock[] = $kingdom_id;
        }

        sort($kids_to_lock);
        $ph_k = implode(',', array_fill(0, count($kids_to_lock), '?'));
        $db_instance->execute_query(
            "SELECT id FROM kingdoms WHERE id IN ($ph_k) ORDER BY id FOR UPDATE",
            $kids_to_lock
        );

        $res_locked_sol = $db_instance->execute_query(
            "SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ? FOR UPDATE",
            [$my_kid]
        );
        $kingdom_soldiers = array_fill_keys(array_keys($soldiers), 0);
        while ($row_sol = $res_locked_sol->fetch_assoc()) {
            $kingdom_soldiers[(int)$row_sol["soldierid"]] = (int)$row_sol["soldiercount"];
        }

        foreach ($clean_soldiers as $soldier_id => $soldier_count) {
            if ($soldier_count > ($kingdom_soldiers[$soldier_id] ?? 0)) {
                throw new RuntimeException("Du hast zu wenig Soldaten vom Typ " . $soldiers[$soldier_id]->get_soldier_name() . "!");
            }
        }

        if ($kingdom_id != MapFieldTypes::MAP_FIELD_WORLD_EVENT) {
            $already_sent_locked = (int)$db_instance->execute_query(
                "SELECT COUNT(*) FROM events WHERE actionid = ? AND userid = ? AND targetx = ? AND targety = ? AND kingdomid = ?",
                [ActionTypes::ACTION_SEND_TROOPS, $my_uid, $target_x, $target_y, $my_kid]
            )->fetch_column();

            if ($already_sent_locked > 0) {
                throw new RuntimeException("Du hast bereits Truppen zu diesen Koordinaten geschickt!");
            }
        }

        $cmd_stats_locked = $kingdom->get_command_stats();
        $max_commands = $cmd_stats_locked["max"];
        $total_occupied_commands = $cmd_stats_locked["occupied"];
        if ($total_occupied_commands >= $max_commands) {
            throw new RuntimeException("Deine Offiziere sind überlastet! (Limit: $max_commands Befehle).<br>Baue das Dorfzentrum weiter aus, falls möglich.");
        }

        if ($is_gift) {
            $gift_locked = $db_instance->execute_query(
                "SELECT id FROM advent_calendar_gifts WHERE user_id = ? AND mapx = ? AND mapy = ? AND is_collected = 0 FOR UPDATE",
                [$my_uid, $target_x, $target_y]
            )->fetch_assoc();
            if (!$gift_locked) {
                throw new RuntimeException("Dieses Geschenk wurde bereits eingesammelt!");
            }
        }

        if ($kingdom_id == MapFieldTypes::MAP_FIELD_EMPTY && $settler_wagon_count > 0) {
            $res_ongoing = $db_instance->execute_query(
                "SELECT COUNT(DISTINCT e.eventid) as total
                 FROM events e
                 JOIN sent_troops st ON e.eventid = st.eventid
                 WHERE e.userid = ? AND e.targetid = ? AND st.soldierid = ? AND e.actionid = ?",
                [$my_uid, MapFieldTypes::MAP_FIELD_EMPTY, Soldiers::SOLDIER_SETTLER_WAGON, ActionTypes::ACTION_SEND_TROOPS]
            );
            $ongoing_foundations = (int)($res_ongoing->fetch_assoc()["total"] ?? 0);

            $res_k_count = $db_instance->execute_query(
                "SELECT COUNT(*) AS total FROM kingdoms WHERE userid = ? AND creation_method != ?",
                [$my_uid, KingdomCreationTypes::KINGDOM_CREATION_CONQUEST]
            );
            $current_settled_count = (int)($res_k_count->fetch_assoc()["total"] ?? 0);

            $res_total_imp = $db_instance->execute_query(
                "SELECT IFNULL(MAX(t.techlevel), 0) AS total
                 FROM techs t JOIN kingdoms k ON t.kingdomid = k.id
                 WHERE k.userid = ? AND t.techid = ?",
                [$my_uid, TechTypes::TECH_TYPE_IMPERIAL]
            );
            $total_imperial_bonus = (int)($res_total_imp->fetch_assoc()["total"] ?? 0);
            $max_allowed_slots = min(GLOBAL_SETTLEMENT_MAX, BASE_SETTLEMENT_LIMIT + $total_imperial_bonus);

            if (($current_settled_count + $ongoing_foundations) >= $max_allowed_slots) {
                if ($max_allowed_slots >= GLOBAL_SETTLEMENT_MAX) {
                    throw new RuntimeException("Das absolute Imperiums-Limit von " . GLOBAL_SETTLEMENT_MAX . " Dörfern ist erreicht!");
                } else {
                    throw new RuntimeException(
                        "Du hast bereits $current_settled_count " . ($current_settled_count > 1 ? "Königreiche" : "Königreich") . " gegründet " .
                        ($ongoing_foundations > 0 ? "und $ongoing_foundations Gründungen laufen" : "") .
                        " (Limit: $max_allowed_slots). Erforsche 'Imperium' im Haupt-Königreich, falls möglich."
                    );
                }
            }
        } else if ($kingdom_id == MapFieldTypes::MAP_FIELD_MINE) {
            $res_mine = $db_instance->execute_query(
                "SELECT id, level, max_troops, claimed_guild_id, claimed_user_id, work_total FROM mines WHERE mapx = ? AND mapy = ? FOR UPDATE",
                [$target_x, $target_y]
            )->fetch_assoc();

            if (!$res_mine) {
                throw new RuntimeException("Diese Mine ist nicht mehr verfügbar!");
            }

            if ($has_non_scout_units) {
                $total_squad_atk = 0;
                foreach ($clean_soldiers as $s_id => $cnt) {
                    $total_squad_atk += $cnt * $soldiers[$s_id]->get_soldier_attack();
                }
                if ($total_squad_atk <= 0) {
                    throw new RuntimeException("Dein Trupp besitzt keine Angriffskraft und kann in der Mine kein Gestein abbauen!");
                }
            }

            $claimed_gid = (int)$res_mine["claimed_guild_id"];
            $claimed_uid = (int)$res_mine["claimed_user_id"];
            $res_curr = $db_instance->execute_query(
                "SELECT (
                    (SELECT IFNULL(SUM(soldiercount), 0) FROM mine_stationed_troops WHERE mine_id = ?) +
                    (SELECT IFNULL(SUM(st.soldiercount), 0) FROM sent_troops st JOIN events e ON st.eventid = e.eventid
                     WHERE e.targetid = ? AND e.targetx = ? AND e.targety = ? AND e.actionid = ?)
                ) AS total",
                [$res_mine["id"], MapFieldTypes::MAP_FIELD_MINE, $target_x, $target_y, ActionTypes::ACTION_SEND_TROOPS]
            );
            $current_mine_troops = (int)$res_curr->fetch_column();
            $max_capacity = (int)$res_mine["max_troops"];
            $free_space = max(0, $max_capacity - $current_mine_troops);
            $is_mine_empty = ($current_mine_troops === 0);
            $is_friendly = ($my_guild_id > 0 && $claimed_gid === $my_guild_id) || ($claimed_uid === $my_uid);

            if (!$has_non_scout_units) {
                if ($is_friendly && !$is_mine_empty) {
                    throw new RuntimeException("Du kannst eine von dir oder deinen Verbündeten besetzte Mine nicht ausspionieren!");
                }
            } else {
                if ($total_units_in_request > $max_capacity) {
                    throw new RuntimeException("In einer Mine dürfen maximal $max_capacity Einheiten eingesetzt werden!");
                }

                if (!$is_mine_empty && $is_friendly && $total_units_in_request > $free_space) {
                    throw new RuntimeException("In der Mine sind aktuell nur noch $free_space Plätze frei (Kapazität: $max_capacity)!");
                }
            }
        } else if ($is_self_station) {
            $target_k_obj = new Kingdom($kingdom_id);
            $target_limit = $target_k_obj->get_troop_limit();
            $target_occupied = $target_k_obj->get_current_troop_count(true, true);
            $free_space = max(0, $target_limit - $target_occupied);

            $non_exempt_units = 0;
            foreach ($clean_soldiers as $s_id => $cnt) {
                if (!in_array($s_id, TROOP_LIMIT_EXEMPT, true)) {
                    $non_exempt_units += $cnt;
                }
            }

            if ($non_exempt_units > $free_space) {
                throw new RuntimeException("Im Zielkönigreich ist nicht genug Platz für die Stationierung! Frei: " . fnum($free_space) . " Plätze.");
            }

            foreach (SPECIAL_UNIT_LIMITS as $sp_id => $sp_info) {
                $sent_sp = (int)($clean_soldiers[$sp_id] ?? 0);

                if ($sent_sp > 0) {
                    $target_sp_limit = $target_k_obj->get_special_unit_limit($sp_id) ?? 0;
                    $cur_in_target = $target_k_obj->get_unit_total_count($sp_id, true, true);

                    if ($cur_in_target + $sent_sp > $target_sp_limit) {
                        $rem_sp = max(0, $target_sp_limit - $cur_in_target);
                        throw new RuntimeException("Im Zielkönigreich sind maximal $target_sp_limit {$sp_info["name"]} erlaubt! (Vorhanden/im Bau/unterwegs: $cur_in_target, noch möglich: $rem_sp)");
                    }
                }
            }
        } else if ($is_support) {
            $target_k_obj = new Kingdom($kingdom_id);
            $target_barracks_lvl = $target_k_obj->get_kingdom_building_level(BuildingTypes::BUILDING_BARRACKS);
            $g_cap_lvl = Guild::get_user_guild_tech_level($enemy_user_id, GuildTechTypes::GUILD_TECH_SUPPORT_CAPACITY);
            $support_limit = SUPPORT_LIMIT_BASE + ($target_barracks_lvl * SUPPORT_LIMIT_PER_BARRACKS) + ($g_cap_lvl * GUILD_BONUS_SUPPORT_CAP_PER_LVL);

            $res_current = $db_instance->execute_query(
                "SELECT
                    (SELECT IFNULL(SUM(soldiercount), 0) FROM stationed_troops WHERE target_kingdom_id = ?) +
                    (SELECT IFNULL(SUM(st.soldiercount), 0) FROM sent_troops st JOIN events e ON st.eventid = e.eventid WHERE e.targetid = ? AND e.actionid = ?) AS total",
                [$kingdom_id, $kingdom_id, ActionTypes::ACTION_STATION_TROOPS]
            );
            $current_support_count = (int)$res_current->fetch_column();
            if (($current_support_count + $total_units_in_request) > $support_limit) {
                throw new RuntimeException("Das Ziel-Königreich kann keine weitere Unterstützung aufnehmen (Limit: $support_limit).");
            }
        }

        $now = time();
        $is_pure_scouting = ($has_soldiers && !$has_non_scout_units);
        $arrival_time = $map->get_arrival_time(
            $kingdom->get_kingdom_map_x(),
            $kingdom->get_kingdom_map_y(),
            $target_x,
            $target_y,
            $my_kid,
            $kingdom_id,
            $is_pure_scouting,
            false,
            $is_ally
        );

        $result = $db_instance->execute_query(
            "INSERT INTO events (actionid, userid, kingdomid, buildingtime, targetid, targetx, targety, arrivaltime) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING eventid",
            [$action_id, $my_uid, $my_kid, $now, $kingdom_id, $target_x, $target_y, $now + $arrival_time]
        );
        $event_id = $result->fetch_assoc()["eventid"];

        $log_troops = [];
        foreach ($clean_soldiers as $soldier_id => $soldier_count) {
            $db_instance->execute_query(
                "INSERT INTO sent_troops (eventid, soldierid, soldiercount, initial_count, source_kingdom_id) VALUES (?, ?, ?, ?, ?)",
                [$event_id, $soldier_id, $soldier_count, $soldier_count, $my_kid]
            );
            $db_instance->execute_query(
                "UPDATE soldiers SET soldiercount = soldiercount - ? WHERE kingdomid = ? AND soldierid = ? AND soldiercount >= ?",
                [$soldier_count, $my_kid, $soldier_id, $soldier_count]
            );

            if ($db_instance->affected_rows !== 1) {
                throw new RuntimeException("Nicht genügend Soldaten vom Typ " . $soldiers[$soldier_id]->get_soldier_name() . " vorhanden!");
            }

            $kingdom_soldiers[$soldier_id] -= $soldier_count;
            $log_troops[$soldier_id] = $soldier_count;
        }

        $logger->log_game("COMBAT", "ATTACK_SEND", [
            "target_x" => $target_x,
            "target_y" => $target_y,
            "target_kingdom_id" => $kingdom_id,
            "arrival_in" => $arrival_time,
            "troops" => $log_troops
        ], $my_kid);

        $db_instance->commit();

        if (!$is_support && !$is_self_station && $kingdom_id != MapFieldTypes::MAP_FIELD_EMPTY && $has_non_scout_units) {
            Stats::update_player_stat($my_uid, "troops_sent_combat");
        }

        $is_pvp_attack = ($enemy_user_id > 0 && $enemy_user_id != $my_uid && !$is_ally && $has_non_scout_units);
        $is_mine_attack = ($kingdom_id == MapFieldTypes::MAP_FIELD_MINE && !$is_mine_empty && !$is_friendly && $has_non_scout_units);
        if (($is_pvp_attack || $is_mine_attack) && $kingdom->has_new_kingdom_protection()) {
            $kingdom->remove_protection();
            $lost_protection = true;
        }
    } catch (RuntimeException $e) {
        $db_instance->rollback();

        send_json(["success" => false, "error" => $e->getMessage()]);
    } catch (Throwable $e) {
        $db_instance->rollback();

        $logger->error("send_troops DB error: " . $e->getMessage());

        send_json(["success" => false, "error" => "Interner Fehler beim Absenden der Truppen."]);
    }

    $cache_file = sys_get_temp_dir() . "/me_map_cache_" . $my_uid . ".json";
    if (file_exists($cache_file)) {
        @unlink($cache_file);
    }

    $is_world_event = ($kingdom_id == MapFieldTypes::MAP_FIELD_WORLD_EVENT);
    $from_page = post_str("from_page", $_SERVER["HTTP_REFERER"] ?? '');
    if (str_contains($from_page, "events.php") || str_contains($from_page, "barracks.php") || $is_world_event) {
        $_SESSION["game_success"] = "Truppen erfolgreich gesendet!";
    }

    $success_msg = $lost_protection
        ? "Truppen erfolgreich gesendet!<br>Hinweis: Der Angriffsschutz für dein Königreich ist hierdurch vorzeitig erloschen."
        : "Truppen erfolgreich gesendet!";

    if ($is_support && $target_k_obj !== null) {
        send_user_push(
            $enemy_user_id,
            "🛡️ Unterstützung unterwegs: " . $target_k_obj->get_kingdom_name(),
            $user->get_user_name() . " schickt Verstärkung zu deinem Königreich " . $target_k_obj->get_kingdom_name() . " (Ankunft in " . convert_sec_to_str($arrival_time) . ").",
            "troops",
            "overview.php"
        );
    }

    send_json([
        "success" => true,
        "message" => $success_msg,
        "troops" => $kingdom_soldiers
    ]);
}

if (!empty($warning_box)) {
    $view .= $warning_box;
}

if ($cmd_stats["is_full"]) {
    $view .= show_warning_box("<b>Befehlslimit erreicht:</b> Truppen aus <b>" . e($kingdom->get_kingdom_name()) . "</b> können nicht entsendet werden.");
}

// Noob protection check
if ($is_new_kingdom_protected) {
    $target_k_obj = new Kingdom($kingdom_id);
    $rem_str = convert_sec_to_str($target_k_obj->get_protection_time_left());

    $view .= show_warning_box("
        <b>Gründungsschutz aktiv:</b><br>
        Dieses Königreich wurde vor kurzem gegründet und steht noch für <b>$rem_str</b> unter Schutz vor feindlichen Angriffen.<br>
        Reine Spionage (nur Späher) ist möglich.
    ");
} else if ($only_scouts_allowed) {
    $my_score = $user->get_user_score();
    $min_allowed = floor($my_score * NOOB_PROTECTION_MULT);
    $max_allowed = floor($my_score / NOOB_PROTECTION_MULT);

    $view .= show_warning_box("
        <b>Punktestand zu unterschiedlich:</b><br>
        Ein Angriff ist nicht möglich.<br>
        Du kannst nur Spieler angreifen, die einen Score von <b>" . fnum($min_allowed) . "</b> bis <b>" . fnum($max_allowed) . "</b> haben <b>(" . (NOOB_PROTECTION_MULT * 100) . "%)</b>.<br>
        Reine Spionage (nur Späher) ist allerdings möglich.
    ");
}

if ($kingdom_id == MapFieldTypes::MAP_FIELD_MINE) {
    $res_mine = $db_instance->execute_query("SELECT * FROM mines WHERE mapx = ? AND mapy = ?", [$target_x, $target_y])->fetch_assoc();

    if (!$res_mine) {
        exit_send_troops_error("Diese Mine existiert nicht mehr!");
    }

    $mine_lvl = (int)($res_mine["level"] ?? 1);
    $max_capacity = (int)($res_mine["max_troops"] ?? 50);

    $res_curr = $db_instance->execute_query("
            SELECT IFNULL(SUM(soldiercount), 0)
            FROM mine_stationed_troops
            WHERE mine_id = ?", [(int)($res_mine["id"] ?? 0)]);
    $current_mine_troops = (int)$res_curr->fetch_column();

    $my_gid = $user->get_user_guild_id();
    $claimed_gid = (int)($res_mine["claimed_guild_id"] ?? 0);
    $claimed_uid = (int)($res_mine["claimed_user_id"] ?? 0);

    $incoming_guild_troops = 0;
    $other_guild_members_warning = [];
    $now = time();

    if ($my_gid > 0) {
        $res_inc = $db_instance->execute_query("
                SELECT u.id, u.username, SUM(st.soldiercount) as count, MIN(e.arrivaltime) as earliest_arrival
                FROM events e
                JOIN sent_troops st ON e.eventid = st.eventid
                JOIN users u ON e.userid = u.id
                WHERE e.targetid = ? AND e.targetx = ? AND e.targety = ? 
                  AND e.actionid = ? AND (u.guildid = ? OR u.id = ?)
                GROUP BY u.id, u.username
                ORDER BY earliest_arrival
            ", [MapFieldTypes::MAP_FIELD_MINE, $target_x, $target_y, ActionTypes::ACTION_SEND_TROOPS, $my_gid, $user->get_user_id()]);

        while ($inc = $res_inc->fetch_assoc()) {
            $incoming_guild_troops += (int)$inc["count"];

            $time_diff = (int)$inc["earliest_arrival"] - $now;
            if ($time_diff <= 0) {
                $time_text = "treffen gerade ein";
            } else {
                $time_text = "Ankunft in " . convert_sec_to_str($time_diff);
            }
            $info_text = "<b>" . e($inc["username"]) . "</b> (" . fnum($inc["count"]) . " Truppen, $time_text)";

            if ((int)$inc["id"] !== $user->get_user_id()) {
                $other_guild_members_warning[] = $info_text;
            }
        }
    } else {
        $res_inc = $db_instance->execute_query("
                SELECT SUM(st.soldiercount) as count, MIN(e.arrivaltime) as earliest_arrival
                FROM events e
                JOIN sent_troops st ON e.eventid = st.eventid
                WHERE e.targetid = ? AND e.targetx = ? AND e.targety = ? 
                  AND e.actionid = ? AND e.userid = ?
            ", [MapFieldTypes::MAP_FIELD_MINE, $target_x, $target_y, ActionTypes::ACTION_SEND_TROOPS, $user->get_user_id()]);

        $row_inc = $res_inc->fetch_assoc();
        $incoming_guild_troops = (int)($row_inc["count"] ?? 0);

        if ($incoming_guild_troops > 0) {
            $time_left = max(0, (int)$row_inc["earliest_arrival"] - $now);
        }
    }

    if (!empty($other_guild_members_warning)) {
        $view .= show_warning_box("<b>Achtung:</b> Gildenmitglieder sind bereits auf dem Weg zu dieser Mine:<br>• " . implode("<br>• ", $other_guild_members_warning));
    }

    $total_occupied_or_incoming = $current_mine_troops + $incoming_guild_troops;
    $free_space = max(0, $max_capacity - $current_mine_troops);

    $is_mine_empty = ($current_mine_troops === 0);
    $is_friendly = ($my_gid > 0 && $claimed_gid === $my_gid) || ($claimed_uid === $user->get_user_id());
    $mine_limit = ($is_friendly && !$is_mine_empty) ? $free_space : $max_capacity;

    $current_mine_atk = 0;
    if ($is_friendly && !$is_mine_empty) {
        $res_atk = $db_instance->execute_query("SELECT IFNULL(SUM(soldiercount * unit_atk), 0) FROM mine_stationed_troops WHERE mine_id = ?", [(int)$res_mine["id"]]);
        $current_mine_atk = (int)$res_atk->fetch_column();
    }

    $work_rem = max(0, (int)$res_mine["work_total"] - (int)$res_mine["work_done"]);

    $max_rate = (int)$res_mine["work_total"] / MINE_MIN_DURATION_SECONDS;
    $initial_rate = min($current_mine_atk * MINE_WORK_RATE_FACTOR, $max_rate);

    if ($initial_rate > 0) {
        $initial_rate_text = fdec($initial_rate) . " Pkt./s";
        $initial_dur_sec = max(MINE_MIN_DURATION_SECONDS, (int)ceil($work_rem / $initial_rate));
        $initial_dur_text = "ca. " . convert_sec_to_str($initial_dur_sec);
    } else {
        $initial_rate_text = "<i>Keine Truppen gewählt</i>";
        $initial_dur_text = "<i>Keine Truppen gewählt</i>";
    }

    $send_title = $is_spying ? "Mine auskundschaften" : "Mine abbauen";

    $view .= '<div class="title-border">Erzmine (Stufe ' . $mine_lvl . ')</div>
                  <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                      <tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>' . $target_x . ':' . $target_y . '</td></tr>';
    if (!$is_spying) {
        $cap_display = ($is_friendly && !$is_mine_empty)
            ? $current_mine_troops . ' / ' . $max_capacity . ' Einheiten'
            : ($is_mine_empty ? '<b>0</b> / ' . $max_capacity . ' Einheiten' : 'Max. ' . $max_capacity . ' Einheiten');

        $view .= '<tr><td class="td-mapinfo"><b>Kapazität</b></td><td><span id="mine-capacity-display">' . $cap_display . '</span></td></tr>';

        $view .= '<tr><td class="td-mapinfo"><b>Abbau-Tempo</b></td><td><span id="mine-rate-display">' . $initial_rate_text . '</span></td></tr>
                      <tr><td class="td-mapinfo"><b>Abbauzeit</b></td><td><span id="mine-duration-display">' . $initial_dur_text . '</span></td></tr>';
    }

    $view .= '    <tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>' . convert_sec_to_str($arrival_time) . '</td></tr>
                      <tr><td colspan="2" style="font-size: 13px; opacity: 0.8; text-align: center; padding: 10px;">
                          <i>' . ($is_spying ? "Späher erkunden die verbleibenden Erzvorkommen und feindliche Truppen."
            : "Tipp: Truppen mit hohem Angriffswert bauen die Mine deutlich schneller ab!") . '</i>
                      </td></tr>
                  </table>';
} else if ($kingdom_id == MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM) {
    $res_ruin = $db_instance->execute_query(
        "SELECT kingdom_name, tc_level FROM abandoned_kingdoms WHERE mapx = ? AND mapy = ?",
        [$target_x, $target_y]
    )->fetch_assoc();

    $ruin_name = $res_ruin["kingdom_name"] ?? "Vergessenes Reich";
    $tc_lvl = (int)($res_ruin["tc_level"] ?? 1);

    $send_title = $is_spying ? "Ruine auskundschaften" : "Ruine stürmen";

    $view .= '<div class="title-border">Ruinen von ' . e($ruin_name) . '</div>
                  <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                      <tr>
                          <td class="td-mapinfo"><b>Koordinaten</b></td>
                          <td>' . $target_x . ':' . $target_y . '</td>
                      </tr>
                      <tr>
                          <td class="td-mapinfo"><b>Ehemalige Stufe</b></td>
                          <td>Dorfzentrum Stufe ' . $tc_lvl . '</td>
                      </tr>
                      <tr>
                          <td class="td-mapinfo"><b>Ankunftszeit</b></td>
                          <td>' . convert_sec_to_str($arrival_time) . '</td>
                      </tr>
                      <tr>
                        <td colspan="2" style="font-size: 14px; opacity: 0.8; text-align: center; padding: 10px;">
                            <i>Hinweis: Diese verlassene Festung wird von Monstern besetzt. Schlage die Besatzer in die Flucht, um die verbliebenen Schätze zu bergen!</i>
                        </td>
                      </tr>
                  </table>';
} else if ($kingdom_id == MapFieldTypes::MAP_FIELD_MONSTER_CAMP) {
    $send_title = $is_spying ? "Monstercamp spionieren" : "Monstercamp angreifen";

    $res_m = $db_instance->execute_query("SELECT level FROM monster_camps WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
    $m_lvl = $res_m->fetch_column() ?: 1;

    $view .= '<div class="title-border">Monstercamp (Stufe ' . $m_lvl . ')</div>
                  <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                      <tr>
                          <td class="td-mapinfo"><b>Koordinaten</b></td>
                          <td>' . $target_x . ':' . $target_y . '</td>
                      </tr>
                      <tr>
                          <td class="td-mapinfo"><b>Ankunftszeit</b></td>
                          <td>' . convert_sec_to_str($arrival_time) . '</td>
                      </tr>
                      <tr>
                        <td colspan="2" style="font-size: 14px; opacity: 0.8; text-align: center; padding: 10px;">
                            <i>Hinweis: Die Marschzeit beträgt bei Camps ' . (MONSTER_CAMP_TRAVEL_BOOST * 100) . '% des normalen Werts.</i>
                        </td>
                      </tr>
                  </table>';
} else if ($kingdom_id == MapFieldTypes::MAP_FIELD_WORLD_EVENT) {
    $we_manager = new WorldEvent();
    $active_ev = $we_manager->get_active_event();

    $send_title = "Event-Boss";
    $display_name = "Großer Angriff";

    if ($active_ev) {
        $pool = $we_manager->get_monster_pool();
        $monster = $pool[$active_ev["monster_index"]] ?? $pool[0];

        if ($active_ev["event_type"] === "BOSS_HP") {
            $display_name = $monster["name"];
        } else {
            $display_name = "Großer Angriff (" . $monster["name"] . ")";
        }
    }

    $view .= '<div class="title-border">' . $display_name . '</div>
                          <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                              <tr>
                                  <td class="td-mapinfo"><b>Koordinaten</b></td>
                                  <td>' . $target_x . ':' . $target_y . '</td>
                              </tr>
                              <tr>
                                  <td class="td-mapinfo"><b>Ankunftszeit</b></td>
                                  <td>' . convert_sec_to_str($we_manager->get_current_duration()) . '</td>
                              </tr>
                              <tr>
                                <td colspan="2" style="font-size: 14px; opacity: 0.8; text-align: center; padding: 10px;">
                                    <i>Hinweis: Truppen kehren von Welt-Events immer ohne Verluste heim.</i>
                                </td>
                              </tr>
                          </table>';
} else if ($row) {
    $is_self_station = ($enemy_user_id == $user->get_user_id());

    if ($is_self_station) {
        $send_title = "Truppen stationieren";
    } else if ($is_ally) {
        $send_title = "Königreich unterstützen";
    } else {
        $send_title = $is_spying ? "Königreich spionieren" : "Königreich angreifen";
    }

    $target_soldiers = [];
    $target_occupied = 0;
    $target_limit = 0;
    $target_free_space = 0;
    $target_extra = [];

    if ($is_self_station) {
        $target_k_obj = new Kingdom($kingdom_id);
        $target_limit = $target_k_obj->get_troop_limit();
        $target_occupied = $target_k_obj->get_current_troop_count(true, true);
        $target_free_space = max(0, $target_limit - $target_occupied);

        $res_target_soldiers = $db_instance->execute_query("
            SELECT s.soldierid, s.soldiercount, sl.soldiername, sl.icon
            FROM soldiers s
            JOIN soldier_list sl ON s.soldierid = sl.id
            WHERE s.kingdomid = ? AND s.soldiercount > 0
            ORDER BY sl.category, sl.id
        ", [$kingdom_id]);
        $target_soldiers = $res_target_soldiers->fetch_all(MYSQLI_ASSOC);

        $target_extra = $db_instance->execute_query("
            SELECT
                (SELECT IFNULL(SUM(st.soldiercount), 0)
                 FROM sent_troops st
                 JOIN events e ON st.eventid = e.eventid
                 WHERE e.targetid = ? AND e.actionid = ? AND e.kingdomid != ? AND e.userid = ?) AS incoming_count,
                (
                    (SELECT IFNULL(SUM(soldiercount), 0) FROM sent_troops WHERE source_kingdom_id = ?) +
                    (SELECT IFNULL(SUM(soldiercount), 0) FROM stationed_troops WHERE source_kingdom_id = ?) +
                    (SELECT IFNULL(SUM(soldiercount), 0) FROM mine_stationed_troops WHERE kingdom_id = ?)
                ) AS away_count,
                (SELECT IFNULL(SUM(soldiergoal), 0)
                 FROM events
                 WHERE kingdomid = ? AND actionid IN (?, ?)) AS queue_count,
                (SELECT IFNULL(SUM(soldiercount), 0)
                 FROM stationed_troops
                 WHERE target_kingdom_id = ?) AS support_count
        ", [
            $kingdom_id, ActionTypes::ACTION_SEND_TROOPS, $kingdom_id, $user->get_user_id(),
            $kingdom_id, $kingdom_id, $kingdom_id,
            $kingdom_id, ActionTypes::ACTION_BUILD_TROOPS, ActionTypes::ACTION_UPGRADE_TROOPS,
            $kingdom_id
        ])->fetch_assoc() ?: [];
    }

    $view .= '<div id="stealth-warning-box" style="display: none; margin-bottom: 10px;"></div>
                <div class="title-border">Königreich-Info (' . $field_name . ')</div>
                      <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                          <tr>
                              <td class="td-mapinfo"><b>Koordinaten</b></td>
                              <td>' . $target_x . ':' . $target_y . '</td>
                          </tr>';
    if ($is_ally) {
        $load_class = ($current_support_load >= $total_support_limit) ? "class='error'" : "class='passed'";

        $view .= '<tr>
                <td class="td-mapinfo"><b>Kapazität Hilfe</b></td>
                <td><span id="support-capacity-display" ' . $load_class . '>' . fnum($current_support_load) . '</span> / ' . fnum($total_support_limit) . '</td>
              </tr>';
    } else if ($is_self_station) {
        $cap_class = ($target_occupied >= $target_limit) ? "class='error'" : "class='passed'";

        $view .= '<tr>
            <td class="td-mapinfo"><b>Kapazität</b></td>
            <td><span id="station-capacity-display" ' . $cap_class . '>' . fnum($target_occupied) . '</span> / ' . fnum($target_limit) . '</td>
        </tr>';
    }

    $view .= '<tr>
                  <td class="td-mapinfo"><b>Königreich</b></td>
                  <td>' . $row["kingdomname"] . '</td>
              </tr>
              ' . ($enemy_user_id === $user->get_user_id()
            ? ''
            : '<tr>
              <td class="td-mapinfo"><b>Besitzer</b></td>
              <td><a href="#" 
                   data-on-click="openOverlay" 
                   data-url="userinfo.php?userid=' . e($enemy_user_id) . '" 
                   data-title="Spieler-Info">' . e($row["username"]) . '</a></td>
          </tr>'
        ) . '
              <tr>
                  <td class="td-mapinfo"><b>Ankunftszeit</b></td>
                  <td>' . convert_sec_to_str($arrival_time) . '</td>
              </tr>
          ';
} else if ($is_gift) {
    $view .= '<div class="title-border">Advents-Geschenk (Tag ' . $gift_day . ')</div>
    <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
        <tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>' . $target_x . ':' . $target_y . '</td></tr>
        <tr><td class="td-mapinfo"><b>Inhalt</b></td><td>Rohstoffe, ' . $gift_day . ' ' . ($gift_day === 1 ? "Münze" : "Münzen") . ' & Truppen-Chance</td></tr>
        <tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>' . convert_sec_to_str($arrival_time) . ' <small style="color: #0BDA51;">(Eilbote)</small></td></tr>
        <tr><td colspan="2" style="font-size: 13px; opacity: 0.8; text-align: center; padding: 10px;">
            <i>Entsende 1 beliebige Einheit als Boten, um das Geschenk zu bergen.</i>
        </td></tr>
    </table>';
} else {
    if ($kingdom_id == MapFieldTypes::MAP_FIELD_RESOURCE_TILE) {
        $send_title = $is_spying ? "Vorratslager spionieren" : "Vorratslager plündern";
        $display_name = "Verlassenes Vorratslager";
    } else {
        $send_title = "Erobern";
        $display_name = $field_name;
    }

    $view .= '<div class="title-border">' . $display_name . '</div>
                                  <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                                      <tr>
                                          <td class="td-mapinfo"><b>Koordinaten</b></td>
                                          <td>' . $target_x . ':' . $target_y . '</td>
                                      </tr>
                                      <tr>
                                          <td class="td-mapinfo"><b>Ankunftszeit</b></td>
                                          <td>' . convert_sec_to_str($arrival_time) . '</td>
                                      </tr>
                                ';
}

$view .= "</table>";

if ($is_self_station) {
    $has_target_soldiers = !empty($target_soldiers);
    $badges_html = "<div id='target-kingdom-troops-list' style='display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;'>";

    if ($has_target_soldiers) {
        foreach ($target_soldiers as $ts) {
            $sid = (int)$ts["soldierid"];
            $cnt = (int)$ts["soldiercount"];
            $s_name = e($ts["soldiername"]);
            $s_icon = e($ts["icon"]);

            $badges_html .= "<div class='unit-badge' data-soldier-id='$sid' data-base-count='$cnt' data-name='$s_name' title='$s_name'>
                <img src='images/icons/$s_icon.png' class='ressource-icons' alt='$s_name'>
                <b>" . fnum($cnt) . "</b>
            </div>";
        }
    }

    $badges_html .= "</div>";
    $badges_html .= "<span id='target-kingdom-empty-msg' style='opacity: 0.6; font-size: 13px; " . ($has_target_soldiers ? "display: none;" : "") . "'><i>Keine Truppen im Zielkönigreich vorhanden.</i></span>";

    $extra_parts = [];
    if (!empty($target_extra["incoming_count"])) $extra_parts[] = "Im Anmarsch: <b>" . fnum((int)$target_extra["incoming_count"]) . "</b>";
    if (!empty($target_extra["away_count"])) $extra_parts[] = "Unterwegs: <b>" . fnum((int)$target_extra["away_count"]) . "</b>";
    if (!empty($target_extra["queue_count"])) $extra_parts[] = "In Ausbildung: <b>" . fnum((int)$target_extra["queue_count"]) . "</b>";
    if (!empty($target_extra["support_count"])) $extra_parts[] = "Unterstützung: <b>" . fnum((int)$target_extra["support_count"]) . "</b>";

    $extra_html = !empty($extra_parts)
        ? "<div style='margin-top: 8px; font-size: 12px; opacity: 0.75; display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;'>" . implode(" <span style='opacity: 0.4;'>|</span> ", $extra_parts) . "</div>"
        : "";

    $view .= "
    <div style='display: flex; flex-direction: column; align-items: center; max-width: 500px; margin: 15px auto 0 auto;'>
        <div class='title-border' style='margin-bottom: 10px;'>
            Truppen im Zielkönigreich 
        </div>
        $badges_html
        $extra_html
    </div>";
}


$category_counts = SoldierTypes::get_empty_category_map();

$total_units_available = 0;

foreach ($soldiers as $soldier_id => $s_obj) {
    $count = $kingdom_soldiers[$soldier_id] ?? 0;

    if ($count > 0) {
        if ($only_scouts_allowed && $soldier_id !== Soldiers::SOLDIER_SCOUT) {
            continue;
        }

        if (($kingdom_id == MapFieldTypes::MAP_FIELD_MINE
                && $s_obj->get_soldier_category() == SoldierTypes::SOLDIER_TYPE_SPECIAL
                && $s_obj->get_soldier_id() !== Soldiers::SOLDIER_SCOUT) ||
            $kingdom_id == MapFieldTypes::MAP_FIELD_RESOURCE_TILE
            && $s_obj->get_soldier_id() !== Soldiers::SOLDIER_RAIDER
            && $s_obj->get_soldier_id() !== Soldiers::SOLDIER_SCOUT) {
            continue;
        }

        $category_counts[$s_obj->get_soldier_category()] += $count;
        $total_units_available += $count;
    }
}

$settler_wagon_count = (int)($kingdom_soldiers[Soldiers::SOLDIER_SETTLER_WAGON] ?? 0);
$first_active_cat = get_int("cat", -1);
if (($only_scouts_allowed && $scout_count > 0)
    || $is_founding
    || $kingdom_id == MapFieldTypes::MAP_FIELD_RESOURCE_TILE
    || ($first_active_cat === -1 && ($kingdom_id == MapFieldTypes::MAP_FIELD_EMPTY
            || in_array(get_str("mode"), ["plunder", "spy", "scout"], true)))) {
    $first_active_cat = SoldierTypes::SOLDIER_TYPE_SPECIAL;
}

if ($first_active_cat === -1 || ($category_counts[$first_active_cat] ?? 0) <= 0) {
    foreach ($category_counts as $cat_id => $count) {
        if ($count > 0) {
            $first_active_cat = $cat_id;
            break;
        }
    }
}

$show_all_checked = (($_COOKIE["me_list_view"] ?? "0") === "1");

if ($barracks_level > 0) {
    if ($is_founding && $settler_wagon_count <= 0) {
        $view .= "<div style='margin-top: 20px;'>"
            . show_warning_box("Du besitzt in <b>" . $kingdom->get_kingdom_name() . "</b> keinen Gründungskarren, um dieses Land zu besiedeln.")
            . "</div>";
    } else if ($only_scouts_allowed && $scout_count <= 0) {
        $view .= "<div style='margin-top: 20px;'>" .
            show_warning_box("Du besitzt in <b>" . $kingdom->get_kingdom_name() . "</b> keine Späher, um auszuspionieren.") .
            "</div>";
    } else if ($total_units_available > 0) {
        $button_label = "Truppen schicken";

        if ($is_gift) {
            $button_label = "Geschenk bergen";
        } else if ($is_ally) {
            $button_label = "Unterstützung senden";
        } else if ($enemy_user_id == $user->get_user_id()) {
            $button_label = "Einheiten stationieren";
        } else if ($kingdom_id == MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM) {
            $button_label = $is_spying ? "Späher entsenden" : "Ruine stürmen";
        } else if ($kingdom_id == MapFieldTypes::MAP_FIELD_RESOURCE_TILE) {
            $button_label = $is_spying ? "Späher entsenden" : "Vorratslager plündern";
        } else if ($kingdom_id == MapFieldTypes::MAP_FIELD_MONSTER_CAMP) {
            $button_label = $is_spying ? "Späher entsenden" : "Camp angreifen";
        }

        $mine_attrs = "";
        if ($kingdom_id == MapFieldTypes::MAP_FIELD_MINE && !$is_spying) {
            $visible_rem_work = ($is_friendly && !$is_mine_empty) ? $work_rem : (int)$res_mine["work_total"];
            $mine_attrs = ' data-is-mine="true"
                            data-mine-limit="' . $mine_limit . '" 
                            data-mine-current="' . ($is_friendly ? $total_occupied_or_incoming : 0) . '" 
                            data-mine-max="' . $max_capacity . '" 
                            data-mine-friendly="' . ($is_friendly && !$is_mine_empty ? "true" : "false") . '"
                            data-mine-rem-work="' . $visible_rem_work . '"
                            data-mine-work-total="' . (int)$res_mine["work_total"] . '"
                            data-mine-current-atk="' . ($is_friendly ? $current_mine_atk : 0) . '" 
                            data-mine-work-factor="' . MINE_WORK_RATE_FACTOR . '"
                            data-mine-min-duration="' . MINE_MIN_DURATION_SECONDS . '"';
        }

        $support_attrs = "";
        if ($is_ally) {
            $free_support_slots = max(0, $total_support_limit - $current_support_load);
            $support_attrs = ' data-is-support="true" data-support-limit="' . $free_support_slots . '" 
                                data-support-current="' . $current_support_load . '" 
                                data-support-max="' . $total_support_limit . '"';
        }

        $station_attrs = "";
        if ($is_self_station) {
            $station_attrs = ' data-is-station="true"'
                . ' data-station-limit="' . $target_free_space . '"'
                . ' data-station-current="' . $target_occupied . '"'
                . ' data-station-max="' . $target_limit . '"'
                . ' data-exempt-ids="' . e(json_encode(TROOP_LIMIT_EXEMPT)) . '"';
        }

        $troop_limit_attr = ' data-troop-limit="' . $troop_limit . '"';
        $gift_attrs = $is_gift ? ' data-is-gift="true" data-max-units="1"' : '';
        $ajax_query_extra = $is_gift ? "&mode=gift&day=$gift_day" : "";
        $unit_ids_attr = ' data-scout-id="' . Soldiers::SOLDIER_SCOUT . '" data-thief-id="' . Soldiers::SOLDIER_THIEF . '"';

        $view .= '<form action="javascript:void(0);" data-ajax-url="ajax/send_troops.php?x=' . $target_x . '&y=' . $target_y . $ajax_query_extra . '" 
                        method="POST" id="send-troops-form" data-on-submit="submitSendTroopsForm"' . $troop_limit_attr . $unit_ids_attr . $mine_attrs . $support_attrs . $gift_attrs . $station_attrs . '>
                        <div id="troop-summary-container" style="display: none; flex-direction: column; align-items: center;">
                            <div class="title-border" style="margin-bottom: 10px; margin-top: 15px;">
                                Gewählte Truppen <span class="battle-report-troops-origin" style="letter-spacing: normal;">(<span id="troop-summary-count">0</span> / ' . fnum($troop_limit, true) . ')</span>
                            </div>
                            <div id="troop-summary-list" style="display: flex; gap: 5px; justify-content: center; align-items: center; flex-wrap: wrap;"></div>
                            <div id="troop-summary-totals" style="width: 100%; display: flex; justify-content: center; margin-top: 15px;"></div>
                        </div>
                        <div id="troop-action-buttons" style="display: flex; align-items: center; justify-content: center; gap: 10px; margin: 20px;">
                            <input type="submit" value="' . $button_label . '" disabled>
                            <input type="button" value="Alle wählen" data-on-click="selectAllTroops" title="Alle verfügbaren Truppen auswählen">
                            <input type="button" 
                                   value="X" 
                                   data-on-click="clearAllTroops" 
                                   title="Alle Eingaben löschen" 
                                   style="width: 32px;">
                        </div>';

        $categories = SoldierTypes::get_labels();

        $view .= "<div class='tab' id='sendtroops-tabs' style='margin: 0 auto 10px auto; max-width: 500px; text-align: center; " . ($show_all_checked ? "display: none;" : "") . "'>";

        foreach ($categories as $id => $name) {
            if ($name === "Unterstützung") {
                continue;
            }

            if ($is_founding) {
                if ($id === SoldierTypes::SOLDIER_TYPE_SPECIAL) {
                    $view .= "<div class='tablinks active' data-on-click='filterSendTroops' data-category='$id'>$name</div>";
                } else {
                    $view .= "<div class='tablinks tab-disabled' title='Für Neugründungen können nur Gründungskarren entsendet werden'>$name</div>";
                }
                continue;
            }

            if ($kingdom_id == MapFieldTypes::MAP_FIELD_MINE && $id == SoldierTypes::SOLDIER_TYPE_SPECIAL) {
                if (($kingdom_soldiers[Soldiers::SOLDIER_SCOUT] ?? 0) <= 0) {
                    $view .= "<div class='tablinks tab-disabled' title='Keine Späher verfügbar'>$name</div>";
                    continue;
                }
            }

            if ($category_counts[$id] > 0) {
                $active_class = ($id === $first_active_cat) ? "active" : "";

                $view .= "<div class='tablinks $active_class' data-on-click='filterSendTroops' data-category='$id'>$name</div>";
            } else {
                $view .= "<div class='tablinks tab-disabled'>$name</div>";
            }
        }

        $view .= "</div>";

        // Show users soldiers
        $view .= '<table class="table send-selection-table" style="max-width: 500px;">
                            <colgroup>
                                <col style="width: auto;">
                                <col style="width: 130px;">
                            </colgroup>
                            <tr id="sendtroops-header" style="' . ($show_all_checked ? 'display: none;' : '') . '">
                                <td class="td-center td-gradient">Soldat</td>
                                <td class="td-center td-gradient">Anzahl</td>
                            </tr>';

        $prev_cat = -1;
        $category_names = [
            SoldierTypes::SOLDIER_TYPE_INFANTRY => "Infanterie",
            SoldierTypes::SOLDIER_TYPE_CAVALRY => "Kavallerie",
            SoldierTypes::SOLDIER_TYPE_ARCHERS => "Fernkampf",
            SoldierTypes::SOLDIER_TYPE_SPECIAL => "Spezialeinheiten"
        ];

        foreach ($soldiers as $soldier_id => $s_obj) {
            $soldier_id = $s_obj->get_soldier_id();

            if ($is_founding && $soldier_id !== Soldiers::SOLDIER_SETTLER_WAGON) {
                continue;
            }

            if ($only_scouts_allowed && $soldier_id !== Soldiers::SOLDIER_SCOUT) {
                continue;
            }

            $soldier_name = $s_obj->get_soldier_name();
            $unit_cat = $s_obj->get_soldier_category();
            $icon_name = $s_obj->get_soldier_icon_name();
            $atk_bonus = 0;
            $def_bonus = 0;
            $owned_count = $kingdom_soldiers[$soldier_id] ?? 0;

            if (($owned_count <= 0) || ($kingdom_id == MapFieldTypes::MAP_FIELD_MINE
                    && $unit_cat == SoldierTypes::SOLDIER_TYPE_SPECIAL
                    && $soldier_id !== Soldiers::SOLDIER_SCOUT) || ($kingdom_id == MapFieldTypes::MAP_FIELD_RESOURCE_TILE
                    && $soldier_id !== Soldiers::SOLDIER_RAIDER
                    && $soldier_id !== Soldiers::SOLDIER_SCOUT)) {
                continue;
            }

            if ($prev_cat !== $unit_cat) {
                $cat_label = $category_names[$unit_cat] ?? "";
                $divider_style = $show_all_checked ? "" : "display: none;";

                $view .= "<tr class='unit-category-divider' style='$divider_style'>
                                <td class='td-center td-gradient' style='border-top: 1px solid var(--box-header); border-bottom: 2px solid var(--box-header);'>
                                    <b>$cat_label</b>
                                </td>
                                <td class='td-center td-gradient' style='border-top: 1px solid var(--box-header); border-bottom: 2px solid var(--box-header);'>
                                    <b>Anzahl</b>
                                </td>
                              </tr>";
            }
            $prev_cat = $unit_cat;

            if ($unit_cat == SoldierTypes::SOLDIER_TYPE_INFANTRY) {
                $atk_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES) * SMITHY_INF_ATK_BONUS;
                $def_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL) * SMITHY_INF_DEF_BONUS;
            } else if ($unit_cat == SoldierTypes::SOLDIER_TYPE_CAVALRY) {
                $atk_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING) * SMITHY_CAV_ATK_BONUS;
                $def_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS) * SMITHY_CAV_DEF_BONUS;
            } else if ($unit_cat == SoldierTypes::SOLDIER_TYPE_ARCHERS) {
                $atk_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS) * SMITHY_ARC_ATK_BONUS;
                $def_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET) * SMITHY_ARC_DEF_BONUS;
            }

            $shrine_mult = 1.0;
            if ($kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
                $shrine_mult += $kingdom->calculate_shrine_bonus($kingdom->get_shrine_modifier());
            }

            $real_atk = (int)round($s_obj->get_soldier_attack() * $shrine_mult) + $atk_bonus;
            $real_def = (int)($s_obj->get_soldier_defense() + $def_bonus);

            $row_style = ($show_all_checked || $unit_cat === $first_active_cat) ? "" : "display: none;";

            $view .= "<tr class='unit-row' data-unit-category='$unit_cat' style='$row_style'>
                                <td>
                                    <div class='image-and-user' style='margin-bottom: 5px;'>" . $s_obj->get_soldier_icon() . " <b>" . $soldier_name . " (" . $owned_count . ")</b></div>
                                    <div class='map-legend' style='justify-content: left;'>
                                        <div class='legend-item' style='width: 60px;'>
                                            <div>
                                                <img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff'> " . $s_obj->get_soldier_attack() . "
                                            </div>
                                        </div>
                                        <div class='legend-item'>
                                            <div style='margin-left: 15px;'>
                                                <img src='images/icons/icon_shield.png' class='ressource-icons' alt='Verteidigung'> " . $s_obj->get_soldier_defense() . "
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class='td-center'>
                                    <div style='display: flex; gap: 5px; align-items: center; justify-content: center;'>
                                        <input type='text' 
                                               id='sol_$soldier_id' 
                                               name='soldiers[$soldier_id]' 
                                               size='5' 
                                               maxlength='6'
                                               inputmode='numeric' pattern='[0-9]*'
                                               class='js-unit-input' 
                                               data-name='" . e($soldier_name) . "' 
                                               data-id='$soldier_id'
                                               data-icon='$icon_name'
                                               data-max='$owned_count'
                                               data-atk='$real_atk' 
                                               data-def='$real_def'>
                                        <input type='button' 
                                               value='Max.' 
                                               data-on-click='fillMaxAndRefresh' 
                                               data-target='sol_$soldier_id' 
                                               data-value='$owned_count'
                                               tabindex='-1'>
                                        <input type='button'
                                               value='X' 
                                               title='Feld leeren'
                                               data-on-click='resetUnitAndRefresh' 
                                               data-target='sol_$soldier_id'
                                               style='width: 32px;'
                                               tabindex='-1'>       
                                    </div>
                                </td>
                              </tr>";
        }

        $view .= '</table></form>';
    } else {
        $no_units_msg = ($kingdom_id == MapFieldTypes::MAP_FIELD_RESOURCE_TILE)
            ? "In <b>" . $kingdom->get_kingdom_name() . "</b> befinden sich aktuell weder Räuber noch Späher für das Vorratslager."
            : "In <b>" . $kingdom->get_kingdom_name() . "</b> befinden sich aktuell keine Truppen.";

        $view .= "<div style='margin-top: 20px;'>" . show_warning_box($no_units_msg) . "</div>";
    }
} else {
    $view .= "<div style='margin-top: 20px;'>" .
        show_warning_box("Du hast in <b>" . $kingdom->get_kingdom_name() . "</b> keine Kaserne errichtet.") .
        "</div>";
}

/*
 * HTML Section
 */
echo "<div id='modal-title-data' data-title='" . e($send_title) . "' data-width='850px' style='display: none;'></div>";
echo "<div style='padding: 10px; max-width: 850px; margin: 0 auto; box-sizing: border-box; text-align: left;'>" . $view . "</div>";
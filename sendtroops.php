<?php
require_once("includes/core.php");

// Barracks required for sending troops
check_user_login($user);

$current_k_id = $user->get_current_kingdom();
$kingdom = new Kingdom($db_instance, $current_k_id);
$barracks_level = $kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_BARRACKS);

$map = new Map($db_instance, $user);
$kingdom = new Kingdom($db_instance, $user->get_current_kingdom());
$target_x = (isset($_GET["x"]) && ctype_digit($_GET["x"])) ? intval($_GET["x"]) : 1;
$target_y = (isset($_GET["y"]) && ctype_digit($_GET["y"])) ? intval($_GET["y"]) : 1;
$kingdom_id = $map->get_field_kingdom_id($target_x, $target_y);
$send_title = "Erobern";

if ($target_x > MAX_X || $target_x < 1 || $target_y > MAX_Y || $target_y < 1) {
    $_SESSION["game_error"] = "Diese Koordinaten gibt es nicht!";

    change_location("map.php?startx=$target_x&starty=$target_y");
    exit;
}

if ($kingdom_id == WORLD_EVENT_ID) {
    $world_event_manager = new WorldEvent($db_instance);
    $active_event = $world_event_manager->get_active_event();

    if (!$active_event) {
        $_SESSION["game_error"] = "Dieses Gebiet ist durch einen magischen Bann versiegelt!";

        change_location("map.php?startx=$target_x&starty=$target_y");
        exit;
    }

    if ($active_event["event_type"] === "DAMAGE") {
        $res_check = $db_instance->execute_query(
            "SELECT attempts_used FROM world_event_participants WHERE event_id = ? AND userid = ?",
            [$active_event["id"], $user->get_user_id()]
        );
        $attempts = $res_check->fetch_assoc()["attempts_used"] ?? 0;

        if ($attempts >= WORLD_EVENT_MAX_ATTEMPTS) {
            $_SESSION["game_error"] = "Du hast bereits alle " . WORLD_EVENT_MAX_ATTEMPTS . " Versuche für dieses Event verbraucht!";

            change_location("map.php?startx=$target_x&starty=$target_y");
            exit;
        }
    }
}

if ($barracks_level <= 0) {
    $_SESSION["game_error"] = "Dein Königreich benötigt eine Kaserne, um Truppenbewegungen zu koordinieren!";

    change_location("map.php?startx=$target_x&starty=$target_y");
    exit;
}

// Get users kingdom and score + noob check
$enemy_score = 0;
$enemy_user_id = -1;
$result_enemy = $db_instance->execute_query("
        SELECT k.userid, u.score 
        FROM kingdoms k
        JOIN users u ON k.userid = u.id 
        WHERE k.mapx = ? AND k.mapy = ?", [$target_x, $target_y]);
$row_enemy = $result_enemy->fetch_assoc();

if ($row_enemy) {
    $enemy_score = $row_enemy["score"];
    $enemy_user_id = $row_enemy["userid"];
}

$is_noob_protected = false;
if ($enemy_user_id > 0 && $enemy_user_id != $user->get_user_id()) {
    $is_noob_protected = new Conquest($db_instance)->has_noob_protection($user->get_user_score(), $enemy_score);
}

// Check if the user already sent troops to that kingdom
$already_sent = 0;

if ($kingdom_id != -999) {
    $result = $db_instance->execute_query("SELECT COUNT(*) AS alreadysent FROM events 
                               WHERE actionid = ? AND userid = ? AND targetx = ? AND targety = ? AND kingdomid = ?",
        [ActionTypes::ACTION_SEND_TROOPS, $user->get_user_id(), $target_x, $target_y, $user->get_current_kingdom()]);
    $already_sent = $result->fetch_assoc()["alreadysent"];
}

if ($already_sent > 0) {
    $_SESSION["game_error"] = "Du hast bereits Truppen zu diesen Koordinaten geschickt!";

    change_location("map.php?startx=$target_x&starty=$target_y");
    exit;
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

// Get field info
$query = "
            SELECT 
                m.fieldtype, 
                f.fieldname,
                COALESCE(r.expires_at, mc.expires_at, 0) AS expires_at
            FROM map m
            JOIN field_types f ON m.fieldtype = f.fieldid
            LEFT JOIN resource_tiles_data r ON m.mapx = r.mapx AND m.mapy = r.mapy
            LEFT JOIN monster_camps mc ON m.mapx = mc.mapx AND m.mapy = mc.mapy
            WHERE m.mapx = ? AND m.mapy = ?
        ";
$result2 = $db_instance->execute_query($query, [$target_x, $target_y]);
$field_data = $result2->fetch_assoc();
$field_name = $field_data["fieldname"];
$expires_at = (int)$field_data["expires_at"];

$arrival_time = $map->get_arrival_time($kingdom->get_kingdom_map_x(), $kingdom->get_kingdom_map_y(),
    $target_x, $target_y, $user->get_current_kingdom(), $kingdom_id);

if ($expires_at > 0) {
    $remaining_time = $expires_at - time();

    if ($arrival_time > $remaining_time) {
        $view .= show_warning_box("<b>Warnung:</b> Deine Truppen werden nicht rechtzeitig ankommen<br>(Restzeit: " . convert_sec_to_str($remaining_time) . ").");
    }
}

// Check if sent troop was clicked
if (!empty($_POST["soldiers"])) {
    if ($barracks_level <= 0) {
        $error = "Befehl verweigert: Du besitzt keine Kaserne!";
    } else {
        $event_id = null;
        $has_soldiers = false;
        $has_non_scout_units = false;

        foreach ($_POST["soldiers"] as $soldier_id => $count) {
            $soldier_id = intval($soldier_id);
            $soldier_count = intval($count);

            if ($soldier_count > 0) {
                $has_soldiers = true;

                if ($soldier_id !== Soldiers::SOLDIER_SCOUT) {
                    $has_non_scout_units = true;
                }

                if ($soldier_count > ($kingdom_soldiers[$soldier_id] ?? 0)) {
                    $error = "Du hast zu wenig Soldaten vom Typ " . $soldiers[$soldier_id]->get_soldier_name() . "!";
                    break;
                }
            }
        }

        if ($is_noob_protected && $has_non_scout_units) {
            $error = "Aufgrund des Noob-Schutzes dürfen nur reine Spionage-Trupps (Späher) entsendet werden!";
        }

        $tc_level = $kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_TOWNCENTER);
        $max_commands = BASE_SEND_TROOPS_LIMIT + $tc_level;
        $active_res = $db_instance->execute_query(
            "SELECT COUNT(*) as total FROM events WHERE kingdomid = ? AND (actionid = ? OR actionid = ?)",
            [$user->get_current_kingdom(), ActionTypes::ACTION_SEND_TROOPS, ActionTypes::ACTION_RETURN_TROOPS]
        );
        $settler_wagon_count = (int)($_POST["soldiers"][Soldiers::SOLDIER_SETTLER_WAGON] ?? 0);

        $res_k_count = $db_instance->execute_query(
            "SELECT COUNT(*) AS total FROM kingdoms WHERE userid = ? AND creation_method = 0",
            [$user->get_user_id()]
        );
        $current_settled_count = $res_k_count->fetch_assoc()["total"] ?? 0;

        $res_total_imp = $db_instance->execute_query("
                    SELECT COUNT(*) AS total 
                    FROM techs t
                    JOIN kingdoms k ON t.kingdomid = k.id
                    WHERE k.userid = ? AND t.techid = ? AND t.techlevel > 0
                ", [$user->get_user_id(), TechTypes::TECH_TYPE_IMPERIAL]);
        $total_imperial_bonus = $res_total_imp->fetch_assoc()["total"] ?? 0;

        $max_allowed_slots = min(GLOBAL_SETTLEMENT_MAX, BASE_SETTLEMENT_LIMIT + $total_imperial_bonus);

        $total_units_in_request = 0;
        foreach ($_POST["soldiers"] as $count) {
            $total_units_in_request += (int)$count;
        }

        if (!$has_soldiers) {
            $error = "Du musst mindestens einen Soldaten auswählen!";
        } else if ($active_res->fetch_assoc()["total"] >= $max_commands) {
            $error = "Deine Offiziere sind überlastet! (Limit: $max_commands Befehle).<br>Baue das Dorfzentrum weiter aus, falls möglich.";
        } else if ($kingdom_id == -1 && $settler_wagon_count > 0 && $current_settled_count >= $max_allowed_slots) {
            if ($max_allowed_slots >= GLOBAL_SETTLEMENT_MAX) {
                $error = "Das absolute Imperiums-Limit von " . GLOBAL_SETTLEMENT_MAX . " Dörfern ist erreicht!";
            } else {
                $error = "Keine weiteren Siedlungen möglich! Erforsche 'Imperium' in einem weiteren Dorf, um einen Slot freizuschalten (Aktuell: $max_allowed_slots).";
            }
        } else if ($enemy_user_id == $user->get_user_id() && $target_x != -1) {
            $target_k_obj = new Kingdom($db_instance, $kingdom_id);
            $target_limit = $target_k_obj->get_troop_limit();

            $target_occupied = $target_k_obj->get_current_troop_count(true, true);
            $free_space = max(0, $target_limit - $target_occupied);

            if ($total_units_in_request > $free_space) {
                $error = "Im Zielkönigreich ist nicht genug Platz für die Stationierung! Frei: " . fnum($free_space) . " Plätze.";
            }
        }

        if (empty($error)) {
            $now = time();

            $is_pure_scouting = ($has_soldiers && !$has_non_scout_units);
            $arrival_time = $map->get_arrival_time(
                $kingdom->get_kingdom_map_x(),
                $kingdom->get_kingdom_map_y(),
                $target_x,
                $target_y,
                $user->get_current_kingdom(),
                $kingdom_id,
                $is_pure_scouting
            );

            $result = $db_instance->execute_query(
                "INSERT INTO events (actionid, userid, kingdomid, buildingtime, targetid, targetx, targety, arrivaltime) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING eventid",
                [ActionTypes::ACTION_SEND_TROOPS, $user->get_user_id(), $user->get_current_kingdom(), $now, $kingdom_id, $target_x, $target_y, $now + $arrival_time]
            );
            $event_id = $result->fetch_assoc()["eventid"];

            foreach ($_POST["soldiers"] as $soldier_id => $count) {
                $soldier_id = intval($soldier_id);
                $soldier_count = intval($count);

                if ($soldier_count > 0) {
                    // Insert troop record
                    $db_instance->execute_query(
                        "INSERT INTO sent_troops (eventid, soldierid, soldiercount, initial_count) VALUES (?, ?, ?, ?)",
                        [$event_id, $soldier_id, $soldier_count, $soldier_count]
                    );

                    // Subtract soldiers from kingdom
                    $query = "UPDATE soldiers SET soldiercount = soldiercount - ? WHERE kingdomid = ? AND soldierid = ?";
                    $db_instance->execute_query($query, [$soldier_count, $user->get_current_kingdom(), $soldier_id]);

                    // Update local count
                    $kingdom_soldiers[$soldier_id] -= $soldier_count;
                }
            }

            if ($event_id !== null) {
                $log_troops = [];

                foreach ($_POST["soldiers"] as $s_id => $count) {
                    $count = (int)$count;

                    if ($count > 0) {
                        $name = $soldiers[$s_id]->get_soldier_name();
                        $log_troops[$name] = $count;
                    }
                }

                $logger->log_game("COMBAT", "ATTACK_SEND", [
                    "target_x" => $target_x,
                    "target_y" => $target_y,
                    "target_kingdom_id" => $kingdom_id,
                    "arrival_in" => $arrival_time,
                    "troops" => $log_troops
                ], $user->get_current_kingdom());

                $_SESSION["game_success"] = "Truppen erfolgreich gesendet!";

                if ($kingdom_id == WORLD_EVENT_ID) {
                    change_location("events.php");
                } else {
                    change_location("map.php?startx=$target_x&starty=$target_y");
                }
                exit;
            }
        }
    }
}

if ($target_x == $kingdom->get_kingdom_map_x() && $target_y == $kingdom->get_kingdom_map_y()) {
    $_SESSION["game_error"] = "Das ist dein aktuelles Königreich!";

    change_location("map.php?startx=$target_x&starty=$target_y");
    exit;
} else {
    // Noob protection check
    if ($is_noob_protected && $enemy_user_id != -1) {
        $my_score = $user->get_user_score();

        $min_allowed = floor($my_score * NOOB_PROTECTION_MULT);
        $max_allowed = floor($my_score / NOOB_PROTECTION_MULT);

        $view .= show_warning_box("
                    <b>Punktestand zu unterschiedlich:</b><br>
                    Ein Angriff ist nicht möglich.<br>
                    Du kannst nur Spieler angreifen, die einen Score von <b>" . fnum($min_allowed) . "</b> bis <b>" . fnum($max_allowed) . "</b> haben <b>(" . (NOOB_PROTECTION_MULT * 100) . "%)</b>.<br>
                    Reine Spionage (nur Späher) ist allerdings möglich.
                ");
        $only_scouts_allowed = true;
    } else {
        $only_scouts_allowed = false;
    }

    $is_spying = (isset($_GET["mode"]) && $_GET["mode"] === "spy");

    if (!empty($_POST["soldiers"])) {
        $is_spying = true;

        foreach ($_POST["soldiers"] as $s_id => $count) {
            if ((int)$s_id !== Soldiers::SOLDIER_SCOUT && (int)$count > 0) {
                $is_spying = false;
                break;
            }
        }
    }

    if ($only_scouts_allowed) {
        $is_spying = true;
    }

    if ($kingdom_id == -3) {
        $send_title = $is_spying ? "Monstercamp spionieren" : "Monstercamp angreifen";

        $res_m = $db_instance->execute_query("SELECT level FROM monster_camps WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
        $m_lvl = $res_m->fetch_column() ?: 1;

        $view .= '<div class="title-border">Monstercamp</div>
                  <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                      <tr>
                          <td class="td-mapinfo"><b>Koordinaten</b></td>
                          <td>' . $target_x . ':' . $target_y . '</td>
                      </tr>
                      <tr>
                          <td class="td-mapinfo"><b>Gefahrenstufe</b></td>
                          <td>Stufe ' . $m_lvl . '</td>
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
    } else if ($kingdom_id == WORLD_EVENT_ID) {
        $we_manager = new WorldEvent($db_instance);
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
                                  <td>' . convert_sec_to_str(WORLD_EVENT_ATTACK_DURATION) . '</td>
                              </tr>
                              <tr>
                                <td colspan="2" style="font-size: 14px; opacity: 0.8; text-align: center; padding: 10px;">
                                    <i>Hinweis: Truppen kehren von Welt-Events immer ohne Verluste heim.</i>
                                </td>
                              </tr>
                          </table>';
    } else if ($row) {
        if ($enemy_user_id == $user->get_user_id()) {
            $send_title = "Truppen stationieren";
        } else {
            $send_title = $is_spying ? "Königreich spionieren" : "Königreich angreifen";
        }

        $view .= '<div class="title-border">Königreich-Info (' . $field_name . ')</div>
                                  <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                                      <tr>
                                          <td class="td-mapinfo"><b>Koordinaten</b></td>
                                          <td>' . $target_x . ':' . $target_y . '</td>
                                      </tr>
                                      <tr>
                                          <td class="td-mapinfo"><b>Königreich</b></td>
                                          <td>' . $row["kingdomname"] . '</td>
                                      </tr>
                                      <tr>
                                          <td class="td-mapinfo"><b>Besitzer</b></td>
                                          <td><a href="#" 
                                               data-on-click="openOverlay" 
                                               data-url="userinfo.php?userid=' . e($enemy_user_id) . '" 
                                               data-title="Spieler-Info">' . e($row["username"]) . '</a></td>
                                      </tr>
                                      <tr>
                                          <td class="td-mapinfo"><b>Ankunftszeit</b></td>
                                          <td>' . convert_sec_to_str($arrival_time) . '</td>
                                      </tr>
                                  ';

    } else {
        if ($kingdom_id == -2) {
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

    $category_counts = [
        SoldierTypes::SOLDIER_TYPE_INFANTRY => 0,
        SoldierTypes::SOLDIER_TYPE_CAVALRY => 0,
        SoldierTypes::SOLDIER_TYPE_ARCHERS => 0,
        SoldierTypes::SOLDIER_TYPE_SPECIAL => 0
    ];

    $total_units_available = 0;

    foreach ($soldiers as $soldier_id => $s_obj) {
        $count = $kingdom_soldiers[$soldier_id] ?? 0;
        if ($count > 0) {
            $category_counts[$s_obj->get_soldier_category()] += $count;
            $total_units_available += $count;
        }
    }

    $first_active_cat = -1;
    $requested_mode = $_GET['mode'] ?? '';
    if (in_array($requested_mode, ["plunder", "spy", "scout"])) {
        $first_active_cat = SoldierTypes::SOLDIER_TYPE_SPECIAL;
    } else {
        foreach ($category_counts as $cat_id => $count) {
            if ($count > 0) {
                $first_active_cat = $cat_id;
                break;
            }
        }
    }

    if ($barracks_level > 0) {
        if ($total_units_available > 0) {
            $view .= '<form action="sendtroops.php?x=' . $target_x . '&y=' . $target_y . '" method="POST" id="send-troops-form">
                        <div id="troop-summary-container" style="display: none; flex-direction: column; align-items: center;">
                            <div class="title-border" style="margin-bottom: 10px; margin-top: 15px;">Gewählte Truppen:</div>
                            <div id="troop-summary-list" style="display: flex; gap: 5px; justify-content: center; align-items: center; flex-wrap: wrap;"></div>
                            <div id="troop-summary-totals" style="width: 100%; display: flex; justify-content: center; margin-top: 15px;"></div>
                        </div>
                        <div id="troop-action-buttons" style="display: flex; align-items: center; justify-content: center; gap: 10px; margin: 20px;">
                            <input type="submit" value="Truppen schicken">
                            <input type="button" value="Alle wählen" data-on-click="selectAllTroops" title="Alle verfügbaren Truppen auswählen">
                            <input type="button" 
                                   value="X" 
                                   data-on-click="clearAllTroops" 
                                   title="Alle Eingaben löschen" 
                                   style="width: 32px;">
                        </div>';

            $categories = SoldierTypes::get_labels();

            $view .= "<div class='tab' style='margin-top: 10px;'>";
            foreach ($categories as $id => $name) {
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
                            <tr>
                                <td class="td-center td-gradient">Soldat</td>
                                <td class="td-center td-gradient">Anzahl</td>
                            </tr>';

            foreach ($soldiers as $soldier_id => $s_obj) {
                $soldier_id = $s_obj->get_soldier_id();
                $soldier_name = $s_obj->get_soldier_name();
                $unit_cat = $s_obj->get_soldier_category();
                $icon_name = $s_obj->get_soldier_icon_name();
                $cat = $s_obj->get_soldier_category();
                $atk_bonus = 0;
                $def_bonus = 0;
                $owned_count = $kingdom_soldiers[$soldier_id] ?? 0;

                if ($owned_count <= 0) {
                    continue;
                }

                if ($cat == SoldierTypes::SOLDIER_TYPE_INFANTRY) {
                    $atk_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES) * SMITHY_INF_ATK_BONUS;
                    $def_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL) * SMITHY_INF_DEF_BONUS;
                } elseif ($cat == SoldierTypes::SOLDIER_TYPE_CAVALRY) {
                    $atk_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING) * SMITHY_CAV_ATK_BONUS;
                    $def_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS) * SMITHY_CAV_DEF_BONUS;
                } elseif ($cat == SoldierTypes::SOLDIER_TYPE_ARCHERS) {
                    $atk_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS) * SMITHY_ARC_ATK_BONUS;
                    $def_bonus = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET) * SMITHY_ARC_DEF_BONUS;
                }

                $shrine_mult = 1.0;
                if ($kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
                    $shrine_mult += $kingdom->get_shrine_modifier();
                }

                $real_atk = (int)round(($s_obj->get_soldier_attack() + $atk_bonus) * $shrine_mult);
                $real_def = (int)($s_obj->get_soldier_defense() + $def_bonus);

                $row_style = ($unit_cat === $first_active_cat) ? "" : "display: none;";

                $is_input_disabled = ($only_scouts_allowed && $soldier_id !== Soldiers::SOLDIER_SCOUT) ? "disabled" : "";

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
                                               $is_input_disabled
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
                                               $is_input_disabled
                                               value='Max.' 
                                               data-on-click='fillMaxAndRefresh' 
                                               data-target='sol_$soldier_id' 
                                               data-value='$owned_count'>
                                        <input type='button'
                                               $is_input_disabled
                                               value='X' 
                                               title='Feld leeren'
                                               data-on-click='resetUnitAndRefresh' 
                                               data-target='sol_$soldier_id'
                                               style='width: 32px;'>       
                                    </div>
                                </td>
                              </tr>";
            }

            $view .= '</table></form>';
        } else {
            $view .= "<div style='margin-top: 20px;'>" .
                show_warning_box("In diesem Königreich befinden sich aktuell keine Truppen.") .
                "</div>";
        }
    } else {
        $view .= "<div style='margin-top: 20px;'>" .
            show_warning_box("Du kannst keine Truppen versenden, da du in diesem Königreich noch keine Kaserne errichtet hast.") .
            "</div>";
    }
}

/*
 * HTML Section
 */
$title = $send_title;
$header = $send_title;
$script_files = ["userinfo", "sendtroops"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
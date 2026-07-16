<?php
require_once("includes/core.php");

// Barracks required for sending troops
//check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_BARRACKS);
check_user_login($user);

$map = new Map($db_instance, $user);
$kingdom = new Kingdom($db_instance, $user->get_current_kingdom());
$target_x = (isset($_GET["x"]) && ctype_digit($_GET["x"])) ? intval($_GET["x"]) : -1;
$target_y = (isset($_GET["y"]) && ctype_digit($_GET["y"])) ? intval($_GET["y"]) : -1;
$kingdom_id = $map->get_field_kingdom_id($target_x, $target_y);
$send_title = "Erobern";

if ($target_x > MAX_X || $target_x < 1 || $target_y > MAX_Y || $target_y < 1) {
    $error = "Diese Koordinaten gibt es nicht!";
} else {
    // Check if the user already sent troops to that kingdom
    $result = $db_instance->execute_query("SELECT COUNT(*) AS alreadysent FROM events 
                               WHERE actionid = ? AND userid = ? AND targetx = ? AND targety = ? AND kingdomid = ?",
        [ActionTypes::ACTION_SEND_TROOPS, $user->get_user_id(), $target_x, $target_y, $user->get_current_kingdom()]);
    $already_sent = $result->fetch_assoc()["alreadysent"];

    if ($already_sent > 0) {
        $error = "Du hast bereits Truppen zu diesen Koordinaten geschickt!";

        change_location("map.php?startx=$target_x&starty=$target_y", 3);
    } else {
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
                    SELECT m.fieldtype, f.fieldname FROM map m
                    JOIN field_types f ON m.fieldtype = f.fieldid
                    WHERE mapx = ? AND mapy = ?
            ";
        $result2 = $db_instance->execute_query($query, [$target_x, $target_y]);
        $field_name = $result2->fetch_assoc()["fieldname"];
        $arrival_time = $map->get_arrival_time($kingdom->get_kingdom_map_x(), $kingdom->get_kingdom_map_y(), $target_x, $target_y);

        // Check if sent troop was clicked
        if (!empty($_POST["soldiers"])) {
            $event_id = null;
            $has_soldiers = false;

            foreach ($_POST["soldiers"] as $soldier_id => $count) {
                $soldier_id = intval($soldier_id);
                $soldier_count = intval($count);

                if ($soldier_count > 0) {
                    $has_soldiers = true;

                    if ($soldier_count > ($kingdom_soldiers[$soldier_id] ?? 0)) {
                        $error = "Du hast zu wenig Soldaten vom Typ " . $soldiers[$soldier_id]->get_soldier_name() . "!";
                        break;
                    }
                }
            }

            $tc_level = $kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_TOWNCENTER);
            $max_commands = BASE_SEND_TROOPS_LIMIT + $tc_level;
            $active_res = $db_instance->execute_query(
                "SELECT COUNT(*) as total FROM events WHERE kingdomid = ? AND (actionid = ? OR actionid = ?)",
                [$user->get_current_kingdom(), ActionTypes::ACTION_SEND_TROOPS, ActionTypes::ACTION_RETURN_TROOPS]
            );
            $settler_wagon_count = (int)($_POST["soldiers"][Soldiers::SOLDIER_SETTLER_WAGON] ?? 0);
            $imp_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_IMPERIAL);
            $max_settled_slots = BASE_SETTLEMENT_LIMIT + $imp_lvl;
            $current_k_count = ($settler_wagon_count > 0 ? $db_instance->execute_query(
                "SELECT COUNT(*) AS total FROM kingdoms WHERE userid = ? AND creation_method = 0",
                [$user->get_user_id()])->fetch_assoc()["total"]
                : 0);

            if (!$has_soldiers) {
                $error = "Du musst mindestens einen Soldaten auswählen!";
            } else if ($active_res->fetch_assoc()["total"] >= $max_commands) {
                $error = "Deine Offiziere sind überlastet! (Limit: $max_commands Befehle).<br>Baue das Dorfzentrum weiter aus, falls möglich.";
            } else if ($kingdom_id == -1 && $settler_wagon_count > 0 && $current_k_count >= $max_settled_slots) {
                $error = "Keine Siedler-Slots mehr frei! Erforsche 'Imperium', um mehr als $max_settled_slots Dörfer gründen zu können.";
            } else if (empty($error)) {
                $now = time();

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
                            "INSERT INTO sent_troops (eventid, soldierid, soldiercount) VALUES (?, ?, ?)",
                            [$event_id, $soldier_id, $soldier_count]
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

                    $view .= show_passed_box("Truppen erfolgreich gesendet!");
                }
            }
        }

        // Get users kingdom and score
        $score = 0;
        $enemy_user_id = -1;
        $result3 = $db_instance->execute_query("
                SELECT 
                    k.userid, 
                    u.score 
                FROM kingdoms k
                JOIN users u ON k.userid = u.id 
                WHERE k.mapx = ? AND k.mapy = ?",
            [$target_x, $target_y]);
        $row3 = $result3->fetch_assoc();

        if ($row3) {
            $score = $row3["score"];
            $enemy_user_id = $row3["userid"];
        }

        if ($target_x == $kingdom->get_kingdom_map_x() && $target_y == $kingdom->get_kingdom_map_y()) {
            $error = "Das ist dein aktuelles Königreich!";

            change_location("map.php?startx=$target_x&starty=$target_y", 3);
        } else {
            // Noob protection message
            if (new Conquest($db_instance)->has_noob_protection($user->get_user_score(), $score) && $enemy_user_id != -1) {
                $view .= show_error_box("Euer Punktestand ist zu unterschiedlich (Noob-Schutz)!");

                change_location("map.php?startx=$target_x&starty=$target_y", 3);
            } else {
                if ($row) {
                    if ($enemy_user_id == $user->get_user_id()) {
                        $send_title = "Truppen stationieren";
                    } else {
                        $send_title = "Königreich angreifen";
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
                    $view .= '<div class="title-border">' . $field_name . '</div>
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

                    $send_title = "Erobern";
                }
                $view .= "</table><br>";

                $categories = [
                    SoldierTypes::SOLDIER_TYPE_INFANTRY => "Infanterie",
                    SoldierTypes::SOLDIER_TYPE_CAVALRY => "Kavallerie",
                    SoldierTypes::SOLDIER_TYPE_ARCHERS => "Schützen",
                    SoldierTypes::SOLDIER_TYPE_SPECIAL => "Spezial"
                ];

                $view .= "<div class='tab'>";
                foreach ($categories as $id => $name) {
                    $active_class = ($id === 0) ? "active" : "";
                    $view .= "<div class='tablinks $active_class' data-on-click='filterSendTroops' data-category='$id'>$name</div>";
                }
                $view .= "</div>";

                // Show users soldiers
                $view .= '<form action="sendtroops.php?x=' . $target_x . '&y=' . $target_y . '" method="POST" id="send-troops-form">
                            <table class="table" style="max-width: 500px;">
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
                    $owned_count = $kingdom_soldiers[$soldier_id] ?? 0;

                    $row_style = ($unit_cat === 0) ? "" : "display: none;";

                    $view .= "<tr class='unit-row' data-unit-category='$unit_cat' style='$row_style'>
                                <td>
                                    <div class='image-and-user' style='margin-bottom: 5px;'>" . $s_obj->get_soldier_icon() . " <b>" . $soldier_name . " (" . $owned_count . ")</b></div>
                                    <div class='map-legend' style='justify-content: left;'>
                                        <div class='legend-item' style='width: 60px;'>
                                            <div>
                                                <img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff'> " . $s_obj->get_soldier_attack() . "
                                            </div>
                                        </div>
                                        <div class='legend-item' style='width: 80px;'>
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
                                               class='js-unit-input' 
                                               data-name='" . e($soldier_name) . "' 
                                               data-id='$soldier_id'
                                               data-icon='$icon_name'
                                               data-max='$owned_count'>
                                        <input type='button' 
                                               value='Max.' 
                                               data-on-click='fillMaxAndRefresh' 
                                               data-target='sol_$soldier_id' 
                                               data-value='$owned_count'>
                                        <input type='button' 
                                               value='X' 
                                               title='Feld leeren'
                                               data-on-click='resetUnitAndRefresh' 
                                               data-target='sol_$soldier_id'>       
                                    </div>
                                </td>
                              </tr>";
                }

                $view .= '</table>';
                $view .= '<div id="troop-summary-container" style="margin-top: 20px; display: none; flex-direction: column;">
                                <div style="font-weight: bold; margin-bottom: 10px;">Gewählte Truppen:</div>
                                <div id="troop-summary-list" style="display: flex; gap: 5px; justify-content: center; align-items: center;"></div>
                            </div>
                            <input type="submit" style="margin-top: 10px;" value="Truppen schicken">
                        </form>';
            }
        }
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
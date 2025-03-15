<?php
require_once("includes/core.php");

// Barracks required for sending troops
check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_BARRACKS);

$map = new Map($db_instance);
$kingdom = new Kingdoms($db_instance);
$kingdom->get_kingdom_info($user->get_current_kingdom());
$target_x = (isset($_GET["x"]) && ctype_digit($_GET["x"])) ? intval($_GET["x"]) : 1;
$target_y = (isset($_GET["y"]) && ctype_digit($_GET["y"])) ? intval($_GET["y"]) : 1;
$kingdom_id = $map->get_field_kingdom_id($target_x, $target_y);
$arrival_time = $map->get_arrival_time($kingdom->get_kingdom_map_x(), $kingdom->get_kingdom_map_y(), $target_x, $target_y);
$send_title = "Erobern";

if (!str_contains($user->get_user_name(), "Macronix")) {
    $view .= show_error_box("Erobern ist derzeit nicht möglich!");
} else {
    if ($target_x > MAX_X || $target_x < 1 || $target_y > MAX_Y || $target_y < 1) {
        $error = "Diese Koordinaten gibt es nicht!";
    } else {
        // Check if the user already sent troops to that kingdom
        $result = $db_instance->execute_query("SELECT COUNT(*) AS alreadysent FROM events WHERE actionid = ? AND userid = ? AND targetx = ? AND targety = ?",
            [ACTION_SEND_TROOPS, $user->get_user_id(), $target_x, $target_y]);
        $already_sent = $result->fetch_assoc()["alreadysent"];

        if ($already_sent > 0) {
            $error = "Du hast bereits Truppen zu diesem Feld/Königreich geschickt!";
        } else {
            // Get soldier data
            $soldiers = [];
            $result = $db_instance->execute_query("SELECT id, soldiername, attack, defense FROM soldierlist");

            foreach ($result as $row) {
                $soldier = new Soldier();
                $soldier->set_soldier_id($row["id"]);
                $soldier->set_soldier_name($row["soldiername"]);
                $soldier->set_soldier_attack($row["attack"]);
                $soldier->set_soldier_defense($row["defense"]);

                $soldiers[] = $soldier;
            }

            // Get soldiers from kingdom
            $result = $db_instance->execute_query("SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?", [$user->get_current_kingdom()]);

            foreach ($result as $row) {
                $soldier_id = $row['soldierid'] ?? -1;
                $sol_count = $row['soldiercount'] ?? 0;
                $kingdom_soldiers[$soldier_id] = $sol_count;
            }

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

                if (!$has_soldiers) {
                    $view .= show_error_box("Du musst mindestens einen Soldaten auswählen!");
                } else if (empty($error)) {
                    $db_instance->execute_query(
                        "INSERT INTO events (actionid, userid, kingdomid, targetid, targetx, targety, arrivaltime) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [ACTION_SEND_TROOPS, $user->get_user_id(), $user->get_current_kingdom(), $kingdom_id, $target_x, $target_y, time() + 10]
                    );
                    $event_id = $db_instance->insert_id;

                    foreach ($_POST["soldiers"] as $soldier_id => $count) {
                        $soldier_id = intval($soldier_id);
                        $soldier_count = intval($count);

                        if ($soldier_count > 0) {
                            // Insert troop record
                            $db_instance->execute_query(
                                "INSERT INTO senttroops (eventid, soldierid, soldiercount) VALUES (?, ?, ?)",
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
                        $view .= show_passed_box("Truppen erfolgreich gesendet!");
                    }
                }
            }

            // Get target kingdom
            $result = $db_instance->execute_query("SELECT * FROM kingdoms WHERE mapx = ? AND mapy = ?", [$target_x, $target_y]);
            $row = $result->fetch_assoc();

            // Get field info
            $query = "
                    SELECT m.fieldtype, f.fieldname FROM map m
                    JOIN fieldtypes f ON m.fieldtype = f.fieldid
                    WHERE mapx = ? AND mapy = ?
            ";
            $result2 = $db_instance->execute_query($query, [$target_x, $target_y]);
            $field_name = $result2->fetch_assoc()["fieldname"];

            // Get users kingdom
            $result3 = $db_instance->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$user->get_current_kingdom()]);
            $row2 = $result3->fetch_assoc();
            $x = $row2["mapx"];
            $y = $row2["mapy"];

            if ($target_x == $kingdom->get_kingdom_map_x() && $target_y == $kingdom->get_kingdom_map_y()) {
                $error = "Das ist dein aktuelles Königreich!";
            } else {
                if ($row) {
                    if ($row["userid"] == $user->get_user_id()) {
                        $send_title = "Truppen stationieren";
                    } else {
                        $send_title = "Königreich angreifen";
                    }
                    $view .= '<div style="border-bottom: 2px solid rgba(0, 0, 0, 0.5); width: 50%; margin: auto; line-height: 40px;">Königreich-Info (' . $field_name . ')</div>
                      <table class="table" style="margin-top: 20px; max-width: 400px; text-align: left;">
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
                              <td><a href="javascript:void(0);" onclick="openPopup(\'userinfo.php?userid=' . $row["userid"] . '\');">' . $row["username"] . '</a></td>
                          </tr>
                          <tr>
                              <td class="td-mapinfo"><b>Ankunftszeit</b></td>
                              <td>' . convert_sec_to_str($arrival_time) . '</td>
                          </tr>
                      ';
                } else {
                    $view .= '<div style="border-bottom: 2px solid rgba(0, 0, 0, 0.5); width: 50%; margin: auto; line-height: 40px;">' . $field_name . '</div>
                      <table class="table" style="margin-top: 20px; max-width: 400px; text-align: left;">
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

                // Show users soldiers
                $view .= '<form action="sendtroops.php?x=' . $target_x . '&y=' . $target_y . '" method="POST">
                <table class="table" style="max-width: 400px;">
                <tr>
                    <td class="td-center td-gradient">Soldat</td>
                    <td class="td-center td-gradient">Anzahl</td>
                </tr>';

                for ($i = 0; $i < count($soldiers); $i++) {
                    $soldier_name = $soldiers[$i]->get_soldier_name();

                    $view .= "<tr>
                <td>
                    <div class='image-and-user' style='margin-bottom: 5px;'>" . $soldiers[$i]->get_soldier_icon() . " <b>" . $soldier_name . " (" . (isset($kingdom_soldiers[$i]) ? fnum($kingdom_soldiers[$i]) : 0) . ")</b>
                    </div>
                    <div class='split-content' style='width: 104px;'>
                        <div>
                            <img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff'> " . $soldiers[$i]->get_soldier_attack() . "
                        </div>
                        <div style='margin-left: 15px;'>
                            <img src='images/icons/icon_shield.png' class='ressource-icons' alt='Verteidigung'> " . $soldiers[$i]->get_soldier_defense() . "
                        </div>
                    </div>
                </td>
                <td class='td-center'>
                    <input type='text' id='" . $i . "' name='soldiers[" . $i . "]' size='5' maxlength='6'>
                </td>
              </tr>";
                }

                $view .= '</table>
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
$script_files = ["userinfo"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include('layout/base.php');
<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_BARRACKS);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

$kingdom_food = $kingdom->get_kingdom_food();
$kingdom_gold = $kingdom->get_kingdom_gold();
$kingdom_villager = $kingdom->get_kingdom_villager();
$s_id = (empty($_GET["recruit"]) ? 0 : $_GET["recruit"]);
$kingdom_recruiting_id = -1;
$kingdom_is_recruiting = $kingdom->is_kingdom_recruiting($current_kingdom);

if ($kingdom_is_recruiting) {
    $kingdom_recruiting_id = $kingdom->get_kingdom_recruiting_id();
}

// Get all soldier types from the database
$result = $db_instance->execute_query("SELECT * FROM soldierlist");

foreach ($result as $row) {
    $soldier = new Soldier();
    $soldier->set_soldier_id($row["id"]);
    $soldier->set_soldier_name($row["soldiername"]);
    $soldier->set_soldier_description($row["description"]);
    $soldier->set_soldier_attack($row["attack"]);
    $soldier->set_soldier_defense($row["defense"]);
    $soldier->set_soldier_food_cost($row["food"]);
    $soldier->set_soldier_gold_cost($row["gold"]);
    $soldier->set_soldier_villager_cost($row["villager"]);
    $soldier->set_soldier_required_level($row["requiredlevel"]);
    $soldier->set_soldier_time($row["requiredtime"]);
    $soldier->set_soldier_score_gain($row["scoregain"]);

    $soldiers[] = $soldier;
}

$soldiers_count = count($soldiers);

if (isset($_GET["recruit"]) && isset($_GET["count"])) {
    if ($_GET["count"] == "cancel") {
        if ($kingdom_is_recruiting) {
            // Calculate remaining soldiers to be recruited and resulting refunds
            $result = $db_instance->execute_query("SELECT soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?", [$current_kingdom, ACTION_BUILD_TROOPS, $s_id]);
            $soldier_goal = $result->fetch_assoc()['soldiergoal'];

            // Refund player
            $kingdom->give_kingdom_food($soldier_goal * $soldiers[$s_id]->get_soldier_food_cost());
            $kingdom->give_kingdom_gold($soldier_goal * $soldiers[$s_id]->get_soldier_gold_cost());

            // Delete the job
            $db_instance->execute_query("DELETE FROM events WHERE userid = ? AND soldierid = ? AND kingdomid = ?", [$user->get_user_id(), $s_id, $current_kingdom]);
        } else {
            $error = "Du rekrutierst gerade nicht!";
        }
    } else {
        if ($kingdom_is_recruiting) {
            $error = "Du bist bereits am Rekrutieren!";
        } else if (!is_numeric($_GET["count"]) || $_GET["count"] < 1) {
            $error = "Keine Angabe der Anzahl!";
        } else if ($_GET["count"] > 99) {
            $error = "Maximale Anzahl beträgt 99!";
        } else if ($_GET["recruit"] < 0 || $_GET["recruit"] > $soldiers_count) {
            $error = "Diese Einheit existiert nicht!";
        } else {
            $cost_food = $soldiers[$s_id]->get_soldier_food_cost() * $_GET["count"];
            $cost_gold = $soldiers[$s_id]->get_soldier_gold_cost() * $_GET["count"];
            $cost_villager = $soldiers[$s_id]->get_soldier_villager_cost() * $_GET["count"];

            if ($cost_food > $kingdom_food) {
                $error = "Nicht genug Nahrung!";
            } else if ($cost_gold > $kingdom_gold) {
                $error = "Nicht genug Gold!";
            } else if ($cost_villager > $kingdom_villager) {
                $error = "Nicht genug Dorfbewohner!";
            } else {
                $current_time = time();
                $recruiting_time = $current_time + $soldiers[$s_id]->get_soldier_time() * $_GET["count"];

                $query = "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname, soldierid, recruittime, soldiergoal) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $db_instance->execute_query($query, [ACTION_BUILD_TROOPS, $user->get_user_id(), $current_kingdom, '0', $current_time, '0', '-', $s_id, $recruiting_time, $_GET["count"]]);

                // Subtract values for food and gold
                $kingdom->give_kingdom_food(-$cost_food);
                $kingdom->give_kingdom_gold(-$cost_gold);
            }
        }
    }
}

/*
 * HTML Content Part
 */
$kingdom_food = $kingdom->get_kingdom_food();
$kingdom_gold = $kingdom->get_kingdom_gold();
$kingdom_villager = $kingdom->get_kingdom_villager();
$last_recruited_soldier = $user->get_last_recruited_soldier($current_kingdom);

if (!empty($last_recruited_soldier)) {
    $soldier_name = $last_recruited_soldier["soldiername"];
    $soldier_count = $last_recruited_soldier["soldiercount"];

    $view .= show_weighted_box("$soldier_name (+$soldier_count)", "Ausbildung abgeschlossen:");

    $user->clear_last_recruited_soldier($current_kingdom);
}
$view .= '<table class="table">
                        <tr>
                            <td class="td-center td-gradient" colspan="2">
                                <b>Soldat</b></td>
                            <td class="td-center td-gradient">
                                <b>Aktion</b></td>
                        </tr>';
$kingdom_is_recruiting = $kingdom->is_kingdom_recruiting($current_kingdom);

if ($kingdom_is_recruiting) {
    $kingdom_recruiting_id = $kingdom->get_kingdom_recruiting_id();
}

// Get soldiers of kingdom
$result = $db_instance->execute_query("SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?", [$current_kingdom]);

foreach ($result as $row) {
    $soldier_id = $row['soldierid'] ?? -1;
    $sol_count = $row['soldiercount'] ?? 0;
    $kingdom_soldiers[$soldier_id] = $sol_count;
}

for ($i = 0; $i < $soldiers_count; $i++) {
    $cost_food = $soldiers[$i]->get_soldier_food_cost();
    $cost_gold = $soldiers[$i]->get_soldier_gold_cost();
    $cost_villager = $soldiers[$i]->get_soldier_villager_cost();

    $text_food = ($cost_food > $kingdom_food ? "<b class='error'>" . fnum($cost_food) . "</b>" : fnum($cost_food));
    $text_gold = ($cost_gold > $kingdom_gold ? "<b class='error'>" . fnum($cost_gold) . "</b>" : fnum($cost_gold));
    $text_villager = ($cost_villager > $kingdom_villager ? "<b class='error'>" . fnum($cost_villager) . "</b>" : fnum($cost_villager));

    if ($kingdom_is_recruiting) {
        if ($kingdom_recruiting_id == $i) {
            $result = $db_instance->execute_query("SELECT recruittime, soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?", [$current_kingdom, ACTION_BUILD_TROOPS, $i]);
            $row = $result->fetch_assoc();
            $recruit_time = $row["recruittime"];
            $soldier_goal = $row["soldiergoal"];
            $soldier_time = $soldiers[$i]->get_soldier_time();
            $current_time = time();
            $total_difference = $recruit_time - $current_time;
            $remaining_time_in_seconds = max(0, $total_difference % $soldier_time);

            // Job was just started
            if ($remaining_time_in_seconds == 0) {
                $remaining_time_in_seconds = $soldiers[$i]->get_soldier_time();
            }

            $text_build = "In Ausbildung: " . $soldier_goal . "<br><br><b><span id='counter'></span></b><br> 
                          <script type='text/javascript'>
                                document.addEventListener('DOMContentLoaded', function () {
                                      let diff = $remaining_time_in_seconds;
                                      startCountdown(diff || 0);
                                });
                          </script>
                          <form action='barracks.php' method='GET'>
                            <input type='hidden' name='recruit' value='" . $i . "'>
                            <input type='hidden' name='count' value='cancel'>
                            <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                          </form>";
        } else {
            $text_build = "-";
        }
    } else {
        if ($cost_food > $kingdom_food || $cost_gold > $kingdom_gold) {
            $text_build = "Nicht genug Rohstoffe!";
        } else if ($kingdom_villager < $cost_villager) {
            $text_build = "Nicht genug Dorfbewohner!";
        } else {
            // Calculate the maximum soldiers recruitable based on each resource
            $food_cost_per_soldier = $soldiers[$i]->get_soldier_food_cost();
            $gold_cost_per_soldier = $soldiers[$i]->get_soldier_gold_cost();
            $villager_cost_per_soldier = $soldiers[$i]->get_soldier_villager_cost();
            $max_soldiers_food = floor($kingdom_food / $food_cost_per_soldier);
            $max_soldiers_gold = floor($kingdom_gold / $gold_cost_per_soldier);
            $max_soldiers_villagers = floor($kingdom_villager / $villager_cost_per_soldier);
            $max_recruit_val = min($max_soldiers_food, $max_soldiers_gold, $max_soldiers_villagers);
            $max_soldiers = min($max_recruit_val, 99);

            $text_build = "<form action='barracks.php?' method='GET'>
                            <input type='hidden' name='recruit' value='" . $i . "'>
                            <input type='text' name='count' id='count" . $i . "' size='2' maxlength='2'>
                            <input type='button' value='Max.' onclick='fillMax(\"" . $i . "\", \"" . $max_soldiers . "\")'><br>
                            <input type='submit' value='Ausbilden' style='margin-top: 10px'>
                        </form>
                        <script>
                            function fillMax(i, maxValue) {
                                document.getElementById('count' + i).value = maxValue;
                                return false;
                            }
                        </script>";
        }
    }

    $view .= "<tr>
                <td class='td-center' style='width: 10%;'>" . $soldiers[$i]->get_soldier_icon() . "</td>
                <td style='width: 40%;'><b class='popup' id='description" . $i . "'>" . $soldiers[$i]->get_soldier_name() . " 
                    <div id='description" . $i . "_box' class='popupbox'>" . $soldiers[$i]->get_soldier_description() . "</div>  (" . (isset($kingdom_soldiers[$i]) ? fnum($kingdom_soldiers[$i]) : 0) . ")</b><br><br>
                    <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung' title='Nahrung'/> " . $text_food . "
                    <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold' title='Gold'/> " . $text_gold . "
                    <img src='images/icons/icon_villager.png' class='ressource-icons' alt='Dorfbewohner' title='Dorfbewohner'/> " . $text_villager . "<br>
                    <img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff' title='Angriff'/> " . $soldiers[$i]->get_soldier_attack() . " 
                    <img src='images/icons/icon_shield.png' class='ressource-icons' alt='Verteidigung' title='Verteidigung'/> " . $soldiers[$i]->get_soldier_defense() . "<br>
                    <img src='images/icons/icon_time.png' class='ressource-icons' alt='Rekrutierzeit' title='Rekrutierzeit'/> " . convert_sec_to_str($soldiers[$i]->get_soldier_time()) . "
                    <br></td>
                <td class='td-center' style='width: 40%;'>$text_build</td>
            </tr>";
}
$view .= '</table>';

/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = ["counter"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include('layout/base.php');
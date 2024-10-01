<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$view = "Hier kannst du das Ergebnis eines Kampfes berechnen.<br><br>";

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

$soldiers_array = json_encode(array_map(function ($soldier) {
    return $soldier->get_soldier_name();
}, $soldiers));

$view .= '<table class="table">
    <tr>
        <td class="td-center td-gradient">Soldat</td>
        <td class="td-center td-gradient">Spieler</td>
        <td class="td-center td-gradient">Gegner</td>
    </tr>';

for ($i = 0; $i < count($soldiers); $i++) {
    $soldier_name = $soldiers[$i]->get_soldier_name();

    // Concatenate each soldier row to $view
    $view .= "<tr>
                <td>" . $soldiers[$i]->get_soldier_icon() . " " . $soldier_name . "<br>
                    <div class='split-content' style='width: 104px;'>
                        <div id='" . $soldier_name . "_atk' data-attack='" . $soldiers[$i]->get_soldier_attack() . "'>
                            <img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff'> " . $soldiers[$i]->get_soldier_attack() . "
                        </div>
                        <div id='" . $soldier_name . "_def' data-defense='" . $soldiers[$i]->get_soldier_defense() . "' style='margin-left: 15px;'>
                            <img src='images/icons/icon_shield.png' class='ressource-icons' alt='Verteidigung'> " . $soldiers[$i]->get_soldier_defense() . "
                        </div>
                    </div>
                </td>
                <td class='td-center'><input type='text' id='" . $soldier_name . "_own' name='" . $soldier_name . "_own' size='2' maxlength='3' value='0'></td>
                <td class='td-center'><input type='text' id='" . $soldier_name . "_enemy' name='" . $soldier_name . "_enemy' size='2' maxlength='3' value='0'></td>
              </tr>";
}

$view .= '</table>
    <button type="button" style="margin-top: 10px;" onclick="calculateWarOutcome();">Berechnen</button>
    <button type="button" onclick="resetFields();">Reset</button>';

$view .= '<script type="text/javascript">
    // Define soldier types
    let soldierTypes = ' . json_encode(array_map(function ($soldier) {
        return $soldier->get_soldier_name();
    }, $soldiers)) . ';

    function resetFields() {
        soldierTypes.forEach(type => {
            document.getElementById(type + "_own").value = 0;
            document.getElementById(type + "_own").style.color = "inherit";
            document.getElementById(type + "_enemy").value = 0;
            document.getElementById(type + "_enemy").style.color = "inherit";
        });
    }

    function calculateWarOutcome() {
        let mySoldiers = {};
        let enemySoldiers = {};
        let myTotalATK = {};
        let myTotalDEF = {};
        let enemyTotalATK = {};
        let enemyTotalDEF = {};
        let soldierTypeATK = {};
        let soldierTypeDEF = {};

        // Initialize totals for each soldier type
        soldierTypes.forEach(type => {
            mySoldiers[type] = [];
            enemySoldiers[type] = [];
            myTotalATK[type] = 0;
            myTotalDEF[type] = 0;
            enemyTotalATK[type] = 0;
            enemyTotalDEF[type] = 0;
            soldierTypeATK[type] = 0;
            soldierTypeDEF[type] = 0;
        });

        // Collect input values and calculate total ATK and DEF for each soldier type
        soldierTypes.forEach(type => {
            mySoldiers[type] = parseInt(document.getElementById(`${type}_own`).value);
            enemySoldiers[type] = parseInt(document.getElementById(`${type}_enemy`).value);

            myTotalATK[type] += mySoldiers[type] * parseInt(document.getElementById(`${type}_atk`).getAttribute("data-attack"));
            myTotalDEF[type] += mySoldiers[type] * parseInt(document.getElementById(`${type}_def`).getAttribute("data-defense"));
            enemyTotalATK[type] += enemySoldiers[type] * parseInt(document.getElementById(`${type}_atk`).getAttribute("data-attack"));
            enemyTotalDEF[type] += enemySoldiers[type] * parseInt(document.getElementById(`${type}_def`).getAttribute("data-defense"));

            soldierTypeATK[type] = document.getElementById(`${type}_atk`).getAttribute("data-attack");
            soldierTypeDEF[type] = document.getElementById(`${type}_def`).getAttribute("data-defense");
        });

        soldierTypes.forEach(attackerType => {
            if (mySoldiers[attackerType] > 0) {
                soldierTypes.forEach(defenderType => {
                    if (enemySoldiers[defenderType] > 0) {
                        const outcomeForMe = Math.ceil(Math.max(myTotalATK[attackerType] - enemyTotalDEF[defenderType], 0) / soldierTypeATK[attackerType]);
                        const outcomeForEnemy = Math.ceil(Math.max(enemyTotalDEF[defenderType] - myTotalATK[attackerType], 0) / soldierTypeDEF[defenderType]);

                        // Update the input fields with the new values for each soldier type
                        mySoldiers[attackerType] = outcomeForMe;
                        enemySoldiers[defenderType] = outcomeForEnemy;
                        document.getElementById(`${attackerType}_own`).value = mySoldiers[attackerType];
                        document.getElementById(`${defenderType}_enemy`).value = enemySoldiers[defenderType];

                        // Recalculate total ATK for type
                        myTotalATK[attackerType] = mySoldiers[attackerType] * parseInt(document.getElementById(`${attackerType}_atk`).getAttribute("data-attack"));

                        // Change text color based on losses
                        document.getElementById(`${attackerType}_own`).style.color = outcomeForMe > 0 ? "#F55353" : "inherit";
                        document.getElementById(`${defenderType}_enemy`).style.color = outcomeForEnemy > 0 ? "#F55353" : "inherit";
                    }
                });
            }
        });
    }
</script>';

/*
 * HTML Section
 */
$title = "War Simulator";
$header = "War Simulator";

include('layout/base.php');
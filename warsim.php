<?php
require_once("includes/core.php");

check_user_login($user);

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
    <script type="text/javascript">
        let soldierTypes = ' . json_encode(array_map(function ($soldier) {
        return $soldier->get_soldier_name();
    }, $soldiers)) . ';
    </script>
    <button type="button" style="margin-top: 10px;" onclick="calculateWarOutcome(soldierTypes)">Berechnen</button>
    <button type="button" onclick="resetFields(soldierTypes)">Reset</button>';


/*
 * HTML Section
 */
$title = "War Simulator";
$header = "War Simulator";
$script_files = ["warsim"];

include('layout/base.php');
<?php
global $db_instance, $user;
require_once("functions.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php", 0);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/header.php");
?>
<div class="content">
    <div class="content-box">
        <div class="left-container">
            <?php
            include_once("layout/left.php");
            ?>
        </div>

        <div class="middle-container">
            <div class="big-box-container">
                <div class="big-box-header"><p>War Simulator</p></div>
                <div class="big-box-content">
                    <?php
                    echo "Hier kannst du das Ergebnis eines Kampfes berechnen.<br><br>";

                    $soldiers = [];
                    $result = $db_instance->query("SELECT id, soldiername, attack, defense FROM soldierlist");

                    while ($row = $result->fetch_assoc()) {
                        $soldier = new Soldier();
                        $soldier->setSoldierID($row["id"]);
                        $soldier->setSoldierName($row["soldiername"]);
                        $soldier->setSoldierAttack($row["attack"]);
                        $soldier->setSoldierDefense($row["defense"]);

                        $soldiers[] = $soldier;
                    }
                    $result->close();
                    ?>
                    <table class="table">
                        <tr>
                            <td class="td-center td-gradient">Soldat</td>
                            <td class="td-center td-gradient">Eigene Truppen</td>
                            <td class="td-center td-gradient">Gegner Truppen</td>
                        </tr>
                        <?php
                        $soldierCount = count($soldiers);

                        for ($i = 0; $i < $soldierCount; $i++) {
                            $soldierName = $soldiers[$i]->getSoldierName();

                            echo "<tr>
                                        <td>" . $soldiers[$i]->getSoldierIcon() . " " . $soldierName . "<br>
                                        <div class='split-content' style='width: 104px;'>
                                            <div id='" . $soldierName . "_atk' data-attack='" . $soldiers[$i]->getSoldierAttack() . "'><img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff'> " . $soldiers[$i]->getSoldierAttack() . "</div>
                                            <div id='" . $soldierName . "_def' data-defense='" . $soldiers[$i]->getSoldierDefense() . "' style='margin-left: 15px;'><img src='images/icons/icon_shield.png' class='ressource-icons' alt='Verteidigung'> " . $soldiers[$i]->getSoldierDefense() . "</div>
                                        </div>
                                        </td>
                                        <td class='td-center'><input type='text' id='" . $soldierName . "_own' name='" . $soldierName . "_own' size='2' maxlength='3' value='0'></td>
                                        <td class='td-center'><input type='text' id='" . $soldierName . "_enemy' name='" . $soldierName . "_enemy' size='2' maxlength='3' value='0'></td>
                                        </tr>";
                        }
                        ?>
                    </table>
                    <button type="submit" style="margin-top: 10px;" onclick="calculateWarOutcome();">
                        Berechnen
                    </button>
                    <button type="submit" onclick="resetFields();">
                        Reset
                    </button>
                    <script>
                        // Define soldier types
                        const soldierTypes = <?php echo json_encode(array_map(function ($soldier) {
                            return $soldier->getSoldierName();
                        }, $soldiers)); ?>;

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
                                soldierTypeDEF[type] = document.getElementById(`${type}_def`).getAttribute("data-defense")
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

                        /*const soldierCount = <?php echo $soldierCount ?>;

                        function resetFields() {
                            for (let i = 0; i < soldierCount; i++) {
                                document.getElementById("own_" + i).value = 0;
                                document.getElementById("own_" + i).style.color = "inherit";
                                document.getElementById("enemy_" + i).value = 0;
                                document.getElementById("enemy_" + i).style.color = "inherit";
                            }
                        }

                        function calculateWarOutcome() {
                            let mySoldiers = [];
                            let enemySoldiers = [];
                            let myTotalATK = 0;
                            let myTotalDEF = 0;
                            let enemyTotalATK = 0;
                            let enemyTotalDEF = 0;

                            // Collect input values and calculate total ATK and DEF
                            for (let i = 0; i < soldierCount; i++) {
                                mySoldiers[i] = parseInt(document.getElementById("own_" + i).value);
                                enemySoldiers[i] = parseInt(document.getElementById("enemy_" + i).value);

                                myTotalATK += mySoldiers[i] * parseInt(document.getElementById("atk_" + i).getAttribute("data-attack"));
                                myTotalDEF += mySoldiers[i] * parseInt(document.getElementById("def_" + i).getAttribute("data-defense"));
                                enemyTotalATK += enemySoldiers[i] * parseInt(document.getElementById("atk_" + i).getAttribute("data-attack"));
                                enemyTotalDEF += enemySoldiers[i] * parseInt(document.getElementById("def_" + i).getAttribute("data-defense"));
                            }

                            // Validate input values (non-negative integers)
                            if (mySoldiers.some(count => isNaN(count) || count < 0) || enemySoldiers.some(count => isNaN(count) || count < 0)) {
                                return;
                            }

                            // Calculate damage inflicted by my soldiers
                            const myDamage = Math.max(myTotalATK - enemyTotalDEF, 0);
                            // Calculate damage inflicted by enemy soldiers
                            const enemyDamage = Math.max(enemyTotalATK - myTotalDEF, 0);

                            // Calculate and subtract losses
                            for (let i = 0; i < soldierCount; i++) {
                                // Subtract losses from each side
                                const myLosses = Math.min(enemyDamage, mySoldiers[i]);
                                const enemyLosses = Math.min(myDamage, enemySoldiers[i]);

                                // Update the input fields with the new values
                                document.getElementById("own_" + i).value = mySoldiers[i] - myLosses;
                                document.getElementById("enemy_" + i).value = enemySoldiers[i] - enemyLosses;

                                // Change text color based on losses
                                document.getElementById("own_" + i).style.color = myLosses > 0 ? "#F55353" : "inherit";
                                document.getElementById("enemy_" + i).style.color = enemyLosses > 0 ? "#F55353" : "inherit";
                            }
                        }*/
                    </script>
                </div>
            </div>
        </div>

        <div class="right-container">
            <?php
            include_once("layout/right.php");
            ?>
        </div>
    </div>
</div>
<?php
include_once("layout/footer.php");
?>
</body>
</html>

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
                            echo "<tr>
                                        <td>" . $soldiers[$i]->getSoldierIcon() . " " . $soldiers[$i]->getSoldierName() . "<br>
                                        <div class='split-content' style='width: 104px;'>
                                            <div id='atk_" . $i . "' data-attack='" . $soldiers[$i]->getSoldierAttack() . "'><img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff'> " . $soldiers[$i]->getSoldierAttack() . "</div>
                                            <div id='def_" . $i . "' data-defense='" . $soldiers[$i]->getSoldierDefense() . "' style='margin-left: 15px;'><img src='images/icons/icon_shield.png' class='ressource-icons' alt='Verteidigung'> " . $soldiers[$i]->getSoldierDefense() . "</div>
                                        </div>
                                        </td>
                                        <td class='td-center'><input type='text' id='own_" . $i . "' name='own" . $i . "' size='2' maxlength='3' value='0'></td>
                                        <td class='td-center'><input type='text' id='enemy_" . $i . "' name='enemy" . $i . "' size='2' maxlength='3' value='0'></td>
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
                        const soldierCount = <?php echo $soldierCount ?>;

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
                        }
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

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
                <?php
                $kingdom = new Kingdoms($db_instance);
                $kingdom->getKingdomRessources($_SESSION["kingdomid"]);
                $mysqli = $db_instance;
                $buildings = new Buildings($db_instance);

                $kID = $_SESSION["kingdomid"];
                $bID = (empty($_GET["bid"]) ? 0 : $_GET["bid"]);
                ?>
                <div class="big-box-header">
                    <p>
                        <?php
                        $error = null;

                        if (isset($_GET["bid"]) && ($bID < 0 || $bID > $buildings->getBuildingCount())) {
                            echo "Fehler";
                            $error = "Das Gebäude existiert nicht!";
                        } else {
                            echo(isset($_GET["action"]) ? $buildings->getBuildingName(0) : $buildings->getBuildingName($bID));
                        }
                        ?>
                    </p>
                </div>
                <div class="big-box-content">
                    <?php
                    $kingdomWood = $kingdom->getKingdomWood();
                    $kingdomFood = $kingdom->getKingdomFood();
                    $kingdomStone = $kingdom->getKingdomStone();
                    $kingdomGold = $kingdom->getKingdomGold();

                    $kingdomIsBuilding = false;
                    $kingdomBuildingID = -1;

                    // An action is set via URL
                    if (isset($_GET["action"])) {
                        $kingdomIsBuilding = $kingdom->isKingdomBuilding($kID);
                        if ($kingdomIsBuilding) {
                            $kingdomBuildingID = $kingdom->getKingdomBuildingID();
                        }

                        if ($bID >= 0 && $bID < $buildings->getBuildingCount()) {
                            $buildingLevel = $buildings->getBuildingLevel($bID, $kID);
                            $costs = $buildings->calculateBuildingCost($buildings, $bID, $buildingLevel);
                            $costWood = $costs["costWood"];
                            $costFood = $costs["costFood"];
                            $costStone = $costs["costStone"];
                            $costGold = $costs["costGold"];

                            // The action that was set is "building"
                            if ($_GET["action"] == "build") {
                                if ($buildingLevel >= MAX_BUILDING_LEVEL) {
                                    $error = "Das Gebäude ist schon maximal ausgebaut!";
                                } else if ($costWood > $kingdomWood || $costFood > $kingdomFood || $costStone > $kingdomStone || $costGold > $kingdomGold) {
                                    $error = "Nicht genügend Ressourcen!";
                                } else {
                                    if ($kingdomIsBuilding) {
                                        $error = "Du baust bereits!";
                                    } else {
                                        $towncenterLevel = $buildings->getBuildingLevel(1, $kID);
                                        $requiredLevel = $buildings->getBuildingRequiredLevel($bID);

                                        // Town center level is equal or higher than required level... build!
                                        if ($towncenterLevel >= $requiredLevel) {
                                            $buildingTime = time() + $buildings->getBuildingTime($bID) * ($buildingLevel == 0 ? 1 : $buildingLevel + 1);

                                            $kingdom->setKingdomWood($kID, $kingdom->getKingdomWood() - $costWood);
                                            $kingdom->setKingdomFood($kID, $kingdom->getKingdomFood() - $costFood);
                                            $kingdom->setKingdomStone($kID, $kingdom->getKingdomStone() - $costStone);
                                            $kingdom->setKingdomGold($kID, $kingdom->getKingdomGold() - $costGold);

                                            $mysqli->query("INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname) VALUES('" . ACTION_BUILD_BUILDING . "', '{$user->getUserID()}', '$kID', '$bID', '$buildingTime', '{$buildings->getBuildingLevel($bID, $kID)}', '{$buildings->getBuildingName($bID)}');");

                                            $_SESSION["buildingID"] = $bID;
                                        } else {
                                            $error = "Das benötigte Level ist höher als das Level vom Dorfzentrum!";
                                        }
                                    }
                                }
                            } else if ($_GET["action"] == "cancel") { // The action that was set is "cancel building"
                                if ($kingdomIsBuilding) {
                                    $mysqli->query("DELETE FROM events WHERE userid = '{$user->getUserID()}' AND buildingid = '$bID'");

                                    // Refund the player
                                    $kingdom->setKingdomWood($kID, $kingdom->getKingdomWood() + $costWood);
                                    $kingdom->setKingdomFood($kID, $kingdom->getKingdomFood() + $costFood);
                                    $kingdom->setKingdomStone($kID, $kingdom->getKingdomStone() + $costStone);
                                    $kingdom->setKingdomGold($kID, $kingdom->getKingdomGold() + $costGold);
                                } else {
                                    $error = "Du baust gerade nichts!";
                                }
                            } else {
                                $error = "Diese Aktion existiert nicht!";
                            }
                        }
                    } else {
                        if ($bID >= 0 && $bID < $buildings->getBuildingCount()) {
                            if ($buildings->isBuilt($kID, $bID)) {
                                echo $buildings->showBuildingInfo($bID, $kID, $kingdom);
                            } else {
                                $error = "Das Gebäude wurde noch nicht gebaut!";
                            }
                        } else {
                            $error = "Das Gebäude existiert nicht!";
                        }
                    }

                    if ($error != null) {
                        echo $error;
                        changeLocation("buildings.php?bid=0", 2);
                    } else {
                        if ($bID == 0 || (isset($_GET["action"]) && $_GET["action"] == "build") || (isset($_GET["action"]) && $_GET["action"] == "cancel")) {
                            $towncenterlevel = $buildings->getBuildingLevel(0, $kID);

                            $kingdomIsBuilding = $kingdom->isKingdomBuilding($kID);
                            if ($kingdomIsBuilding) {
                                $kingdomBuildingID = $kingdom->getKingdomBuildingID();
                            }
                            ?>
                            <table class="table">
                            <tr>
                                <td class="td-center td-gradient"
                                    style="width: 5%;">
                                    <b></b></td>
                                <td class="td-center td-gradient"
                                    style="width: 35%;">
                                    <b>Gebäude</b></td>
                                <td class="td-center td-gradient"
                                    style="width: 30%;">
                                    <b>Aktion</b></td>
                            </tr>
                            <?php
                            for ($i = 0; $i < $buildings->getBuildingCount(); $i++) {
                                if ($towncenterlevel >= $buildings->getBuildingRequiredLevel($i)) {
                                    $level = $buildings->getBuildingLevel($i, $kID);

                                    if ($level < MAX_BUILDING_LEVEL) {
                                        if (!is_numeric($level)) {
                                            $level = "0";
                                        }

                                        $costs = $buildings->calculateBuildingCost($buildings, $i, $level);
                                        $costWood = $costs["costWood"];
                                        $costFood = $costs["costFood"];
                                        $costStone = $costs["costStone"];
                                        $costGold = $costs["costGold"];

                                        /*$textWood = ($costWood > $kingdomWood ? "<b class='error'>" . $costWood . "</b>" : $costWood);
                                        $textFood = ($costFood > $kingdomFood ? "<b class='error'>" . $costFood . "</b>" : $costFood);
                                        $textStone = ($costStone > $kingdomStone ? "<b class='error'>" . $costStone . "</b>" : $costStone);
                                        $textGold = ($costGold > $kingdomGold ? "<b class='error'>" . $costGold . "</b>" : $costGold);*/
                                        $textWood = $buildings->getRessourceText($costWood, $kingdomWood);
                                        $textFood = $buildings->getRessourceText($costFood, $kingdomFood);
                                        $textStone = $buildings->getRessourceText($costStone, $kingdomStone);
                                        $textGold = $buildings->getRessourceText($costGold, $kingdomGold);
                                        $textBuild = "";

                                        if ($kingdomIsBuilding) {
                                            if ($kingdomBuildingID == $i) {
                                                $stmt = $mysqli->prepare("SELECT buildingtime FROM events WHERE kingdomid = ? AND buildingid = ? AND actionid = ?");
                                                $action = ACTION_BUILD_BUILDING;
                                                $stmt->bind_param('iii', $kID, $i, $action);
                                                $stmt->execute();
                                                $stmt->bind_result($buildTime);
                                                $stmt->fetch();
                                                $stmt->close();

                                                $differenceTime = $buildTime - time();

                                                $textBuild = "<b><span id='counter'></span></b><br>
                                                              <script type='text/javascript'>
                                                                diff = " . json_encode($differenceTime) . "
                                                                startCountdown(diff);
                                                              </script>
                                                              <form action='buildings.php' method='GET'>
                                                                <input type='hidden' name='action' value='cancel'>
                                                                <input type='hidden' name='bid' value='" . $i . "'>
                                                                <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                                                              </form>";
                                            } else {
                                                $textBuild = "-";
                                            }
                                        } else {
                                            if ($costWood > $kingdomWood || $costFood > $kingdomFood || $costStone > $kingdomStone || $costGold > $kingdomGold) {
                                                $textBuild = "Nicht genug Rohstoffe!";
                                            } else {
                                                // $textBuild = "<a href='buildings.php?action=build&bid=" . $i . "'>Aufrüsten</a>";
                                                $textBuild = "<form action='buildings.php' method='GET'>
                                                                    <input type='hidden' name='action' value='build'>
                                                                    <input type='hidden' name='bid' value='" . $i . "'>
                                                                    <input type='submit' value='" . ($level > 0 ? "Aufrüsten" : "Bauen") . "'>
                                                                  </form>";
                                            }
                                        }

                                        echo "<tr><td class='td-center' style='width: 10%;'>" . $buildings->getBuildingIcon($i) . "</td>";
                                        echo "<td style='width: 40%;'><b>" . $buildings->getBuildingName($i) . " (" . $level . ")</b><br><br>";
                                        echo "<img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz'> " . $textWood . "   <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung'> " . $textFood . "<br>";
                                        echo "<img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein'> " . $textStone . "    <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold'> " . $textGold . "<br>";
                                        echo "<img src='images/icons/icon_hammer.png' class='ressource-icons' alt='Bauzeit'> " . convertSecToStr($buildings->getBuildingTime($i) * ($level == 0 ? 1 : $level + 1)) . "<br><br></td><td class='td-center' style='width: 40%;'>" . $textBuild . "</td></tr>";
                                    }
                                }
                            }
                        }
                        ?>
                        </table>
                        <?php
                    }
                    ?>
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

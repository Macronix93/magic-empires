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
                $kID = $_SESSION["kingdomid"];

                $mysqli = $db_instance;

                // Get all available buildings and create objects for them
                $buildings = [];
                $result = $mysqli->query("SELECT * FROM buildinglist");

                while ($row = $result->fetch_assoc()) {
                    $building = new Building($mysqli);
                    $building->setBuildingID($row["id"]);
                    $building->setBuildingKingdomID($kID);
                    $building->setBuildingName($row["buildingname"]);
                    $building->setBuildingScore($row["buildingscore"]);
                    $building->setBuildingWoodCost($row["woodcost"]);
                    $building->setBuildingFoodCost($row["foodcost"]);
                    $building->setBuildingStoneCost($row["stonecost"]);
                    $building->setBuildingGoldCost($row["goldcost"]);
                    $building->setBuildingMult($row["multiplicator"]);
                    $building->setBuildingTime($row["timetobuild"]);
                    $building->setBuildingRequiredLevel($row["requiredlevel"]);
                    $building->setBuildingLevel();

                    $buildings[] = $building;
                }
                $result->close();

                $buildingcount = count($buildings);
                $bID = (empty($_GET["bid"]) ? 0 : $_GET["bid"]);
                $error = null;
                ?>
                <div class="big-box-header">
                    <p>
                        <?php
                        if (isset($_GET["bid"]) && ($bID < 0 || $bID > $buildingcount)) {
                            changeLocation("buildings.php?bid=0", 0);
                        } else {
                            echo(isset($_GET["action"]) ? $buildings[0]->getBuildingName() : $buildings[$bID]->getBuildingName());
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

                        if ($bID >= 0 && $bID < $buildingcount) {
                            $buildingLevel = $buildings[$bID]->getBuildingLevel();
                            $costs = $buildings[$bID]->calculateBuildingCost();
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
                                        $towncenterLevel = $buildings[0]->getBuildingLevel();
                                        $requiredLevel = $buildings[$bID]->getBuildingRequiredLevel();

                                        // Town center level is equal or higher than required level... build!
                                        if ($towncenterLevel >= $requiredLevel) {
                                            $buildingTime = time() + $buildings[$bID]->getBuildingTime() * ($buildingLevel == 0 ? 1 : $buildingLevel + 1);

                                            $kingdom->setKingdomWood($kID, $kingdom->getKingdomWood() - $costWood);
                                            $kingdom->setKingdomFood($kID, $kingdom->getKingdomFood() - $costFood);
                                            $kingdom->setKingdomStone($kID, $kingdom->getKingdomStone() - $costStone);
                                            $kingdom->setKingdomGold($kID, $kingdom->getKingdomGold() - $costGold);

                                            $mysqli->query("INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname) VALUES('" . ACTION_BUILD_BUILDING . "', '{$user->getUserID()}', '$kID', '$bID', '$buildingTime', '{$buildings[$bID]->getBuildingLevel()}', '{$buildings[$bID]->getBuildingName()}');");

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
                        if ($bID >= 0 && $bID < $buildingcount) {
                            if ($buildings[$bID]->isBuilt()) {
                                // Show building specific infos
                                switch ($bID) {
                                    case 1:
                                        // Universität
                                        break;
                                    case 2:
                                        // Kaserne
                                        $soldiers = [];
                                        $result = $mysqli->query("SELECT * FROM soldierlist");

                                        while ($row = $result->fetch_assoc()) {
                                            $soldier = new Soldier();
                                            $soldier->setSoldierID($row["id"]);
                                            $soldier->setSoldierName($row["soldiername"]);
                                            $soldier->setSoldierDescription($row["description"]);
                                            $soldier->setSoldierAttack($row["attack"]);
                                            $soldier->setSoldierDefense($row["defense"]);
                                            $soldier->setSoldierFoodCost($row["food"]);
                                            $soldier->setSoldierGoldCost($row["gold"]);
                                            $soldier->setSoldierVillagerCost($row["villager"]);
                                            $soldier->setSoldierRequiredLevel($row["requiredlevel"]);
                                            $soldier->setSoldierTime($row["requiredtime"]);
                                            $soldier->setSoldierScoreGain($row["scoregain"]);

                                            $soldiers[] = $soldier;
                                        }
                                        $result->close();
                                        $soldiercount = count($soldiers);

                                        $kingdomFood = $kingdom->getKingdomFood();
                                        $kingdomGold = $kingdom->getKingdomGold();
                                        $kingdomVillager = $kingdom->getKingdomVillager();

                                        $kID = $_SESSION["kingdomid"];
                                        $sID = (empty($_GET["recruit"]) ? 0 : $_GET["recruit"]);

                                        $kingdomRecruitingID = -1;
                                        $error = null;

                                        $kingdomIsRecruiting = $kingdom->isKingdomRecruiting($kID);
                                        if ($kingdomIsRecruiting) {
                                            $kingdomRecruitingID = $kingdom->getKingdomRecruitingID();
                                        }

                                        if (isset($_GET["recruit"]) && isset($_GET["count"])) {
                                            if ($_GET["count"] == "cancel") {
                                                if ($kingdomIsRecruiting) {
                                                    // Calculate remaining soldiers to be recruited and resulting refunds
                                                    $stmt = $mysqli->prepare("SELECT soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?");
                                                    $action = ACTION_BUILD_TROOPS;
                                                    $soldiergoal = 0;
                                                    $stmt->bind_param('iii', $kID, $action, $sID);
                                                    $stmt->execute();
                                                    $stmt->bind_result($soldiergoal);
                                                    $stmt->fetch();
                                                    $stmt->close();

                                                    // Refund player
                                                    $kingdomFood = $kingdom->setKingdomFood($kID, $kingdom->getKingdomFood() + $soldiergoal * $soldiers[$sID]->getSoldierFoodCost());
                                                    $kingdomGold = $kingdom->setKingdomGold($kID, $kingdom->getKingdomGold() + $soldiergoal * $soldiers[$sID]->getSoldierGoldCost());

                                                    // Delete the job
                                                    $mysqli->query("DELETE FROM events WHERE userid = '{$user->getUserID()}' AND soldierid = '$sID' AND kingdomid = '$kID'");
                                                } else {
                                                    $error = "Du rekrutierst gerade nicht!";
                                                }
                                            } else {
                                                if ($kingdomIsRecruiting) {
                                                    $error = "Du bist bereits am rekrutieren!";
                                                } else if (!is_numeric($_GET["count"]) || $_GET["count"] < 1) {
                                                    $error = "Keine Angabe der Anzahl!";
                                                } else if ($_GET["count"] > 99) {
                                                    $error = "Maximale Anzahl beträgt 99!";
                                                } else if ($_GET["recruit"] < 0 || $_GET["recruit"] > $soldiercount) {
                                                    $error = "Diese Einheit existiert nicht!";
                                                } else {
                                                    $costFood = $soldiers[$sID]->getSoldierFoodCost() * $_GET["count"];
                                                    $costGold = $soldiers[$sID]->getSoldierGoldCost() * $_GET["count"];
                                                    $costVillager = $soldiers[$sID]->getSoldierVillagerCost() * $_GET["count"];

                                                    if ($costFood > $kingdomFood) {
                                                        $error = "Nicht genug Nahrung!";
                                                    } else if ($costGold > $kingdomGold) {
                                                        $error = "Nicht genug Gold!";
                                                    } else if ($costVillager > $kingdomVillager) {
                                                        $error = "Nicht genug Dorfbewohner!";
                                                    } else {
                                                        $currenttime = time();
                                                        $recruitingtime = $currenttime + $soldiers[$sID]->getSoldierTime() * $_GET["count"];

                                                        // TODO: Maybe prepare this query?!
                                                        $mysqli->query("INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname, soldierid, recruittime, soldiergoal) 
                                                VALUES('" . ACTION_BUILD_TROOPS . "', '{$user->getUserID()}', '$kID', '0', '0', '0', '-', '$sID', '" . $recruitingtime . "', " . $_GET["count"] . ");");

                                                        // Subtract values for food and gold
                                                        $kingdom->setKingdomFood($kID, $kingdom->getKingdomFood() - $costFood);
                                                        $kingdom->setKingdomGold($kID, $kingdom->getKingdomGold() - $costGold);
                                                    }
                                                }
                                            }
                                        }
                                        if ($error != null) {
                                            echo $error . "<br><br>";
                                        }
                                        ?>
                                        <table class="table">
                                            <tr>
                                                <td class="td-center td-gradient"
                                                    style="width: 5%;">
                                                    <b></b></td>
                                                <td class="td-center td-gradient"
                                                    style="width: 35%;">
                                                    <b>Soldat</b></td>
                                                <td class="td-center td-gradient"
                                                    style="width: 30%;">
                                                    <b>Aktion</b></td>
                                            </tr>
                                            <?php
                                            $kingdomIsRecruiting = $kingdom->isKingdomRecruiting($kID);
                                            if ($kingdomIsRecruiting) {
                                                $kingdomRecruitingID = $kingdom->getKingdomRecruitingID();
                                            }

                                            // Get soldiers of kingdom
                                            $stmt = $mysqli->prepare("SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?");
                                            $stmt->bind_param("i", $kID);
                                            $stmt->execute();
                                            $soldierid = -1;
                                            $solcount = 0;
                                            $stmt->bind_result($soldierid, $solcount);
                                            $kingdomSoldiers = array();
                                            while ($stmt->fetch()) {
                                                $kingdomSoldiers[$soldierid] = $solcount;
                                            }
                                            $stmt->close();

                                            for ($i = 0; $i < $soldiercount; $i++) {
                                                $costFood = $soldiers[$i]->getSoldierFoodCost();
                                                $costGold = $soldiers[$i]->getSoldierGoldCost();
                                                $costVillager = $soldiers[$i]->getSoldierVillagerCost();

                                                $textFood = ($costFood > $kingdomFood ? "<b class='error'>" . $costFood . "</b>" : $costFood);
                                                $textGold = ($costGold > $kingdomGold ? "<b class='error'>" . $costGold . "</b>" : $costGold);
                                                $textVillager = ($costVillager > $kingdomVillager ? "<b class='error'>" . $costVillager . "</b>" : $costVillager);

                                                if ($kingdomIsRecruiting) {
                                                    if ($kingdomRecruitingID == $i) {
                                                        $stmt = $mysqli->prepare("SELECT recruittime, soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?");
                                                        $action = ACTION_BUILD_TROOPS;
                                                        $recruittime = 0;
                                                        $soldiergoal = 0;
                                                        $stmt->bind_param('iii', $kID, $action, $i);
                                                        $stmt->execute();
                                                        $stmt->bind_result($recruittime, $soldiergoal);
                                                        $stmt->fetch();
                                                        $stmt->close();

                                                        $soldiertime = $soldiers[$i]->getSoldierTime();
                                                        $currenttime = time();
                                                        $totaldifference = $recruittime - $currenttime;
                                                        $remainingTimeInSeconds = max(0, $totaldifference % $soldiertime);

                                                        // Job was just started
                                                        if ($remainingTimeInSeconds == 0) {
                                                            $remainingTimeInSeconds = $soldiers[$i]->getSoldierTime();
                                                        }

                                                        $textBuild = "In Ausbildung: " . $soldiergoal . "<br><br><b>
                                              <span id='counter'>
                                              <script type='text/javascript'>
                                              diff = " . json_encode($remainingTimeInSeconds) . "
                                              startCountdown(diff);
                                              </script>
                                              </span></b><br> 
                                              <form action='buildings.php' method='GET'>
                                                <input type='hidden' name='bid' value='2'>
                                                <input type='hidden' name='recruit' value='" . $i . "'>
                                                <input type='hidden' name='count' value='cancel'>
                                                <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                                              </form>";
                                                    } else {
                                                        $textBuild = "-";
                                                    }
                                                } else {
                                                    if ($costFood > $kingdomFood || $costGold > $kingdomGold) {
                                                        $textBuild = "Nicht genug Rohstoffe!";
                                                    } else if ($kingdomVillager < $costVillager) {
                                                        $textBuild = "Nicht genug Dorfbewohner!";
                                                    } else {
                                                        // Calculate the maximum soldiers recruitable based on each resource
                                                        $foodCostPerSoldier = $soldiers[$i]->getSoldierFoodCost();
                                                        $goldCostPerSoldier = $soldiers[$i]->getSoldierGoldCost();
                                                        $villagerCostPerSoldier = $soldiers[$i]->getSoldierVillagerCost();
                                                        $maxSoldiersFood = floor($kingdomFood / $foodCostPerSoldier);
                                                        $maxSoldiersGold = floor($kingdomGold / $goldCostPerSoldier);
                                                        $maxSoldiersVillagers = floor($kingdomVillager / $villagerCostPerSoldier);
                                                        $maxRecruitVal = min($maxSoldiersFood, $maxSoldiersGold, $maxSoldiersVillagers);
                                                        $maxSoldiers = min($maxRecruitVal, 99);

                                                        $textBuild = "<form action='buildings.php?' method='GET'>
                                                    <input type='hidden' name='bid' value='2'>
                                                    <input type='hidden' name='recruit' value='" . $i . "'>
                                                    <input type='text' name='count' id='count" . $i . "' size='2' maxlength='2'>
                                                    <input type='button' value='Max.' onclick='fillMax(\"" . $i . "\", \"" . $maxSoldiers . "\")'><br>
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

                                                echo "<tr>
                                                    <td class='td-center' style='width: 10%;'>" . $soldiers[$i]->getSoldierIcon() . "</td>
                                                    <td style='width: 40%;'><b class='popup' id='description" . $i . "'>" . $soldiers[$i]->getSoldierName() . " 
                                                        <div id='description" . $i . "_box' class='popupbox'>" . $soldiers[$i]->getSoldierDescription() . "</div>  (" . ($kingdomSoldiers[$i] ?? 0) . ")</b><br><br>
                                                        <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung'> " . $textFood . "
                                                        <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold'> " . $textGold . "
                                                        <img src='images/icons/icon_villager.png' class='ressource-icons' alt='Dorfbewohner'> " . $textVillager . "<br>
                                                        <img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff'> " . $soldiers[$i]->getSoldierAttack() . " 
                                                        <img src='images/icons/icon_shield.png' class='ressource-icons' alt='Verteidigung'> " . $soldiers[$i]->getSoldierDefense() . "<br>
                                                        <img src='images/icons/icon_time.png' class='ressource-icons' alt='Rekrutierzeit'> " . convertSecToStr($soldiers[$i]->getSoldierTime()) . "
                                                        <br><br></td>
                                                    <td class='td-center' style='width: 40%;'>$textBuild</td>
                                                </tr>";
                                            }
                                            ?>
                                        </table>
                                        <?php
                                        break;
                                    case 3:
                                        // Mauer
                                        $level = $buildings[3]->getBuildingLevel() * DEFAULT_WALL_HP;
                                        echo "<p><b>Verteidigungswert:</b> $level</p>";
                                        break;
                                    case 4:
                                        // Schmiede
                                        break;
                                    case 5:
                                        // Mühle
                                        echo "<p><b>Nahrungsertrag pro Stunde:</b> {$kingdom->getKingdomFoodPerHour()}</p>";
                                        break;
                                    case 6:
                                        // Sägewerk
                                        echo "<p><b>Holzertrag pro Stunde:</b> {$kingdom->getKingdomWoodPerHour()}</p>";
                                        break;
                                    case 7:
                                        // Steinmine
                                        echo "<p><b>Steinertrag pro Stunde:</b> {$kingdom->getKingdomStonePerHour()}</p>";
                                        break;
                                    case 8:
                                        // Goldmine
                                        echo "<p><b>Goldertrag pro Stunde:</b> {$kingdom->getKingdomGoldPerHour()}</p>";
                                        break;
                                    case 9:
                                        // Lager
                                        echo "<div style='margin: auto; width: 200px;'>
                                                <div class='split-content'>
                                                    <div>
                                                        <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung'> 
                                                        {$kingdom->getKingdomFood()}
                                                    </div>
                                                    <div>von {$kingdom->getKingdomMaxFood()}</div>
                                                </div>
                                                <div class='split-content'>
                                                    <div>
                                                        <img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz'> 
                                                        {$kingdom->getKingdomWood()}
                                                    </div>
                                                    <div>von {$kingdom->getKingdomMaxWood()}</div>
                                                </div>
                                                <div class='split-content'>
                                                    <div>
                                                        <img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein'> 
                                                        {$kingdom->getKingdomStone()}
                                                    </div>
                                                    <div>von {$kingdom->getKingdomMaxStone()}</div>
                                                </div>
                                                <div class='split-content'>
                                                    <div>
                                                        <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold'> 
                                                        {$kingdom->getKingdomGold()}
                                                    </div>
                                                    <div>von {$kingdom->getKingdomMaxGold()}</div>
                                                </div>
                                        </div>";
                                        break;

                                }
                            } else {
                                $error = "Das Gebäude wurde noch nicht gebaut!";
                            }
                        } else {
                            $error = "Das Gebäude existiert nicht!";
                        }
                    }

                    // Show an error if there is any
                    if ($error != null && $bID != 2) {
                        echo $error . "<br><br>";
                    }

                    // Display all available buildings
                    if ($bID == 0 || (isset($_GET["action"]) && $_GET["action"] == "build") || (isset($_GET["action"]) && $_GET["action"] == "cancel")) {
                    // Get current ressources
                    $kingdomWood = $kingdom->getKingdomWood();
                    $kingdomFood = $kingdom->getKingdomFood();
                    $kingdomStone = $kingdom->getKingdomStone();
                    $kingdomGold = $kingdom->getKingdomGold();

                    $towncenterlevel = $buildings[0]->getBuildingLevel();

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
                        for ($i = 0; $i < $buildingcount; $i++) {
                            if ($towncenterlevel >= $buildings[$i]->getBuildingRequiredLevel()) {
                                $level = $buildings[$i]->getBuildingLevel();

                                if ($level < MAX_BUILDING_LEVEL) {
                                    if (!is_numeric($level)) {
                                        $level = "0";
                                    }

                                    $costs = $buildings[$i]->calculateBuildingCost();
                                    $costWood = $costs["costWood"];
                                    $costFood = $costs["costFood"];
                                    $costStone = $costs["costStone"];
                                    $costGold = $costs["costGold"];

                                    $textWood = $buildings[$i]->getRessourceText($costWood, $kingdomWood);
                                    $textFood = $buildings[$i]->getRessourceText($costFood, $kingdomFood);
                                    $textStone = $buildings[$i]->getRessourceText($costStone, $kingdomStone);
                                    $textGold = $buildings[$i]->getRessourceText($costGold, $kingdomGold);
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
                                            $textBuild = "<form action='buildings.php' method='GET'>
                                                                            <input type='hidden' name='action' value='build'>
                                                                            <input type='hidden' name='bid' value='" . $i . "'>
                                                                            <input type='submit' value='" . ($level > 0 ? "Aufrüsten" : "Bauen") . "'>
                                                                          </form>";
                                        }
                                    }

                                    echo "<tr><td class='td-center' style='width: 10%;'>" . $buildings[$i]->getBuildingIcon() . "</td>";
                                    echo "<td style='width: 40%;'><b>" . $buildings[$i]->getBuildingName() . " (" . $level . ")</b><br><br>";
                                    echo "<img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz'> " . $textWood . "   <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung'> " . $textFood . "<br>";
                                    echo "<img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein'> " . $textStone . "    <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold'> " . $textGold . "<br>";
                                    echo "<img src='images/icons/icon_hammer.png' class='ressource-icons' alt='Bauzeit'> " . convertSecToStr($buildings[$i]->getBuildingTime() * ($level == 0 ? 1 : $level + 1)) . "<br><br></td><td class='td-center' style='width: 40%;'>" . $textBuild . "</td></tr>";
                                }
                            }
                        }
                        }
                        ?>
                    </table>
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

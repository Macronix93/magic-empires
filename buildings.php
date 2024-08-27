<?php
global $db_instance, $user;
require_once("functions.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php");
    exit;
}

$error = null;

// Hier PHP Logik + GET/POST Anfragen
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
$bID = (empty($_GET["id"]) ? 0 : $_GET["id"]);
$buildid = (empty($_GET["bid"]) ? 0 : $_GET["bid"]);
$buildingName = "";
$lastid = 0;

// Soldier variables
$soldiers = [];
$soldiercount = 0;

// Check if building is valid
if (isset($_GET["id"]) && ($bID >= 0 && $bID < $buildingcount)) {
    $buildingName = isset($_GET["action"]) ? $buildings[0]->getBuildingName() : $buildings[$bID]->getBuildingName();
    $lastid = $_GET["id"];
}

// Get kingdoms current resources
$kingdomWood = $kingdom->getKingdomWood();
$kingdomFood = $kingdom->getKingdomFood();
$kingdomStone = $kingdom->getKingdomStone();
$kingdomGold = $kingdom->getKingdomGold();
$kingdomVillager = $kingdom->getKingdomVillager();

$kingdomIsBuilding = false;
$kingdomBuildingID = -1;

// An action is set via URL
if (isset($_GET["action"])) {
    $kingdomIsBuilding = $kingdom->isKingdomBuilding($kID);
    if ($kingdomIsBuilding) {
        $kingdomBuildingID = $kingdom->getKingdomBuildingID();
    }

    if ($buildid >= 0 && $buildid < $buildingcount) {
        $buildingLevel = $buildings[$buildid]->getBuildingLevel();
        $costs = $buildings[$buildid]->calculateBuildingCost();
        $costWood = $costs["costWood"];
        $costFood = $costs["costFood"];
        $costStone = $costs["costStone"];
        $costGold = $costs["costGold"];

        // The action that was set is "building"
        if ($_GET["action"] == "build") {
            if ($buildingLevel >= MAX_BUILDING_LEVEL) {
                $error = "Das Gebäude ist schon maximal ausgebaut!";
            } else {
                if ($kingdomIsBuilding) {
                    $error = "Du baust bereits!";
                } else {
                    if ($costWood > $kingdomWood || $costFood > $kingdomFood || $costStone > $kingdomStone || $costGold > $kingdomGold) {
                        $error = "Nicht genügend Ressourcen!";
                    } else {
                        $towncenterLevel = $buildings[0]->getBuildingLevel();
                        $requiredLevel = $buildings[$buildid]->getBuildingRequiredLevel();

                        // Town center level is equal or higher than required level... build!
                        if ($towncenterLevel >= $requiredLevel) {
                            $buildingTime = time() + $buildings[$buildid]->getBuildingTime() * ($buildingLevel == 0 ? 1 : $buildingLevel + 1);

                            /*$kingdom->setKingdomWood($kID, $kingdom->getKingdomWood() - $costWood);
                            $kingdom->setKingdomFood($kID, $kingdom->getKingdomFood() - $costFood);
                            $kingdom->setKingdomStone($kID, $kingdom->getKingdomStone() - $costStone);
                            $kingdom->setKingdomGold($kID, $kingdom->getKingdomGold() - $costGold);*/
                            $kingdom->giveKingdomWood($kID, -$costWood);
                            $kingdom->giveKingdomFood($kID, -$costFood);
                            $kingdom->giveKingdomStone($kID, -$costStone);
                            $kingdom->giveKingdomGold($kID, -$costGold);

                            $mysqli->query("INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname) 
                                                    VALUES('" . ACTION_BUILD_BUILDING . "', '{$user->getUserID()}', '$kID', '$buildid', '$buildingTime', '{$buildings[$buildid]->getBuildingLevel()}', '{$buildings[$buildid]->getBuildingName()}');");

                            $_SESSION["buildingID"] = $buildid;
                        } else {
                            $error = "Das benötigte Level ist höher als das Level vom Dorfzentrum!";
                        }
                    }
                }
            }
        } else if ($_GET["action"] == "cancel") { // The action that was set is "cancel building"
            if ($kingdomIsBuilding) {
                $mysqli->query("DELETE FROM events WHERE userid = '{$user->getUserID()}' AND buildingid = '$buildid'");

                // Refund the player
                /*$kingdom->setKingdomWood($kID, $kingdom->getKingdomWood() + $costWood);
                $kingdom->setKingdomFood($kID, $kingdom->getKingdomFood() + $costFood);
                $kingdom->setKingdomStone($kID, $kingdom->getKingdomStone() + $costStone);
                $kingdom->setKingdomGold($kID, $kingdom->getKingdomGold() + $costGold);*/
                $kingdom->giveKingdomWood($kID, $costWood);
                $kingdom->giveKingdomFood($kID, $costFood);
                $kingdom->giveKingdomStone($kID, $costStone);
                $kingdom->giveKingdomGold($kID, $costGold);
            } else {
                $error = "Du baust gerade nichts!";
            }
        } else {
            $error = "Diese Aktion existiert nicht!";
        }
    } else {
        $error = "Dieses Gebäude existiert nicht!";
    }
} else {
    if ($bID >= 0 && $bID < $buildingcount) {
        if ($buildings[$bID]->isBuilt()) {
            // PHP logic for buildings
            switch ($bID) {
                case 1:
                    // Universität
                    break;
                case 2:
                    // Kaserne
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
                                /*$kingdomFood = $kingdom->setKingdomFood($kID, $kingdom->getKingdomFood() + $soldiergoal * $soldiers[$sID]->getSoldierFoodCost());
                                $kingdomGold = $kingdom->setKingdomGold($kID, $kingdom->getKingdomGold() + $soldiergoal * $soldiers[$sID]->getSoldierGoldCost());*/
                                $kingdom->giveKingdomFood($kID, $soldiergoal * $soldiers[$sID]->getSoldierFoodCost());
                                $kingdom->giveKingdomGold($kID, $soldiergoal * $soldiers[$sID]->getSoldierGoldCost());

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
                                    $kingdom->giveKingdomFood($kID, -$costFood);
                                    $kingdom->giveKingdomGold($kID, -$costGold);
                                }
                            }
                        }
                    }
                    break;
                case 3:
                    // Mauer
                    break;
                case 4:
                    // Schmiede
                    break;
                case 5:
                    // Mühle
                    break;
                case 6:
                    // Sägewerk
                    break;
                case 7:
                    // Steinmine
                    break;
                case 8:
                    // Goldmine
                    break;
                case 9:
                    // Lager
                    break;
                case 10:
                    // Marktplatz
                    $stmt = $mysqli->prepare("SELECT supply, supplyvalue FROM marketplace WHERE offerid = ? AND kingdomid = ?");
                    $stmt->bind_param('ii', $_GET["delete"], $kID);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $stmt->close();

                    if (isset($_GET["accept"])) {
                        $stmt = $mysqli->prepare("SELECT username, kingdomid, supply, supplyvalue, demand, demandvalue FROM marketplace WHERE offerid = ?");
                        $stmt->bind_param('i', $_GET["accept"]);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $row = $result->fetch_assoc();
                        $stmt->close();

                        if ($row && $kID != $row["kingdomid"]) {
                            $supply = $row["supply"];
                            $supplyvalue = $row["supplyvalue"];
                            $demand = $row["demand"];
                            $demandvalue = $row["demandvalue"];

                            // Check if kingdom has enough ressources to handle the trade
                            if ($demand == 0 && $kingdom->getKingdomFood() < $demandvalue) {
                                $error = "Soviel Nahrung kannst du nicht aufbringen!";
                            } else if ($demand == 1 && $kingdom->getKingdomWood() < $demandvalue) {
                                $error = "Soviel Holz kannst du nicht aufbringen!";
                            } else if ($demand == 2 && $kingdom->getKingdomStone() < $demandvalue) {
                                $error = "Soviel Stein kannst du nicht aufbringen!";
                            } else if ($demand == 3 && $kingdom->getKingdomGold() < $demandvalue) {
                                $error = "Soviel Gold kannst du nicht aufbringen!";
                            } else {
                                $otherkingdom = $row["kingdomid"];
                                $supplyressource = "";
                                $demandressource = "";

                                // Give both kingdoms the respective ressources
                                switch ($supply) {
                                    case 0:
                                        $kingdom->giveKingdomFood($kID, $supplyvalue);
                                        $supplyressource = "Nahrung";
                                        break;
                                    case 1:
                                        $kingdom->giveKingdomWood($kID, $supplyvalue);
                                        $supplyressource = "Holz";
                                        break;
                                    case 2:
                                        $kingdom->giveKingdomStone($kID, $supplyvalue);
                                        $supplyressource = "Stein";
                                        break;
                                    case 3:
                                        $kingdom->giveKingdomGold($kID, $supplyvalue);
                                        $supplyressource = "Gold";
                                        break;
                                }
                                switch ($demand) {
                                    case 0:
                                        $kingdom->giveKingdomFood($kID, -$demandvalue);
                                        $kingdom->giveKingdomFood($otherkingdom, $demandvalue);
                                        $demandressource = "Nahrung";
                                        break;
                                    case 1:
                                        $kingdom->giveKingdomWood($kID, -$demandvalue);
                                        $kingdom->giveKingdomWood($otherkingdom, $demandvalue);
                                        $demandressource = "Holz";
                                        break;
                                    case 2:
                                        $kingdom->giveKingdomStone($kID, -$demandvalue);
                                        $kingdom->giveKingdomStone($otherkingdom, $demandvalue);
                                        $demandressource = "Stein";
                                        break;
                                    case 3:
                                        $kingdom->giveKingdomGold($kID, -$demandvalue);
                                        $kingdom->giveKingdomGold($otherkingdom, $demandvalue);
                                        $demandressource = "Gold";
                                        break;
                                }

                                // Delete the marketplace offer
                                $mysqli->query("DELETE FROM marketplace WHERE offerid = '{$_GET["accept"]}'");

                                // Send a message to the other kingdom that the offer has been accepted
                                $time = time();
                                $notread = 0;
                                $sender = "Server";
                                $message = "Dein Marktplatz-Angebot (" . $supplyvalue . " " . $supplyressource . " gegen " . $demandvalue . " " . $demandressource . ")<br>wurde von " . $user->getUserName() . " angenommen!";

                                $stmt = $mysqli->prepare("INSERT INTO messages (sender, receiver, date, hasread, message) VALUES (?, ?, ?, ?, ?)");
                                $stmt->bind_param("ssiiss", $sender, $row["username"], $time, $notread, $message);
                                $stmt->execute();
                                $stmt->close();
                            }
                        } else {
                            $error = "Dieses Angebot existiert nicht oder ist von deinem Königreich!";
                        }
                    } else if (isset($_GET["delete"])) {
                        $stmt = $mysqli->prepare("SELECT supply, supplyvalue FROM marketplace WHERE offerid = ? AND kingdomid = ?");
                        $stmt->bind_param('ii', $_GET["delete"], $kID);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $row = $result->fetch_assoc();
                        $stmt->close();

                        if ($row) {
                            $supply = $row["supply"];
                            $supplyvalue = $row["supplyvalue"];

                            // Give supply ressources back to kingdom
                            switch ($supply) {
                                case 0:
                                    $kingdom->giveKingdomFood($kID, $supplyvalue);
                                    break;
                                case 1:
                                    $kingdom->giveKingdomWood($kID, $supplyvalue);
                                    break;
                                case 2:
                                    $kingdom->giveKingdomStone($kID, $supplyvalue);
                                    break;
                                case 3:
                                    $kingdom->giveKingdomGold($kID, $supplyvalue);
                                    break;
                            }

                            // Delete the marketplace offer
                            $mysqli->query("DELETE FROM marketplace WHERE offerid = '{$_GET["delete"]}'");
                        } else {
                            $error = "Dieses Angebot existiert nicht oder ist nicht von deinem aktuellen Königreich!";
                        }
                    } else if (!empty($_GET["sv"]) && !empty($_GET["dv"])) {
                        if ($_GET["s"] < 0 || $_GET["s"] > 3 || $_GET["d"] < 0 || $_GET["d"] > 3) {
                            $error = "Diese Ressource gibt es nicht!";
                        } else if ($_GET["s"] == $_GET["d"]) {
                            $error = "Die Ressourcentypen dürfen nicht gleich sein!";
                        } else {
                            if ($_GET["sv"] <= 0 || !is_numeric($_GET["sv"]) || $_GET["dv"] <= 0 || !is_numeric($_GET["dv"]) || $_GET["sv"] > 99999 || $_GET["dv"] > 99999) {
                                $error = "Die Werte müssen zwischen 1 und 99999 liegen!";
                            } else {
                                // Check if kingdom has enough ressources to handle the trade
                                if ($_GET["s"] == 0 && $kingdom->getKingdomFood() < $_GET["sv"]) {
                                    $error = "Soviel Nahrung kannst du nicht bieten!";
                                } else if ($_GET["s"] == 1 && $kingdom->getKingdomWood() < $_GET["sv"]) {
                                    $error = "Soviel Holz kannst du nicht bieten!";
                                } else if ($_GET["s"] == 2 && $kingdom->getKingdomStone() < $_GET["sv"]) {
                                    $error = "Soviel Stein kannst du nicht bieten!";
                                } else if ($_GET["s"] == 3 && $kingdom->getKingdomGold() < $_GET["sv"]) {
                                    $error = "Soviel Gold kannst du nicht bieten!";
                                } else {
                                    // Check if there is already an offer for this kingdom
                                    $stmtkingdom = $mysqli->prepare("SELECT offerid FROM marketplace WHERE kingdomid = ?");
                                    $stmtkingdom->bind_param('i', $kID);
                                    $stmtkingdom->execute();
                                    $stmtkingdom->bind_result($offerid);
                                    $stmtkingdom->fetch();
                                    $stmtkingdom->close();

                                    if ($offerid != 0) {
                                        $error = "Du hast bereits ein Angebot für dieses Königreich am laufen!";
                                    } else {
                                        // No offer found for the kingdom - insert to database
                                        $mysqli->query("INSERT INTO marketplace (userid, username, kingdomid, supply, supplyvalue, demand, demandvalue) 
                                                                                VALUES('{$user->getUserID()}', '{$user->getUserName()}', '$kID', '{$_GET["s"]}', '{$_GET["sv"]}', '{$_GET["d"]}', '{$_GET["dv"]}');");

                                        switch ($_GET["s"]) {
                                            case 0:
                                                $kingdom->giveKingdomFood($kID, -$_GET["sv"]);
                                                break;
                                            case 1:
                                                $kingdom->giveKingdomWood($kID, -$_GET["sv"]);
                                                break;
                                            case 2:
                                                $kingdom->giveKingdomStone($kID, -$_GET["sv"]);
                                                break;
                                            case 3:
                                                $kingdom->giveKingdomGold($kID, -$_GET["sv"]);
                                                break;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    break;
            }
        } else {
            $error = "Das Gebäude wurde noch nicht gebaut!";
            $lastid = 0;
        }
    } else {
        $error = "Das Gebäude existiert nicht!";
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/banner.html");
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
                <div class="big-box-header">
                    <p>
                        <?php
                        if ($error != null) {
                            echo "Fehler";
                        } else {
                            echo $buildingName;
                        }
                        ?>
                    </p>
                </div>
                <div class="big-box-content">
                    <?php
                    // Hier Logik für die HTML View (basierend auf dem Gebäude)

                    // Show error if there is any
                    if ($error != null) {
                        echo $error . "<br>";

                        changeLocation("buildings.php?id=$lastid", 2);
                    } else {
                        if ($bID == 0 || (isset($_GET["action"]) && ($_GET["action"] == "build" || $_GET["action"] == "cancel"))) {
                            // Dorfzentrum

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
                                    <td class="td-center td-gradient" colspan="2">
                                        <b>Gebäude</b></td>
                                    <td class="td-center td-gradient">
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
                                                                        <input type='hidden' name='id' value='0'>
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
                                                                            <input type='hidden' name='id' value='0'>
                                                                            <input type='hidden' name='action' value='build'>
                                                                            <input type='hidden' name='bid' value='" . $i . "'>
                                                                            <input type='submit' value='" . ($level > 0 ? "Upgrade" : "Bauen") . "'>
                                                                          </form>";
                                                }
                                            }

                                            echo "<tr><td class='td-center' style='width: 10%;'>" . $buildings[$i]->getBuildingIcon() . "</td>
                                            <td style='width: 40%;'><b>" . $buildings[$i]->getBuildingName() . " (" . $level . ")</b><br><br>
                                            <img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz'> " . $textWood . "   <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung'> " . $textFood . "<br>
                                            <img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein'> " . $textStone . "    <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold'> " . $textGold . "<br>
                                            <img src='images/icons/icon_hammer.png' class='ressource-icons' alt='Bauzeit'> " . convertSecToStr($buildings[$i]->getBuildingTime() * ($level == 0 ? 1 : $level + 1)) . "<br></td><td class='td-center' style='width: 40%;'>" . $textBuild . "</td></tr>";
                                        }
                                    }
                                }

                                ?>
                            </table>
                            <?php
                        } else {
                            if ($buildings[$bID]->isBuilt()) {
                                switch ($bID) {
                                    case 1:
                                        // Universität
                                        break;
                                    case 2:
                                        // Kaserne
                                        ?>
                                        <table class="table">
                                            <tr>
                                                <td class="td-center td-gradient" colspan="2">
                                                    <b>Soldat</b></td>
                                                <td class="td-center td-gradient">
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
                                                                        <input type='hidden' name='id' value='2'>
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
                                                                            <input type='hidden' name='id' value='2'>
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
                                                        <br></td>
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
                                    case 10:
                                        // Marktplatz
                                        ?>
                                        <table class="table">
                                            <form action='buildings.php?' method='GET'>
                                                <input type='hidden' name='id' value='10'>
                                                <tr>
                                                    <td>
                                                        <label for='sv'>Ich biete:</label>
                                                        <input type='text'
                                                               name='sv'
                                                               id='sv'
                                                               size='5'
                                                               maxlength='5'>
                                                        <label>
                                                            <select name="s">
                                                                <?php
                                                                echo "<option value='0'>Nahrung</option>
                                                                        <option value='1'>Holz</option>
                                                                        <option value='2'>Stein</option>
                                                                        <option value='3'>Gold</option>";
                                                                ?>
                                                            </select>
                                                        </label>
                                                    </td>
                                                    <td>
                                                        <label for='dv'>Ich suche:</label>
                                                        <input type='text'
                                                               name='dv'
                                                               id='dv'
                                                               size='5'
                                                               maxlength='5'>
                                                        <label>
                                                            <select name="d">
                                                                <?php
                                                                echo "<option value='0'>Nahrung</option>
                                                                        <option value='1'>Holz</option>
                                                                        <option value='2'>Stein</option>
                                                                        <option value='3'>Gold</option>";
                                                                ?>
                                                            </select>
                                                        </label>
                                                    </td>
                                                    <td>
                                                        <input type='submit' value='Abschicken'>
                                                    </td>
                                                </tr>
                                            </form>
                                        </table>
                                        <br>
                                        <br>
                                        <table class="table" style="word-break: break-word">
                                            <tr>
                                                <td class="td-center td-gradient">
                                                    <b>Benutzer</b></td>
                                                <td class="td-center td-gradient">
                                                    <b>Königreich</b></td>
                                                <td class="td-center td-gradient">
                                                    <b>Bietet</b></td>
                                                <td class="td-center td-gradient">
                                                    <b>Benötigt</b></td>
                                                <td class="td-center td-gradient">
                                                    <b>Aktion</b></td>
                                            </tr>
                                            <?php
                                            $stmt = $mysqli->prepare("SELECT * FROM marketplace");
                                            $stmt->execute();
                                            $result = $stmt->get_result();

                                            $ressourceicon = array();
                                            $ressourceicon[0] = "<img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung'>";
                                            $ressourceicon[1] = "<img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz'>";
                                            $ressourceicon[2] = "<img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein'>";
                                            $ressourceicon[3] = "<img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold'>";

                                            echo "<p>Aktuelle Angebote</p><br>";

                                            while ($row = $result->fetch_assoc()) {
                                                $stmtkingdom = $mysqli->prepare("SELECT kingdomname FROM kingdoms WHERE id = ?");
                                                $stmtkingdom->bind_param('i', $row["kingdomid"]);
                                                $stmtkingdom->execute();
                                                $stmtkingdom->bind_result($kingdomname);
                                                $stmtkingdom->fetch();
                                                $stmtkingdom->close();

                                                $action = ($row["kingdomid"] == $kID) ? "Löschen" : "Annehmen";
                                                $param = ($row["kingdomid"] == $kID) ? "delete" : "accept";

                                                $textbuild = "<form action='buildings.php' method='GET'>
                                                                    <input type='hidden' name='id' value='10'>
                                                                    <input type='hidden' name='$param' value='" . $row["offerid"] . "'>
                                                                    <input type='submit' value='$action'>
                                                                </form>";

                                                echo "<tr><td>{$row["username"]}</td>
                                                            <td>$kingdomname</td>
                                                            <td class='td-center'>{$ressourceicon[$row["supply"]]} {$row["supplyvalue"]}</td>
                                                            <td class='td-center'>{$ressourceicon[$row["demand"]]} {$row["demandvalue"]}</td>
                                                            <td class='td-center'>$textbuild</td>";
                                            }
                                            $stmt->close();
                                            ?>
                                        </table>
                                        <?php
                                        break;
                                }
                            }
                        }
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

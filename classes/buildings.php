<?php

class Buildings {
    private $mysqli;
    private $buildings = array(array());

    // Constructor
    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
        $this->getBuildingList();
    }

    public function isBuilt($kingdomid, $bid): bool {
        $stmt = $this->mysqli->prepare("SELECT * FROM buildings WHERE kingdomid = ? AND buildingid = ?");
        $stmt->bind_param("ii", $kingdomid, $bid);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->close();
            return true;
        } else {
            $stmt->close();
            return false;
        }
    }

    public function getBuildingList(): array {
        $result = $this->mysqli->query("SELECT * FROM buildinglist");
        $this->buildings = [];

        while ($row = $result->fetch_assoc()) {
            $this->buildings[] = [
                "id" => $row["id"],
                "buildingname" => $row["buildingname"],
                "buildingscore" => $row["buildingscore"],
                "woodcost" => $row["woodcost"],
                "foodcost" => $row["foodcost"],
                "stonecost" => $row["stonecost"],
                "goldcost" => $row["goldcost"],
                "multiplicator" => $row["multiplicator"],
                "timetobuild" => $row["timetobuild"],
                "requiredlevel" => $row["requiredlevel"]
            ];
        }

        $result->close();

        return $this->buildings;
    }

    public function getBuildingLevel($bid, $kingdomid): int {
        $level = 0;

        $stmt = $this->mysqli->prepare("SELECT buildinglevel FROM buildings WHERE kingdomid = ? AND buildingid = ?");
        $stmt->bind_param('ii', $kingdomid, $bid);
        $stmt->execute();
        $stmt->bind_result($level);
        $stmt->fetch();
        $stmt->close();

        return $level;
    }

    public function getBuildingRequiredLevel($bid) {
        return $this->buildings[$bid]["requiredlevel"];
    }

    public function getBuildingTime($bid) {
        return $this->buildings[$bid]["timetobuild"];
    }

    public function getBuildingMult($bid) {
        return $this->buildings[$bid]["multiplicator"];
    }

    public function getBuildingName($bid) {
        return $this->buildings[$bid]["buildingname"];
    }

    public function getBuildingScore($bid) {
        return $this->buildings[$bid]["buildingscore"];
    }

    public function getBuildingCost($bid, $costid) {
        $cost = 0;

        switch ($costid) {
            case 1:
            { // Wood
                $cost = $this->buildings[$bid]["woodcost"];
                break;
            }
            case 2:
            { // Food
                $cost = $this->buildings[$bid]["foodcost"];
                break;
            }
            case 3:
            { // Stone
                $cost = $this->buildings[$bid]["stonecost"];
                break;
            }
            case 4:
            { // Gold
                $cost = $this->buildings[$bid]["goldcost"];
                break;
            }
        }

        return $cost;
    }

    function calculateBuildingCost($building, $bID, $level): array {
        $mult = $building->getBuildingMult($bID);

        $costWood = round($building->getBuildingCost($bID, BUILDING_COST_WOOD) + $building->getBuildingCost($bID, BUILDING_COST_WOOD) * $mult * $level);
        $costFood = round($building->getBuildingCost($bID, BUILDING_COST_FOOD) + $building->getBuildingCost($bID, BUILDING_COST_WOOD) * $mult * $level);
        $costStone = round($building->getBuildingCost($bID, BUILDING_COST_STONE) + $building->getBuildingCost($bID, BUILDING_COST_WOOD) * $mult * $level);
        $costGold = round($building->getBuildingCost($bID, BUILDING_COST_GOLD) + $building->getBuildingCost($bID, BUILDING_COST_WOOD) * $mult * $level);

        return array(
            "costWood" => $costWood,
            "costFood" => $costFood,
            "costStone" => $costStone,
            "costGold" => $costGold,
        );
    }

    public function showBuildingInfo($bid, $kid, $kObject): string {
        switch ($bid) {
            case 1:
                // Universität
                break;
            case 2:
                // Kaserne
                $kingdom = new Kingdoms($this->mysqli);
                $kingdom->getKingdomRessources($_SESSION["kingdomid"]);
                $user = new User($this->mysqli);
                $soldiers = new Barracks($this->mysqli);

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

                if (isset($_GET["recruit"])) {
                    if (isset($_GET["count"])) {
                        if ($_GET["count"] == "cancel") {
                            if ($kingdomIsRecruiting) {
                                // Calculate remaining soldiers to be recruited and resulting refunds
                                $stmt = $this->mysqli->prepare("SELECT soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?");
                                $action = ACTION_BUILD_TROOPS;
                                $soldiergoal = 0;
                                $stmt->bind_param('iii', $kID, $action, $sID);
                                $stmt->execute();
                                $stmt->bind_result($soldiergoal);
                                $stmt->fetch();
                                $stmt->close();

                                // Refund player
                                $kingdomFood = $kingdom->setKingdomFood($kID, $kingdom->getKingdomFood() + $soldiergoal * $soldiers->getSoldierFoodCost($sID));
                                $kingdomGold = $kingdom->setKingdomGold($kID, $kingdom->getKingdomGold() + $soldiergoal * $soldiers->getSoldierGoldCost($sID));

                                // Delete the job
                                $this->mysqli->query("DELETE FROM events WHERE userid = '{$user->getUserID()}' AND soldierid = '$sID' AND kingdomid = '$kID'");
                            } else {
                                $error = "Du rekrutierst gerade nicht!";
                            }
                        } else {
                            if ($kingdomIsRecruiting) {
                                $error = "Du bist bereits am rekrutieren!";
                            } else if (!is_numeric($_GET["count"]) || $_GET["count"] < 1) {
                                $error = "Keine Angabe der Anzahl!";
                            } else {
                                $costFood = $soldiers->getSoldierFoodCost($sID) * $_GET["count"];
                                $costGold = $soldiers->getSoldierGoldCost($sID) * $_GET["count"];
                                $costVillager = $soldiers->getSoldierVillagerCost($sID) * $_GET["count"];

                                if ($costFood > $kingdomFood) {
                                    $error = "Nicht genug Nahrung!";
                                } else if ($costGold > $kingdomGold) {
                                    $error = "Nicht genug Gold!";
                                } else if ($costVillager > $kingdomVillager) {
                                    $error = "Nicht genug Dorfbewohner!";
                                } else {
                                    $currenttime = time();
                                    $recruitingtime = $currenttime + $soldiers->getSoldierTime($sID) * $_GET["count"];

                                    // Maybe prepare this query?!
                                    $this->mysqli->query("INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname, soldierid, recruittime, soldiergoal) 
                                                VALUES('" . ACTION_BUILD_TROOPS . "', '{$user->getUserID()}', '$kID', '0', '0', '0', '-', '$sID', '" . $recruitingtime . "', " . $_GET["count"] . ");");

                                    // Subtract values for food and gold
                                    $kingdom->setKingdomFood($kID, $kingdom->getKingdomFood() - $costFood);
                                    $kingdom->setKingdomGold($kID, $kingdom->getKingdomGold() - $costGold);
                                }
                            }
                        }
                    }
                }
                if ($error != null) {
                    echo $error;
                    changeLocation("buildings.php?bid=2", 2);
                } else {
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
                    $stmt = $this->mysqli->prepare("SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?");
                    $stmt->bind_param("i", $kID);
                    $stmt->execute();
                    $soldierid = -1;
                    $soldiercount = 0;
                    $stmt->bind_result($soldierid, $soldiercount);
                    $kingdomSoldiers = array();
                    while ($stmt->fetch()) {
                        $kingdomSoldiers[$soldierid] = $soldiercount;
                    }
                    $stmt->close();

                    for ($i = 0; $i < $soldiers->getSoldierCount(); $i++) {
                        $costFood = $soldiers->getSoldierFoodCost($i);
                        $costGold = $soldiers->getSoldierGoldCost($i);
                        $costVillager = $soldiers->getSoldierVillagerCost($i);

                        $textFood = ($costFood > $kingdomFood ? "<b class='error'>" . $costFood . "</b>" : $costFood);
                        $textGold = ($costGold > $kingdomGold ? "<b class='error'>" . $costGold . "</b>" : $costGold);
                        $textVillager = ($costVillager > $kingdomVillager ? "<b class='error'>" . $costVillager . "</b>" : $costVillager);

                        if ($kingdomIsRecruiting) {
                            if ($kingdomRecruitingID == $i) {
                                $stmt = $this->mysqli->prepare("SELECT recruittime, soldiergoal FROM events WHERE kingdomid = ? AND actionid = ? AND soldierid = ?");
                                $action = ACTION_BUILD_TROOPS;
                                $recruittime = 0;
                                $soldiergoal = 0;
                                $stmt->bind_param('iii', $kID, $action, $i);
                                $stmt->execute();
                                $stmt->bind_result($recruittime, $soldiergoal);
                                $stmt->fetch();
                                $stmt->close();

                                $soldiertime = $soldiers->getSoldierTime($i);
                                $currenttime = time();
                                $totaldifference = $recruittime - $currenttime;
                                $remainingTimeInSeconds = max(0, $totaldifference % $soldiertime);

                                // Job was just started
                                if ($remainingTimeInSeconds == 0) {
                                    $remainingTimeInSeconds = $soldiers->getSoldierTime($i);
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
                            } else if ($kingdomVillager == 0) {
                                $textBuild = "Nicht genug Dorfbewohner!";
                            } else {
                                // Calculate the maximum soldiers recruitable based on each resource
                                $foodCostPerSoldier = $soldiers->getSoldierFoodCost($i);
                                $goldCostPerSoldier = $soldiers->getSoldierGoldCost($i);
                                $villagerCostPerSoldier = $soldiers->getSoldierVillagerCost($i);
                                $maxSoldiersFood = floor($kingdomFood / $foodCostPerSoldier);
                                $maxSoldiersGold = floor($kingdomGold / $goldCostPerSoldier);
                                $maxSoldiersVillagers = floor($kingdomVillager / $villagerCostPerSoldier);
                                $maxSoldiers = min($maxSoldiersFood, $maxSoldiersGold, $maxSoldiersVillagers);

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
                                    <td class='td-center' style='width: 10%;'>" . $soldiers->getSoldierIcon($i) . "</td>
                                    <td style='width: 40%;'><b class='popup' id='description" . $i . "' style='cursor: pointer;'>" . $soldiers->getSoldierName($i) . " 
                                        <div id='description" . $i . "_box' class='popupbox'>" . $soldiers->getSoldierDescription($i) . "</div>  (" . ($kingdomSoldiers[$i] ?? 0) . ")</b><br><br>
                                        <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung'> " . $textFood . "
                                        <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold'> " . $textGold . "
                                        <img src='images/icons/icon_villager.png' class='ressource-icons' alt='Dorfbewohner'> " . $textVillager . "<br>
                                        <img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff'> " . $soldiers->getSoldierAttack($i) . " 
                                        <img src='images/icons/icon_shield.png' class='ressource-icons' alt='Verteidigung'> " . $soldiers->getSoldierDefense($i) . "<br>
                                        <img src='images/icons/icon_time.png' class='ressource-icons' alt='Rekrutierzeit'> " . convertSecToStr($soldiers->getSoldierTime($i)) . "
                                        <br><br></td>
                                    <td class='td-center' style='width: 40%;'>$textBuild</td>
                                </tr>";
                    }
                }
                ?>
                </table>
                <?php
                break;
            case 3:
                // Mauer
                $level = $this->getBuildingLevel($bid, $kid) * DEFAULT_WALL_HP;
                return "<p><b>Verteidigungswert:</b> $level</p>";
            case 4:
                // Schmiede
                break;
            case 5:
                // Mühle
                return "<p><b>Nahrungsertrag pro Stunde:</b> {$kObject->getKingdomFoodPerHour()}</p>";
            case 6:
                // Sägewerk
                return "<p><b>Holzertrag pro Stunde:</b> {$kObject->getKingdomWoodPerHour()}</p>";
            case 7:
                // Steinmine
                return "<p><b>Steinertrag pro Stunde:</b> {$kObject->getKingdomStonePerHour()}</p>";
            case 8:
                // Goldmine
                return "<p><b>Goldertrag pro Stunde:</b> {$kObject->getKingdomGoldPerHour()}</p>";
            case 9:
                // Lager
                return "<div style='margin: auto; width: 200px;'>
                            <div class='split-content'>
                                <div>
                                    <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung'> 
                                    {$kObject->getKingdomFood()}
                                </div>
                                <div>von {$kObject->getKingdomMaxFood()}</div>
                            </div>
                            <div class='split-content'>
                                <div>
                                    <img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz'> 
                                    {$kObject->getKingdomWood()}
                                </div>
                                <div>von {$kObject->getKingdomMaxWood()}</div>
                            </div>
                            <div class='split-content'>
                                <div>
                                    <img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein'> 
                                    {$kObject->getKingdomStone()}
                                </div>
                                <div>von {$kObject->getKingdomMaxStone()}</div>
                            </div>
                            <div class='split-content'>
                                <div>
                                    <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold'> 
                                    {$kObject->getKingdomGold()}
                                </div>
                                <div>von {$kObject->getKingdomMaxGold()}</div>
                            </div>
                    </div>";

        }
        return "";
    }

    public function getBuildingIcon($bid): string {
        if (isset($this->buildings[$bid])) {
            return "<img src='images/icons/icon_building$bid.png' class='ressource-icons' alt='{$this->buildings[$bid]["buildingname"]}'/>";
        } else {
            return "ICON NOT FOUND";
        }
    }

    public function getBuildingCount(): int {
        return count($this->buildings);
    }

    public function getRessourceText($cost, $currentVal): string {
        return ($cost > $currentVal ? "<b class='error'>" . $cost . "</b>" : $cost);
    }
}
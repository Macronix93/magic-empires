<?php
global $db_instance, $user;
require_once("functions.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php");
    exit;
}

// Fetch all buildings and their dependencies
$buildings = [];

$stmt = $db_instance->prepare("SELECT b.*, d.dependencyid, d.dependencylevel FROM buildinglist b LEFT JOIN buildingdeps d ON b.id = d.buildingid");
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

while ($row = $result->fetch_assoc()) {
    $buildingID = $row["id"];

    // Check if building object already exists
    if (!isset($buildings[$buildingID])) {
        $building = new Building($db_instance);
        $building->setBuildingID($buildingID);
        $building->setBuildingKingdomID($_SESSION["kingdomid"]);
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

        $buildings[$buildingID] = $building;
    }

    // Check if theres a dependency and add it
    if ($row["dependencyid"] !== null) {
        $buildings[$buildingID]->addBuildingDependency($row["dependencyid"], $row["dependencylevel"]);
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
<div class="content-box">
    <div class="left-container">
        <?php
        include_once("layout/left.php");
        ?>
    </div>

    <div class="middle-container">
        <div class="big-box-container">
            <div class="big-box-header"><p>Gebäudeliste</p></div>
            <div class="big-box-content">
                <table class="table">
                    <tr>
                        <td class="td-center td-gradient" colspan="2">
                            <b>Gebäude</b></td>
                        <td class="td-center td-gradient">
                            <b>Voraussetzungen</b></td>
                    </tr>
                    <?php
                    $dependencyText = "";

                    for ($i = 0; $i < count($buildings); $i++) {
                        $currentBuildingLevel = $buildings[$i]->getBuildingLevel();
                        $buildingDependencies = $buildings[$i]->getBuildingDependencies();

                        if (count($buildingDependencies) != 0) {
                            foreach ($buildingDependencies as $dependency) {
                                $levelOfDependencyBuilding = $buildings[$dependency["dependencyid"]]->getBuildingLevel();

                                if ($dependency["dependencylevel"] > $levelOfDependencyBuilding) {
                                    $dependencyText .= "<span class='error'>- " . $buildings[$dependency["dependencyid"]]->getBuildingName() . " (" . $dependency["dependencylevel"] . ")</span><br>";
                                } else {
                                    $dependencyText .= "<span class='passed'>- " . $buildings[$dependency["dependencyid"]]->getBuildingName() . " (" . $dependency["dependencylevel"] . ")</span><br>";
                                }
                            }
                        }

                        echo "<tr><td class='td-center' style='width: 10%;'>" . $buildings[$i]->getBuildingIcon() . "</td>
                                            <td style='width: 40%;'><b>" . $buildings[$i]->getBuildingName() . " ($currentBuildingLevel)</b></td>
                                            <td>" . (!empty($dependencyText) ? $dependencyText : "-") . "</td></tr>";

                        $dependencyText = "";
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
<?php
include_once("layout/footer.php");
?>
</body>
</html>

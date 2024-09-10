<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php");
    exit;
}

// Fetch all buildings and their dependencies
$buildings = [];
$query = "
            SELECT b.*, d.dependencyid, d.dependencylevel, bl.buildinglevel 
            FROM buildinglist b 
            LEFT JOIN buildingdeps d ON b.id = d.buildingid 
            LEFT JOIN buildings bl ON bl.buildingid = b.id AND bl.kingdomid = ?
";
$result = $db_instance->execute_query($query, [$_SESSION["kingdomid"]]);

foreach ($result as $row) {
    $buildingID = $row["id"];

    // Check if building object already exists
    if (!isset($buildings[$buildingID])) {
        $building = new Building($db_instance);
        $buildings = $building->createBuilding($building, $row, $buildings, $buildingID);

    }

    // Check if there's a dependency and add it
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

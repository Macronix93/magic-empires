<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

// Fetch all buildings and their dependencies
$buildings = fetch_all_buildings($_SESSION["kingdomid"]);
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
                    $dependency_text = "";

                    for ($i = 0; $i < count($buildings); $i++) {
                        $current_building_level = $buildings[$i]->get_building_level();
                        $building_dependencies = $buildings[$i]->get_building_dependencies();

                        if (count($building_dependencies) != 0) {
                            foreach ($building_dependencies as $dependency) {
                                $level_of_dependency_building = $buildings[$dependency["dependencyid"]]->get_building_level();

                                if ($dependency["dependencylevel"] > $level_of_dependency_building) {
                                    $dependency_text .= "<span class='error'>- " . $buildings[$dependency["dependencyid"]]->get_building_name() . " (" . $dependency["dependencylevel"] . ")</span><br>";
                                } else {
                                    $dependency_text .= "<span class='passed'>- " . $buildings[$dependency["dependencyid"]]->get_building_name() . " (" . $dependency["dependencylevel"] . ")</span><br>";
                                }
                            }
                        }

                        echo "<tr><td class='td-center' style='width: 10%;'>" . $buildings[$i]->get_building_icon() . "</td>
                                            <td style='width: 40%;'><b>" . $buildings[$i]->get_building_name() . " ($current_building_level)</b></td>
                                            <td>" . (!empty($dependency_text) ? $dependency_text : "-") . "</td></tr>";

                        $dependency_text = "";
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

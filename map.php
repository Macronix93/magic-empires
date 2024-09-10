<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php");
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
            <div class="big-box-header"><p>Landschaft</p></div>
            <div class="big-box-content">

                <?php
                $map = new Map($db_instance);

                if (!empty($_GET["startx"]) && !empty($_GET["starty"])) {
                    if ($_GET["startx"] >= 90) {
                        $_GET["startx"] = 91;
                    }
                    if ($_GET["starty"] >= 90) {
                        $_GET["starty"] = 91;
                    }
                    $map->startx = $_GET["startx"];
                    $map->starty = $_GET["starty"];

                    if (!is_numeric($_GET["startx"]) || $_GET["startx"] < 0 || $_GET["startx"] > MAX_X) $map->startx = 1;
                    if (!is_numeric($_GET["starty"]) || $_GET["starty"] < 0 || $_GET["starty"] > MAX_Y) $map->starty = 1;
                } else {
                    // Get the coords of the current kingdom
                    $result = $db_instance->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$_SESSION["kingdomid"]]);
                    $row = $result->fetch_assoc();
                    $x = $row["mapx"];
                    $y = $row["mapy"];

                    // Calculate start coordinates
                    $map->startx = max(1, min($x - 5, 91));
                    $map->starty = max(1, min($y - 5, 91));

                    echo "<input type='hidden' id='highlightedfield'>
                            <script type='text/javascript'>
                                document.addEventListener('DOMContentLoaded', function() {
                                    let kingdomid = " . json_encode($_SESSION["kingdomid"]) . ";
                                    let x = " . json_encode($x) . ";
                                    let y = " . json_encode($y) . ";
                                    highlightField(kingdomid || -1, x || 0, y || 0);
                                });
                            </script>";
                }

                // Show info about the fields
                echo "<div style='padding-bottom: 5px;'><img src='images/hochland.png' class='map-legend' alt='Hochland' title='Hochland'/> Hochland 
                          <img src='images/küste.png' class='map-legend' alt='Küste' title='Küste'/> Küste 
                          <img src='images/wald.png' class='map-legend' alt='Wald' title='Wald'/> Wald 
                          <img src='images/wüste.png' class='map-legend' alt='Wüste' title='Wüste'/> Wüste 
                          <img src='images/gebirge.png' class='map-legend' alt='Gebirge' title='Gebirge'/> Gebirge</div>";
                echo "<div id='map-container'>";
                $map->renderMap($map->startx, $map->starty);
                echo "</div>";
                ?>
                <div id='field-info'></div>
                <br>
                <form id="update-map">
                    X: <label>
                        <input type="text" id="startx" name="startx" size="3" maxlength="3">
                    </label>
                    Y: <label>
                        <input type="text" id="starty" name="starty" size="3" maxlength="3">
                    </label>
                    <input type="button" value="Anzeigen" onclick="sendUpdateMapRequest()">
                </form>
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

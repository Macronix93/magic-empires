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
                        $stmt = $db_instance->prepare("SELECT mapx, mapy FROM kingdoms WHERE id = ?");
                        $stmt->bind_param('i', $_SESSION["kingdomid"]);
                        $stmt->execute();
                        $stmt->bind_result($x, $y);
                        $stmt->fetch();
                        $stmt->close();

                        // Calculate start coordinates
                        $map->startx = ($x % 10 == 0) ? ($x - 9) : (10 * floor($x / 10) + 1);
                        $map->starty = ($y % 10 == 0) ? ($y - 9) : (10 * floor($y / 10) + 1);
                    }

                    echo "<div id='map-container'>";
                    $map->renderMap($map->startx, $map->starty);
                    echo "</div>";
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

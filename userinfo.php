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
<?php
if (isset($_GET["userid"])) {
    $query = "
            SELECT users.*, kingdoms.mapx, kingdoms.mapy
            FROM users
            INNER JOIN kingdoms ON users.mainkingdom = kingdoms.id
            WHERE users.id = ?
    ";
    $result = $db_instance->execute_query($query, [$_GET["userid"]]);
    $row = $result->fetch_assoc();

    // Get sorted list of players and calculate the rank
    $result = $db_instance->execute_query("SELECT * FROM users ORDER BY score DESC");
    $sortedusers = $result->fetch_all(MYSQLI_ASSOC);
    $userrank = array_search($_GET["userid"], array_column($sortedusers, "id")) + 1;

    if (!$row) {
        echo "<div style='text-align: center;'>
        <p style='background-color: rgba(0, 0, 0, 0.7); display: inline-block;'>Dieser Benutzer existiert nicht!
        </p></div>";
        return;
    }
    ?>
    <table class="table" style="">
        <tr>
            <td style="width: 200px;"><b>Benutzer</b></td>
            <?php
            if (time() - $row["lastactivity"] > INACTIVITY_DELAY) {
                echo "<td style='width: 300px;'>" . $row["username"] . " (Inaktiv)</td>";
            } else {
                echo "<td style='width: 300px;'>" . $row["username"] . "</td>";
            }
            ?>
        </tr>
        <tr>
            <td><b>Letzte Aktivität</b></td>
            <?php
            echo "<td>" . ($row["lastactivity"] == 0 ? "Nicht verfügbar" : date("d.m.Y", $row["lastactivity"]) . " um " . date("H:i:s", $row["lastactivity"])) . "</td>";
            ?>
        </tr>
        <tr>
            <td><b>Punkte</b></td>
            <?php
            echo "<td>" . $row["score"] . "</td>";
            ?>
        </tr>
        <tr>
            <td><b>Rang</b></td>
            <?php
            echo "<td>" . $userrank . "</td>";
            ?>
        </tr>
        <?php
        if ($row["guildid"]) {
            ?>
            <tr>
                <td><b>Gilde</b></td>
                <?php
                echo "<td>" . $row["guildid"] . "</td>";
                ?>
            </tr>
            <?php
        }
        ?>
        <tr>
            <td><b>Haupt-Königreich</b></td>
            <?php
            $x = $row['mapx'];
            $y = $row['mapy'];

            echo "<td><a href='javascript:void(0);' onclick='redirectToMap(\"$x\", \"$y\")'>" . $x . ":" . $y . "</a></td>";
            ?>
            <script>
                let mainWindow = null;

                function redirectToMap(x, y) {
                    if (mainWindow === null || mainWindow.closed) {
                        mainWindow = window.open("map.php?startx=" + x + "&starty=" + y, "mainWindow");
                    } else {
                        let url = mainWindow.location.href;

                        if (url.includes("map.php")) {
                            mainWindow.document.getElementById('startx').value = x;
                            mainWindow.document.getElementById('starty').value = y;
                            mainWindow.sendUpdateMapRequest();
                        } else {
                            mainWindow.location.href = "map.php?startx=" + x + "&starty=" + y;
                        }
                    }
                }
            </script>
        </tr>
    </table>
    <br>
    <div style="text-align:center">
        <a href="javascript:window.close()"
           style="background-color: rgba(0, 0, 0, 0.7); display: inline-block;">[Schließen]</a>
    </div>
    <title>Magic Empires - <?php echo $row["username"]; ?></title>
    <?php
}
?>
</body>
</html>

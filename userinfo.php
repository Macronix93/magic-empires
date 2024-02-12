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
if (isset($_GET["userid"])) {
    $stmt = $db_instance->prepare("
        SELECT users.*, kingdoms.mapx, kingdoms.mapy
        FROM users
        INNER JOIN kingdoms ON users.mainkingdom = kingdoms.id
        WHERE users.id = ?
    ");
    $stmt->bind_param('i', $_GET["userid"]);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    // Get sorted list of players and calculate the rank
    $stmt = $db_instance->prepare("SELECT * FROM users ORDER BY score DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    $sortedusers = $result->fetch_all(MYSQLI_ASSOC);
    $userrank = array_search($_GET["userid"], array_column($sortedusers, "id")) + 1;
    $stmt->close();

    if (!$row) {
        echo "<div style='text-align: center;'>
        <p style='margin-top: 20px; background-color: rgba(0, 0, 0, 0.7); display: inline-block;'>Dieser Benutzer existiert nicht!
        </p></div>";
        return;
    }
    ?>
    <table class="table" style="margin-top: 20px; min-width: 400px;">
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
            $x = ($row["mapx"] % 10 == 0) ? ($row["mapx"] - 9) : (10 * floor($row["mapx"] / 10) + 1);
            $y = ($row["mapy"] % 10 == 0) ? ($row["mapy"] - 9) : (10 * floor($row["mapy"] / 10) + 1);

            echo "<td><a href='javascript:void(0);' onclick='openMap(\"map.php?startx=$x&starty=$y&kid={$row["mainkingdom"]}\")'>" . $row['mapx'] . ":" . $row['mapy'] . "</a></td>";
            ?>
            <script>
                function openMap(link) {
                    window.opener.location.href = link;
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

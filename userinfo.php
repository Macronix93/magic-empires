<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<script type="text/javascript" src="js/userinfo.js"></script>
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/banner.html");
?>
<?php
$user_id = $_GET['userid'];

if (isset($user_id)) {
    $query = "
            SELECT users.*, kingdoms.mapx, kingdoms.mapy
            FROM users
            INNER JOIN kingdoms ON users.mainkingdom = kingdoms.id
            WHERE users.id = ?
    ";
    $result = $db_instance->execute_query($query, [$user_id]);
    $row = $result->fetch_assoc();

    // Get sorted list of players and calculate the rank
    $result = $db_instance->execute_query("SELECT id, username, lastactivity, score, guildid FROM users ORDER BY score DESC");
    $sorted_users = $result->fetch_all(MYSQLI_ASSOC);
    $user_rank = array_search($user_id, array_column($sorted_users, "id")) + 1;

    if (!$row) {
        echo "<div style='text-align: center;'>
        <p style='background-color: rgba(0, 0, 0, 0.7); display: inline-block;'>Dieser Benutzer existiert nicht!
        </p></div>";
        return;
    }

    $user_name = $row['username'];
    $last_activity = $row['lastactivity'];
    $score = $row['score'];
    $guild_id = $row['guildid'];
    $x = $row['mapx'];
    $y = $row['mapy'];
    ?>
    <table class="table">
        <tr>
            <td style="width: 200px;"><b>Benutzer</b></td>
            <?php
            if (time() - $row["lastactivity"] > INACTIVITY_DELAY) {
                echo "<td style='width: 300px;'>" . $user_name . " (Inaktiv)</td>";
            } else {
                echo "<td style='width: 300px;'>" . $user_name . "</td>";
            }
            ?>
        </tr>
        <tr>
            <td><b>Letzte Aktivität</b></td>
            <?php
            echo "<td>" . ($last_activity == 0 ? "Nicht verfügbar" : date("d.m.Y", $last_activity) . " um " . date("H:i:s", $last_activity)) . "</td>";
            ?>
        </tr>
        <tr>
            <td><b>Punkte</b></td>
            <?php
            echo "<td>" . fnum($score) . "</td>";
            ?>
        </tr>
        <tr>
            <td><b>Rang</b></td>
            <?php
            echo "<td>" . $user_rank . "</td>";
            ?>
        </tr>
        <?php
        if ($guild_id) {
            ?>
            <tr>
                <td>
                    <b>Gilde</b>
                </td>
                <?php
                echo "<td>" . $guild_id . "</td>";
                ?>
            </tr>
            <?php
        }
        ?>
        <tr>
            <td>
                <b>Haupt-Königreich</b>
            </td>
            <td>
                <a href='javascript:void(0);' onclick='redirectToMap(<?php echo $x; ?>, <?php echo $y; ?>)'>
                    <?php echo $x . ":" . $y; ?>
                </a>
            </td>
            <?php
            //echo "<td><a href='javascript:void(0);' onclick='redirectToMap(\"$x\", \"$y\")'>" . $x . ":" . $y . "</a></td>";
            ?>
        </tr>
    </table>
    <br>
    <div style="text-align:center">
        <a href="javascript:window.close()"
           style="background-color: rgba(0, 0, 0, 0.7); display: inline-block;">
            [Schließen]
        </a>
    </div>
    <title>Magic Empires - <?php echo $row["username"]; ?></title>
    <?php
}
?>
</body>
</html>

<?php
require_once("includes/core.php");

check_user_login($user);
?>
<!DOCTYPE html>
<html lang="de">
<?php
$script_files = ["userinfo"];

include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/banner_small.html");
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
        <p style='background-color: rgba(0, 0, 0, 0.7); display: inline-block;'>Dieser Spieler existiert nicht!
        </p></div>";
        return;
    }

    $user_name = $row["username"];
    $last_activity = $row["lastactivity"];
    $score = $row["score"];
    $guild_id = $row["guildid"];
    $x = $row["mapx"];
    $y = $row["mapy"];

    $map = new Map($db_instance, $user);
    $minimapHTML = $map->render_minimap($x, $y);
    ?>
    <table class="table">
        <tr>
            <td style="width: 200px;"><b>Spieler</b></td>
            <td style="width: 300px;">
                <?php
                if (time() - $row["lastactivity"] > INACTIVITY_DELAY) {
                    echo "<i>" . $user_name . "</i> (Inaktiv)";
                } else {
                    echo $user_name;
                }
                ?>
            </td>
        </tr>
        <tr>
            <td><b>Letzte Aktivität</b></td>
            <td><?= $last_activity == 0 ? "Nicht verfügbar" : date("d.m.Y", $last_activity) . " um " . date("H:i:s", $last_activity) ?></td>
        </tr>
        <tr>
            <td><b>Punkte</b></td>
            <?= "<td>" . fnum($score) . "</td>" ?>
        </tr>
        <tr>
            <td><b>Rang</b></td>
            <?= "<td>" . $user_rank . "</td>" ?>
        </tr>
        <tr>
            <td>
                <b>Gilde</b>
            </td>
            <?= "<td>" . $guild_id . "</td>" ?>
        </tr>
        <tr>
            <td>
                <b>Haupt-Königreich</b>
            </td>
            <td>
                <a href="#"
                   data-on-click="mapJump"
                   data-x="<?= e($x) ?>"
                   data-y="<?= e($y) ?>">
                    <?= e($x) . ":" . e($y) ?>
                </a>
            </td>
        </tr>
        <tr>
            <td><b>Position</b></td>
            <td>
                <div class="minimap-wrapper">
                    <?= $minimapHTML ?>
                </div>
            </td>
        </tr>
    </table>
    <br>
    <div style="text-align:center">
        <a href="#"
           data-on-click="closeOverlay"
           style="background-color: rgba(0, 0, 0, 0.7); display: inline-block;">
            [Schließen]
        </a>
    </div>
    <title>Magic Empires - <?= $user_name ?></title>
    <?php
}
?>
</body>
</html>

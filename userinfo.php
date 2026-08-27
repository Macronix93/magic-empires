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
$user_id = (int)($_GET["userid"] ?? 0);

if ($user_id) {
    $query = "
        SELECT users.id, users.username, users.lastactivity, users.guildid, users.registerdate,
               users.ranking_points AS score,
               kingdoms.mapx, kingdoms.mapy
        FROM users
        INNER JOIN kingdoms ON users.mainkingdom = kingdoms.id
        WHERE users.id = ?
    ";
    $result = $db_instance->execute_query($query, [$user_id]);
    $row = $result->fetch_assoc();

    if (!$row) {
        echo "<div style='text-align: center;'>
        <p style='background-color: rgba(0, 0, 0, 0.7); display: inline-block;'>Dieser Spieler existiert nicht!
        </p></div>";
        return;
    }

    $res_all_k = $db_instance->execute_query(
            "SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ? ORDER BY id",
            [$user_id]
    );

    $all_kingdoms_html = "";
    if ($res_all_k->num_rows > 0) {
        $all_kingdoms_html .= "<div style='display: flex; flex-direction: column; gap: 10px;'>";

        while ($k = $res_all_k->fetch_assoc()) {
            $coords = e($k["mapx"]) . ":" . e($k["mapy"]);

            $all_kingdoms_html .= "
                <div class='location-wrapper' style='gap: 10px;'>
                    <span class='kingdom-name-break' title='" . e($k["kingdomname"]) . "'>
                        • " . e($k["kingdomname"]) . "
                    </span>
                    <a href='#' data-on-click='mapJump' data-x='" . e($k["mapx"]) . "' data-y='" . e($k["mapy"]) . "'>
                        $coords
                    </a>
                </div>";
        }
        $all_kingdoms_html .= "</div>";
    } else {
        $all_kingdoms_html = "<i>Keine Ländereien gefunden.</i>";
    }

    $user_name = $row["username"];
    $user_id = $row["id"];
    $last_activity = $row["lastactivity"];
    $score = $row["score"];
    $guild_id = $row["guildid"];
    $register_date = $row["registerdate"];
    $x = $row["mapx"];
    $y = $row["mapy"];

    // Get sorted list of players and calculate the rank
    $rank_query = "
        SELECT COUNT(*) + 1 AS rank 
        FROM users 
        WHERE (score > ?) 
           OR (score = ? AND id < ?)
    ";
    $result = $db_instance->execute_query($rank_query, [$score, $score, $user_id]);
    $user_rank = $result->fetch_column();

    $map = new Map($db_instance, $user);
    $minimap_html = $map->render_minimap($x, $y);
    ?>
    <table class="table" style="width: fit-content;">
        <tr>
            <td style="width: 200px;"><b>Spieler</b></td>
            <td style="width: 300px;">
                <?php
                if (time() - $last_activity > INACTIVITY_DELAY && $last_activity != 0) {
                    echo "<i>" . $user_name . "</i> (Inaktiv)";
                } else {
                    echo $user_name;
                }
                ?>
            </td>
        </tr>
        <tr>
            <td><b>Letzte Aktivität</b></td>
            <td><?= $last_activity == 0 ? "Nicht verfügbar" : date("d.m.Y \u\m  H:i:s", $last_activity) ?>
                Uhr
            </td>
        </tr>
        <tr>
            <td><b>Registriert seit</b></td>
            <td><?= date("d.m.Y H:i:s", $register_date) ?> Uhr</td>
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
            <?= "<td>" . ($guild_id == -1 ? "Keine Gilde" : $guild_id) . "</td>" ?>
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
                    <?= $minimap_html ?>
                </div>
            </td>
        </tr>
        <tr>
            <td><b>Königreiche</b></td>
            <td>
                <?= $all_kingdoms_html ?>
            </td>
        </tr>
    </table>
    <br>
    <div style="text-align: center">
        <button data-on-click="closeOverlay">
            Schließen
        </button>
    </div>
    <title>Magic Empires - <?= $user_name ?></title>
    <?php
}
?>
</body>
</html>

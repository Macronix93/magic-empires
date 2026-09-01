<?php
require_once("includes/core.php");

check_user_login($user);
?>
<!DOCTYPE html>
<html lang="de">
<?php
$script_files = ["userinfo", "guild"];

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

    $my_guild_id = $user->get_user_guild_id();
    $target_guild_id = (int)$row["guildid"];
    $is_ally = ($my_guild_id > 0 && $my_guild_id === $target_guild_id && $user->get_user_id() !== $user_id);

    $res_all_k = $db_instance->execute_query(
            "SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ? ORDER BY id",
            [$user_id]
    );

    $all_kingdoms_html = "";
    if ($res_all_k->num_rows > 0) {
        $all_kingdoms_html .= "<div style='display: flex; flex-direction: column; gap: 4px;'>";

        while ($k = $res_all_k->fetch_assoc()) {
            $coords_display = e($k["mapx"]) . ":" . e($k["mapy"]);

            if ($is_ally) {
                $target_k_obj = new Kingdom($db_instance, $k["id"]);
                $b_lvl = $target_k_obj->get_kingdom_building_level(BuildingTypes::BUILDING_BARRACKS);
                $limit = SUPPORT_LIMIT_BASE + ($b_lvl * SUPPORT_LIMIT_PER_BARRACKS);

                $res_count = $db_instance->execute_query("
                    SELECT (
                        (SELECT IFNULL(SUM(soldiercount), 0) FROM stationed_troops WHERE target_kingdom_id = ?) +
                        (SELECT IFNULL(SUM(st.soldiercount), 0) FROM sent_troops st JOIN events e ON st.eventid = e.eventid WHERE e.targetid = ? AND e.actionid = ?)
                    ) as total", [$k["id"], $k["id"], ActionTypes::ACTION_STATION_TROOPS]);
                $current = (int)$res_count->fetch_column();

                if ($current >= $limit) {
                    $support_ui = "<button disabled style='padding: 2px 5px; font-size: 10px; opacity: 0.6;'>Voll</button>";
                } else {
                    $url = "sendtroops.php?x={$k['mapx']}&y={$k['mapy']}";
                    $support_ui = "<button data-on-click='redirect' data-url='$url' style='padding: 2px 5px; font-size: 10px;'>Helfen</button>";
                }

                $all_kingdoms_html .= "
                    <div class='location-wrapper' style='display: flex; justify-content: space-between; align-items: center; padding: 2px 0;'>
                        <span class='kingdom-name-break' title='" . e($k["kingdomname"]) . "' style='flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-right: 10px;'>
                            • " . e($k["kingdomname"]) . "
                        </span>
                        <div style='display: flex; align-items: center; gap: 10px; flex-shrink: 0;'>
                            <a href='#' data-on-click='mapJump' data-x='" . e($k["mapx"]) . "' data-y='" . e($k["mapy"]) . "'>$coords_display</a>
                            $support_ui
                        </div>
                    </div>";
            } else {
                $all_kingdoms_html .= "
                    <div class='location-wrapper' style='display: flex; justify-content: space-between; align-items: center; padding: 2px 0;'>
                        <span class='kingdom-name-break' title='" . e($k["kingdomname"]) . "'>• " . e($k["kingdomname"]) . "</span>
                        <a href='#' data-on-click='mapJump' data-x='" . e($k["mapx"]) . "' data-y='" . e($k["mapy"]) . "' style='margin-left: 10px;'>$coords_display</a>
                    </div>";
            }
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
            <?= "<td>" . fnum($score, true) . "</td>" ?>
        </tr>
        <tr>
            <td><b>Rang</b></td>
            <?= "<td>" . $user_rank . "</td>" ?>
        </tr>
        <tr>
            <td>
                <b>Gilde</b>
            </td>
            <?php
            $guild_logic = new Guild($db_instance, $user);
            $my_perms = $guild_logic->get_user_permissions($user->get_user_id());

            $guild_display = "Keine Gilde";
            if ($my_guild_id !== -1) {
                $guild_logic->load_guild($my_guild_id);

                $guild_display = "<div><span style='cursor: pointer;' 
                             data-on-click='openGuildInfo' 
                             data-id='$my_guild_id'><b>[" . $guild_logic->get_tag() . "]</b></span> " . $guild_logic->get_name() . "</div>";
            }


            echo "<td style='display: flex; justify-content: space-between; align-items: center;'>$guild_display";

            if ($my_guild_id !== -1 && $my_perms["can_invite"] && $row["guildid"] == -1 && $user_id != $user->get_user_id()) {
                echo "<button data-on-click='inviteToGuildDialog' 
                                data-userid='$user_id' 
                                data-username='" . e($user_name) . "'>
                            Einladen
                        </button>
                    </td>
                  </tr>";
            }
            ?>
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

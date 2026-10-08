<?php
require_once("includes/core.php");

$user->check_user_login();
?>
<!DOCTYPE html>
<html lang="de">
<?php
$script_files = ["userinfo", "guild", "sendtroops"];

include_once("layout/head.php");
?>
<body>
<?php
$user_id = (int)($_GET["userid"] ?? 0);

if ($user_id) {
    $now = time();

    $query = "
        SELECT users.id, users.username, users.lastactivity, users.guildid, 
               users.registerdate, users.ranking_points AS score, users.is_vacation, 
               users.vacation_until, kingdoms.mapx, kingdoms.mapy
        FROM users 
        JOIN kingdoms ON users.mainkingdom = kingdoms.id 
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

    // Check, if a conversation already exists with that user
    $res_conv = $db_instance->execute_query("
        SELECT 1 FROM messages 
        WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
          AND deleted = 0 
        LIMIT 1",
            [$user->get_user_id(), $user_id, $user_id, $user->get_user_id()]
    );
    $has_conversation = ($res_conv->num_rows > 0);
    $msg_url = $has_conversation
            ? "messages.php?action=read&s=" . $user_id
            : "messages.php?action=new&receiver=" . urlencode($row["username"]);

    $res_all_k = $db_instance->execute_query(
            "SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ? ORDER BY created_at, id",
            [$user_id]
    );

    $my_guild_id = $user->get_user_guild_id();
    $target_guild_id = (int)$row["guildid"];
    $is_ally = ($my_guild_id > 0 && $my_guild_id === $target_guild_id && $user->get_user_id() !== $user_id);

    $all_kingdoms_html = "";
    if ($res_all_k->num_rows > 0) {
        $all_kingdoms_html .= "<div class='userinfo-kingdoms-scroll'>";

        while ($k = $res_all_k->fetch_assoc()) {
            $coords_display = e($k["mapx"]) . ":" . e($k["mapy"]);

            if ($is_ally) {
                $limit = new Kingdom($k["id"])->get_support_limit();

                $res_count = $db_instance->execute_query("
                    SELECT (
                        (SELECT IFNULL(SUM(soldiercount), 0) FROM stationed_troops WHERE target_kingdom_id = ?) +
                        (SELECT IFNULL(SUM(st.soldiercount), 0) FROM sent_troops st JOIN events e ON st.eventid = e.eventid WHERE e.targetid = ? AND e.actionid = ?)
                    ) as total", [$k["id"], $k["id"], ActionTypes::ACTION_STATION_TROOPS]);
                $current = (int)$res_count->fetch_column();

                if ($current >= $limit) {
                    $support_ui = "<button disabled style='padding: 2px 5px; font-size: 10px; opacity: 0.6;'>Voll</button>";
                } else {
                    $url = "ajax/send_troops.php?x={$k['mapx']}&y={$k['mapy']}";
                    $support_ui = "<button data-on-click='openOverlay' data-url='$url' style='padding: 2px 5px; font-size: 10px;'>Helfen</button>";
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
    $target_user_obj = new User($row["id"], $row["username"]);
    $user_title = $target_user_obj->get_active_title();

    $title_html = !empty($user_title) ? "<div class='user-title' style='display: inline-block; margin-left: 5px;'>&bdquo;" . e($user_title) . "&ldquo;</div>" : "";

    // Get sorted list of players and calculate the rank
    $rank_query = "SELECT COUNT(*) + 1 AS rank FROM users WHERE (score > ?) OR (score = ? AND id < ?)";
    $result = $db_instance->execute_query($rank_query, [$score, $score, $user_id]);
    $user_rank = $result->fetch_column();

    $map = new Map($user);
    $minimap_html = $map->render_minimap($x, $y);
    ?>
    <table class="table" style="width: fit-content;">
        <tr>
            <td><b>Spieler</b></td>
            <td>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <?php if ($now - $last_activity > INACTIVITY_DELAY && $last_activity != 0): ?>
                        <div><i><?= e($user_name) ?><?= $title_html ?></i></div>
                    <?php else: ?>
                        <div><?= e($user_name) ?><?= $title_html ?></div>
                    <?php endif; ?>
                    <?php
                    if ($user_id !== $user->get_user_id()): ?>
                        <div data-on-click="redirect" data-url="<?= $msg_url ?>"
                             style="font-size: 18px; cursor: pointer;"><?= Messages::wrap_emojis("✉️") ?>
                        </div>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
        $is_vacation = (!empty($row["is_vacation"]) && (int)$row["vacation_until"] > $now);
        if ($is_vacation):
            ?>
            <tr>
                <td><b>Status</b></td>
                <td>
                    <span style="color: #3498db; font-weight: bold;">🏖️ Im Urlaubsmodus</span><br>
                </td>
            </tr>
        <?php endif; ?>
        <tr>
            <td><b>Zuletzt aktiv</b></td>
            <td>
                <?php
                $is_me = ($user->get_user_id() === $user_id);

                if ($is_ally || $is_me) {
                    echo ($last_activity == 0) ? "Nicht verfügbar" : date("d.m.Y \u\m H:i:s", $last_activity) . " Uhr";
                } else {
                    echo ($last_activity == 0) ? "Nicht verfügbar" : format_relative_activity($last_activity, $now);
                }
                ?>
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
            $guild_logic = new Guild($user);
            $my_perms = $guild_logic->get_user_permissions($user->get_user_id());

            $guild_display = "Keine Gilde";
            if ($guild_id !== -1) {
                $guild_logic->load_guild($guild_id);

                $guild_display = "<div><span style='cursor: pointer;' 
                             data-on-click='openGuildInfo' 
                             data-id='$guild_id'><b>[" . $guild_logic->get_tag() . "]</b></span> " . $guild_logic->get_name() . "</div>";
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

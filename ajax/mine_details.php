<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    check_user_login($user);

    $x = (int)($_GET["x"] ?? 0);
    $y = (int)($_GET["y"] ?? 0);
    $uid = $user->get_user_id();

    $mine = $db_instance->execute_query("SELECT * FROM mines WHERE mapx = ? AND mapy = ?", [$x, $y])->fetch_assoc();

    if (!$mine) {
        echo show_error_box("Diese Mine existiert nicht mehr.");
        exit;
    }

    $mine_id = (int)$mine["id"];
    $my_gid = $user->get_user_guild_id();
    $claimed_gid = (int)($mine["claimed_guild_id"] ?? 0);
    $claimed_uid = (int)($mine["claimed_user_id"] ?? 0);

    $has_my_troops = (bool)$db_instance->execute_query(
        "SELECT 1 FROM mine_stationed_troops WHERE mine_id = ? AND user_id = ? LIMIT 1",
        [$mine_id, $uid]
    )->fetch_column();

    $is_authorized = $has_my_troops || ($my_gid > 0 && $claimed_gid === $my_gid) || ($claimed_uid === $uid);

    if (!$is_authorized) {
        echo show_error_box("Zugriff verweigert: Du kannst die Besatzung einer fremden Mine nicht einsehen!");
        exit;
    }

    $query = "
        SELECT mst.user_id, mst.kingdom_id, u.username, k.kingdomname, k.mapx, k.mapy,
               SUM(mst.soldiercount) AS total_soldiers,
               (mst.user_id = ?) AS is_me
        FROM mine_stationed_troops mst
        JOIN users u ON mst.user_id = u.id
        JOIN kingdoms k ON mst.kingdom_id = k.id
        WHERE mst.mine_id = ?
        GROUP BY mst.user_id, mst.kingdom_id, u.username, k.kingdomname, k.mapx, k.mapy
        ORDER BY is_me DESC, u.username, k.kingdomname
    ";
    $res_miners = $db_instance->execute_query($query, [$uid, $mine_id])->fetch_all(MYSQLI_ASSOC);

    if (empty($res_miners)) {
        echo "<p style='text-align: center; opacity: 0.7;'>Aktuell sind keine Truppen in dieser Mine stationiert.</p>";
        echo "<div style='text-align: center;'><button data-on-click='closeOverlay'>Schließen</button></div>";
        exit;
    }

    echo "<h3 class='title-border' style='margin-top: 0;'>Besatzung der Mine</h3>";
    echo "<table class='table' style='width: 100%; margin-bottom: 20px;'>
            <colgroup>
                <col style='width: 30%;'>
                <col style='width: 30%;'>
                <col style='width: auto;'>
                <col style='width: 110px;'>
            </colgroup>
            <tr>
                <td class='td-gradient td-center'><b>Spieler</b></td>
                <td class='td-gradient td-center'><b>Königreich</b></td>
                <td class='td-gradient td-center'><b>Truppen</b></td>
                <td class='td-gradient td-center'><b>Aktion</b></td>
            </tr>";

    foreach ($res_miners as $m) {
        $is_me = (bool)$m["is_me"];
        $m_user = new User((int)$m["user_id"], $m["username"]);
        $c_link = "<a href='map.php?startx={$m["mapx"]}&starty={$m["mapy"]}' data-on-click='mapJump' data-x='{$m["mapx"]}' data-y='{$m["mapy"]}'>{$m["mapx"]}:{$m["mapy"]}</a>";

        $res_troops = $db_instance->execute_query("
            SELECT sl.soldiername, sl.icon, SUM(mst.soldiercount) as count
            FROM mine_stationed_troops mst
            JOIN soldier_list sl ON mst.soldier_id = sl.id
            WHERE mst.mine_id = ? AND mst.user_id = ? AND mst.kingdom_id = ?
            GROUP BY sl.id, sl.soldiername, sl.icon
        ", [$mine_id, $m["user_id"], $m["kingdom_id"]]);

        $troop_badges = "<div style='display: flex; flex-wrap: wrap; gap: 4px; justify-content: center;'>";
        while ($t = $res_troops->fetch_assoc()) {
            $troop_badges .= "
                <div class='unit-badge' title='{$t["soldiername"]}' style='padding: 2px 5px;'>
                    <img src='images/icons/{$t["icon"]}.png' class='ressource-icons' alt=''>
                    <b>{$t["count"]}</b>
                </div>";
        }
        $troop_badges .= "</div>";

        $action_cell = "-";
        if ($is_me) {
            $action_cell = "
                <form method='POST' action='map.php' style='margin: 0;'>
                    <input type='hidden' name='recall_mine_troops' value='1'>
                    <input type='hidden' name='mine_x' value='$x'>
                    <input type='hidden' name='mine_y' value='$y'>
                    <input type='hidden' name='kingdom_id' value='{$m["kingdom_id"]}'>
                    <button type='submit' class='btn-delete'>
                        Heimrufen
                    </button>
                </form>";
        }

        $row_style = $is_me ? "style='background: rgba(212, 175, 55, 0.1);'" : "";

        echo "<tr $row_style>
                <td class='td-center'>" . $m_user->render_user() . "</td>
                <td class='td-center'>
                    <b>" . e($m["kingdomname"]) . "</b><br>
                    <small>($c_link)</small>
                </td>
                <td class='td-center'>$troop_badges</td>
                <td class='td-center'>$action_cell</td>
              </tr>";
    }

    echo "</table>";
    echo "<div style='text-align: center;'><button data-on-click='closeOverlay'>Schließen</button></div>";
} else {
    change_location("map.php");
}
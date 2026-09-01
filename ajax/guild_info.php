<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $guild_id = (int)($_GET["id"] ?? 0);
    $guild_logic = new Guild($db_instance, $user);
    $data = $guild_logic->get_guild_details($guild_id);

    if (!$data) {
        echo show_error_box("Gilde nicht gefunden.");
        exit;
    }

    $my_guild_id = $user->get_user_guild_id();
    $user_score = $user->get_user_score();
    $can_join = ($my_guild_id <= 0 && $user_score >= $data["min_score"] && $data["members"] < $data["max_members"]);

    echo "<div style='text-align: center;'><img src='" . $guild_logic->get_avatar() . "' class='guild-avatar' alt='Wappen'><h2>[" . e($data["tag"]) . "] " . e($data["name"]) . "</h2>";

    if ($data["motto"]) {
        echo "<p class='guild-motto' style='color: rgb(208, 208, 208); opacity: 0.7;'><i>&bdquo;" . e($data["motto"]) . "&ldquo;</i></p>";
    }

    echo "</div>";

    echo "<table class='table' style='width: 70%;'>
            <tr><td>Mitglieder:</td><td>" . $data["members"] . " / " . $data["max_members"] . "</td></tr>
            <tr><td>Beitritt ab:</td><td>" . ($data["min_score"] > 0 ? fnum($data["min_score"], true) : "-") . "</td></tr>
            <tr><td>Gilden-Punkte:</td><td>" . fnum($data["score"]) . "</td></tr>
        </table>";

    if ($can_join) {
        echo "<div style='text-align: center; margin: 10px 0;'>
            <button data-on-click='joinGuild' data-id='{$data["id"]}' data-name='" . e($data["name"]) . "'>Gilde beitreten</button>
          </div>";
    }

    echo "<div class='title-border' style='margin-top: 15px;'>Mitglieder-Liste</div>";
    echo "<table class='table' style='width: 80%;'>
        <tr>
            <td class='td-gradient td-center'><b>Spieler</b></td>
            <td class='td-gradient td-center'><b>Rang</b></td>
            <td class='td-gradient td-center'><b>Punkte</b></td>
        </tr>";

    $members = $guild_logic->get_members_detailed($guild_id);
    while ($m = $members->fetch_assoc()) {
        $guild_user = new User($m["id"], $m["username"]);

        echo "<tr>
            <td>
            <div class='image-and-user'>
                <img class='user-image' src='{$guild_user->get_avatar()}' alt=''>
                <a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid={$m["id"]}' data-title='Spieler-Info'>" . e($m["username"]) . "</a>
            </div>
            </td>
            <td style='color: {$m["rank_color"]}; font-weight: bold;'>" . e($m["rank_name"]) . "</td>
            <td class='td-center'>" . fnum($m["ranking_points"]) . "</td>
          </tr>";
    }
    echo "</table><br>";

    echo "<div style='text-align: center'><button data-on-click='closeOverlay'>Schließen</button></div>";
} else {
    change_location("guilds.php");
}
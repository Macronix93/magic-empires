<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $type = $_GET["type"] ?? '';
    $id = (int)($_GET["id"] ?? 0);

    if (empty($type) || $id <= 0) die("Ungültige Anfrage");

    $query = "SELECT r.emoji, u.username, u.id as uid 
              FROM reactions r 
              JOIN users u ON r.user_id = u.id 
              WHERE r.entity_type = ? AND r.entity_id = ? 
              ORDER BY r.emoji, u.username";

    $res = $db_instance->execute_query($query, [$type, $id]);

    echo "<div style='text-align: left; padding: 10px;'>";
    echo "<h3 class='title-border' style='margin-top: 0;'>Reaktionen</h3>";

    $current_emoji = "";
    $first = true;

    while ($row = $res->fetch_assoc()) {
        if ($current_emoji !== $row["emoji"]) {
            if (!$first) {
                echo "</div></div>";
            }

            $current_emoji = $row["emoji"];
            $first = false;

            echo "<div class='reaction-group' style='display: flex; align-items: flex-start; gap: 20px; padding: 10px; background: rgba(0,0,0,0.2); border-radius: 5px;'>";
            echo "<div class='emoji-side' style='font-size: 26px; min-width: 40px; text-align: center; line-height: 1;'>" . wrap_emojis($current_emoji) . "</div>";
            echo "<div class='user-list' style='flex: 1; display: flex; flex-direction: column; gap: 8px;'>";
        }

        $player = new User($row["uid"], $row["username"]);
        $avatar = $player->get_avatar();

        echo "<div class='image-and-user' style='margin: 0;'>
                <img src='$avatar' class='user-image' style='width: 24px; height: 24px;' alt='Avatar'>
                <a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid={$row["uid"]}' data-title='Spieler-Info'>" . e($row["username"]) . "</a>
              </div>";
    }

    if (!$first) {
        echo "</div></div>";
    } else {
        echo "<p style='text-align: center; opacity: 0.6;'>Bisher keine Reaktionen vorhanden.</p>";
    }

    echo "</div>";
    echo "<div style='text-align: center;'><button data-on-click='closeOverlay'>Schließen</button></div>";
}
<?php
require_once("../includes/ajax_core.php");

$type = get_str("type");
$id = get_int("id");

if (!Messages::is_valid_reaction_type($type) || $id <= 0) {
    echo show_error_box("Ungültige Anfrage");
    exit;
}
if (!Messages::can_access_reaction_entity($type, $id, $user)) {
    echo show_error_box("Kein Zugriff auf diese Nachricht.");
    exit;
}

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
        echo "<div class='emoji-side' style='font-size: 26px; min-width: 40px; text-align: center; line-height: 1;'>" . Messages::wrap_emojis($current_emoji) . "</div>";
        echo "<div class='user-list' style='flex: 1; display: flex; flex-direction: column; gap: 8px;'>";
    }

    $player = new User($row["uid"], $row["username"]);

    echo $player->render_user();
}

if (!$first) {
    echo "</div></div>";
} else {
    echo "<p style='text-align: center; opacity: 0.6;'>Bisher keine Reaktionen vorhanden.</p>";
}

echo "</div>";
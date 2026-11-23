<?php
require_once("../includes/ajax_core.php");

$type = get_str("type");
$id = get_int("id");
$allowed_types = ["news", "world_chat", "chat", "guild_chat"];
if (!in_array($type, $allowed_types, true) || $id <= 0) die("Ungültige Anfrage");

$uid = $user->get_user_id();
$has_access = match ($type) {
    "news" => (bool)$db_instance->execute_query("SELECT 1 FROM news WHERE id = ? LIMIT 1",
        [$id])->fetch_column(),
    "world_chat" => (bool)$db_instance->execute_query("SELECT 1 FROM world_chat WHERE id = ? AND deleted = 0 LIMIT 1",
        [$id])->fetch_column(),
    "chat" => (bool)$db_instance->execute_query("SELECT 1 FROM messages WHERE id = ? AND (senderid = ? OR receiverid = ?) AND deleted = 0 LIMIT 1",
        [$id, $uid, $uid])->fetch_column(),
    "guild_chat" => ($user->get_user_guild_id() > 0) && $db_instance->execute_query("SELECT 1 FROM guild_chat WHERE id = ? AND guild_id = ? AND deleted = 0 LIMIT 1",
            [$id, $user->get_user_guild_id()])->fetch_column(),
    default => false
};

if (!$has_access) {
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
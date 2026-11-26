<?php
require_once("../includes/ajax_core.php");
require_post();

session_write_close();

$type = post_str("type");
$id = post_int("id");
$emoji = post_str("emoji");
$mode = post_str("mode", "full");
if (!in_array($mode, ["full", "btn_only", "badges_only"], true)) {
    $mode = "full";
}

if (!Messages::is_valid_reaction_type($type) || $id <= 0 || empty($emoji)) {
    send_json(["success" => false, "error" => "Ungültige Daten"]);
}

$allowed_emojis = Messages::get_chat_emojis();
if (!in_array($emoji, $allowed_emojis, true)) {
    send_json(["success" => false, "error" => "Dieses Symbol ist als Reaktion nicht erlaubt!"]);
}

if (!Messages::can_access_reaction_entity($type, $id, $user)) {
    send_json(["success" => false, "error" => "Kein Zugriff auf diese Nachricht."]);
}

$uid = $user->get_user_id();

$db_instance->begin_transaction();
try {
    $db_instance->execute_query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$uid]);

    $check = $db_instance->execute_query(
        "SELECT id FROM reactions WHERE entity_type = ? AND entity_id = ? AND user_id = ? AND emoji = ?",
        [$type, $id, $uid, $emoji]
    );

    if ($check->num_rows > 0) {
        $db_instance->execute_query("DELETE FROM reactions WHERE id = ?", [$check->fetch_assoc()["id"]]);
    } else {
        $current_count = (int)$db_instance->execute_query(
            "SELECT COUNT(*) FROM reactions WHERE entity_type = ? AND entity_id = ? AND user_id = ?",
            [$type, $id, $uid]
        )->fetch_column();

        if ($current_count >= MAX_REACTIONS_PER_USER) {
            $db_instance->rollback();
            send_json([
                "success" => false,
                "error" => "Limit erreicht! Du kannst maximal " . MAX_REACTIONS_PER_USER . " Reaktionen geben."
            ]);
        }

        $db_instance->execute_query(
            "INSERT IGNORE INTO reactions (entity_type, entity_id, user_id, emoji) VALUES (?, ?, ?, ?)",
            [$type, $id, $uid, $emoji]
        );
    }

    $db_instance->commit();
} catch (Throwable $e) {
    $db_instance->rollback();
    send_json(["success" => false, "error" => "Fehler beim Speichern der Reaktion."]);
}

send_json([
    "success" => true,
    "html" => Messages::render_reactions_bar($type, $id, $user, $mode)
]);
<?php
require_once("../includes/core.php");

session_write_close();

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $type = $_POST["type"] ?? '';
    $id = (int)($_POST["id"] ?? 0);
    $emoji = $_POST["emoji"] ?? '';
    $mode = $_POST["mode"] ?? "full";
    $uid = $user->get_user_id();

    $allowed_types = ["news", "world_chat", "chat"];
    if (!in_array($type, $allowed_types) || $id <= 0 || empty($emoji)) {
        echo json_encode(["error" => "Ungültige Daten"]);
        exit;
    }

    $allowed_emojis = get_chat_emojis();

    if (!in_array($emoji, $allowed_emojis)) {
        echo json_encode(["error" => "Dieses Symbol ist als Reaktion nicht erlaubt!"]);
        exit;
    }

    $check = $db_instance->execute_query(
        "SELECT id FROM reactions WHERE entity_type = ? AND entity_id = ? AND user_id = ? AND emoji = ?",
        [$type, $id, $uid, $emoji]
    );

    if ($check->num_rows > 0) {
        $db_instance->execute_query("DELETE FROM reactions WHERE id = ?", [$check->fetch_assoc()["id"]]);
    } else {
        $count_res = $db_instance->execute_query(
            "SELECT COUNT(*) as total FROM reactions WHERE entity_type = ? AND entity_id = ? AND user_id = ?",
            [$type, $id, $uid]
        );
        $current_count = $count_res->fetch_assoc()["total"];

        if ($current_count >= MAX_REACTIONS_PER_USER) {
            echo json_encode([
                "success" => false,
                "error" => "Limit erreicht! Du kannst maximal " . MAX_REACTIONS_PER_USER . " Reaktionen geben."
            ]);
            exit;
        }

        $db_instance->execute_query(
            "INSERT IGNORE INTO reactions (entity_type, entity_id, user_id, emoji) VALUES (?, ?, ?, ?)",
            [$type, $id, $uid, $emoji]
        );
    }

    echo json_encode([
        "success" => true,
        "html" => render_reactions_bar($type, $id, $user, $mode)
    ]);
} else {
    change_location("messages.php");
}
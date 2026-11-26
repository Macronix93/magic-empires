<?php
require_once("../includes/ajax_core.php");
require_post();

$type = post_str("type", "private");

$allowed_types = ["private", "world", "guild"];
if (!in_array($type, $allowed_types, true)) {
    send_json(["success" => false, "error" => "Ungültiger Chat-Typ!"]);
}

$u_id = $user->get_user_id();
$u_name = $user->get_user_name();
$now = time();

// Rate limit check
$remaining_seconds = Messages::check_rate_limit();
if ($remaining_seconds > 0) {
    send_json([
        "error" => "Du schickst zu viele Nachrichten! Warte bitte:",
        "counter" => $remaining_seconds
    ]);
}

// Validate text
$validation = Messages::clean_and_validate_chat_text(post_str("text"));
if (isset($validation["error"])) {
    send_json(["error" => $validation["error"]]);
}
$cleaned_text = $validation["text"];

$message_id = 0;

if ($type === "world") {
    $res = $db_instance->execute_query(
        "INSERT INTO world_chat (userid, username, message, date) VALUES (?, ?, ?, ?) RETURNING id",
        [$u_id, $u_name, $cleaned_text, $now]
    );
    $message_id = (int)$res->fetch_assoc()["id"];

    $db_instance->execute_query("UPDATE users SET last_world_chat_id = ? WHERE id = ?", [$message_id, $u_id]);
} else if ($type === "guild") {
    $guild_id = $user->get_user_guild_id();
    if ($guild_id <= 0) {
        send_json(["error" => "Du bist in keiner Gilde!"]);
    }

    $res = $db_instance->execute_query(
        "INSERT INTO guild_chat (guild_id, userid, username, message, date) VALUES (?, ?, ?, ?, ?) RETURNING id",
        [$guild_id, $u_id, $u_name, $cleaned_text, $now]
    );
    $message_id = (int)$res->fetch_assoc()["id"];

    $db_instance->execute_query("UPDATE users SET last_guild_chat_id = ? WHERE id = ?", [$message_id, $u_id]);
} else { // private
    $client_token = post_str("token");
    $session_token = $_SESSION["active_chat_token"] ?? "";
    $receiver_id = post_int("receiver");

    if ($session_token === "" || !hash_equals($session_token, $client_token)) {
        send_json(["error" => "redirect", "chatPartner" => "privmsgs"]);
    }

    if ($receiver_id <= 0 || $receiver_id === $u_id) {
        send_json(["error" => "Kein gültiger Empfänger angegeben!"]);
    }

    $res_u = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$receiver_id]);
    $receiver_name = $res_u->fetch_column();
    if (!$receiver_name) {
        send_json(["error" => "Dieser Spieler existiert nicht!"]);
    }

    $res = $db_instance->execute_query(
        "INSERT INTO messages (senderid, sender, receiverid, receiver, date, message) VALUES (?, ?, ?, ?, ?, ?) RETURNING id",
        [$u_id, $u_name, $receiver_id, $receiver_name, $now, $cleaned_text]
    );
    $message_id = (int)$res->fetch_assoc()["id"];

    // Push Message
    $res_unread = $db_instance->execute_query(
        "SELECT COUNT(*) FROM messages WHERE senderid = ? AND receiverid = ? AND hasread = 0 AND deleted = 0 AND id != ?",
        [$u_id, $receiver_id, $message_id]
    );
    if ((int)$res_unread->fetch_column() === 0) {
        send_user_push(
            $receiver_id,
            "📩 Neue Nachricht",
            "$u_name hat dir eine Nachricht geschrieben.",
            "messages",
            "messages.php?action=read&s=" . $u_id
        );
    }
}

$bubble_html = Messages::render_chat_bubble(
    $type,
    $message_id,
    $u_id,
    $u_name,
    $cleaned_text,
    $now,
    $user,
    $user->get_avatar(),
    []
);

send_json([
    "lastId" => $message_id,
    "html" => $bubble_html
]);
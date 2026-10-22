<?php
require_once("../includes/core.php");

if (!isset($_SERVER["HTTP_X_REQUESTED_WITH"]) || $_SERVER["HTTP_X_REQUESTED_WITH"] !== "XMLHttpRequest") {
    change_location("messages.php");
    exit;
}

if (!$user->is_logged_in() || $user->get_user_id() <= 0) {
    echo json_encode(["error" => "redirect", "chatPartner" => "privmsgs"]);
    exit;
}

$type = $_POST["type"] ?? "private";
$u_id = $user->get_user_id();
$u_name = $user->get_user_name();
$now = time();

// Rate limit check
$remaining_seconds = Messages::check_rate_limit();
if ($remaining_seconds > 0) {
    echo json_encode([
        "error" => "Du schickst zu viele Nachrichten! Warte bitte:",
        "counter" => $remaining_seconds
    ]);
    exit;
}

// Validate text
$validation = Messages::clean_and_validate_chat_text($_POST["text"] ?? "");
if (isset($validation["error"])) {
    echo json_encode(["error" => $validation["error"]]);
    exit;
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
        echo json_encode(["error" => "Du bist in keiner Gilde!"]);
        exit;
    }

    $res = $db_instance->execute_query(
        "INSERT INTO guild_chat (guild_id, userid, username, message, date) VALUES (?, ?, ?, ?, ?) RETURNING id",
        [$guild_id, $u_id, $u_name, $cleaned_text, $now]
    );
    $message_id = (int)$res->fetch_assoc()["id"];
    $db_instance->execute_query("UPDATE users SET last_guild_chat_id = ? WHERE id = ?", [$message_id, $u_id]);

} else { // private
    $client_token = $_POST["token"] ?? "";
    $session_token = $_SESSION["active_chat_token"] ?? "";
    $receiver_id = (int)($_POST["receiver"] ?? 0);

    if ($client_token !== $session_token) {
        echo json_encode(["error" => "redirect", "chatPartner" => "privmsgs"]);
        exit;
    }

    if ($receiver_id <= 0) {
        echo json_encode(["error" => "Kein gültiger Empfänger angegeben!"]);
        exit;
    }

    $res_u = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$receiver_id]);
    $receiver_name = $res_u->fetch_column();
    if (!$receiver_name) {
        echo json_encode(["error" => "Dieser Spieler existiert nicht!"]);
        exit;
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
    $user->get_avatar()
);

echo json_encode([
    "lastId" => $message_id,
    "html" => $bubble_html
]);
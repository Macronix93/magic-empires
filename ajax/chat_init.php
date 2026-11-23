<?php
require_once("../includes/ajax_core.php");

$type = get_str("type", "private");
$messages = new Messages($user);
$chat_data = ["html" => "", "last_id" => 0];

if ($type === "private") {
    $sender_id = get_int("s");
    $token = get_str("token");
    $session_token = $_SESSION["active_chat_token"] ?? "";

    if ($sender_id <= 0 || $session_token === "" || !hash_equals($session_token, $token)) {
        echo json_encode(["error" => "redirect"]);
        exit;
    }

    $chat_data = $messages->get_private_history_html($sender_id);
} else if ($type === "world") {
    $chat_data = $messages->get_world_history_html();
} else if ($type === "guild") {
    $chat_data = $messages->get_guild_history_html();
}

$unreads = $user->get_unread_counts();
$inbox_only = $unreads["pms"] + $unreads["server"] + $unreads["support"];

$rate_limit_rem = 0;
if (($_SESSION["message_count"] ?? 0) >= MAX_MESSAGES_RATELIMIT && time() < ($_SESSION["message_timeframe_end"] ?? 0)) {
    $rate_limit_rem = $_SESSION["message_timeframe_end"] - time();
}

send_json([
    "html" => $chat_data["html"],
    "lastId" => (int)$chat_data["last_id"],
    "rateLimitSeconds" => $rate_limit_rem,
    "badges" => [
        "priv" => $inbox_only,
        "world" => (int)$unreads["world"],
        "guild" => (int)$unreads["guild"],
        "suggestions" => (int)($unreads["suggestions"] ?? 0),
        "achievements" => (int)($unreads["achievements"] ?? 0),
        "arena" => (int)($unreads["arena"] ?? 0),
        "arena_coins" => Arena::has_available_coins($user->get_user_id()),
        "forum" => (int)($unreads["forum"] ?? 0)
    ]
]);
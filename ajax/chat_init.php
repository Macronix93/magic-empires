<?php
require_once("../includes/ajax_core.php");

$type = $_GET["type"] ?? "private";
$messages = new Messages($user);
$chat_data = ["html" => "", "last_id" => 0];

if ($type === "private") {
    $sender_id = (int)$_GET["s"];
    $token = $_GET["token"] ?? "";
    $chat_partner = $_GET["partner_name"] ?? "Unbekannt";

    if ($token !== $_SESSION["active_chat_token"]) {
        echo json_encode(["error" => "redirect"]);
        exit;
    }

    $chat_data = $messages->get_private_history_html($sender_id, $chat_partner);
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

echo json_encode([
    "html" => $chat_data["html"],
    "lastId" => (int)$chat_data["last_id"],
    "privUnread" => $inbox_only,
    "worldUnread" => (int)$unreads["world"],
    "guildUnread" => (int)$unreads["guild"],
    "achievementsUnread" => (int)($unreads["achievements"] ?? 0),
    "rateLimitSeconds" => $rate_limit_rem
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
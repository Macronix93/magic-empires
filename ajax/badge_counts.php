<?php
require_once("../includes/ajax_core.php");

session_write_close();

$unreads = $user->get_unread_counts();
$inbox_only = $unreads["pms"] + $unreads["server"] + $unreads["support"];

echo json_encode([
    "priv" => $inbox_only,
    "world" => (int)$unreads["world"],
    "guild" => (int)$unreads["guild"],
    "suggestions" => (int)$unreads["suggestions"],
    "achievements" => (int)($unreads["achievements"] ?? 0),
    "arena" => (int)($unreads["arena"] ?? 0),
    "arena_coins" => Arena::has_available_coins($user->get_user_id()) ? 1 : 0
]);
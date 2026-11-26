<?php
require_once("../includes/ajax_core.php");

$unreads = $user->get_unread_counts();
session_write_close();

$inbox_only = $unreads["pms"] + $unreads["server"] + $unreads["support"];

send_json([
    "priv" => $inbox_only,
    "world" => (int)$unreads["world"],
    "guild" => (int)$unreads["guild"],
    "suggestions" => (int)$unreads["suggestions"],
    "achievements" => (int)($unreads["achievements"] ?? 0),
    "arena" => (int)($unreads["arena"] ?? 0),
    "arena_coins" => Arena::has_available_coins($user->get_user_id()),
    "forum" => (int)($unreads["forum"] ?? 0),
]);
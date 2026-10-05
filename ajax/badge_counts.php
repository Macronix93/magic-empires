<?php
require_once("../includes/core.php");

session_write_close();

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    if (!$user->is_logged_in() || $user->get_user_id() <= 0) {
        echo json_encode(["priv" => 0, "world" => 0, "guild" => 0]);
        exit;
    }

    $unreads = $user->get_unread_counts();
    $inbox_only = $unreads["pms"] + $unreads["server"] + $unreads["support"];

    echo json_encode([
        "priv" => $inbox_only,
        "world" => (int)$unreads["world"],
        "guild" => (int)$unreads["guild"],
        "suggestions" => (int)$unreads["suggestions"]
    ]);
}
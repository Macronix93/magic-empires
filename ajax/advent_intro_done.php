<?php
require_once("../includes/core.php");

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_SERVER["HTTP_X_REQUESTED_WITH"])) {
    if (!verify_csrf_token()) {
        echo json_encode(["success" => false]);
        exit;
    }

    $db_instance->execute_query("UPDATE users SET advent_intro_seen = 1 WHERE id = ?", [$user->get_user_id()]);

    echo json_encode(["success" => true]);
}
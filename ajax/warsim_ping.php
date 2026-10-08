<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest") {
    if ($user->is_logged_in()) {
        Achievement::unlock($user->get_user_id(), AchievementTypes::ACHIEVEMENT_SECRET_WARSIM);

        echo json_encode(["success" => true]);
        exit;
    }
}

echo json_encode(["success" => false]);
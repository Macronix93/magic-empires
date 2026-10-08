<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest") {
    if ($user->is_logged_in()) {
        $opened_at = $_SESSION["faq_opened_at"] ?? 0;
        $now = time();
        $required_seconds = ACHIEVEMENT_FAQ_OPEN_TIME;

        if ($opened_at > 0 && ($now - $opened_at) >= ($required_seconds - 1)) {
            Achievement::unlock($user->get_user_id(), AchievementTypes::ACHIEVEMENT_SECRET_FAQ);

            unset($_SESSION["faq_opened_at"]);

            echo json_encode(["success" => true]);
            exit;
        }
    }
}

echo json_encode(["success" => false]);
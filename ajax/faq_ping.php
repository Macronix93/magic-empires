<?php
require_once("../includes/ajax_core.php");

$opened_at = $_SESSION["faq_opened_at"] ?? 0;
$now = time();
$required_seconds = ACHIEVEMENT_FAQ_OPEN_TIME;

if ($opened_at > 0 && ($now - $opened_at) >= ($required_seconds - 1)) {
    Achievement::unlock($user->get_user_id(), AchievementTypes::ACHIEVEMENT_SECRET_FAQ);

    unset($_SESSION["faq_opened_at"]);

    echo json_encode(["success" => true]);
    exit;
}

echo json_encode(["success" => false]);
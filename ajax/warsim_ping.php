<?php
require_once("../includes/ajax_core.php");

Achievement::unlock($user->get_user_id(), AchievementTypes::ACHIEVEMENT_SECRET_WARSIM);

echo json_encode(["success" => true]);
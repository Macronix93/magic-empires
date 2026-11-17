<?php
require_once("../includes/ajax_core.php");
require_post();

Achievement::unlock($user->get_user_id(), AchievementTypes::ACHIEVEMENT_SECRET_WARSIM);

echo json_encode(["success" => true]);
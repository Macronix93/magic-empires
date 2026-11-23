<?php
require_once("../includes/ajax_core.php");
require_post();

if (empty($_SESSION["warsim_opened"])) {
    send_json(["success" => false]);
}

Achievement::unlock($user->get_user_id(), AchievementTypes::ACHIEVEMENT_SECRET_WARSIM);

unset($_SESSION["warsim_opened"]);
send_json(["success" => true]);
<?php
require_once("../includes/ajax_core.php");
require_post();

$db_instance->execute_query("UPDATE users SET advent_intro_seen = 1 WHERE id = ?", [$user->get_user_id()]);

send_json(["success" => true]);
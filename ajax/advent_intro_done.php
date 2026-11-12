<?php
require_once("../includes/ajax_core.php");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    exit;
}

$db_instance->execute_query("UPDATE users SET advent_intro_seen = 1 WHERE id = ?", [$user->get_user_id()]);

echo json_encode(["success" => true]);
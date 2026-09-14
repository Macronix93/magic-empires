<?php
require_once("../includes/core.php");

check_user_login($user);

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!isset($data["endpoint"], $data["keys"]["p256dh"], $data["keys"]["auth"])) {
    exit;
}

$uid = $user->get_user_id();
$endpoint = $data["endpoint"];
$key = $data["keys"]["p256dh"];
$token = $data["keys"]["auth"];

$db_instance->execute_query("
    INSERT INTO user_push_subscriptions (user_id, endpoint, public_key, auth_token, created_at)
    VALUES (?, ?, ?, ?, UNIX_TIMESTAMP())
", [$uid, $endpoint, $key, $token]);

echo json_encode(["success" => true]);
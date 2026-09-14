<?php
require_once("../includes/core.php");
check_user_login($user);

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!isset($data["endpoint"])) {
    echo json_encode(["success" => false, "error" => "Kein Endpunkt übergeben."]);
    exit;
}

$uid = $user->get_user_id();
$endpoint = $data["endpoint"];

$db_instance->execute_query(
    "DELETE FROM user_push_subscriptions WHERE user_id = ? AND endpoint = ?",
    [$uid, $endpoint]
);

echo json_encode(["success" => true]);
<?php
require_once("../includes/ajax_core.php");
require_post();

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data) || !is_string($data["endpoint"] ?? null)) {
    echo json_encode(["success" => false, "error" => "Kein gültiger Endpunkt übergeben."]);
    exit;
}

$uid = $user->get_user_id();
$endpoint = $data["endpoint"];

$db_instance->execute_query(
    "DELETE FROM user_push_subscriptions WHERE user_id = ? AND endpoint = ?",
    [$uid, $endpoint]
);

echo json_encode(["success" => true]);
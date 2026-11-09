<?php
require_once(__DIR__ . "/core.php");

if (!isset($_SERVER["HTTP_X_REQUESTED_WITH"]) || strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) !== "xmlhttprequest") {
    change_location("overview.php");
}

$user->check_user_login();

if ($_SERVER["REQUEST_METHOD"] === "POST" && !verify_csrf_token()) {
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(["success" => false, "error" => "Ungültiger Sicherheits-Token!"]);
    exit;
}
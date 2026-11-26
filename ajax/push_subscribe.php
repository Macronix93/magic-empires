<?php
require_once("../includes/ajax_core.php");
require_post();

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data) || !is_string($data["endpoint"] ?? null) || !is_array($data["keys"] ?? null) ||
    !is_string($data["keys"]["p256dh"] ?? null) || !is_string($data["keys"]["auth"] ?? null)) {
    send_json(["success" => false, "error" => "Ungültige Push-Daten."]);
}

$endpoint = (string)$data["endpoint"];
$parts = parse_url($endpoint);
$host = strtolower($parts["host"] ?? "");

$allowed_exact = ["fcm.googleapis.com", "android.googleapis.com"];
$allowed_suffixes = [
    ".push.services.mozilla.com",
    ".notify.windows.com",
    ".push.apple.com",
    ".push.samsungosp.com"
];

$is_allowed_host = in_array($host, $allowed_exact, true);
if (!$is_allowed_host && $host !== "") {
    foreach ($allowed_suffixes as $suffix) {
        if (str_ends_with($host, $suffix)) {
            $is_allowed_host = true;
            break;
        }
    }
}

if (!filter_var($endpoint, FILTER_VALIDATE_URL) || ($parts["scheme"] ?? "") !== "https" || isset($parts["port"]) ||
    isset($parts["user"]) || isset($parts["pass"]) || !$is_allowed_host) {
    send_json(["success" => false, "error" => "Ungültiger Push-Endpunkt."]);
}

$key = $data["keys"]["p256dh"];
$token = $data["keys"]["auth"];

if (!preg_match('/^[A-Za-z0-9\-_]{20,255}$/', $key) || !preg_match('/^[A-Za-z0-9\-_]{10,255}$/', $token)) {
    send_json(["success" => false, "error" => "Ungültige Push-Schlüssel."]);
}

if (strlen($endpoint) > 512 || strlen($key) > 255 || strlen($token) > 255) {
    send_json(["success" => false, "error" => "Push-Daten überschreiten die zulässige Länge."]);
}

$uid = $user->get_user_id();
$db_instance->execute_query("
    INSERT INTO user_push_subscriptions (user_id, endpoint, public_key, auth_token, created_at)
    VALUES (?, ?, ?, ?, UNIX_TIMESTAMP())
    ON DUPLICATE KEY UPDATE
        user_id = VALUES(user_id),
        public_key = VALUES(public_key),
        auth_token = VALUES(auth_token),
        created_at = UNIX_TIMESTAMP()
", [$uid, $endpoint, $key, $token]);

$db_instance->execute_query("
    DELETE FROM user_push_subscriptions
    WHERE user_id = ?
      AND id NOT IN (
          SELECT id FROM (
              SELECT id FROM user_push_subscriptions
              WHERE user_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT 5
          ) tmp
      )
", [$uid, $uid]);

send_json(["success" => true]);
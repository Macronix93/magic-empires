<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $category = $_GET["category"] ?? "Alle";
    $max_id = (int)($_GET["max_id"] ?? 0);
    $uid = $user->get_user_id();

    if ($max_id > 0) {
        if ($category === "Alle") {
            $db_instance->execute_query(
                "DELETE FROM server_messages WHERE receiverid = ? AND id <= ?",
                [$uid, $max_id]
            );
        } else {
            $db_instance->execute_query(
                "DELETE FROM server_messages WHERE receiverid = ? AND category = ? AND id <= ?",
                [$uid, $category, $max_id]
            );
        }
    }

    echo json_encode(["success" => true]);
} else {
    change_location("messages.php");
}

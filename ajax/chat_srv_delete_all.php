<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $category = $_GET["category"] ?? "Alle";
    $uid = $user->get_user_id();

    if ($category === "Alle") {
        $db_instance->execute_query("DELETE FROM server_messages WHERE receiverid = ?", [$uid]);
    } else {
        $db_instance->execute_query("DELETE FROM server_messages WHERE receiverid = ? AND category = ?", [$uid, $category]);
    }

    echo json_encode(["success" => true]);
} else {
    change_location("messages.php");
}

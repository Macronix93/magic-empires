<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $msg_id = (int)($_GET["m_id"] ?? 0);
    $u_id = $user->get_user_id();

    if ($user->is_admin()) {
        $db_instance->execute_query("UPDATE world_chat SET deleted = 1 WHERE id = ?", [$msg_id]);
    } else {
        $db_instance->execute_query("UPDATE world_chat SET deleted = 1 WHERE id = ? AND userid = ?", [$msg_id, $u_id]);
    }
    echo json_encode(["success" => true, "id" => $msg_id]);
} else {
    change_location("messages.php");
}

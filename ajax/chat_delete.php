<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $response = [];
    $message_to_delete = (int)$_GET["m_id"];

    $result = $db_instance->execute_query("SELECT senderid FROM messages WHERE id = ?", [$message_to_delete]);

    if ($result->num_rows == 0) {
        $response["error"] = "Diese Nachricht existiert nicht!";
    } else {
        $row = $result->fetch_assoc();

        $is_owner = ($row["senderid"] == $user->get_user_id());
        $is_admin = $user->is_admin();

        if (!$is_owner && !$is_admin) {
            $response["error"] = "Diese Nachricht kannst du nicht löschen!";
        } else {
            $db_instance->execute_query("UPDATE messages SET deleted = 1 WHERE id = ?", [$message_to_delete]);
            $response["success"] = true;
        }
    }

    echo json_encode($response);
} else {
    change_location("messages.php");
}
<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $response = [];
    $message_to_delete = htmlspecialchars($_GET["m_id"]);

    // Get message to delete
    $result = $db_instance->execute_query("SELECT senderid, receiverid FROM messages WHERE id = ?", [$message_to_delete]);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();

        if ($row["senderid"] != $user->get_user_id()) {
            $response["error"] = "Diese Nachricht kannst du nicht löschen!";
        } else {
            $db_instance->execute_query("UPDATE messages SET deleted = 1 WHERE id = ?", [$_GET["m_id"]]);
        }
    } else {
        $response["error"] = "Diese Nachricht existiert nicht!";
    }

    echo json_encode($response);
} else {
    change_location("messages.php");
}

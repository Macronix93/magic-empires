<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $chat_partner_id = (int)$_GET["s"];
    $session_partner_id = (int)($_SESSION["msgreceiver"] ?? 0);
    $messages_to_delete = [];
    $error = "";
    $html = "";

    // Chat mismatch recognition
    if ($session_partner_id !== $chat_partner_id) {
        echo json_encode([
            "error" => "redirect",
            "chatPartner" => $session_partner_id
        ]);
        exit;
    }

    $user->check_session_id();

    $result = $db_instance->execute_query("SELECT * FROM messages WHERE senderid = ? AND receiverid = ? AND hasread = 0", [$chat_partner_id, $user->get_user_id()]);

    $my_chat_image = $user->get_avatar();
    $chat_partner_image = "";

    while ($row = $result->fetch_assoc()) {
        if (empty($chat_partner_image)) {
            $partner = new User((int)$row["senderid"], $row["sender"]);
            $chat_partner_image = $partner->get_avatar();
        }

        $html .= "<div class='sender-bubble' id='msg-" . $row["id"] . "'>
                        <div class='image-and-user message-border'>
                            <img class='user-image' src='$chat_partner_image' alt=''> " . $row["sender"] . " am " . date("d.m.Y H:i:s", $row["date"]) . "
                        </div>
                        " . $row["message"] . "
                      </div>";

        $db_instance->execute_query("UPDATE messages SET hasread = 1 WHERE id = ?", [$row["id"]]);
    }

    $del_res = $db_instance->execute_query("SELECT id FROM messages WHERE senderid = ? AND receiverid = ? AND deleted = 1", [$chat_partner_id, $user->get_user_id()]);
    foreach ($del_res as $del_row) {
        $messages_to_delete[] = $del_row["id"];
    }

    if (!empty($messages_to_delete)) {
        $db_instance->execute_query("DELETE FROM messages WHERE senderid = ? AND receiverid = ? AND deleted = 1", [$chat_partner_id, $user->get_user_id()]);
    }

    echo json_encode([
        "html" => $html,
        "messagesToDelete" => $messages_to_delete,
        "error" => $error,
        "chatPartner" => $session_partner_id
    ]);
} else {
    change_location("messages.php");
}
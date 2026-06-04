<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $chat_partner_id = (int)$_GET["s"];
    $last_id = (int)($_GET["last_id"] ?? 0);
    $session_partner_id = (int)($_SESSION["msgreceiver"] ?? 0);
    $client_token = $_GET["token"] ?? "";
    $session_token = $_SESSION["active_chat_token"] ?? "";
    $messages_to_delete = [];
    $u_id = $user->get_user_id();
    $error = "";
    $html = "";

    // Chat mismatch recognition
    if ($client_token !== $session_token) {
        echo json_encode([
            "error" => "redirect",
            "chatPartner" => "privmsgs"
        ]);
        exit;
    }

    $user->check_session_id();

    $query = "SELECT * FROM messages 
              WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
              AND id > ? AND deleted = 0 
              ORDER BY id";
    $result = $db_instance->execute_query($query, [
        $chat_partner_id, $u_id,
        $u_id, $chat_partner_id,
        $last_id
    ]);

    $chat_partner_image = "";
    $new_last_id = $last_id;

    while ($row = $result->fetch_assoc()) {
        $new_last_id = $row["id"];
        $is_me = ((int)$row["senderid"] === $u_id);

        $class = $is_me ? "receiver-bubble" : "sender-bubble";
        $avatar = $is_me ? $user->get_avatar() : (new User((int)$row["senderid"], $row["sender"]))->get_avatar();
        $name = $is_me ? "Du" : e($row["sender"]);

        $delete_icon = $is_me ? "<img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' data-on-click='deleteChatMsg' data-id='" . e($row["id"]) . "' style='cursor: pointer;'>" : "";

        $html .= "<div class='$class' id='msg-" . $row["id"] . "'>
                    <div class='image-and-user message-border'>
                        <img class='user-image' src='" . e($avatar) . "' alt=''> $name am " . date("d.m.Y \u\m H:i:s", $row["date"]) . " 
                        $delete_icon
                    </div>
                    " . nl2br(e($row["message"])) . "
                  </div>";

        if (!$is_me) {
            $db_instance->execute_query("UPDATE messages SET hasread = 1 WHERE id = ?", [$row["id"]]);
        }
    }

    $del_query = "SELECT id FROM messages 
                  WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
                  AND deleted = 1";
    $del_res = $db_instance->execute_query($del_query, [$chat_partner_id, $u_id, $u_id, $chat_partner_id]);

    while ($del_row = $del_res->fetch_assoc()) {
        $messages_to_delete[] = $del_row["id"];
    }

    echo json_encode([
        "html" => $html,
        "messagesToDelete" => $messages_to_delete,
        "lastId" => $new_last_id,
        "error" => $error,
        "chatPartner" => $session_partner_id
    ]);
} else {
    change_location("messages.php");
}
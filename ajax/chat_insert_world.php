<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $response = [];
    $u_id = $user->get_user_id();
    $u_name = $user->get_user_name();

    $raw_text = $_POST["text"] ?? "";
    $message = nl2br(e($raw_text));
    $message = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\s+/u', '/\p{Mn}/u'], ['', ' ', ' ', ''], $message);

    $length = mb_strlen(strip_tags($message), "UTF-8");

    if (empty(trim(strip_tags($message)))) {
        $response["error"] = "Bitte eine Nachricht eingeben!";
    } else if ($length > MAX_MESSAGE_LENGTH) {
        $response["error"] = "Nachricht zu lang! ($length / " . MAX_MESSAGE_LENGTH . ")";
    } else {
        $current_time = time();

        $query = "INSERT INTO world_chat (userid, username, message, date) VALUES (?, ?, ?, ?) RETURNING id;";
        $result = $db_instance->execute_query($query, [$u_id, $u_name, $message, $current_time]);
        $message_id = $result->fetch_assoc()["id"];

        $use_filter = ($_SESSION["chat_filter"] ?? 1);
        $display_text = ($use_filter == 1) ? filter_chat_message($message) : $message;
        $display_text = wrap_emojis($display_text);

        $delete_icon = "<img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' 
                            data-on-click='deleteWorldChatMsg' data-id='$message_id' style='cursor: pointer;'>";

        $response["lastId"] = $message_id;
        $response["html"] = "
            <div class='receiver-bubble' id='world-msg-$message_id'>
                <div class='message-border'>
                    <span class='msg-header-left'>
                        <img class='user-image' src='" . $user->get_avatar() . "' alt=''> 
                        <span>Du am " . date("d.m.Y \u\m H:i:s", $current_time) . "</span>
                    </span>
                    $delete_icon
                </div>
                $display_text
            </div>";
    }

    echo json_encode($response);
} else {
    change_location("messages.php");
}
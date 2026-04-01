<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    // Get chat partner
    $chat_partner = htmlspecialchars($_GET["s"]);
    $messages_to_delete = [];
    $error = "";

    // Render the conversation HTML
    ob_start();

    $user->check_session_id();

    if ($_SESSION["msgreceiver"] != $chat_partner) {
        $error = "redirect";
    } else {
        $result = $db_instance->execute_query("SELECT * FROM messages WHERE senderid = ? AND receiverid = ? AND hasread = 0", [$chat_partner, $user->get_user_id()]);
        $chat_partner_image = "";
        $my_chat_image = $user->get_avatar();
        $row = $result->fetch_assoc();
        $partner = new User($row["senderid"], $row["sender"]);

        foreach ($result as $row) {
            if (empty($chat_partner_image)) {
                $chat_partner_image = $partner->get_avatar() ?? "";
            }

            echo "<div class='sender-bubble' id='msg-" . $row["id"] . "'>
                            <div class='image-and-user message-border'>
                                <img class='user-image' src='$chat_partner_image' alt='Nutzerbild'> " . $row["sender"] . " am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "
                            </div>
                            " . $row["message"] . "
                        </div>";

            // Set message as read
            $db_instance->execute_query("UPDATE messages SET hasread = 1 WHERE id = ?", [$row["id"]]);
        }

        // Get messages to delete
        $query = "
                    DELETE FROM messages 
                    WHERE senderid = ? AND receiverid = ? AND deleted = 1 
                    RETURNING id
        ";
        $result = $db_instance->execute_query($query, [$chat_partner, $user->get_user_id()]);

        foreach ($result as $row) {
            $messages_to_delete[] = $row['id'];
        }
    }

    $html = ob_get_clean();

    echo json_encode([
        "html" => $html,
        "messagesToDelete" => $messages_to_delete,
        "error" => $error,
        "chatPartner" => $_SESSION["msgreceiver"]
    ]);
} else {
    change_location("messages.php");
}
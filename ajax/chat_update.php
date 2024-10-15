<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    // Get chat partner
    $chat_partner = htmlspecialchars($_GET["s"]);
    $messages_to_delete = [];
    $error = "";

    // Render the conversation HTML
    ob_start();

    if ($_SESSION["msgreceiver"] != $chat_partner) {
        echo "<div style='text-align: center;'>Bitte nutze nur einen Tab für Konversationen!<br>Gesendete Nachrichten gehen an " . $_SESSION["msgreceiver"] . "</div>";
    } else {
        $result = $db_instance->execute_query("SELECT * FROM messages WHERE senderid = ? AND receiverid = ? AND hasread = 0", [$chat_partner, $user->get_user_id()]);
        $chat_partner_image = "";
        $my_chat_image = $user->get_avatar($user->get_user_name());
        $row = $result->fetch_assoc();

        if ($result->num_rows > 0) {
            echo "<div id='new-message-line' class='error'>Neue Nachrichten seit dem " . date("d.m.Y \u\m H:i:s", $row["date"]) . "</div>";
        }

        foreach ($result as $row) {
            if (empty($chat_partner_image)) {
                $chat_partner_image = $user->get_avatar($row["sender"]) ?? "";
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
        $result = $db_instance->execute_query("SELECT id FROM messages WHERE senderid = ? AND receiverid = ? AND deleted = 1", [$chat_partner, $user->get_user_id()]);

        foreach ($result as $row) {
            $messages_to_delete[] = $row["id"];

            $db_instance->execute_query("DELETE FROM messages WHERE id = ?", [$row["id"]]);
        }
    }

    $html = ob_get_clean();

    echo json_encode([
        "html" => $html,
        "messagesToDelete" => $messages_to_delete,
        "error" => $error
    ]);
} else {
    change_location("Location: messages.php");
}
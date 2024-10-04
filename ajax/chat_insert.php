<?php
global $db_instance, $user;
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $response = [];
    $receiver_id = $_SESSION["msgreceiver"];
    $message = nl2br(htmlspecialchars($_POST["text"], ENT_QUOTES, "UTF-8"));

    // Anti-spam settings
    $time = time();
    $rate_limit = $time - MESSAGES_RATE_LIMIT;

    // Render the conversation HTML
    ob_start();

    // Check for errors
    $error = get_error($message, $receiver_id);

    // Check if receiver exists
    $result = $db_instance->execute_query("SELECT COUNT(*) AS userexists FROM users WHERE id = ?", [$receiver_id]);
    $user_exists = $result->fetch_assoc()["userexists"];

    if (!$user_exists) {
        $error = "Dieser Benutzer existiert nicht!";
    }

    if (empty($error)) {
        // Check for rate limit
        $result = $db_instance->execute_query("SELECT COUNT(*) AS messagecount FROM messages WHERE senderid = ? AND date > ?", [$_SESSION["userid"], $rate_limit]);
        $message_count = $result->fetch_assoc()["messagecount"];

        if ($message_count >= MAX_MESSAGES_PER_RATELIMIT) {
            $remaining_time_in_seconds = MESSAGES_RATE_LIMIT - ($time - $_SESSION["lastsentmsg"]);
            $response["error"] = "Du schickst zuviele Nachrichten! Warte bitte.";
        } else {
            // Update last sent message time
            $_SESSION["lastsentmsg"] = $time;

            // Get receiverid based on receiver name
            $result = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$receiver_id]);
            $receiver = $result->fetch_assoc()["username"];

            // Insert message into database
            $query = "INSERT INTO messages (senderid, sender, receiverid, receiver, date, hasread, message) VALUES (?, ?, ?, ?, ?, '0', ?)";
            $db_instance->execute_query($query, [$_SESSION["userid"], $_SESSION["username"], $receiver_id, $receiver, $time, $message]);

            // Return the new message bubble HTML
            /*$response["html"] = "<div class='receiver-bubble'><u>Du am " . date("d.m.Y \u\m H:i:s", $time) . " <a href='messages.php?action=delete&m_id=" . $db_instance->insert_id . "'>
                                    <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a></u><br>" . $message . "</div>";*/
            $response["html"] = "<div class='receiver-bubble'>
                                    <div class='image-and-user message-border'>
                                        <img class='user-image' src='" . $user->get_avatar($user->get_user_name()) . "' alt='Nutzerbild'> Du am " . date("d.m.Y \u\m H:i:s", $time) . " <a href='messages.php?action=delete&m_id=" . $db_instance->insert_id . "'>
                                        <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a>
                                    </div>
                                    " . $message . "
                                </div>";
        }
    } else {
        $response["error"] = $error;
    }

    $html = ob_get_clean();

    echo json_encode($response);
} else {
    change_location("messages.php");
}
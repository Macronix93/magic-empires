<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $response = [];
    $receiver_id = (int)($_SESSION["msgreceiver"] ?? 0);
    $tab_partner_id = (int)$_POST["receiver"];
    $client_token = $_POST["token"] ?? "";
    $session_token = $_SESSION["active_chat_token"] ?? "";

    if ($client_token !== $session_token) {
        echo json_encode([
            "error" => "redirect",
            "chatPartner" => "privmsgs"
        ]);
        exit;
    }

    $raw_text = $_POST["text"];

    $message = nl2br(e($raw_text));
    $message = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\s+/u', '/\p{Mn}/u'], ['', ' ', ' ', ''], $message);

    // Render the conversation HTML
    ob_start();

    // Check for errors
    $error = get_error($message, $receiver_id);

    // Check if receiver exists
    $result = $db_instance->execute_query("SELECT COUNT(*) AS userexists FROM users WHERE id = ?", [$receiver_id]);
    $user_exists = $result->fetch_assoc()["userexists"];

    if (!$user_exists) {
        $error = "Dieser Spieler existiert nicht!";
    }

    if (empty($error)) {
        // Current time for rate limit check
        $current_time = time();
        $message_timeframe_end = $_SESSION["message_timeframe_end"] ?? 0;
        $message_count = $_SESSION["message_count"] ?? 0;

        if ($current_time > $message_timeframe_end) {
            $_SESSION["message_count"] = 0;
            $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;
            $message_count = 0;
        }

        // Check if we can send a new message
        if ($message_count >= MAX_MESSAGES_RATELIMIT) {
            // Calculate remaining time to wait
            $remaining_time_in_seconds = $message_timeframe_end - $current_time;
            $response["counter"] = $remaining_time_in_seconds;
            $response["messageLimit"] = MAX_MESSAGES_RATELIMIT;
            $response["error"] = "Du schickst zu viele Nachrichten! Warte bitte:";
        } else {
            $_SESSION["message_count"] = ++$message_count;
            $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;

            // Get receiver's username based on receiver ID
            $result = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$receiver_id]);
            $receiver = $result->fetch_assoc()["username"];

            // Insert message into the database
            $query = "INSERT INTO messages (senderid, sender, receiverid, receiver, date, message) VALUES (?, ?, ?, ?, ?, ?) RETURNING id;";
            $result = $db_instance->execute_query($query, [$_SESSION["userid"], $_SESSION["username"], $receiver_id, $receiver, $current_time, $message]);

            $row = $result->fetch_assoc();
            $message_id = $row["id"];
            $response["lastId"] = $message_id;
            $display_text = ($_SESSION["chat_filter"]) ? filter_chat_message($message) : $message;
            $display_text = wrap_emojis($display_text);

            // Return the new message bubble HTML
            $response["html"] = "<div class='receiver-bubble' id='msg-" . $message_id . "'>
                                    <div class='message-border'>
                                        <span class='msg-header-left'>
                                            <img class='user-image' src='" . $user->get_avatar() . "' alt='Nutzerbild'> 
                                            <span>Du am " . date("d.m.Y \u\m H:i:s", $current_time) . "</span>
                                        </span>
                                        <img src='images/icons/icon_delete.png' 
                                             class='ressource-icons' 
                                             alt='Löschen' 
                                             data-on-click='deleteChatMsg' 
                                             data-id='" . e($message_id) . "' 
                                             style='cursor: pointer;'>
                                    </div>
                                    " . $display_text . "
                                </div>";
        }
    } else {
        $response["error"] = $error;
    }

    ob_get_clean();

    echo json_encode($response);
} else {
    change_location("messages.php");
}

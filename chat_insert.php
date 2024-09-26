<?php
session_start();
require_once("includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    global $db_instance;
    $response = array();

    $receiverid = $_SESSION["msgreceiver"];
    $message = nl2br(htmlspecialchars($_POST["text"], ENT_QUOTES, "UTF-8"));

    // Anti-spam settings
    $time = time();
    $ratelimit = $time - MESSAGES_RATE_LIMIT;

    // Render the conversation HTML
    ob_start();

    // Check for errors
    $error = get_error($message, $receiverid);

    // Check if receiver exists
    $result = $db_instance->execute_query("SELECT COUNT(*) AS userexists FROM users WHERE id = ?", [$receiverid]);
    $row = $result->fetch_assoc();

    if (!$row["userexists"]) {
        $error = "Dieser Benutzer existiert nicht!";
    }

    if ($error == null) {
        // Check for rate limit
        $result = $db_instance->execute_query("SELECT COUNT(*) AS messagecount FROM messages WHERE senderid = ? AND date > ?", [$_SESSION["userid"], $ratelimit]);
        $row = $result->fetch_assoc();
        $messagecount = $row["messagecount"];

        if ($messagecount >= MAX_MESSAGES_PER_RATELIMIT) {
            $remainingTimeInSeconds = MESSAGES_RATE_LIMIT - ($time - $_SESSION["lastsentmsg"]);
            $response["error"] = "Du schickst zuviele Nachrichten! Warte bitte.";
        } else {
            // Update last sent message time
            $_SESSION["lastsentmsg"] = $time;

            // Get receiverid based on receiver name
            $result = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$receiverid]);
            $row = $result->fetch_assoc();
            $receiver = $row["username"];

            // Insert message into database
            $query = "INSERT INTO messages (senderid, sender, receiverid, receiver, date, hasread, message) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $db_instance->execute_query($query, [$_SESSION["userid"], $_SESSION["username"], $receiverid, $receiver, $time, 0, $message]);

            // Return the new message bubble HTML
            $response["html"] = "<div class='receiver-bubble'><u>Du am " . date("d.m.Y \u\m H:i:s", $time) . " <a href='messages.php?action=delete&m_id=" . $db_instance->insert_id . "'>
                                    <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a></u><br>" . $message . "</div>";
        }
    } else {
        $response["error"] = $error;
    }

    $html = ob_get_clean();

    echo json_encode($response);
} else {
    change_location("Location: messages.php");
}
<?php
session_start();
require_once("functions.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    global $db_instance;
    $response = array();

    $receiver = htmlspecialchars($_POST["receiver"], ENT_QUOTES, "UTF-8");
    $message = nl2br(htmlspecialchars($_POST["text"], ENT_QUOTES, "UTF-8"));

    // Anti-spam settings
    $time = time();
    $ratelimit = $time - MESSAGES_RATE_LIMIT;

    // Check for errors
    $error = getError($message, $receiver);

    if ($error == null) {
        // Check for rate limit
        $stmt = $db_instance->prepare("SELECT COUNT(*) AS messagecount FROM messages WHERE senderid = ? AND date > ?");
        $stmt->bind_param("ii", $_SESSION["userid"], $ratelimit);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $messagecount = $row["messagecount"];

        if ($stmt) {
            $stmt->close();

            if ($messagecount >= MAX_MESSAGES_PER_RATELIMIT) {
                $remainingTimeInSeconds = MESSAGES_RATE_LIMIT - ($time - $_SESSION["lastsentmsg"]);
                $response["error"] = "Du schickst zuviele Nachrichten! Warte bitte.";
            } else {
                $notread = 0;

                // Update last sent message time
                $_SESSION["lastsentmsg"] = $time;

                // Insert message into database
                $stmt = $db_instance->prepare("INSERT INTO messages (senderid, sender, receiver, date, hasread, message) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issiis", $_SESSION["userid"], $_SESSION["username"], $receiver, $time, $notread, $message);
                $stmt->execute();
                $stmt->close();

                // Return the new message bubble HTML
                $response["html"] = "<div class='receiver-bubble'><u>Du am " . date("d.m.Y \u\m H:i:s", $time) . " <a href='messages.php?action=delete&m_id=" . $db_instance->insert_id . "'><img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a></u><br>" . $message . "</div>";
            }
        }
    } else {
        $response["error"] = $error;
    }

    echo json_encode($response);
} else {
    changeLocation("Location: messages.php");
}
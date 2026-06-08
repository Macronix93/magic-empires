<?php
require_once("includes/core.php");

check_user_login($user);
$messages = new Messages($db_instance, $user);

// Starting a new conversation (or insert message in existing conversation)
if (isset($_POST["sendpm"])) {
    $receiver_name = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\p{Mn}/u'], ['', ' ', ''], $_POST["receiver"]);
    $_SESSION["msgreceiver"] = $receiver_name;
    $text = nl2br(htmlspecialchars($_POST["text"], ENT_QUOTES, "UTF-8"));
    $text = filter_chat_message($text);
    $error = get_error($text, $receiver_name);

    if ($error == null) {
        // Prevent HTML Injection
        $sender_id = $user->get_user_id();
        $sender_name = $user->get_user_name();
        $receiver_name = htmlspecialchars($receiver_name, ENT_QUOTES, "UTF-8");
        $message = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\s+/u', '/\p{Mn}/u'], ['', ' ', ' ', ''], $text);
        $current_time = time();

        // Query to check if the user exists
        $result = $db_instance->execute_query("SELECT id FROM users WHERE username = ?", [$receiver_name]);

        if ($result->num_rows == 0) {
            $error = "Dieser Spieler existiert nicht!";
        } else {
            $receiver_id = $result->fetch_assoc()["id"];

            if ($receiver_id == $sender_id) {
                $error = "Du kannst dir selbst keine Nachricht schicken!";
            } else {
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
                    $response["error"] = "Du schickst zu viele Nachrichten! Warte bitte: ";
                } else {
                    $_SESSION["message_count"] = ++$message_count;
                    $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;

                    // Send message to the receiver
                    $messages->send_message($sender_id, $sender_name, $receiver_id, $receiver_name, $current_time, $message);

                    change_location("messages.php?action=read&s=$receiver_id");
                }
            }
        }
    }
}

if (isset($_GET["action"])) {
    if ($_GET["action"] == "new") {
        $receiver = isset($_GET["s"]) ? htmlspecialchars($_GET["s"]) : "";
        $receiver_value = isset($_GET["receiver"]) ? htmlspecialchars($_GET["receiver"]) : (isset($_POST["receiver"]) ? htmlspecialchars($_POST["receiver"]) : "");
        $message = isset($_POST["text"]) ? htmlspecialchars($_POST["text"]) : "";

        if (isset($_POST["text"]) && $error == null) {
            $view = $messages->show_private_inbox();
        } else {
            $view .= "
                <div class='msg-back-button-container'>
                    <button class='msg-back-button' data-on-click='redirect' data-url='messages.php?privmsgs'>Zurück</button>
                </div>
            ";
            $view .= "<form id='newmessage'
                              action='messages.php?action=new'
                              method='POST'>
                            <table class='table'>
                                <tr>
                                    <td style='width: 25%;'>
                                        <b>Empfänger:</b>
                                    </td>
                                    <td>
                                        <label>
                                            <input type='text' name='receiver' maxlength='16' value='$receiver_value'>
                                        </label>
                                        <button type='button' data-on-click='openOverlay' data-url='userlist.php' data-title='Spielerliste'>
                                        Spielerliste
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>Nachricht:</b>
                                    </td>
                                    <td>
                                        <label>
                                            <textarea name='text' rows='8' maxlength='" . MAX_MESSAGE_LENGTH . "' style='resize: vertical;'>$message</textarea>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan='2'>
                                        <input type='submit' name='sendpm' value='Abschicken'>
                                    </td>
                                </tr>
                            </table>
                        </form>
            ";
        }
    } else if ($_GET["action"] == "read") {
        $inbox_header = "Privatnachrichten";
        $current_time = time();
        $message_timeframe_end = $_SESSION["message_timeframe_end"] ?? 0;
        $message_count = $_SESSION["message_count"] ?? 0;

        // Check if the current time is past the message timeframe
        if ($current_time > $message_timeframe_end) {
            $_SESSION["message_count"] = 0;
            $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;
        }

        if (!isset($_GET["s"])) {
            change_location("messages.php?privmsgs");
        } else {
            $sender_id = htmlspecialchars($_GET["s"]);

            if ($sender_id == null) {
                $error = "Der Spieler existiert nicht!";
                $view = $messages->show_private_inbox();
            } else {
                $_SESSION["msgreceiver"] = $sender_id;

                // Get chat partner name based on id
                $result = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$sender_id]);
                $chat_partner = $result->fetch_assoc()["username"] ?? "";

                // Check if conversation between the two exists
                $query = "SELECT * FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)";
                $result = $db_instance->execute_query($query, [$sender_id, $user->get_user_id(), $user->get_user_id(), $sender_id]);

                if ($result->num_rows == 0) {
                    $error = "Du hast keine Konversation mit diesem Nutzer!";
                    $view = $messages->show_private_inbox();
                } else {
                    // Delete messages that are marked for deletion
                    $messages->delete_marked_messages($sender_id);

                    $view .= "<div class='info-box event-error' style='display: none;'></div>";
                    $view .= "<div class='msg-back-button-container'><button class='msg-back-button' data-on-click='redirect' data-url='messages.php?privmsgs'>Zurück</button>
                            <h3 style='width: 85%; margin: 0;'>
                                <a href='#' 
                                 data-on-click='openOverlay' 
                                 data-url='userinfo.php?userid=" . e($sender_id) . "' 
                                 data-title='Spieler-Info'
                                 class='popup' 
                                 style='cursor: pointer;'>
                                 $chat_partner
                                </a>
                            </h3></div>";

                    // Show messages between chatpartner and user
                    $view .= "<div id='messages-section'>";
                    $view .= "<button id='load-older-btn'
                                data-on-click='loadOlderChat'
                                data-partnerid='" . e($sender_id) . "'
                                class='msg-load-more'>Ältere Nachrichten laden</button>";
                    $view .= $messages->show_messages_with_chatpartner($sender_id, $chat_partner);
                    $view .= "</div>";
                    $view .= "
                            <div id='newmessage-section'>
                                <form name=\"newmessage\"
                                      id='newmessage'
                                      action=\"messages.php?action=read&s=" . $sender_id . "\"
                                      method=\"POST\">
                                        <input type=\"hidden\" name=\"receiver\" value=\"" . $sender_id . "\">
                                        <textarea id=\"message-input\" 
                                              name=\"text\" 
                                              rows=\"5\"
                                              maxlength=\"" . MAX_MESSAGE_LENGTH . "\"
                                              style=\"resize: vertical; margin-right: 10px;\">" . (isset($_POST["text"]) ? htmlspecialchars($_POST["text"]) : '') . "</textarea>
                                        <input type=\"submit\" name=\"sendpm\" value=\"Absenden\n[ENTER]\"/>
                                </form>
                            </div>
                    ";
                }
            }
        }
    } else if ($_GET["action"] == "delete") {
        $chat_partner_id = htmlspecialchars($_GET["s"]);
        $user_id = $user->get_user_id();

        if (empty($chat_partner_id)) {
            change_location("messages.php?privmsgs");
        } else {
            $query = "SELECT * FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?) LIMIT 1";
            $result = $db_instance->execute_query($query, [$chat_partner_id, $user_id, $user_id, $chat_partner_id]);

            if ($result->num_rows == 0) {
                $error = "Du hast keine Konversation mit diesem Nutzer!";
                $view = $messages->show_private_inbox();
            } else {
                $query = "DELETE FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)";
                $db_instance->execute_query($query, [$chat_partner_id, $user_id, $user_id, $chat_partner_id]);

                change_location("messages.php?privmsgs");
            }
        }
    } else {
        change_location("messages.php");
    }
}

if (isset($_GET["servermsgs"])) {
    $view .= "
                <div class='msg-back-button-container'>
                    <button class='msg-back-button' data-on-click='redirect' data-url='messages.php'>
                        Zurück
                    </button>
                </div>
    ";

    // Category Tabs
    $view .= "<div class='tab'>
                    <div class='tablinks active' data-on-click='filterServer'>Alle</div>
                    <div class='tablinks' data-on-click='filterServer'>Krieg</div>
                    <div class='tablinks' data-on-click='filterServer'>Handel</div>
                </div>";

    $view .= "<div id='messages-section'>";
    $view .= $messages->show_server_inbox();
    $view .= "</div>";

    $inbox_header = "Servernachrichten";
} else if (isset($_GET["privmsgs"])) {
    $view = $messages->show_private_inbox();
    $inbox_header = "Privatnachrichten";
} else if (!isset($_GET["action"])) {
    $private = $messages->get_unread_private_count();
    $server = $messages->get_unread_server_count();

    $view .= "<div class='msg-button-container'>";

    $view .= "<a href='messages.php?privmsgs' class='msg-button'>
    <div class='msg-left'>
        <span>📩</span>
        <span>Privatnachrichten</span>
    </div>";

    if ($private > 0) {
        $view .= "<span class='msg-badge'>" . $messages->show_messages_indicator($private) . "</span>";
    }

    $view .= "</a>";

    $view .= "<a href='messages.php?servermsgs' class='msg-button'>
    <div class='msg-left'>
        <span>🖥️</span>
        <span>Servernachrichten</span>
    </div>";

    if ($server > 0) {
        $view .= "<span class='msg-badge'>" . $messages->show_messages_indicator($server) . "</span>";
    }

    $view .= "</a></div>";
}


/*
 * HTML Section
 */
$title = "Nachrichten";
$header = $inbox_header ?? "Nachrichten";
$script_files = ["counter", "chat", "userinfo"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
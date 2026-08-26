<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $response = [];
    $receiver_id = (int)($_SESSION["msgreceiver"] ?? 0);
    $client_token = $_POST["token"] ?? "";
    $session_token = $_SESSION["active_chat_token"] ?? "";

    if ($client_token !== $session_token) {
        echo json_encode(["error" => "redirect", "chatPartner" => "privmsgs"]);
        exit;
    }

    $raw_text = $_POST["text"] ?? "";

    $cleaned_text = preg_replace([
        '/[^\P{Cc}\r\n]+|[\p{Cf}\p{Mn}]+/u',
        '/[ \t]+/u',
        '/^[ \t]+/m',
        '/[ \t]+$/m'
    ], ['', ' ', '', ''], $raw_text);

    $cleaned_text = trim($cleaned_text);
    $text_only = preg_replace('/\[\/?quote.*?]/i', '', $cleaned_text);

    $line_breaks_count = substr_count($cleaned_text, "\n");
    $length = mb_strlen($cleaned_text, "UTF-8");

    if (empty($cleaned_text) || empty(trim($text_only))) {
        $error = "Bitte alle Felder ausfüllen!";
    } else if ($length > MAX_MESSAGE_LENGTH) {
        $error = "Die Nachricht darf maximal " . MAX_MESSAGE_LENGTH . " Zeichen lang sein!";
    } else if ($line_breaks_count > MAX_LINE_BREAK_COUNT) {
        $error = "Dein Text darf maximal " . MAX_LINE_BREAK_COUNT . " Zeilenumbrüche beinhalten!";
    } else {
        $result = $db_instance->execute_query("SELECT COUNT(*) FROM users WHERE id = ?", [$receiver_id]);
        if ($result->fetch_row()[0] == 0) {
            $error = "Dieser Spieler existiert nicht!";
        }
    }

    if (empty($error)) {
        $current_time = time();
        $message_timeframe_end = $_SESSION["message_timeframe_end"] ?? 0;
        $message_count = $_SESSION["message_count"] ?? 0;

        if ($current_time > $message_timeframe_end) {
            $message_count = 0;
            $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;
        }

        if ($message_count >= MAX_MESSAGES_RATELIMIT) {
            $response["counter"] = $_SESSION["message_timeframe_end"] - $current_time;
            $response["error"] = "Du schickst zu viele Nachrichten! Warte bitte:";
        } else {
            $_SESSION["message_count"] = ++$message_count;

            $receiver = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$receiver_id])->fetch_column();
            $query = "INSERT INTO messages (senderid, sender, receiverid, receiver, date, message) VALUES (?, ?, ?, ?, ?, ?) RETURNING id;";
            $res = $db_instance->execute_query($query, [$_SESSION["userid"], $_SESSION["username"], $receiver_id, $receiver, $current_time, $cleaned_text]);

            $message_id = $res->fetch_assoc()["id"];
            $response["lastId"] = $message_id;

            $text = e($cleaned_text);
            $text = parse_chat_quotes($text);
            $text = nl2br($text);
            if ($_SESSION["chat_filter"]) $text = filter_chat_message($text);
            $display_text = wrap_emojis($text);

            $quote_icon = "<img src='images/icons/icon_quote.png' class='ressource-icons' 
                         style='cursor: pointer; margin-left: 5px;' 
                         data-on-click='quoteMessage' 
                         data-author='" . e($user->get_user_name()) . "' 
                         data-text='" . e($cleaned_text) . "' 
                         title='Zitieren' alt=''>";

            $response["html"] = "<div class='receiver-bubble' id='msg-$message_id'>
                                    <div class='message-border'>
                                        <span class='msg-header-left'>
                                            <img class='user-image' src='" . $user->get_avatar() . "' alt='Nutzerbild'> 
                                            <span>Du <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $current_time) . "</small></span>
                                        </span>
                                        <span style='display: flex; gap: 5px; align-items: center;'>
                                            " . render_reactions_bar("chat", $message_id, $user, "btn_only") . "
                                            $quote_icon 
                                            <img src='images/icons/icon_delete.png' class='ressource-icons' data-on-click='deleteChatMsg' data-id='$message_id' style='cursor: pointer;' alt=''>
                                        </span>
                                    </div>
                                    " . $display_text . "
                                    <div class='chat-reaction-footer'>
                                        " . render_reactions_bar("chat", $message_id, $user, "badges_only") . "
                                    </div>
                                </div>";
        }
    } else {
        $response["error"] = $error;
    }
    echo json_encode($response);
} else {
    change_location("messages.php");
}
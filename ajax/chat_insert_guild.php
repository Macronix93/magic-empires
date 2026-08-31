<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $response = [];
    $u_id = $user->get_user_id();
    $u_name = $user->get_user_name();
    $guild_id = $user->get_user_guild_id();

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
        echo json_encode($response);
        exit;
    }

    $raw_text = $_POST["text"] ?? "";
    $cleaned_text = preg_replace([
        '/^[^\S\r\n]+/',
        '/[^\P{Cc}\r\n]+|\p{Cf}+/u',
        '/\p{Z}+/u',
        '/[^\S\r\n]{2,}/u',
        '/\p{Mn}/u'
    ], ['', '', ' ', ' ', ''], $raw_text);

    $cleaned_text = trim($cleaned_text);
    $line_breaks_count = substr_count($cleaned_text, "\n");
    $length = mb_strlen($cleaned_text, "UTF-8");

    if (empty($cleaned_text)) {
        $response["error"] = "Bitte eine Nachricht eingeben!";
    } else if ($length > MAX_MESSAGE_LENGTH) {
        $response["error"] = "Nachricht zu lang! ($length / " . MAX_MESSAGE_LENGTH . ")";
    } else if ($line_breaks_count > MAX_LINE_BREAK_COUNT) {
        $response["error"] = "Dein Text darf maximal " . MAX_LINE_BREAK_COUNT . " Zeilenumbrüche beinhalten!";
    } else {
        $_SESSION["message_count"] = ++$message_count;

        $query = "INSERT INTO guild_chat (guild_id, userid, username, message, date) VALUES (?, ?, ?, ?, ?) RETURNING id;";
        $result = $db_instance->execute_query($query, [$guild_id, $u_id, $u_name, $cleaned_text, $current_time]);
        $message_id = $result->fetch_assoc()["id"];

        $text = e($cleaned_text);
        $text = parse_chat_quotes($text);
        $text = nl2br($text);
        if ($_SESSION["chat_filter"]) {
            $text = filter_chat_message($text);
        }
        $display_text = wrap_emojis($text);

        $delete_icon = "<img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' 
                            data-on-click='deleteGuildChatMsg' data-id='$message_id' style='cursor: pointer;'>";

        $quote_icon = "<img src='images/icons/icon_quote.png' class='ressource-icons' 
                     style='cursor: pointer; margin-left: 5px;' 
                     data-on-click='quoteMessage' 
                     data-author='" . e($u_name) . "' 
                     data-text='" . e($cleaned_text) . "' 
                     title='Nachricht zitieren' alt=''>";

        $response["lastId"] = $message_id;
        $response["html"] = "
            <div class='receiver-bubble' id='guild-msg-$message_id'>
                <div class='message-border'>
                    <span class='msg-header-left'>
                        <img class='user-image' src='" . $user->get_avatar() . "' alt=''> 
                        <span>Du <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $current_time) . "</small></span>
                    </span>
                    <span style='display: flex; gap: 5px; align-items: center;'>
                        " . render_reactions_bar("guild_chat", $message_id, $user, "btn_only") . "
                        $quote_icon
                        $delete_icon
                    </span>
                </div>
                $display_text
                <div class='chat-reaction-footer'>
                    " . render_reactions_bar("guild_chat", $message_id, $user, "badges_only") . "
                </div>
            </div>";
    }

    echo json_encode($response);
} else {
    change_location("guild.php");
}
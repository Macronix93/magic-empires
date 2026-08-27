<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $chat_partner_id = (int)$_GET["s"];
    $last_id = (int)($_GET["last_id"] ?? 0);
    $session_partner_id = (int)($_SESSION["msgreceiver"] ?? 0);
    $client_token = $_GET["token"] ?? "";
    $session_token = $_SESSION["active_chat_token"] ?? "";
    $messages_to_delete = [];
    $u_id = $user->get_user_id();
    $is_admin = $user->is_admin();
    $error = "";
    $html = "";

    // Chat mismatch recognition
    if ($client_token !== $session_token) {
        echo json_encode([
            "error" => "redirect",
            "chatPartner" => "privmsgs"
        ]);
        exit;
    }

    $user->check_session_id();

    $query = "SELECT * FROM messages 
              WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
              AND id > ? AND deleted = 0 
              ORDER BY id";
    $result = $db_instance->execute_query($query, [
        $chat_partner_id, $u_id,
        $u_id, $chat_partner_id,
        $last_id
    ]);

    $chat_partner_image = "";
    $new_last_id = $last_id;

    while ($row = $result->fetch_assoc()) {
        $text = e($row["message"]);
        $text = parse_chat_quotes($text);
        $text = nl2br($text);
        if (!empty($_SESSION["chat_filter"])) {
            $text = filter_chat_message($text);
        }
        $display_message = wrap_emojis($text);

        $new_last_id = $row["id"];
        $is_me = ((int)$row["senderid"] === $u_id);

        $class = $is_me ? "receiver-bubble" : "sender-bubble";
        $avatar = $is_me ? $user->get_avatar() : new User((int)$row["senderid"], $row["sender"])->get_avatar();

        $quote_icon = "<img src='images/icons/icon_quote.png' class='ressource-icons' 
                         data-on-click='quoteMessage' 
                         data-author='" . e($row["sender"]) . "' 
                         data-text='" . e($row["message"]) . "' 
                         title='Zitieren' alt=''>";
        $delete_icon = ($is_me || $is_admin) ? "<img src='images/icons/icon_delete.png' class='ressource-icons' data-on-click='deleteChatMsg' data-id='{$row["id"]}' style='cursor: pointer;' alt='Löschen'>" : "";
        $sender_link = $is_me ? "Du" : "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $row["senderid"] . "' data-title='Spieler-Info'>" . e($row["sender"]) . "</a>";

        $html .= "<div class='$class' id='msg-" . $row["id"] . "'>
                    <div class='message-border'>
                        <span class='msg-header-left'>
                            <img class='user-image' src='" . e($avatar) . "' alt=''> 
                            <span>$sender_link <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $row["date"]) . "</small></span>
                        </span>
                        <span style='display: flex; gap: 5px; align-items: center;'>
                            " . render_reactions_bar("chat", $row["id"], $user, "btn_only") . "
                            $quote_icon
                            $delete_icon
                        </span>
                    </div>
                    " . $display_message . "
                    <div class='chat-reaction-footer'>
                        " . render_reactions_bar("chat", $row["id"], $user, "badges_only") . "
                    </div>
                  </div>";

        if (!$is_me) {
            $db_instance->execute_query("UPDATE messages SET hasread = 1 WHERE id = ?", [$row["id"]]);
        }
    }

    $del_query = "SELECT id FROM messages 
                  WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
                  AND deleted = 1";
    $del_res = $db_instance->execute_query($del_query, [$chat_partner_id, $u_id, $u_id, $chat_partner_id]);

    while ($del_row = $del_res->fetch_assoc()) {
        $messages_to_delete[] = $del_row["id"];
    }

    $reaction_updates = [];
    $res_recent = $db_instance->execute_query(
        "SELECT id FROM messages 
         WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
         ORDER BY id DESC LIMIT ?",
        [$chat_partner_id, $u_id, $u_id, $chat_partner_id, SHOW_MESSAGES_LIMIT]
    );
    while ($r = $res_recent->fetch_assoc()) {
        $reaction_updates[$r["id"]] = render_reactions_bar("chat", $r["id"], $user, "badges_only");
    }

    echo json_encode([
        "html" => $html,
        "messagesToDelete" => $messages_to_delete,
        "lastId" => $new_last_id,
        "error" => $error,
        "chatPartner" => $session_partner_id,
        "reactionUpdates" => $reaction_updates
    ]);
} else {
    change_location("messages.php");
}
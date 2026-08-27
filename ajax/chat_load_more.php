<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    if (isset($_GET["s"]) && isset($_GET["oldest_id"])) {
        $partner_id = (int)$_GET["s"];
        $oldest_id = (int)$_GET["oldest_id"];
        $limit = SHOW_MESSAGES_LIMIT;

        $is_admin = $user->is_admin();
        $u_id = $user->get_user_id();

        $res = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$partner_id]);
        $p_data = $res->fetch_assoc();
        $partner_name = $p_data["username"] ?? "Unbekannt";

        $messages_obj = new Messages($db_instance, $user);
        $history = $messages_obj->get_chat_history_paged($partner_id, $oldest_id, $limit + 1);

        $has_more = false;

        if (count($history) > $limit) {
            $has_more = true;
            array_shift($history);
        }

        $html = "";
        $my_avatar = $user->get_avatar();
        $partner = new User($partner_id, $partner_name);
        $partner_avatar = $partner->get_avatar();

        foreach ($history as $row) {
            $is_me = ($row["senderid"] == $user->get_user_id());
            $class = $is_me ? "receiver-bubble" : "sender-bubble";
            $img = $is_me ? $my_avatar : $partner_avatar;
            $name = $is_me ? "Du" : $row["sender"];
            $del_btn = ($is_me || $is_admin) ? "<img src='images/icons/icon_delete.png' 
                          class='ressource-icons' 
                          data-on-click='deleteChatMsg' 
                          data-id='" . e($row["id"]) . "' 
                          style='cursor:pointer;' 
                          alt='Löschen'>" : "";
            $quote_icon = "<img src='images/icons/icon_quote.png' class='ressource-icons' 
                         data-on-click='quoteMessage' 
                         data-author='" . e($name) . "' 
                         data-text='" . e($row["message"]) . "' 
                         title='Zitieren' alt=''>";
            $sender_link = $is_me ? "Du" : "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $row["senderid"] . "' data-title='Spieler-Info'>" . e($row["sender"]) . "</a>";

            $html .= "<div class='$class' id='msg-{$row["id"]}'>
                        <div class='message-border'>
                            <span class='msg-header-left'>
                                <img class='user-image' src='$img' alt=''> 
                                <span>$sender_link <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $row["date"]) . "</small></span>
                            </span>
                            <span style='display: flex; gap: 5px; align-items: center;'>
                                " . render_reactions_bar("chat", $row["id"], $user, "btn_only") . "
                                $quote_icon
                                $del_btn
                            </span>
                        </div>
                        {$row["message"]}
                        <div class='chat-reaction-footer'>
                            " . render_reactions_bar("chat", $row["id"], $user, "badges_only") . "
                        </div>
                      </div>";
        }

        $reaction_updates = [];
        $res_recent = $db_instance->execute_query(
            "SELECT id FROM messages 
                 WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
                 ORDER BY id DESC LIMIT ?",
            [$partner_id, $u_id, $u_id, $partner_id, SHOW_MESSAGES_LIMIT]
        );
        while ($r = $res_recent->fetch_assoc()) {
            $reaction_updates[$r["id"]] = render_reactions_bar("chat", $r["id"], $user, "badges_only");
        }

        echo json_encode([
            "html" => $html,
            "count" => count($history),
            "hasMore" => $has_more,
            "reactionUpdates" => $reaction_updates
        ]);
    }
} else {
    change_location("messages.php");
}

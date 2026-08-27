<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    if (isset($_GET["oldest_id"])) {
        $oldest_id = (int)$_GET["oldest_id"];
        $limit = 30;
        $u_id = $user->get_user_id();
        $is_admin = $user->is_admin();

        $query = "SELECT * FROM world_chat WHERE id < ? AND deleted = 0 ORDER BY id DESC LIMIT ?";
        $result = $db_instance->execute_query($query, [$oldest_id, $limit + 1]);
        $rows = $result->fetch_all(MYSQLI_ASSOC);

        $has_more = (count($rows) > $limit);
        if ($has_more) {
            array_pop($rows);
        }

        $rows = array_reverse($rows);

        $html = "";
        $use_filter = ($_SESSION["chat_filter"] ?? 1);

        foreach ($rows as $row) {
            $is_me = ($row["userid"] == $u_id);
            $class = $is_me ? "receiver-bubble" : "sender-bubble";
            $quote_icon = "<img src='images/icons/icon_quote.png' class='ressource-icons' 
                         data-on-click='quoteMessage' 
                         data-author='" . e($row["username"]) . "' 
                         data-text='" . e($row["message"]) . "' 
                         title='Zitieren' alt=''>";
            $del_icon = ($is_me || $is_admin) ? "<img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' 
                                                data-on-click='deleteWorldChatMsg' data-id='{$row["id"]}' style='cursor: pointer;'>" : "";

            $msg = ($use_filter == 1) ? filter_chat_message($row["message"]) : $row["message"];
            $msg = wrap_emojis($msg);

            $sender = new User($row["userid"], $row["username"]);
            $avatar = $sender->get_avatar();

            $sender_display = $is_me ? "Du" : "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $row["userid"] . "' data-title='Spieler-Info'>" . e($row["username"]) . "</a>";

            $html .= "<div class='$class' id='world-msg-{$row["id"]}'>
                <div class='message-border'>
                    <span class='msg-header-left'>
                        <img class='user-image' src='$avatar' alt=''> 
                        <span>$sender_display <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $row["date"]) . "</small></span>
                    </span>
                    <span style='display: flex; gap: 5px; align-items: center;'>
                        " . render_reactions_bar("world_chat", $row["id"], $user, "btn_only") . "
                        $quote_icon
                        $del_icon
                    </span>
                </div>
                $msg
                <div class='chat-reaction-footer'>
                    " . render_reactions_bar("world_chat", $row["id"], $user, "badges_only") . "
                </div>
              </div>";
        }

        $reaction_updates = [];
        $res_recent = $db_instance->execute_query("SELECT id FROM world_chat ORDER BY id DESC LIMIT ?", [MAX_WORLD_CHAT_MESSAGES_SHOWN]);
        while ($r = $res_recent->fetch_assoc()) {
            $reaction_updates[$r["id"]] = render_reactions_bar("world_chat", $r["id"], $user, "badges_only");
        }

        echo json_encode([
            "html" => $html,
            "count" => count($rows),
            "hasMore" => $has_more,
            "reactionUpdates" => $reaction_updates
        ]);
    }
} else {
    change_location("messages.php");
}
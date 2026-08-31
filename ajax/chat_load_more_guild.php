<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    if (isset($_GET["oldest_id"])) {
        $oldest_id = (int)$_GET["oldest_id"];
        $guild_id = $user->get_user_guild_id();
        $u_id = $user->get_user_id();
        $is_admin = $user->is_admin();
        $limit = MAX_GUILD_CHAT_MESSAGES_SHOWN;

        $query = "SELECT * FROM guild_chat WHERE guild_id = ? AND id < ? AND deleted = 0 ORDER BY id DESC LIMIT ?";
        $result = $db_instance->execute_query($query, [$guild_id, $oldest_id, $limit + 1]);
        $rows = $result->fetch_all(MYSQLI_ASSOC);

        $has_more = (count($rows) > $limit);
        if ($has_more) array_pop($rows);
        $rows = array_reverse($rows);

        $html = "";
        $reaction_updates = [];
        $use_filter = ($_SESSION["chat_filter"] ?? 1);

        foreach ($rows as $row) {
            $is_me = ($row["userid"] == $u_id);
            $class = $is_me ? "receiver-bubble" : "sender-bubble";

            $msg = e($row["message"]);
            $msg = parse_chat_quotes($msg);
            $msg = nl2br($msg);
            if ($use_filter == 1) $msg = filter_chat_message($msg);
            $msg = wrap_emojis($msg);

            $sender_user = new User($row["userid"], $row["username"]);
            $avatar = $sender_user->get_avatar();
            $sender_display = $is_me ? "Du" : "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $row["userid"] . "'>" . e($row["username"]) . "</a>";

            $quote_icon = "<img src='images/icons/icon_quote.png' class='ressource-icons' data-on-click='quoteMessage' data-author='" . e($row["username"]) . "' data-text='" . e($row["message"]) . "' title='Zitieren'>";
            $del_icon = ($is_me || $is_admin) ? "<img src='images/icons/icon_delete.png' class='ressource-icons' data-on-click='deleteGuildChatMsg' data-id='{$row["id"]}' style='cursor: pointer;' alt='Löschen'>" : "";

            $html .= "
            <div class='$class' id='guild-msg-{$row["id"]}'>
                <div class='message-border'>
                    <span class='msg-header-left'>
                        <img class='user-image' src='$avatar' alt=''> 
                        <span>$sender_display <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $row["date"]) . "</small></span>
                    </span>
                    <span style='display: flex; gap: 5px; align-items: center;'>
                        " . render_reactions_bar("guild_chat", $row["id"], $user, "btn_only") . "
                        $quote_icon
                        $del_icon
                    </span>
                </div>
                $msg
                <div class='chat-reaction-footer'>
                    " . render_reactions_bar("guild_chat", $row["id"], $user, "badges_only") . "
                </div>
            </div>";

            $reaction_updates[$row["id"]] = render_reactions_bar("guild_chat", $row["id"], $user, "badges_only");
        }

        echo json_encode([
            "html" => $html,
            "count" => count($rows),
            "hasMore" => $has_more,
            "reactionUpdates" => $reaction_updates
        ]);
    }
}
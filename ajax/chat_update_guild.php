<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $last_id = (int)($_GET["last_id"] ?? 0);
    $u_id = $user->get_user_id();
    $guild_id = $user->get_user_guild_id();
    $is_admin = $user->is_admin();
    $my_rank = $user->get_guild_rank_id();
    $is_privileged = ($my_rank > 0 && $my_rank <= GuildRanks::GUILD_OFFICER);
    $html = "";
    $deleted_ids = [];

    $query = "SELECT * FROM guild_chat WHERE guild_id = ? AND id > ? AND deleted = 0 ORDER BY id LIMIT ?";
    $result = $db_instance->execute_query($query, [$guild_id, $last_id, MAX_GUILD_CHAT_MESSAGES_SHOWN]);

    $new_last_id = $last_id;
    $use_filter = ($_SESSION["chat_filter"] ?? 1);

    while ($row = $result->fetch_assoc()) {
        $new_last_id = $row["id"];

        if ((int)$row["userid"] === $u_id) continue;

        $is_me = false;
        $quote_icon = "<img src='images/icons/icon_quote.png' class='ressource-icons' 
                         data-on-click='quoteMessage' 
                         data-author='" . e($row["username"]) . "' 
                         data-text='" . e($row["message"]) . "' 
                         title='Zitieren' alt=''>";
        $del_icon = ($is_admin || $is_privileged) ? "<img src='images/icons/icon_delete.png' class='ressource-icons' 
                                        data-on-click='deleteGuildChatMsg' data-id='{$row["id"]}' style='cursor: pointer;' alt=''>" : "";
        $class = "sender-bubble";

        $text = $row["message"];
        $text = e($text);
        $text = parse_chat_quotes($text);
        $text = nl2br($text);
        if ($use_filter == 1) {
            $text = filter_chat_message($text);
        }
        $display_message = wrap_emojis($text);

        $sender = new User($row["userid"], $row["username"]);
        $avatar = $sender->get_avatar();

        $sender_link = "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $row["userid"] . "' data-title='Spieler-Info'>" . e($row["username"]) . "</a>";

        $html .= "
            <div class='$class' id='world-msg-{$row["id"]}'>
                <div class='message-border'>
                    <span class='msg-header-left'>
                        <img class='user-image' src='$avatar' alt=''> 
                        <span>$sender_link <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $row["date"]) . "</small></span>
                    </span>
                    <span style='display: flex; gap: 5px; align-items: center;'>
                        " . render_reactions_bar("guild_chat", $row["id"], $user, "btn_only") . "
                        $quote_icon
                        $del_icon
                    </span>
                </div>
                $display_message
                <div class='chat-reaction-footer'>
                        " . render_reactions_bar("guild_chat", $row["id"], $user, "badges_only") . "
                </div>
            </div>";
    }

    $del_query = "SELECT id FROM guild_chat WHERE guild_id = ? AND id > (? - 50) AND deleted = 1";
    $del_res = $db_instance->execute_query($del_query, [$guild_id, $last_id]);
    while ($del_row = $del_res->fetch_assoc()) {
        $deleted_ids[] = (int)$del_row["id"];
    }

    $reaction_updates = [];
    $res_recent = $db_instance->execute_query("SELECT id FROM guild_chat WHERE guild_id = ? ORDER BY id DESC LIMIT ?",
        [$guild_id, MAX_GUILD_CHAT_MESSAGES_SHOWN]);
    while ($r = $res_recent->fetch_assoc()) {
        $reaction_updates[$r["id"]] = render_reactions_bar("guild_chat", $r["id"], $user, "badges_only");
    }

    if ($new_last_id > $last_id) {
        $db_instance->execute_query("UPDATE users SET last_guild_chat_id = ? WHERE id = ?", [$new_last_id, $u_id]);
    }

    echo json_encode([
        "html" => $html,
        "lastId" => $new_last_id,
        "messagesToDelete" => $deleted_ids,
        "reactionUpdates" => $reaction_updates
    ]);
}
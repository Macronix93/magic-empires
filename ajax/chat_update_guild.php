<?php
require_once("../includes/core.php");

if (!$user->is_logged_in() || $user->get_user_id() <= 0) {
    echo json_encode(["error" => "redirect"]);
    exit;
}

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $u_id = $user->get_user_id();
    $guild_id = $user->get_user_guild_id();

    if (isset($_GET["mark_all_read"])) {
        if ($guild_id > 0) {
            $max_msg = (int)($db_instance->execute_query(
                "SELECT MAX(id) FROM guild_chat WHERE guild_id = ?", [$guild_id]
            )->fetch_row()[0] ?? 0);

            if ($max_msg > 0) {
                $db_instance->execute_query(
                    "UPDATE users SET last_guild_chat_id = ? WHERE id = ?",
                    [$max_msg, $u_id]
                );
            }
        }
        echo json_encode(["success" => true]);
        exit;
    }

    $last_id = (int)($_GET["last_id"] ?? 0);

    if ($last_id <= 0) {
        echo json_encode([
            "html" => "",
            "lastId" => 0,
            "messagesToDelete" => [],
            "reactionUpdates" => [],
            "guildUnread" => new Messages($user)->get_unread_guild_count()
        ]);
        exit;
    }

    $is_admin = $user->is_admin();
    $my_rank = $user->get_guild_rank_id();
    $is_privileged = ($my_rank > 0 && $my_rank <= GuildRanks::GUILD_OFFICER);

    $html = "";
    $deleted_ids = [];

    $query = "SELECT * FROM guild_chat WHERE guild_id = ? AND id > ? AND deleted = 0 ORDER BY id LIMIT ?";
    $result = $db_instance->execute_query($query, [$guild_id, $last_id, MAX_GUILD_CHAT_MESSAGES_SHOWN]);

    $new_last_id = $last_id;

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

        $display_message = Messages::format_chat_message($row["message"]);

        $sender = new User($row["userid"], $row["username"]);
        $avatar = $sender->get_avatar();

        $sender_link = "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $row["userid"] . "' data-title='Spieler-Info'>" . e($row["username"]) . "</a>";

        $html .= "
            <div class='$class' id='guild-msg-{$row["id"]}'>
                <div class='message-border'>
                    <span class='msg-header-left'>
                        <img class='user-image' src='$avatar' alt=''> 
                        <span>$sender_link <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $row["date"]) . "</small></span>
                    </span>
                    <span style='display: flex; gap: 5px; align-items: center;'>
                        " . Messages::render_reactions_bar("guild_chat", $row["id"], $user, "btn_only") . "
                        $quote_icon
                        $del_icon
                    </span>
                </div>
                <div class='chat-text'>" . $display_message . "</div>
                <div class='chat-reaction-footer'>
                        " . Messages::render_reactions_bar("guild_chat", $row["id"], $user, "badges_only") . "
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
        $reaction_updates[$r["id"]] = Messages::render_reactions_bar("guild_chat", $r["id"], $user, "badges_only");
    }

    if ($new_last_id > $last_id) {
        $db_instance->execute_query("UPDATE users SET last_guild_chat_id = ? WHERE id = ?", [$new_last_id, $u_id]);
    }

    echo json_encode([
        "html" => $html,
        "lastId" => $new_last_id,
        "messagesToDelete" => $deleted_ids,
        "reactionUpdates" => $reaction_updates,
        "guildUnread" => new Messages($user)->get_unread_guild_count()
    ]);
} else {
    change_location("overview.php");
}
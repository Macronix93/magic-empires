<?php

class Messages
{
    private object $mysqli;
    private User $user;
    private string $view = "";

    public function __construct(User $user)
    {
        $this->mysqli = Database::get_instance()->get_connection();
        $this->user = $user;
    }

    public function send_message(
        int    $sender_id,
        string $sender_name,
        int    $receiver_id,
        string $receiver_name,
        int    $time,
        string $message): void
    {
        $query = "INSERT INTO messages (senderid, sender, receiverid, receiver, date, message) VALUES (?, ?, ?, ?, ?, ?)";
        $this->mysqli->execute_query($query, [$sender_id, $sender_name, $receiver_id, $receiver_name, $time, $message]);

        send_user_push(
            $receiver_id,
            "📩 Neue Nachricht",
            "{$_SESSION["username"]} hat dir eine Nachricht geschrieben.",
            "messages",
            "messages.php?action=read&s=" . $_SESSION["userid"]
        );
    }

    public function get_server_history_paged(?int $oldest_id = null, int $category = -1, int $limit = 20): array
    {
        $uid = $this->user->get_user_id();
        $params = [$uid];
        $category_sql = "";

        if ($category !== -1) {
            $category_sql = " AND category = ? ";
            $params[] = $category;
        }

        if ($oldest_id === null) {
            $query = "SELECT * FROM server_messages WHERE receiverid = ? $category_sql ORDER BY id DESC LIMIT ?";
        } else {
            $query = "SELECT * FROM server_messages WHERE receiverid = ? $category_sql AND id < ? ORDER BY id DESC LIMIT ?";
            $params[] = $oldest_id;
        }

        $params[] = $limit;
        $result = $this->mysqli->execute_query($query, $params);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function get_chat_history_paged(int $sender_id, ?int $oldest_id = null, int $limit = 20): array
    {
        $uid = $this->user->get_user_id();

        if ($oldest_id === null) {
            $query = "SELECT * FROM messages 
                  WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
                  AND deleted = 0 
                  ORDER BY id DESC LIMIT ?";
            $result = $this->mysqli->execute_query($query, [$sender_id, $uid, $uid, $sender_id, $limit]);
        } else {
            $query = "SELECT * FROM messages 
                  WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
                  AND deleted = 0 AND id < ?
                  ORDER BY id DESC LIMIT ?";
            $result = $this->mysqli->execute_query($query, [$sender_id, $uid, $uid, $sender_id, $oldest_id, $limit]);
        }

        $messages = [];
        foreach ($result as $row) {
            $messages[] = $row;
        }

        return array_reverse($messages);
    }

    function show_private_inbox(): string
    {
        // Get all conversations for the user
        $query = "
                    SELECT 
                        u.id AS participant_id,
                        u.username AS sendername,
                        MAX(m.date) AS latest_message_date,
                        COUNT(CASE WHEN m.receiverid = ? AND m.hasread = 0 AND m.deleted = 0 THEN 1 END) AS unreadcount
                    FROM messages m
                    JOIN users u ON 
                        (u.id = m.senderid AND m.receiverid = ?) OR 
                        (u.id = m.receiverid AND m.senderid = ?)
                    WHERE (m.senderid = ? OR m.receiverid = ?) 
                      AND m.deleted = 0
                    GROUP BY u.id, u.username
                    ORDER BY latest_message_date DESC
        ";

        $uid = $this->user->get_user_id();
        $result = $this->mysqli->execute_query($query, [$uid, $uid, $uid, $uid, $uid]);

        $this->view .= "
                    <div class='msg-back-button-container'>
                        <button class='msg-back-button' data-on-click='redirect' data-url='messages.php'>Zurück</button>
                    </div>
        ";

        if ($result->num_rows == 0) {
            $this->view = "Du hast keine Konversationen!";
        } else {
            $this->view .= "
                        <table class='table'>
                            <tr>
                                <td class='td-center td-gradient'>
                                    <b>Chatpartner</b>
                                </td>
                                <td class='td-center td-gradient' colspan='2' style='width: 55%;'>
                                    <b>Letzte Nachricht</b>
                                </td>
                            </tr>
            ";

            foreach ($result as $row) {
                $num_unread_messages = $row["unreadcount"];
                $sender_name = $row["sendername"];
                $latest_timestamp = $row["latest_message_date"];
                $old_conversation = time() - $latest_timestamp > CONV_INACTIVITY_TIME ? " tr-inactive" : "";
                $chat_partner = new User($row["participant_id"], $sender_name);

                $badge = ($num_unread_messages > 0)
                    ? "<span class='msg-badge'>" . $this->show_messages_indicator($num_unread_messages) . "</span>"
                    : "";
                $name_and_badge = "<div style='display: flex; justify-content: space-between; align-items: center; width: 100%;'><span>" . e($sender_name) . "</span>$badge</div>";

                $this->view .= "
                    <tr class='tr-hover$old_conversation'>
                        <td class='td-cursor' 
                            data-on-click='redirect' 
                            data-url='messages.php?action=read&s=" . e($row["participant_id"]) . "'>
                            " . $chat_partner->render_user($name_and_badge) . "
                        </td>
                        <td class='td-cursor' 
                            data-on-click='redirect' 
                            data-url='messages.php?action=read&s=" . e($row["participant_id"]) . "'>
                            am " . date("d.m.Y \u\m H:i:s", $latest_timestamp) . "
                        </td>
                        <td class='td-center'>
                            <img src='images/icons/icon_delete.png' 
                                 class='ressource-icons' 
                                 alt='Löschen' 
                                 data-on-click='confirmDeleteConversation' 
                                 data-id='" . e($row["participant_id"]) . "' 
                                 data-name='" . e($sender_name) . "' 
                                 style='cursor: pointer;'>
                        </td>
                    </tr>
                ";
            }

            $this->view .= "</table>";
        }

        $this->view .= "
            <br>
            <form action='messages.php' method='GET'>
                <input type='hidden' name='action' value='new'>
                <input type='submit' value='Neue Konversation' style='margin-top: 5px;'>
            </form>
        ";

        return $this->view;
    }

    function show_messages_indicator(int $number): string
    {
        if ($number <= 0) {
            return "";
        }

        return ($number > 9) ? "9+" : (string)$number;
    }

    function show_server_inbox(): string
    {
        $uid = $this->user->get_user_id();

        // How many new messages do we have?
        $res_count = $this->mysqli->execute_query("SELECT COUNT(*) FROM server_messages WHERE receiverid = ? AND hasread = 0", [$uid]);
        $unread_count = (int)$res_count->fetch_row()[0];

        // Calculate dynamic limit (up to max. 100 new messages)
        $dynamic_limit = max(SHOW_MESSAGES_LIMIT, min(100, $unread_count));

        $query = "SELECT * FROM server_messages WHERE receiverid = ? ORDER BY id DESC LIMIT ?";
        $result = $this->mysqli->execute_query($query, [$uid, $dynamic_limit + 1]);

        if ($result->num_rows == 0) {
            return "<div id='server-empty-category' style='opacity: 0.6;'>Keine Servernachrichten vorhanden.</div>";
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);

        // Mark every unread message as read
        $this->mysqli->execute_query("UPDATE server_messages SET hasread = 1 WHERE receiverid = ? AND hasread = 0", [$uid]);

        $has_more = (count($rows) > $dynamic_limit);
        if ($has_more) {
            array_pop($rows);
        }

        $last_unread_index = -1;
        foreach ($rows as $index => $row) {
            if ($row["hasread"] == 0) {
                $last_unread_index = $index;
            }
        }

        $html = "";
        foreach ($rows as $index => $row) {
            if (!empty($row["data_json"])) {
                $data = json_decode($row["data_json"], true);
                $content = $this->render_message_template($data);
            } else {
                $content = $row["message"];
            }

            $html .= "<div class='server-bubble' data-category='{$row["category"]}' id='msg-{$row["id"]}'>
                            <div class='message-border'>
                                Am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "
                                <img src='images/icons/icon_delete.png' 
                                 class='ressource-icons' 
                                 data-on-click='deleteServerMsg' 
                                 data-id='{$row["id"]}' 
                                 style='cursor: pointer;' alt=''>
                            </div>
                            $content
                        </div>";

            if ($index === $last_unread_index) {
                $html .= "<div id='new-message-line' class='error'>Neue Nachrichten seit " . date("d.m.Y H:i", $row["date"]) . "</div>";
            }
        }

        if ($has_more) {
            $html .= "<button id='load-more-server-btn' 
                          data-on-click='loadMoreServerMsgs' 
                          class='msg-load-more' 
                          style='margin: 10px auto; display: block;'>Ältere Berichte laden</button>";
        }

        return $html;
    }

    public function get_unread_private_count(): int
    {
        $result = $this->mysqli->execute_query("SELECT COUNT(*) AS unreadcount FROM messages WHERE receiverid = ? AND hasread = 0 AND deleted = 0",
            [$this->user->get_user_id()]);
        return $result->fetch_assoc()["unreadcount"];
    }

    public function get_unread_server_count(): int
    {
        $result = $this->mysqli->execute_query("SELECT COUNT(*) AS unreadcount FROM server_messages WHERE receiverid = ? AND hasread = 0",
            [$this->user->get_user_id()]);
        return $result->fetch_assoc()["unreadcount"];
    }

    public function get_unread_world_count(): int
    {
        $uid = $this->user->get_user_id();
        $query = "SELECT COUNT(*) FROM world_chat WHERE id > (SELECT last_world_chat_id FROM users WHERE id = ?) AND userid != ? AND deleted = 0";
        return (int)$this->mysqli->execute_query($query, [$uid, $uid])->fetch_row()[0];
    }

    public function get_unread_guild_count(): int
    {
        $guild_id = $this->user->get_user_guild_id();
        if ($guild_id <= 0) {
            return 0;
        }

        $uid = $this->user->get_user_id();
        $query = "SELECT COUNT(*) FROM guild_chat 
              WHERE guild_id = ? 
              AND id > (SELECT last_guild_chat_id FROM users WHERE id = ?) 
              AND userid != ? 
              AND deleted = 0";

        return (int)$this->mysqli->execute_query($query, [$guild_id, $uid, $uid])->fetch_row()[0];
    }

    public function delete_marked_messages(int $sender_id): void
    {
        $this->mysqli->execute_query("DELETE FROM messages WHERE ((senderid = ? AND receiverid = ?) OR (receiverid = ? AND senderid = ?)) AND deleted = 1",
            [$this->user->get_user_id(), $sender_id, $sender_id, $this->user->get_user_id()]
        );
    }

    public function show_messages_with_chatpartner(int $sender_id, string $chat_partner): string
    {
        $token = time() . "_" . rand(1000, 9999);
        $_SESSION["active_chat_token"] = $token;

        return "<div id='chat-loading-wrapper'>
                    <div id='chat-loading-overlay' class='chat-spinner-full'>
                        <div class='loading-spinner'></div>
                        <p>Nachrichten werden geladen...</p>
                    </div>
                    <div id='messages-section' 
                         data-chat-type='private' 
                         data-partner-id='$sender_id' 
                         data-partner-name='" . e($chat_partner) . "' 
                         data-token='$token' 
                         style='opacity: 0;'>
                    </div>
                </div>
                <div id='chat-tab-token' data-token='$token' style='display: none;'></div>";
    }

    public function get_private_history_html(int $sender_id, string $chat_partner): string
    {
        $limit = SHOW_MESSAGES_LIMIT;
        $result = $this->get_chat_history_paged($sender_id, null, $limit + 1);

        $has_more = count($result) > $limit;
        if ($has_more) array_shift($result);

        $html = "<button id='load-older-btn' data-on-click='loadOlderChat' data-partnerid='" . e($sender_id) . "' class='msg-load-more' style='display: " . ($has_more ? 'block' : 'none') . "'>Ältere Nachrichten laden</button>";
        $html .= "<div id='chat-config' data-has-more='" . ($has_more ? 'true' : 'false') . "'></div>";

        if (empty($result)) {
            return $html . "<div id='chat-empty-placeholder' class='info-box' style='margin: 0; justify-content: center;'>Schreibe eine Nachricht, um den Chat zu beginnen.</div>";
        }

        $chat_partner_image = "";
        $my_chat_image = $this->user->get_avatar();
        $partner = new User($sender_id, $chat_partner);
        $first_sender_message_displayed = false;
        $unread_message_ids = [];

        $is_admin = $this->user->is_admin();

        foreach ($result as $row) {
            $message_id = $row["id"];

            $display_message = self::format_chat_message($row["message"]);

            $has_read = $row["hasread"];
            $date = $row["date"];
            $is_me = ($row["senderid"] == $this->user->get_user_id());

            $delete_icon = ($is_me || $is_admin) ? "<img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' data-on-click='deleteChatMsg' data-id='" . e($row["id"]) . "' style='cursor: pointer;'>" : "";
            $quote_icon = "<img src='images/icons/icon_quote.png' class='ressource-icons'
                     style='cursor: pointer; margin-left: 5px;'
                     data-on-click='quoteMessage'
                     data-author='" . e($row["sender"]) . "'
                     data-text='" . e($row["message"]) . "'
                     title='Nachricht zitieren' alt=''>";
            $sender_link = "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $row["senderid"] . "' data-title='Spieler-Info'>" . e($row["sender"]) . "</a>";

            if ($row["senderid"] == $sender_id) {
                if (empty($chat_partner_image)) {
                    $chat_partner_image = $partner->get_avatar() ?? "";
                }

                if (!$has_read && !$first_sender_message_displayed) {
                    $first_sender_message_displayed = true;
                    $html .= "<div id='new-message-line' class='error'>Neue Nachrichten seit " . date("d.m.Y \u\m H:i:s", $date) . "</div>";
                }

                $html .= "<div class='sender-bubble' id='msg-" . $message_id . "'>
                            <div class='message-border'>
                                <span class='msg-header-left'>
                                    <img class='user-image' src='$chat_partner_image' alt=''>
                                    <span>$sender_link <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $date) . "</small></span>
                                </span>
                                <span style='display: flex; gap: 5px; align-items: center;'>
                                    " . render_reactions_bar("chat", $row["id"], $this->user, "btn_only") . "
                                    $quote_icon
                                    $delete_icon
                                </span>
                            </div>
                            <div class='chat-text'>" . $display_message . "</div>
                            <div class='chat-reaction-footer'>
                                " . render_reactions_bar("chat", $row["id"], $this->user, "badges_only") . "
                            </div>
                        </div>";
            } else {
                $html .= "<div class='receiver-bubble' id='msg-" . $message_id . "'>
                            <div class='message-border'>
                                <span class='msg-header-left'>
                                    <img class='user-image' src='$my_chat_image' alt=''>
                                    <span>Du <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $date) . "</small></span>
                                </span>
                                <span style='display: flex; gap: 5px; align-items: center;'>
                                    " . render_reactions_bar("chat", $row["id"], $this->user, "btn_only") . "
                                    $quote_icon
                                    $delete_icon
                                </span>
                            </div>
                            <div class='chat-text'>" . $display_message . "</div>
                            <div class='chat-reaction-footer'>
                                " . render_reactions_bar("chat", $row["id"], $this->user, "badges_only") . "
                            </div>
                        </div>";
            }

            if (!$has_read && $row["receiverid"] == $this->user->get_user_id()) {
                $unread_message_ids[] = $message_id;
            }
        }

        if (!empty($unread_message_ids)) {
            $placeholders = implode(",", array_fill(0, count($unread_message_ids), "?"));
            $this->mysqli->execute_query("UPDATE messages SET hasread = 1 WHERE id IN ($placeholders)", $unread_message_ids);
        }

        return $html;
    }

    public function show_world_chat(): string
    {
        $html = "<div class='info-box event-error' style='display: none;'></div>";
        $html .= "
        <div id='chat-loading-wrapper'>
            <div id='chat-loading-overlay' class='chat-spinner-full'>
                <div class='loading-spinner'></div>
                <p>Welt-Chat wird geladen...</p>
            </div>
            <div id='messages-section' data-chat-type='world' style='opacity: 0; height: 55vh;'>
            </div>
        </div>";

        $html .= "
        <div id='newmessage-section'>
            <form id='world-chat-form'>
                <textarea id='message-input' name='text' rows='3' maxlength='" . MAX_MESSAGE_LENGTH . "' style='resize: vertical; margin-right: 10px;'></textarea>
                <div class='emoji-picker-container'>
                    <div id='emoji-menu' class='emoji-menu'>";
        foreach (get_chat_emojis() as $emoji) {
            $html .= "<span data-on-click='pickEmoji'>$emoji</span>";
        }
        $html .= "  </div>
                    <button type='button' class='emoji-trigger' data-on-click='toggleEmojis' title='Emoji einfügen'>🙂</button>
                </div>
                <input type='button' data-on-click='sendWorldMessage' value='Absenden\n[ENTER]' />
            </form>
        </div>";

        return $html;
    }

    public function get_world_history_html(): string
    {
        $limit = MAX_WORLD_CHAT_MESSAGES_SHOWN;
        $result = $this->mysqli->execute_query("SELECT * FROM world_chat WHERE deleted = 0 ORDER BY id DESC LIMIT ?", [$limit + 1]);
        $rows = $result->fetch_all(MYSQLI_ASSOC);

        $has_more = (count($rows) > $limit);
        if ($has_more) {
            array_pop($rows);
        }

        $rows = array_reverse($rows);

        $html = "<button id='load-older-btn' data-on-click='loadOlderWorldChat' class='msg-load-more' style='display: " . ($has_more ? "block" : "none") . ";'>Ältere Nachrichten laden</button>";
        $html .= "<div id='chat-config' data-has-more='" . ($has_more ? "true" : "false") . "'></div>";

        if (empty($rows)) {
            $html .= "
            <div id='chat-empty-placeholder' class='info-box' style='margin: 0; justify-content: center;'>
                Im Welt-Chat wurde noch nichts geschrieben. Sei der Erste!
            </div>";
        } else {
            $last_id = 0;
            $last_read_id = $this->mysqli->execute_query("SELECT last_world_chat_id FROM users WHERE id = ?", [$this->user->get_user_id()])->fetch_row()[0] ?? 0;
            $unread_line_shown = false;

            foreach ($rows as $row) {
                $last_id = $row["id"];
                $is_me = ($row["userid"] == $this->user->get_user_id());

                if ($last_read_id > 0 && !$is_me && $row["id"] > $last_read_id && !$unread_line_shown) {
                    $html .= "<div id='new-message-line' class='error'>Neue Nachrichten seit " . date("d.m.Y H:i", $row["date"]) . "</div>";
                    $unread_line_shown = true;
                }

                $class = $is_me ? "receiver-bubble" : "sender-bubble";

                $is_admin = $this->user->is_admin();
                $delete_icon = ($is_me || $is_admin) ? "<img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'
                                                            data-on-click='deleteWorldChatMsg' data-id='{$row["id"]}' style='cursor: pointer;'>" : "";

                $quote_icon = "<img src='images/icons/icon_quote.png' class='ressource-icons'
                     style='cursor: pointer; margin-left: 5px;'
                     data-on-click='quoteMessage'
                     data-author='" . e($row["username"]) . "'
                     data-text='" . e($row["message"]) . "'
                     title='Nachricht zitieren' alt=''>";

                $msg = self::format_chat_message($row["message"]);

                $u = new User($row["userid"], $row["username"]);
                $avatar = $u->get_avatar();

                $sender_link = $is_me ? "Du" : "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $row["userid"] . "' data-title='Spieler-Info'>" . e($row["username"]) . "</a>";

                $html .= "<div class='$class' id='world-msg-{$row["id"]}'>
                    <div class='message-border'>
                        <span class='msg-header-left'>
                            <img class='user-image' src='$avatar' alt=''>
                            <span>$sender_link <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $row["date"]) . "</small></span>
                        </span>
                        <span style='display: flex; gap: 5px; align-items: center;'>
                            " . render_reactions_bar("world_chat", $row["id"], $this->user, "btn_only") . "
                            $quote_icon
                            $delete_icon
                        </span>
                    </div>
                    <div class='chat-text'>" . $msg . "</div>
                    <div class='chat-reaction-footer'>
                        " . render_reactions_bar("world_chat", $row["id"], $this->user, "badges_only") . "
                    </div>
                  </div>";
            }

            if ($last_id > 0) {
                $this->mysqli->execute_query("UPDATE users SET last_world_chat_id = ? WHERE id = ?", [$last_id, $this->user->get_user_id()]);
            }
        }

        return $html;
    }

    public function show_guild_chat(): string
    {
        $guild_id = $this->user->get_user_guild_id();

        if ($guild_id <= 0) {
            return "Du bist in keiner Gilde!";
        }

        $html = "<div class='info-box event-error' style='display: none;'></div> 
                <div id='chat-loading-wrapper'>
                    <div id='chat-loading-overlay' class='chat-spinner-full'>
                        <div class='loading-spinner'></div>
                        <p>Gilden-Chat wird geladen...</p>
                    </div>
                    <div id='messages-section' data-chat-type='guild' style='opacity: 0; height: 55vh;'>
                    </div>
                </div>
                <div id='newmessage-section'>
                    <form id='guild-chat-form'>
                        <textarea id='message-input' name='text' rows='3' maxlength='" . MAX_MESSAGE_LENGTH . "' style='resize: vertical; margin-right: 10px;'></textarea>
                        <div class='emoji-picker-container'>
                            <div id='emoji-menu' class='emoji-menu'>";
        foreach (get_chat_emojis() as $emoji) {
            $html .= "<span data-on-click='pickEmoji'>$emoji</span>";
        }
        $html .= "</div>
                            <button type='button' class='emoji-trigger' data-on-click='toggleEmojis'>🙂</button>
                        </div>
                        <input type='button' data-on-click='sendGuildMessage' value='Absenden\n[ENTER]' />
                    </form>
                </div>";

        return $html;
    }

    public function get_guild_history_html(): string
    {
        $guild_id = $this->user->get_user_guild_id();
        $limit = MAX_WORLD_CHAT_MESSAGES_SHOWN;
        $u_id = $this->user->get_user_id();
        $is_admin = $this->user->is_admin();
        $my_rank = $this->user->get_guild_rank_id();
        $is_privileged = ($my_rank > 0 && $my_rank <= GuildRanks::GUILD_OFFICER);

        // Get the guild messages
        $result = $this->mysqli->execute_query("SELECT * FROM guild_chat WHERE guild_id = ? AND deleted = 0 ORDER BY id DESC LIMIT ?", [$guild_id, $limit + 1]);
        $rows = $result->fetch_all(MYSQLI_ASSOC);

        $has_more = (count($rows) > $limit);
        if ($has_more) array_pop($rows);
        $rows = array_reverse($rows);

        $html = "<button id='load-older-btn' data-on-click='loadOlderGuildChat' class='msg-load-more' style='display: " . ($has_more ? "block" : "none") . ";'>Ältere Nachrichten laden</button>";
        $html .= "<div id='chat-config' data-has-more='" . ($has_more ? "true" : "false") . "'></div>";

        if (empty($rows)) {
            $html .= "<div id='chat-empty-placeholder' class='info-box' style='margin: 0; justify-content: center;'>Schreibe eine Nachricht, um den Chat zu beginnen.</div>";
            return $html;
        }

        $last_read_id = $this->mysqli->execute_query("SELECT last_guild_chat_id FROM users WHERE id = ?", [$u_id])->fetch_row()[0] ?? 0;
        $unread_line_shown = false;

        foreach ($rows as $row) {
            $is_me = ($row["userid"] == $u_id);

            if ($last_read_id > 0 && !$is_me && $row["id"] > $last_read_id && !$unread_line_shown) {
                $html .= "<div id='new-message-line' class='error'>Neue Gilden-Nachrichten</div>";
                $unread_line_shown = true;
            }

            $class = $is_me ? "receiver-bubble" : "sender-bubble";

            $display_msg = self::format_chat_message($row["message"]);

            $sender_user = new User($row["userid"], $row["username"]);
            $avatar = $sender_user->get_avatar();
            $sender_display = $is_me ? "Du" : "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $row["userid"] . "' data-title='Spieler-Info'>" . e($row["username"]) . "</a>";

            $quote_icon = "<img src='images/icons/icon_quote.png' class='ressource-icons' data-on-click='quoteMessage' data-author='" . e($row["username"]) . "' data-text='" . e($row["message"]) . "' title='Zitieren' style='cursor:pointer;'>";
            $del_icon = ($is_me || $is_admin || $is_privileged) ? "<img src='images/icons/icon_delete.png' class='ressource-icons' data-on-click='deleteGuildChatMsg' data-id='{$row["id"]}' style='cursor: pointer;' alt='Löschen'>" : "";

            $html .= "<div class='$class' id='guild-msg-{$row["id"]}'>
                <div class='message-border'>
                    <span class='msg-header-left'>
                        <img class='user-image' src='$avatar' alt=''>
                        <span>$sender_display <small class='msg-date'>" . date(DATE_FORMAT_CHAT, $row["date"]) . "</small></span>
                    </span>
                    <span style='display: flex; gap: 5px; align-items: center;'>
                        " . render_reactions_bar("guild_chat", $row["id"], $this->user, "btn_only") . "
                        $quote_icon
                        $del_icon
                    </span>
                </div>
                <div class='chat-text'>" . $display_msg . "</div>
                <div class='chat-reaction-footer'>
                    " . render_reactions_bar("guild_chat", $row["id"], $this->user, "badges_only") . "
                </div>
            </div>";

            $last_id = $row["id"];
        }

        if (isset($last_id)) {
            $this->mysqli->execute_query("UPDATE users SET last_guild_chat_id = ? WHERE id = ?", [$last_id, $u_id]);
        }

        return $html;
    }

    private function resolve_pvp_outcome(mixed $out, string $role): array
    {
        if (is_array($out)) return $out;

        $is_atk = ($role === "attacker");

        return match ($out) {
            "unhindered" => [
                "title" => $is_atk ? "Kampfausgang: Ungehinderter Vorstoß" : "Kampfausgang: Feind vor den Toren",
                "main_text" => $is_atk
                    ? "Es waren keine feindlichen Truppen zur Verteidigung bereit."
                    : "Ein feindlicher Trupp wurde vor unseren Toren gesichtet und hat die Mauer attackiert.",
                "sub_text" => $is_atk
                    ? "Unsere Soldaten haben das Gebiet gesichert und kehren nun um."
                    : "Der Angreifer zog nach einer Machtdemonstration wieder ab.",
                "type" => $is_atk ? "success" : "neutral"
            ],
            "total_defeat" => [
                "title" => $is_atk ? "Kampfausgang: Totale Niederlage" : "Kampfausgang: Glorreicher Sieg",
                "main_text" => $is_atk
                    ? "Die Schlacht war ein totaler Fehlschlag!"
                    : "<span class='passed'>Die Angreifer wurden restlos vernichtet!</span>",
                "sub_text" => $is_atk
                    ? "Kein einziger Soldat kehrt lebend zurück."
                    : "Unsere Verteidigung hat standgehalten. Kein Angreifer überlebte den Ansturm.",
                "type" => $is_atk ? "error" : "success"
            ],
            "victory" => [
                "title" => $is_atk ? "Kampfausgang: Sieg" : "Kampfausgang: Niederlage",
                "main_text" => $is_atk
                    ? "Der Sieg ist unser! Die Verteidigung wurde durchbrochen."
                    : "<span class='error'>Das Königreich wurde überrannt!</span>",
                "sub_text" => $is_atk
                    ? "Die verbleibenden Truppen machen sich auf den Heimweg."
                    : "Die Verteidiger wurden bis auf den letzten Mann aufgerieben.",
                "type" => $is_atk ? "success" : "error"
            ],
            default => [
                "title" => $is_atk ? "Kampfausgang: Rückzug" : "Kampfausgang: Abgewehrt",
                "main_text" => $is_atk
                    ? "Unser Angriff wurde zurückgeschlagen!"
                    : "<span class='passed'>Die Angreifer wurden erfolgreich abgewehrt!</span>",
                "sub_text" => $is_atk
                    ? "Die verbleibenden Truppen treten den Rückzug an."
                    : "Unsere Garnison hält die Stellung.",
                "type" => $is_atk ? "error" : "success"
            ]
        };
    }

    private function resolve_conquest_data(mixed $cq): ?array
    {
        if (is_array($cq)) return $cq;
        if (!$cq) return null;

        return match ($cq) {
            "conquered_attacker" => [
                "title" => "Eroberung erfolgreich",
                "main_text" => "<b>Glorreicher Sieg!</b> Das Königreich wurde eingenommen und gehört nun dir.",
                "sub_text" => "Für die Eroberung hat sich ein <b>Eroberer</b> geopfert.",
                "type" => "success"
            ],
            "conquered_defender" => [
                "title" => "Königreich verloren",
                "main_text" => "<b>Das Schicksal hat sich gegen uns gewandt!</b> Unser Königreich wurde vom Gegner besetzt.",
                "sub_text" => "",
                "type" => "error"
            ],
            "conquered_defender_wiped" => [
                "title" => "Königreich verloren",
                "main_text" => "<b>Das Schicksal hat sich gegen uns gewandt!</b> Unser Königreich wurde vom Gegner besetzt.",
                "sub_text" => "Da dies dein letztes Dorf war, musst du an einem neuen Standort von vorne beginnen.",
                "type" => "error"
            ],
            "failed_attacker" => [
                "title" => "Eroberungsversuch",
                "main_text" => "Die Eroberung ist gescheitert. Unsere Truppen konnten die Kontrolle über das Stadtzentrum nicht sichern.",
                "sub_text" => "Die Soldaten ziehen sich zurück.",
                "type" => "error"
            ],
            default => null
        };
    }

    public function render_message_template(array $data): string
    {
        $type = $data["template"] ?? $data["type"] ?? "text";

        switch ($type) {
            case "guild_project_started":
                $tech = e($data["tech"] ?? "Projekt");
                $by = e($data["by"] ?? "Jemand");
                return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                        "Neues Gilden-Projekt",
                        "Ein neues Ziel wurde ausgerufen: <b>$tech</b>.<br>Alle Mitglieder sind aufgerufen, Rohstoffe beizusteuern!",
                        0, 0, "Veranlasst durch: $by"
                    ) . "</div>";

            case "guild_research_started":
                $tech = e($data["tech"] ?? "Forschung");
                $lvl = (int)($data["level"] ?? 1);
                $by = e($data["by"] ?? "Jemand");
                return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                        "Gildenforschung gestartet",
                        "Die Ressourcen für <b>$tech (Stufe $lvl)</b> wurden vollständig gesammelt. Die Forschung hat begonnen!",
                        0, 0, "Finaler Beitrag durch: $by", "success"
                    ) . "</div>";

            case "guild_project_cancelled":
                $by = e($data["by"] ?? "Jemand");
                return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                        "Projekt abgebrochen",
                        "Das aktuelle Gilden-Projekt wurde von <b>$by</b> abgebrochen.",
                        0, 0, "", "error"
                    ) . "</div>";

            case "guild_new_member":
                $name = e($data["name"] ?? "Ein Mitglied");
                return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                        "Neues Gilden-Mitglied",
                        "<b>$name</b> ist der Gilde soeben beigetreten.",
                        0, 0, "", "success"
                    ) . "</div>";

            case "guild_research_completed":
                $tech = e($data["tech"] ?? "Forschung");
                $lvl = (int)($data["level"] ?? 1);
                return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                        "Gildenforschung abgeschlossen",
                        "Die Forschung <b>$tech</b> wurde erfolgreich auf <b>Stufe $lvl</b> verbessert!",
                        0, 0, "", "success"
                    ) . "</div>";

            case "guild_member_left":
                $name = e($data["name"] ?? "Ein Mitglied");
                return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                        "Gilden-Austritt",
                        "<b>$name</b> hat die Gilde verlassen.",
                        0, 0, "", "error"
                    ) . "</div>";

            case "guild_settings_updated":
                $by = e($data["by"] ?? "Die Gildenführung");
                $changes = e($data["changes"] ?? "Allgemein");

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Gilden-Update",
                        "Die Gilden-Einstellungen <i>$changes</i> wurden durch <b>$by</b> aktualisiert."
                    ) .
                    "</div>";

            case "home_kingdom_lost":
                $old_name = e($data["old_k_name"] ?? "Unbekannt");
                $main_name = e($data["main_k_name"] ?? "Hauptstadt");

                $main_text = "Während deine Truppen auf dem Rückmarsch waren, wurde dein Königreich <b>$old_name</b> von einem Feind erobert!<br><br>
                         Deine Einheiten haben den Befehl erhalten, sofort zu deinem Haupt-Königreich <b>$main_name</b> abzudrehen.";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Heimat-Königreich verloren!",
                        $main_text,
                        0, 0,
                        "Durch das neue Ziel verzögert sich die Ankunft um 10 Minuten.",
                        "error"
                    ) .
                    "</div>";

            case "troop_return":
                $target_x = (int)($data["target_x"] ?? 0);
                $target_y = (int)($data["target_y"] ?? 0);
                $target_name = e($data["target_name"] ?? "Unbekannt");
                $home_name = e($data["home_name"] ?? "Königreich");

                $c_link = "<a href='map.php?startx=$target_x&starty=$target_y' data-on-click='mapJump' data-x='$target_x' data-y='$target_y'>$target_x:$target_y</a>";

                $loot = $data["loot"] ?? [];
                $units = $data["units"] ?? [];

                $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin: 15px 0; justify-content: center;'>";
                foreach ($units as $t) {
                    $units_html .= BattleReportRenderer::render_unit_card($t);
                }
                $units_html .= "</div>";

                $main_text = "Deine Truppen sind vom Feldzug zu <b>$target_name</b> ($c_link) zurückgekehrt. ";
                $main_text .= !empty($loot) ? "Die Heimkehrer haben wertvolle Beute im Gepäck!" : "Die Soldaten beziehen wieder ihre Quartiere.";
                $main_text .= BattleReportRenderer::render_resource_list($loot);
                $main_text .= $units_html;

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box("Truppenrückkehr - $home_name", $main_text) .
                    "</div>";

            case "battle_monster":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $hx = (int)($data["home_x"] ?? 0);
                $hy = (int)($data["home_y"] ?? 0);
                $home_name = e($data["home_name"] ?? "Königreich");
                $camp_lvl = (int)($data["camp_level"] ?? 1);

                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $h_link = "<a href='map.php?startx=$hx&starty=$hy' data-on-click='mapJump' data-x='$hx' data-y='$hy'>$hx:$hy</a>";

                $html = "<div class='battle-report'>";
                $html .= "<div class='title-border'>Kampfbericht: Monstercamp ($c_link)</div>";
                $html .= "<div style='text-align: center; font-size: 13px; margin-top: -12px; margin-bottom: 6px; opacity: 0.8;'>Truppen aus: <b>$home_name</b> ($h_link)</div>";
                $html .= BattleReportRenderer::render_vs_grid($data["atk_units"] ?? [], $data["def_units"] ?? [], "Deine Truppen", "Monsterhorde (Lv $camp_lvl)");

                $out = $data["outcome"] ?? "defeat";
                if (!is_array($out)) {
                    $out = match ($out) {
                        "victory" => ["title" => "Sieg!", "text" => "Das Camp wurde gesäubert.", "sub" => "Deine Truppen bringen die Beute nach Hause!", "style" => "success"],
                        "pyrrhic_victory" => ["title" => "Sieg!", "text" => "Das Camp wurde gesäubert.", "sub" => "Alle Truppen fielen im Kampf. Die Beute ging verloren!", "style" => "success"],
                        "flawless" => ["title" => "Erfolgreiches Gefecht", "text" => "Wir haben die Reihen der Monster gelichtet!", "sub" => "Angriff ohne eigene Verluste. Wir ziehen uns taktisch zurück.", "style" => "success"],
                        "tactical_retreat" => ["title" => "Taktischer Rückzug", "text" => "Die Monsterhorde wurde geschwächt.", "sub" => "Wir konnten das Camp nicht vollständig säubern.", "style" => "neutral"],
                        "hard_resistance" => ["title" => "Harter Widerstand", "text" => "Die Monster waren diesmal zu stark!", "sub" => "Unsere Truppen mussten fliehen.", "style" => "error"],
                        "total_defeat" => ["title" => "Niederlage", "text" => "Deine Armee wurde vollständig vernichtet!", "sub" => "Kein einziger Soldat kehrte lebend zurück.", "style" => "error"],
                        default => ["title" => "Pattsituation", "text" => "Keine Seite konnte die Oberhand gewinnen.", "sub" => "Wir ziehen uns zurück.", "style" => "neutral"]
                    };
                }

                $html .= BattleReportRenderer::render_outcome_box(
                    $out["title"] ?? "Kampfausgang",
                    $out["text"] ?? "",
                    0, 0,
                    $out["sub"] ?? "",
                    $out["style"] ?? "neutral",
                    $data["loot"] ?? []
                );
                $html .= "</div>";
                return $html;

            case "battle_ruin":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $kname = e($data["ruin_name"] ?? "Vergessenes Reich");
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";

                $html = "<div class='battle-report'>";
                $html .= "<div class='title-border'>Schlacht um die Ruinen von $kname ($c_link)</div>";
                $html .= BattleReportRenderer::render_vs_grid($data["atk_units"] ?? [], $data["def_units"] ?? [], "Deine Truppen", "Besatzer der Ruine");

                $out = $data["outcome"] ?? "defeat";
                if (!is_array($out)) {
                    $out = match ($out) {
                        "victory" => ["title" => "Sieg!", "text" => "Die Ruinen wurden erfolgreich erstürmt.", "sub" => "Die Schätze wurden geborgen!", "style" => "success"],
                        "pyrrhic_victory" => ["title" => "Sieg!", "text" => "Die Ruinen wurden erstürmt.", "sub" => "Deine Truppen fielen. Die Beute ging verloren!", "style" => "success"],
                        default => ["title" => "Rückzug", "text" => "Die Verteidiger leisteten zu starken Widerstand.", "sub" => "Unsere Truppen mussten sich zurückziehen.", "style" => "error"]
                    };
                }

                $html .= BattleReportRenderer::render_outcome_box(
                    $out["title"] ?? "Kampfausgang",
                    $out["text"] ?? "",
                    0, 0,
                    $out["sub"] ?? "",
                    $out["style"] ?? "neutral",
                    $data["loot"] ?? []
                );
                $html .= "</div>";
                return $html;

            case "plunder":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $home_name = e($data["home_name"] ?? "Königreich");
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";

                $raiders_sent = (int)($data["raiders_sent"] ?? 0);
                $raiders_lost = (int)($data["raiders_lost"] ?? 0);
                $loot = $data["loot"] ?? [];
                $was_emptied = !empty($data["was_emptied"]);
                $is_success = ($raiders_sent > $raiders_lost);

                $html = "<div class='battle-report'>";

                if ($is_success) {
                    $main_text = "Unsere Räuber haben ein verlassenes Lager ($c_link) überfallen und Ressourcen erbeutet:";
                    $main_text .= BattleReportRenderer::render_resource_list($loot);
                    if ($was_emptied) {
                        $main_text .= "<br><b>Das Lager wurde komplett geleert.</b>";
                    }
                    $report_title = "Erfolgreiche Plünderung - $home_name";
                    $sub_text = "Die Überlebenden treten mit der Beute den Rückweg an.";
                    $report_type = "normal";
                } else {
                    $main_text = "Unsere Räuber haben ein verlassenes Lager ($c_link) überfallen, wurden aber im Hinterhalt von Dieben überwältigt!";
                    $report_title = "Plünderung gescheitert - $home_name";
                    $sub_text = "Niemand kehrte lebend zurück, die Beute ging verloren!";
                    $report_type = "error";
                }

                $main_text .= "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";
                $main_text .= BattleReportRenderer::render_unit_card("Räuber", $raiders_sent, $raiders_lost, "icon_robber");
                $main_text .= "</div>";

                if ($raiders_lost > 0) {
                    $main_text .= "<div style='margin-top: 10px; color: #ff4d4d; font-size: 0.9em;'>";
                    $main_text .= wrap_emojis("⚠️ <b>Verluste:</b> $raiders_lost Räuber wurden bei Kämpfen mit im Hinterhalt lauernden Dieben getötet.");
                    $main_text .= "</div>";
                }

                $html .= BattleReportRenderer::render_outcome_box($report_title, $main_text, 0, 0, $sub_text, $report_type);
                $html .= "</div>";
                return $html;

            case "plunder_already_empty":
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Plünderung fehlgeschlagen",
                        "Deine Truppen finden nur ein bereits geplündertes Lager vor.",
                        0, 0,
                        "Jemand war schneller! Die Truppen kehren um.",
                    ) .
                    "</div>";

            case "plunder_no_raiders":
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Keine Räuber",
                        "Ohne spezialisierte Räuber können wir diese massiven Vorräte nicht abtransportieren.",
                        0, 0,
                        "Die Truppen kehren unverrichteter Dinge um.",
                    ) .
                    "</div>";

            case "camp_cleared":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Camp bereits gesäubert",
                        "Deine Truppen sind eingetroffen ($c_link), aber das Monstercamp wurde bereits vernichtet.",
                        0, 0,
                        "Die Soldaten treten unverrichteter Dinge den Rückweg an."
                    ) . "</div>";

            case "ruin_decayed":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Ruine verfallen",
                        "Die Ruinen bei ($tx:$ty) sind endgültig zerfallen. Deine Truppen kehren um."
                    ) . "</div>";

            case "settle_result":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $field_name = e($data["field_name"] ?? "Unbekannt");
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $loc = "$c_link <b>($field_name)</b>";

                $status = $data["status"] ?? "error";

                if ($status === "success") {
                    $kname = e($data["founded_name"] ?? "Neues Reich");
                    $main = "<b>Erfolg!</b> Unsere Siedler haben bei $loc fruchtbares Land erschlossen.";
                    $sub = "Das neue Königreich <b>$kname</b> wurde erfolgreich gegründet und steht nun unter deinem Banner. Die restlichen Truppen kehren heim.";

                    return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box("Neues Dorf gegründet", $main, 0, 0, $sub, "success") . "</div>";
                } else if ($status === "failed_roll") {
                    $chance = (int)($data["chance"] ?? 30);
                    $main = "Die Gründung bei $loc ist fehlgeschlagen.";
                    $sub = "Die Siedler konnten sich nicht auf einen Standort einigen. Bei einer Erfolgschance von $chance% haben sie aufgegeben und kehren um.";

                    return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box("Expedition gescheitert", $main, 0, 0, $sub, "error") . "</div>";
                } else if ($status === "blocked") {
                    return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                            "Gründung abgebrochen",
                            "Bei der Ankunft bei $loc mussten unsere Siedler feststellen, dass das Land nicht mehr frei ist.",
                            0, 0,
                            "In der Zwischenzeit hat sich dort etwas anderes niedergelassen. Die Truppen kehren um.",
                            "error"
                        ) . "</div>";
                } else if ($status === "limit_reached") {
                    return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                            "Gründung untersagt",
                            "Deine Siedler sind bei $loc bereit, das Banner zu hissen, aber deine Verwaltung meldet: <b>Limit erreicht!</b>",
                            0, 0,
                            "Das Imperium kann derzeit keine weiteren Königreiche verwalten. Die Truppen kehren um.",
                            "error"
                        ) . "</div>";
                } else if ($status === "creation_error") {
                    return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                            "Gründungsfehler",
                            "Obwohl das Land ideal schien, verhinderte ein Fehler den Bau.",
                            0, 0,
                            "Kontaktiere bitte den Support.",
                            "error"
                        ) . "</div>";
                } else {
                    return "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                            "Keine Siedler",
                            "Hier bei $loc kann eine Siedlung errichtet werden.",
                            0, 0,
                            "Du hast zwar Truppen geschickt, aber keinen <b>Gründungskarren</b>. Ohne Siedler können wir dieses Land nicht beanspruchen.",
                        ) . "</div>";
                }

            case "trade_delivery":
                $kname = e($data["target_name"] ?? "Königreich");
                $main_text = "Eine Karawane ist in deinem Königreich <b>$kname</b> eingetroffen.";
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box("Warenlieferung", $main_text, 0, 0, "Die Vorräte wurden in die Lager eingelagert.", "neutral", $data["loot"] ?? []) .
                    "</div>";

            case "battle_pvp":
                $role = $data["role"] ?? "attacker";
                $is_attacker = ($role === "attacker");

                $opponent_name = e($data["opponent_name"] ?? "Gegner");
                $opp_x = (int)($data["opp_x"] ?? 0);
                $opp_y = (int)($data["opp_y"] ?? 0);
                $my_x = (int)($data["my_x"] ?? 0);
                $my_y = (int)($data["my_y"] ?? 0);

                $opp_link = "<a href='map.php?startx=$opp_x&starty=$opp_y' data-on-click='mapJump' data-x='$opp_x' data-y='$opp_y'>$opp_x:$opp_y</a>";
                $my_link = "<a href='map.php?startx=$my_x&starty=$my_y' data-on-click='mapJump' data-x='$my_x' data-y='$my_y'>$my_x:$my_y</a>";
                $my_kname = e($data["my_kname"] ?? "Königreich");

                if ($is_attacker) {
                    $header_title = "Kampfbericht: <b>$opponent_name</b> ($opp_link)";
                    $sub_title = "Truppen aus: <b>$my_kname</b> ($my_link)";
                    $atk_label = "Deine Truppen";
                    $def_label = "Verteidiger";
                } else {
                    $header_title = "Angriff von: <b>$opponent_name</b> ($opp_link)";
                    $sub_title = "Verteidigung von: <b>$my_kname</b> ($my_link)";
                    $atk_label = "Deine Verteidigung";
                    $def_label = "Angreifer";
                }

                $html = "<div class='battle-report'>";
                $html .= "<div class='title-border'>$header_title</div>";
                $html .= "<div style='text-align: center; font-size: 13px; margin-top: -12px; margin-bottom: 8px; opacity: 0.8;'>$sub_title</div>";
                $html .= BattleReportRenderer::render_vs_grid($data["atk_units"] ?? [], $data["def_units"] ?? [], $atk_label, $def_label);

                $out = $this->resolve_pvp_outcome($data["outcome"] ?? "repelled", $role);
                $html .= BattleReportRenderer::render_outcome_box(
                    $out["title"],
                    $out["main_text"],
                    (int)($data["wall_before"] ?? 0),
                    (int)($data["wall_after"] ?? 0),
                    $out["sub_text"] ?? "",
                    $out["type"] ?? "neutral"
                );

                if (!empty($data["conquest"])) {
                    $cq = $this->resolve_conquest_data($data["conquest"]);
                    if ($cq) {
                        $html .= BattleReportRenderer::render_outcome_box(
                            $cq["title"],
                            $cq["main_text"],
                            0, 0,
                            $cq["sub_text"] ?? "",
                            $cq["type"]
                        );
                    }
                }

                if (!empty($data["loot"])) {
                    $loot_title = $is_attacker ? "Erbeutete Ressourcen" : "Gestohlene Ressourcen";
                    $color = $is_attacker ? "passed" : "error";
                    $html .= BattleReportRenderer::render_resource_box($data["loot"], $loot_title, $color);
                }

                if (!empty($data["scout_intel"])) {
                    $html .= "<div class='battle-column' style='margin-top: 10px;'>";
                    $html .= "<div class='title-border'>Spionagebericht</div>";
                    $html .= BattleReportRenderer::render_scout_intel($data["scout_intel"]);
                    $html .= "</div>";
                }

                $html .= "</div>";
                return $html;

            case "spy_report":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $hx = (int)($data["home_x"] ?? 0);
                $hy = (int)($data["home_y"] ?? 0);
                $target_kname = e($data["target_kname"] ?? "Unbekannt");
                $opp_name = e($data["opp_name"] ?? "Gegner");
                $home_kname = e($data["home_kname"] ?? "Königreich");
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $h_link = "<a href='map.php?startx=$hx&starty=$hy' data-on-click='mapJump' data-x='$hx' data-y='$hy'>$hx:$hy</a>";
                $atk_scouts = (int)($data["atk_scouts"] ?? 0);
                $atk_losses = (int)($data["atk_losses"] ?? 0);

                $html = "<div class='battle-report'>";
                $html .= "<div class='battle-column'>";
                $html .= "<div class='title-border'>Spionagebericht: <b>$opp_name</b> ($c_link)</div>";
                $html .= "<div style='text-align: center; font-size: 13px; margin-top: -12px; margin-bottom: 8px; opacity: 0.8;'>Späher aus: <b>$home_kname</b> ($h_link)</div>";

                if (!empty($data["success"]) && !empty($data["intel"])) {
                    $html .= BattleReportRenderer::render_scout_intel($data["intel"]);
                    $html .= "</div>";
                    $html .= BattleReportRenderer::render_own_scout_status($atk_scouts, $atk_losses);
                } else {
                    $badge = "<div style='display: flex; justify-content: center; margin-top: 15px;'>" .
                        BattleReportRenderer::render_unit_card("Deine Späher", $atk_scouts, $atk_losses, "icon_scout") .
                        "</div>";

                    $main_text = "Unsere Späher wurden im Königreich <b>$target_kname</b> von <b>$opp_name</b> entdeckt und abgefangen." . $badge;
                    $html .= BattleReportRenderer::render_outcome_box(
                        "Spionage gescheitert",
                        $main_text,
                        0, 0,
                        "Kein einziger Späher kehrte lebend zurück.",
                        "error"
                    );
                    $html .= "</div>";
                }

                $html .= "</div>";
                return $html;

            case "spy_detected":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $hx = (int)($data["home_x"] ?? 0);
                $hy = (int)($data["home_y"] ?? 0);
                $attacker = e($data["attacker_name"] ?? "Gegner");
                $home_kname = e($data["home_kname"] ?? "Unbekannt");
                $target_kname = e($data["target_kname"] ?? "Königreich");
                $def_scouts = (int)($data["def_scouts"] ?? 0);
                $def_losses = (int)($data["def_losses"] ?? 0);
                $atk_scouts = (int)($data["atk_scouts"] ?? 0);
                $atk_losses = (int)($data["atk_losses"] ?? 0);
                $all_eliminated = !empty($data["all_eliminated"]);

                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $h_link = "<a href='map.php?startx=$hx&starty=$hy' data-on-click='mapJump' data-x='$hx' data-y='$hy'>$hx:$hy</a>";

                $badges_html = "";
                if ($def_scouts > 0) {
                    $badges_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 15px;'>";
                    $badges_html .= BattleReportRenderer::render_unit_card("Deine Späher", $def_scouts, $def_losses, "icon_scout");

                    if ($atk_scouts > 0) {
                        $badges_html .= BattleReportRenderer::render_unit_card("Gegnerische Späher", $atk_scouts, $atk_losses, "icon_scout");
                    }

                    $badges_html .= "</div>";
                }

                $def_main = "Späher von <b>$attacker</b> aus <b>$home_kname</b> ($h_link) wurden dabei ertappt, wie sie unser Königreich <b>$target_kname</b> ($c_link) ausspionierten." . $badges_html;

                $def_sub = $all_eliminated
                    ? "Unsere Wachen konnten alle feindlichen Spione eliminieren."
                    : "Einigen feindlichen Spionen gelang leider die Flucht mit Informationen.";

                $def_box_type = $all_eliminated ? "success" : "neutral";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Grenzwache: Eindringlinge!",
                        $def_main,
                        0, 0,
                        $def_sub,
                        $def_box_type
                    ) .
                    "</div>";

            case "spy_resource_tile":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $hx = (int)($data["home_x"] ?? 0);
                $hy = (int)($data["home_y"] ?? 0);
                $home_name = e($data["home_name"] ?? "Königreich");
                $atk_scouts = (int)($data["atk_scouts"] ?? 0);
                $losses = (int)($data["losses"] ?? 0);
                $is_success = !empty($data["success"]);

                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $h_link = "<a href='map.php?startx=$hx&starty=$hy' data-on-click='mapJump' data-x='$hx' data-y='$hy'>$hx:$hy</a>";

                $html = "<div class='battle-report'><div class='battle-column'>";

                if ($is_success) {
                    $html .= "<div class='title-border'>Spionage: Vorratslager ($c_link)</div>";
                    $html .= "<div style='text-align: center; font-size: 13px; margin-top: -12px; margin-bottom: 8px; opacity: 0.8;'>Späher aus: <b>$home_name</b> ($h_link)</div>";
                    $html .= "<div class='report-section-title'>Gefundene Vorräte</div>";
                    $html .= BattleReportRenderer::render_scout_resource_bar($data["resources"] ?? []);

                    if ($losses > 0) {
                        $html .= BattleReportRenderer::render_outcome_box(
                            "Erfolg!",
                            "Ein Späher verunglückte bei der Mission, aber die anderen konnten die Vorräte schätzen.",
                            0, 0, "", "success"
                        );
                    }
                } else {
                    $html .= "<div class='title-border'>Mission gescheitert ($c_link)</div>";
                    $html .= "<div style='text-align: center; font-size: 13px; margin-top: -12px; margin-bottom: 8px; opacity: 0.8;'>Späher aus: <b>$home_name</b> ($h_link)</div>";
                    $html .= BattleReportRenderer::render_outcome_box(
                        "Totalverlust",
                        "Dein Späher ist auf dem Weg zum Lager spurlos verschwunden. Wir haben keine Informationen erhalten.",
                        0, 0, "", "error"
                    );
                }

                $html .= BattleReportRenderer::render_own_scout_status($atk_scouts, $losses);
                $html .= "</div></div>";
                return $html;

            case "spy_monster_camp":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $hx = (int)($data["home_x"] ?? 0);
                $hy = (int)($data["home_y"] ?? 0);
                $home_name = e($data["home_name"] ?? "Königreich");
                $camp_lvl = (int)($data["camp_level"] ?? 1);
                $atk_scouts = (int)($data["atk_scouts"] ?? 0);
                $losses = (int)($data["losses"] ?? 0);
                $is_success = !empty($data["success"]);

                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $h_link = "<a href='map.php?startx=$hx&starty=$hy' data-on-click='mapJump' data-x='$hx' data-y='$hy'>$hx:$hy</a>";

                $html = "<div class='battle-report'><div class='battle-column'>";
                $html .= "<div class='title-border'>Spionage: Monstercamp ($c_link)</div>";
                $html .= "<div style='text-align: center; font-size: 13px; margin-top: -12px; margin-bottom: 6px; opacity: 0.8;'>Späher aus: <b>$home_name</b> ($h_link)</div>";

                if ($is_success) {
                    $html .= "<div class='report-section-title'>Gesichtete Kreaturen (Stufe $camp_lvl)</div>";
                    $html .= "<div style='display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 15px; justify-content: center;'>";

                    $sim_data = [];
                    foreach (($data["monsters"] ?? []) as $u) {
                        $sim_data[$u["id"]] = $u["count"];
                        $html .= BattleReportRenderer::render_unit_card($u, 0, 0, "", true);
                    }
                    $html .= "</div>";

                    if (!empty($sim_data)) {
                        $encoded = urlencode(json_encode($sim_data));
                        $html .= "<div style='text-align: center;'>
                            <a href='warsim.php?import_monsters=$encoded'>
                                <button type='button'>⚔️ Werte in War Simulator übertragen</button>
                            </a>
                        </div>";
                    }

                    $est = $data["est_loot"] ?? [];
                    $html .= "<div class='report-section-title' style='margin-top: 10px;'>Geschätzte Beute</div>";
                    $html .= "<div style='background: rgba(0,0,0,0.3); padding: 10px; border-radius: 5px; text-align: left;'>";
                    $html .= "<b>Münzen:</b> " . ($est["coins_min"] ?? 0) . " bis " . ($est["coins_max"] ?? 0) . " " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . "<br>";
                    $html .= "<b>Nahrung/Gold:</b> " . fnum($est["food_gold_min"] ?? 0) . " bis " . fnum($est["food_gold_max"] ?? 0) . "<br>";
                    $html .= "<b>Holz/Stein:</b> " . fnum($est["wood_stone_min"] ?? 0) . " bis " . fnum($est["wood_stone_max"] ?? 0) . "<br>";
                    $html .= "<div style='margin-top: 5px; font-size: 13px; opacity: 0.8;'><i>Hinweis: Nahrung und Gold sind garantiert. Holz und Stein generieren zu " . MONSTER_CAMP_RES_CHANCE . "%.</i></div>";
                    $html .= "</div>";
                } else {
                    $html .= BattleReportRenderer::render_outcome_box(
                        "Mission gescheitert!",
                        "Keiner der Späher kehrte aus dem Camp zurück.",
                        0, 0,
                        "",
                        "error"
                    );
                }

                $html .= BattleReportRenderer::render_own_scout_status($atk_scouts, $losses);
                $html .= "</div></div>";
                return $html;

            case "spy_mine":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $hx = (int)($data["home_x"] ?? 0);
                $hy = (int)($data["home_y"] ?? 0);
                $home_name = e($data["home_name"] ?? "Königreich");
                $mine_lvl = (int)($data["mine_level"] ?? 1);
                $atk_scouts = (int)($data["atk_scouts"] ?? 0);
                $losses = (int)($data["losses"] ?? 0);
                $is_success = !empty($data["success"]);

                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $h_link = "<a href='map.php?startx=$hx&starty=$hy' data-on-click='mapJump' data-x='$hx' data-y='$hy'>$hx:$hy</a>";

                $html = "<div class='battle-report'><div class='battle-column'>";

                if ($is_success) {
                    $html .= "<div class='title-border'>Spionagebericht: Erzmine (Stufe $mine_lvl) ($c_link)</div>";
                    $html .= "<div style='text-align: center; font-size: 13px; margin-top: -12px; margin-bottom: 8px; opacity: 0.8;'>Späher aus: <b>$home_name</b> ($h_link)</div>";

                    $res_std = $data["res_standard"] ?? [];
                    $html .= "<div class='report-section-title'>Verbleibende Rohstoffe</div>";
                    if (array_sum($res_std) > 0) {
                        $html .= BattleReportRenderer::render_scout_resource_bar($res_std);
                    } else {
                        $html .= "<div style='text-align: center; padding: 5px; opacity: 0.8;'><i>Keine Rohstoffe mehr vorhanden.</i></div>";
                    }

                    $res_spec = $data["res_special"] ?? [];
                    $html .= "<div class='report-section-title' style='margin-top: 15px;'>Spezial-Erze (für Gilden-Schatzkammer)</div>";
                    if (array_sum($res_spec) > 0) {
                        $html .= BattleReportRenderer::render_scout_resource_bar($res_spec);
                    } else {
                        $html .= "<div style='text-align: center; padding: 5px; opacity: 0.8;'><i>Keine Spezial-Erze mehr vorhanden.</i></div>";
                    }

                    $html .= "<div class='report-section-title' style='margin-top: 15px;'>Stationierte Truppen</div>";
                    $defenders = $data["defenders"] ?? [];
                    if (!empty($defenders)) {
                        $occupiers = array_map('htmlspecialchars', $data["occupiers"] ?? []);
                        $html .= "<div style='text-align: center; margin-bottom: 8px; font-size: 13px; color: var(--link-color);'>Besetzt durch: <b>" . implode(", ", $occupiers) . "</b></div>";
                        $html .= "<div style='display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;'>";

                        foreach ($defenders as $d) {
                            $html .= BattleReportRenderer::render_unit_card($d, 0, 0, "", true);
                        }

                        $html .= "</div>";
                    } else {
                        $html .= "<div style='text-align: center; padding: 5px; opacity: 0.8;'><i>Die Mine ist aktuell unbesetzt.</i></div>";
                    }

                    if ($losses > 0) {
                        $html .= "<div style='margin-top: 10px; color: #ff4d4d; font-size: 0.9em; text-align: center;'>";
                        $html .= wrap_emojis("⚠️ <b>Verluste:</b> $losses Späher wurden von der Minen-Besatzung entdeckt und ausgeschaltet.");
                        $html .= "</div>";
                    }
                } else {
                    $html .= BattleReportRenderer::render_outcome_box(
                        "Spionage gescheitert",
                        "Deine Späher wurden bei der Erzmine ($c_link) von der Besatzung entdeckt und vollständig ausgelöscht!",
                        0, 0,
                        "Es konnten keine Informationen beschafft werden.",
                        "error"
                    );
                    $html .= "<div style='display: flex; justify-content: center; margin-top: 10px;'>" . BattleReportRenderer::render_unit_card("Deine Späher", $atk_scouts, $losses, "icon_scout", true) . "</div>";
                }

                $html .= BattleReportRenderer::render_own_scout_status($atk_scouts, $losses);
                $html .= "</div></div>";
                return $html;

            case "mine_depleted":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Erzmine erschöpft",
                        "Deine Truppen sind bei den Koordinaten ($c_link) eingetroffen, aber die Erzmine existiert nicht mehr oder wurde bereits vollständig abgebaut.",
                        0, 0,
                        "Deine Einheiten haben unverrichteter Dinge den Rückmarsch angetreten."
                    ) . "</div>";

            case "mine_depleted_early_return":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $home_name = e($data["home_name"] ?? "Königreich");
                $units = $data["units"] ?? [];

                $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 8px; margin-top: 15px; justify-content: center;'>";
                foreach ($units as $u) {
                    $units_html .= BattleReportRenderer::render_unit_card($u, 0, 0, "", true);
                }
                $units_html .= "</div>";

                $main_text = "Die <b>Erzmine</b> bei $c_link wurde vollständig erschöpft und existiert nicht mehr.
                             Deine Truppen aus <b>$home_name</b> haben sofort auf freiem Feld umgedreht und befinden sich auf dem Rückmarsch.$units_html";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Mine erschöpft – Vorzeitige Rückkehr",
                        $main_text,
                        0, 0,
                        "Deine Einheiten kehren ohne Verzögerung in ihre Garnison zurück."
                    ) .
                    "</div>";

            case "spy_mine_detected":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $attacker = e($data["attacker_name"] ?? "Gegner");
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Späher abgewehrt",
                        "Feindliche Späher von <b>$attacker</b> haben unsere Schürfer in der Mine bei $c_link beobachtet, wurden aber entdeckt.",
                        0, 0,
                        "Unsere Truppen schürfen wachsam weiter."
                    ) .
                    "</div>";

            case "spy_ruin":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $losses = (int)($data["losses"] ?? 0);
                $kname = e($data["ruin_name"] ?? "Vergessenes Reich");
                $atk_scouts = (int)($data["atk_scouts"] ?? 0);
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";

                $html = "<div class='battle-report'><div class='battle-column'>";
                $html .= "<div class='title-border'>Kundschafterbericht: Ruinen von $kname ($c_link)</div>";
                $html .= "<div class='report-section-title'>Gelagerte Schätze</div>";
                $html .= BattleReportRenderer::render_scout_resource_bar($data["resources"] ?? []);

                $html .= "<div class='report-section-title' style='margin-top: 15px;'>Gesichtete Besatzer</div>";
                $html .= "<div style='display: flex; flex-wrap: wrap; gap: 5px; justify-content: center; margin-top: 10px;'>";

                $sim_data = [];
                foreach (($data["monsters"] ?? []) as $u) {
                    $sim_data[$u["id"]] = (int)$u["count"];
                    $html .= BattleReportRenderer::render_unit_card($u, 0, 0, "", true);
                }
                $html .= "</div>";

                if (!empty($sim_data)) {
                    $encoded = urlencode(json_encode($sim_data));
                    $html .= "<div style='text-align: center; margin: 15px 0;'>
                        <a href='warsim.php?import_monsters=$encoded'>
                            <button type='button'>⚔️ Werte in War Simulator übertragen</button>
                        </a>
                    </div>";
                }

                $html .= BattleReportRenderer::render_own_scout_status($atk_scouts, $losses);
                $html .= "</div></div>";
                return $html;

            case "support_turned_back":
                $reason = $data["reason"] ?? "target_lost";
                $target = e($data["target_name"] ?? "Königreich");

                [$title_reason, $main_reason] = match ($reason) {
                    "no_alliance" => ["Kein Bündnis", "Da ihr nicht mehr in derselben Gilde seid, wurde deinen Truppen der Einlass in <b>$target</b> verwehrt."],
                    "storage_full" => ["Lager voll", "Das Unterstützungslager in <b>$target</b> ist bereits voll belegt."],
                    default => ["Ziel unbekannt", "Das Ziel-Königreich <b>$target</b> existiert nicht mehr."]
                };

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Hilfsaktion fehlgeschlagen: $title_reason",
                        $main_reason,
                        0, 0,
                        "Deine Truppen haben sofort den Rückmarsch angetreten.",
                        "error"
                    ) . "</div>";

            case "support_arrival":
                $role = $data["role"] ?? "recipient";
                $is_recipient = ($role === "recipient");

                $sender = e($data["sender_name"] ?? "Verbündeter");
                $recipient = e($data["recipient_name"] ?? "Herrscher");
                $target = e($data["target_name"] ?? "Königreich");
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";

                $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 15px;'>";
                foreach (($data["units"] ?? []) as $t) {
                    $units_html .= BattleReportRenderer::render_unit_card($t, 0, 0, "", true);
                }
                $units_html .= "</div>";

                if ($is_recipient) {
                    $title = "Gilden-Unterstützung erhalten";
                    $main = "Die Truppen von <b>$sender</b> sind in <b>$target</b> ($c_link) eingetroffen. $units_html";
                    $sub = "Sie schützen ab sofort dein Königreich.";
                } else {
                    $title = "Unterstützung angekommen";
                    $main = "Deine Truppen haben <b>$target</b> ($c_link) von <b>$recipient</b> erreicht und die Stellung bezogen. $units_html";
                    $sub = "Du kannst sie jederzeit über deine Kaserne zurückrufen.";
                }

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box($title, $main, 0, 0, $sub, "support") .
                    "</div>";

            case "support_recalled":
                $action_type = $data["action_type"] ?? "withdrawn";
                $owner = e($data["owner_name"] ?? "Besitzer");
                $host = e($data["host_name"] ?? "Gastgeber");
                $target = e($data["target_name"] ?? "Königreich");

                $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 15px;'>";
                foreach (($data["units"] ?? []) as $t) {
                    $units_html .= BattleReportRenderer::render_unit_card($t, 0, 0, "", true);
                }
                $units_html .= "</div>";

                if ($action_type === "withdrawn") {
                    $title = "Unterstützung beendet";
                    $main = "Der Spieler <b>$owner</b> hat seine Truppen aus deinem Königreich <b>$target</b> abgezogen.$units_html";
                    $sub = "Die Einheiten haben den Rückmarsch angetreten.";
                } else {
                    $title = "Unterstützung entlassen";
                    $main = "Der Spieler <b>$host</b> hat deine Truppen aus seinem Königreich <b>$target</b> entlassen.$units_html";
                    $sub = "Deine Einheiten befinden sich nun auf dem Heimweg.";
                }

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box($title, $main, 0, 0, $sub, "support") .
                    "</div>";

            case "support_orphaned":
                $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 15px;'>";
                foreach (($data["units"] ?? []) as $t) {
                    $units_html .= BattleReportRenderer::render_unit_card($t, 0, 0, "", true);
                }
                $units_html .= "</div>";

                $main = "Das befreundete Königreich existiert nicht mehr. Deine Truppen sind sofort in deine Kaserne zurückgekehrt!$units_html";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Unterstützung zurückgekehrt",
                        $main,
                        0, 0,
                        "Die Soldaten stehen dir ab sofort wieder zur Verfügung.",
                        "support"
                    ) .
                    "</div>";

            case "support_combat":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $ax = (int)($data["atk_x"] ?? 0);
                $ay = (int)($data["atk_y"] ?? 0);
                $target_name = e($data["target_name"] ?? "Königreich");
                $attacker_name = e($data["attacker_name"] ?? "Gegner");
                $atk_kname = e($data["atk_kname"] ?? "Feinddorf");
                $total_loss = (int)($data["total_loss"] ?? 0);

                $target_c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $atk_c_link = "<a href='map.php?startx=$ax&starty=$ay' data-on-click='mapJump' data-x='$ax' data-y='$ay'>$ax:$ay</a>";

                $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 15px;'>";
                foreach (($data["units"] ?? []) as $u) {
                    $units_html .= BattleReportRenderer::render_unit_card($u);
                }
                $units_html .= "</div>";

                $outcome_text = ($total_loss > 0) ? " Sie haben Verluste erlitten." : " Sie haben den Angriff unbeschadet überstanden.";
                $type = ($total_loss > 0) ? "error" : "success";

                $main_text = "Deine Truppen in <b>$target_name</b> ($target_c_link) wurden von <b>$attacker_name</b> aus <b>$atk_kname</b> ($atk_c_link) angegriffen.";
                $main_text .= $outcome_text;
                $main_text .= $units_html;

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box("Unterstützungskampf: Bericht", $main_text, 0, 0, "", $type) .
                    "</div>";

            case "reinforce_self":
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $target_name = e($data["target_name"] ?? "Königreich");
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";

                $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";
                foreach (($data["units"] ?? []) as $u) {
                    $units_html .= "<div style='flex: 0 1 fit-content;'>" . BattleReportRenderer::render_unit_card($u, 0, 0, "", true) . "</div>";
                }
                $units_html .= "</div>";

                $main_text = "Deine Truppen sind erfolgreich bei deinem Königreich <b>$target_name</b> ($c_link) angekommen." . $units_html;

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Verstärkung angekommen",
                        $main_text,
                        0, 0,
                        "Die Soldaten stehen ab sofort zur Verteidigung bereit.",
                        "success"
                    ) .
                    "</div>";

            case "trade_accepted":
                $buyer_name = e($data["buyer_name"] ?? "Ein Spieler");
                $buyer_kname = e($data["buyer_kname"] ?? "seinem Königreich");
                $arrival_str = convert_sec_to_str((int)($data["arrival_time"] ?? 0));
                $cost = $data["cost"] ?? [];

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Handelsangebot angenommen",
                        "Der Spieler <b>$buyer_name</b> hat deine Warenlieferung aus $buyer_kname akzeptiert.",
                        0, 0,
                        "Deine Karawane bringt den Erlös in <b>$arrival_str</b> zurück.",
                        "neutral",
                        $cost
                    ) .
                    "</div>";

            case "market_offer_expired":
                $fee = (int)($data["listing_fee"] ?? 1);
                $loot = $data["loot"] ?? [];

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Marktplatz-Info",
                        "Ein Handelsangebot ist abgelaufen.",
                        0, 0,
                        "Die Ressourcen wurden sicher in dein Lager zurückgebracht und die Einstellgebühr von $fee Münzen wurde deinem Konto erstattet.",
                        "neutral",
                        $loot
                    ) .
                    "</div>";

            case "trade_aborted_target_lost":
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Handel fehlgeschlagen",
                        "Das Ziel-Königreich existiert nicht mehr.",
                        0, 0,
                        "Deine Karawane hat umgedreht und bringt die Waren zurück.",
                        "error"
                    ) .
                    "</div>";

            case "trade_rerouted_main_kingdom":
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Handels-Info: Karawane umgeleitet",
                        "Das ursprüngliche Ziel-Königreich hat den Besitzer gewechselt.",
                        0, 0,
                        "Deine Karawane wurde zu deinem Haupt-Königreich umgeleitet."
                    ) .
                    "</div>";

            case "trade_lost_no_kingdoms":
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Warenlieferung verloren",
                        "Eine Warenlieferung ging verloren, da du über keine Königreiche mehr verfügst, die die Waren aufnehmen könnten.",
                        0, 0,
                        "",
                        "error"
                    ) .
                    "</div>";

            case "noob_protection":
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Angriff abgebrochen",
                        "Der Gegner steht unter Noob-Schutz!",
                        0, 0,
                        "Deine Truppen machen sich sofort auf den Heimweg.",
                        "error"
                    ) .
                    "</div>";

            case "target_lost":
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Ziel nicht mehr erreichbar",
                        "Dein Ziel wurde aufgegeben oder existiert nicht mehr.",
                        0, 0,
                        "Deine Truppen haben sofort den Rückweg angetreten."
                    ) . "</div>";

            case "outcome_box":
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        $data["title"] ?? "Meldung",
                        $data["main_text"] ?? "",
                        $data["wall_before"] ?? 0,
                        $data["wall_after"] ?? 0,
                        $data["sub_text"] ?? "",
                        $data["result_type"] ?? "neutral",
                        $data["loot"] ?? []
                    ) .
                    "</div>";

            case "watchtower_alert":
                $kname = e($data["kingdom_name"] ?? "Königreich");
                $intel = (int)($data["intel_level"] ?? 0);
                $arrival_sec = (int)($data["arrival_seconds"] ?? 0);

                $main_text = "Unsere Grenzwachen in <b>$kname</b> haben herannahende Truppen gesichtet!<br>";

                // Level 1: Arrival Time
                $sub_text = ($intel < 1)
                    ? "Die Truppen sind auf dem Vormarsch."
                    : "Ankunft in ca.: " . convert_sec_to_str($arrival_sec);

                // Level 2: Location
                if ($intel >= 2 && !empty($data["source"])) {
                    $src = $data["source"];
                    $main_text .= "<br>Herkunft: <b>" . e($src["name"]) . "</b> (" . (int)$src["x"] . ":" . (int)$src["y"] . ")";
                }

                // Level 3: Rough Troop Strength
                if ($intel >= 3 && isset($data["total_units"])) {
                    $total = (int)$data["total_units"];
                    if ($total < 50) $strength_label = "Ein kleiner Trupp";
                    else if ($total < 200) $strength_label = "Eine ansehnliche Streitmacht";
                    else if ($total < 1000) $strength_label = "Ein großes Heer";
                    else $strength_label = "Eine gewaltige Armee";

                    $main_text .= "<br>Späherbericht: <i>$strength_label (ca. " . fnum($total) . " Einheiten)</i>";
                }

                // Level 4: Troops identified
                if ($intel >= 4 && !empty($data["units"])) {
                    $main_text .= "<br><br><b>Identifizierte Einheiten:</b><br>";
                    $main_text .= "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 5px;'>";
                    foreach ($data["units"] as $t) {
                        $main_text .= BattleReportRenderer::render_unit_card($t, 0, 0, "", true);
                    }
                    $main_text .= "</div>";

                    // Level 5: Battle Strength
                    if ($intel >= 5 && !empty($data["strength"])) {
                        $str = $data["strength"];
                        $main_text .= "<div style='margin-top: 12px; padding-top: 8px; border-top: 1px ridge rgba(212,175,55,0.4); text-align: left;'>";
                        $main_text .= "<b>Geschätzte Gesamtstärke:</b><br>";
                        $main_text .= "<span style='margin-right: 20px;'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . " " . fnum($str["atk"]) . "</span>";
                        $main_text .= "<span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . " " . fnum($str["def"]) . "</span>";
                        $main_text .= "</div>";
                    }
                }

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box("WACHTURM-MELDUNG", $main_text, 0, 0, $sub_text, "error") .
                    "</div>";

            case "world_event_missed":
                $status = $data["status"] ?? "boss_dead";
                $event_title = e($data["event_title"] ?? "Weltevent");

                $units_html = "";
                if (!empty($data["units"])) {
                    $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";
                    foreach ($data["units"] as $ru) {
                        $units_html .= BattleReportRenderer::render_unit_card($ru, 0, 0, "", true);
                    }
                    $units_html .= "</div>";
                }

                if ($status === "boss_dead") {
                    $title = $event_title;
                    $main = "Als deine Truppen das Zentrum erreichten, war das Monster bereits von anderen Herrschern besiegt worden! $units_html";
                    $sub = "Die Soldaten feiern den Sieg und kehren heim.";
                    $res_type = "neutral";
                } else if ($status === "no_attempts") {
                    $title = "Keine Versuche";
                    $main = "Deine Truppen sind angekommen, aber du hast bereits alle Versuche für dieses Event aufgebraucht! $units_html";
                    $sub = "Die Soldaten ziehen unverrichteter Dinge ab.";
                    $res_type = "error";
                } else { // "no_event"
                    $title = "Event-Bericht";
                    $main = "Deine Truppen haben das Zentrum erreicht, aber derzeit findet kein Event statt.";
                    $sub = "Die Soldaten treten unverrichteter Dinge den Rückweg an.";
                    $res_type = "neutral";
                }

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box($title, $main, 0, 0, $sub, $res_type) .
                    "</div>";

            case "mine_station_result":
                $status = $data["status"] ?? "started";
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $src_kname = e($data["src_kname"] ?? "Königreich");

                if ($status === "noob_protected") {
                    return "<div class='battle-report'>" .
                        BattleReportRenderer::render_outcome_box(
                            "Angriff abgebrochen: Noob-Schutz",
                            "Die Schürfer in dieser <b>Stufe-1-Mine</b> ($c_link) stehen unter Noob-Schutz! Deine Truppen kehren kampflos um.",
                            0, 0, "", "error"
                        ) .
                        "</div>";
                }

                if ($status === "full") {
                    $max_cap = (int)($data["max_capacity"] ?? 50);
                    $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 8px; margin-top: 15px; justify-content: center;'>";
                    foreach (($data["units"] ?? []) as $u) {
                        $units_html .= BattleReportRenderer::render_unit_card($u, 0, 0, "", true);
                    }
                    $units_html .= "</div>";

                    $msg_text = "Deine Truppen haben die <b>Mine</b> ($c_link) erreicht. Da die Kapazität von <b>$max_cap Einheiten</b> jedoch bereits vollständig belegt ist, konnten sie nicht schürfen und haben sofort den Rückmarsch nach <b>$src_kname</b> angetreten.$units_html";

                    return "<div class='battle-report'>" .
                        BattleReportRenderer::render_outcome_box(
                            "Minen-Kapazität erschöpft",
                            $msg_text,
                            0, 0, "Die Einheiten kehren ohne Beute zurück.", "error"
                        ) .
                        "</div>";
                }

                if ($status === "started") {
                    $sx = (int)($data["src_x"] ?? 0);
                    $sy = (int)($data["src_y"] ?? 0);
                    $h_link = "<a href='map.php?startx=$sx&starty=$sy' data-on-click='mapJump' data-x='$sx' data-y='$sy'>$sx:$sy</a>";

                    $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 8px; margin-top: 15px; justify-content: center;'>";
                    foreach (($data["units"] ?? []) as $u) {
                        $units_html .= BattleReportRenderer::render_unit_card($u, 0, 0, "", true);
                    }
                    $units_html .= "</div>";

                    $msg_text = "Deine Truppen aus <b>$src_kname</b> ($h_link) haben die <b>Mine</b> ($c_link) erreicht und bauen nun Ressourcen ab.$units_html";

                    return "<div class='battle-report'>" .
                        BattleReportRenderer::render_outcome_box(
                            "Schürfarbeiten begonnen",
                            $msg_text,
                            0, 0, "", "success"
                        ) .
                        "</div>";
                }

                if ($status === "partial") {
                    $stationed = $data["stationed_units"] ?? [];
                    $returned = $data["returned_units"] ?? [];
                    $total_stationed = array_sum(array_column($stationed, "count"));
                    $total_returned = array_sum(array_column($returned, "count"));

                    $stationed_html = "<div style='display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; justify-content: center;'>";
                    foreach ($stationed as $ts) {
                        $stationed_html .= BattleReportRenderer::render_unit_card($ts, 0, 0, "", true);
                    }
                    $stationed_html .= "</div>";

                    $return_html = "<div style='display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; justify-content: center;'>";
                    foreach ($returned as $tr) {
                        $return_html .= BattleReportRenderer::render_unit_card($tr, 0, 0, "", true);
                    }
                    $return_html .= "</div>";

                    $msg_text = "Deine Truppen haben die <b>Mine</b> ($c_link) erreicht. Da die Mine fast voll war, 
                                 konnten nur <b>$total_stationed Einheiten</b> den Abbau aufnehmen.<br>Die restlichen <b>$total_returned Einheiten</b> befinden sich auf dem Rückmarsch nach <b>$src_kname</b>.<br>"
                        . "<div class='report-section-title' style='margin-top: 15px;'>Eingesetzte Schürfer</div>" . $stationed_html
                        . "<div class='report-section-title' style='margin-top: 15px;'>Rückkehrende Einheiten</div>" . $return_html;

                    return "<div class='battle-report'>" .
                        BattleReportRenderer::render_outcome_box(
                            "Mine teilweise belegt",
                            $msg_text
                        ) .
                        "</div>";
                }

                return "";

            case "mine_battle":
                $role = $data["role"] ?? "attacker";
                $outcome = $data["outcome"] ?? "conquered";
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";
                $attacker = e($data["attacker_name"] ?? "Gegner");
                $def_list = e($data["def_list"] ?? "die Besatzung");
                $atk_units = $data["atk_units"] ?? [];
                $def_units = $data["def_units"] ?? [];

                $html = "<div class='battle-report'>";

                if ($role === "attacker") {
                    if ($outcome === "conquered") {
                        $html .= BattleReportRenderer::render_vs_grid($atk_units, $def_units, "Deine Streitmacht", "Vertriebene Besatzung");
                        $html .= BattleReportRenderer::render_outcome_box(
                            "Mine erobert!",
                            "Deine Truppen haben die <b>Mine</b> ($c_link) von <b>$def_list</b> erfolgreich eingenommen und bauen die restlichen Rohstoffe ab!",
                            0, 0, "", "success"
                        );
                    } else { // repelled
                        $html .= BattleReportRenderer::render_vs_grid($atk_units, $def_units, "Deine Streitmacht", "Minen-Besatzung");
                        $html .= BattleReportRenderer::render_outcome_box(
                            "Angriff abgewehrt",
                            "Die Verteidiger von <b>$def_list</b> der Mine bei $c_link waren überlegen. Deine Truppen wurden kampflos zurückgedrängt.",
                            0, 0, "", "error"
                        );
                    }
                } else { // defender
                    if ($outcome === "evicted") {
                        $home_kname = e($data["home_kname"] ?? "deinem Königreich");
                        $hx = (int)($data["home_x"] ?? 0);
                        $hy = (int)($data["home_y"] ?? 0);
                        $h_link = ($hx > 0 && $hy > 0) ? " (<a href='map.php?startx=$hx&starty=$hy' data-on-click='mapJump' data-x='$hx' data-y='$hy'>$hx:$hy</a>)" : "";

                        $html .= BattleReportRenderer::render_vs_grid($def_units, $atk_units, "Deine Minen-Truppen", "Übermacht");
                        $html .= BattleReportRenderer::render_outcome_box(
                            "Aus Mine vertrieben!",
                            "Deine Truppen aus <b>$home_kname</b>$h_link wurden aus der <b>Mine</b> ($c_link) von <b>$attacker</b> vertrieben! Sie mussten diese fluchtartig ohne Beute verlassen.",
                            0, 0, "Die Truppen kehren unversehrt heim.", "error"
                        );
                    } else { // defended
                        $html .= BattleReportRenderer::render_vs_grid($def_units, $atk_units, "Deine Minen-Truppen", "Abgewehrt ($attacker)");
                        $html .= BattleReportRenderer::render_outcome_box(
                            "Mine verteidigt!",
                            "Ein Übernahmeversuch von <b>$attacker</b> bei $c_link wurde erfolgreich abgewehrt. Der Abbau geht ungehindert weiter!",
                            0, 0, "", "success"
                        );
                    }
                }

                $html .= "</div>";
                return $html;

            case "support_disbanded":
                $host_kname = e($data["host_kname"] ?? "deinem Königreich");
                $ally_name = e($data["ally_name"] ?? "einem Verbündeten");

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Verbündete Streitmacht aufgelöst",
                        "Die bei dir in <b>$host_kname</b> stationierten Truppen von <b>$ally_name</b> haben sich aufgelöst, da ihr Herrscher vernichtend geschlagen wurde und sein Reich unterging.",
                        0, 0,
                        "Die Einheiten stehen nicht mehr zur Verteidigung zur Verfügung.",
                        "error"
                    ) .
                    "</div>";

            case "guild_invite":
                $guild_name = e($data["guild_name"] ?? "einer Gilde");
                $invite_id = (int)($data["invite_id"] ?? 0);

                $buttons = "<br><br><div style='display: flex; gap: 10px; justify-content: center;'>
                    <button data-on-click='acceptGuildInvite' data-id='$invite_id'>Annehmen</button>
                    <button data-on-click='declineGuildInvite' data-id='$invite_id'>Ablehnen</button>
                </div>";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Gilden-Einladung",
                        "Du wurdest eingeladen, der Gilde <b>$guild_name</b> beizutreten." . $buttons,
                        0, 0,
                        "Die Einladung ist 48 Stunden gültig."
                    ) .
                    "</div>";

            case "guild_invite_declined":
                $declined_by = e($data["declined_by"] ?? "Ein Spieler");
                $guild_name = e($data["guild_name"] ?? "deine Gilde");

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Einladung abgelehnt",
                        "Der Spieler <b>$declined_by</b> hat deine Einladung zu <b>$guild_name</b> abgelehnt.",
                        0, 0,
                        "",
                        "error"
                    ) .
                    "</div>";

            case "guild_recruitment_success":
                $member_name = e($data["member_name"] ?? "Ein neues Mitglied");

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Rekrutierung erfolgreich",
                        "Deine Einladung zur Gilde wurde von <b>$member_name</b> akzeptiert.",
                        0, 0,
                        "Heißt das neue Mitglied im Gilden-Rat willkommen!",
                        "success"
                    ) .
                    "</div>";

            case "guild_rank_changed":
                $rank_name = e($data["rank_name"] ?? "Mitglied");
                $changed_by = e($data["changed_by"] ?? "die Gildenführung");

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Rangänderung",
                        "Dein Rang in der Gilde wurde auf <b>$rank_name</b> geändert.",
                        0, 0,
                        "Veranlasst durch $changed_by"
                    ) .
                    "</div>";

            case "guild_kicked":
                $tag = e($data["guild_tag"] ?? "");
                $name = e($data["guild_name"] ?? "deiner Gilde");
                $tag_display = !empty($tag) ? "[$tag] " : "";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Gildenausschluss",
                        "Du wurdest aus der Gilde <b>$tag_display$name</b> entfernt.",
                        0, 0,
                        "",
                        "error"
                    ) .
                    "</div>";

            case "guild_leadership_transferred":
                $reason = $data["reason"] ?? "leader_left";

                if ($reason === "leader_deleted") {
                    $old_leader = e($data["old_leader_name"] ?? "Der bisherige Anführer");
                    $main = "Der bisherige Gilden-Anführer <b>$old_leader</b> hat das Reich verlassen.<br><br><b>Du bist nun der neue Anführer der Gilde!</b>";
                    $sub = "Verwalte deine Mitglieder weise und führe sie zu Ruhm.";
                } else {
                    $main = "Der bisherige Gilden-Anführer hat die Gilde verlassen. <b>Du bist nun der neue Anführer!</b>";
                    $sub = "";
                }

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Gildenführung",
                        $main,
                        0, 0,
                        $sub,
                        "success"
                    ) .
                    "</div>";

            case "support_alliance_ended":
                $role = $data["role"] ?? "owner";
                $owner = e($data["owner_name"] ?? "Besitzer");
                $host = e($data["host_name"] ?? "Gastgeber");
                $target = e($data["target_name"] ?? "Königreich");
                $travel_str = convert_sec_to_str((int)($data["travel_time"] ?? 0));

                $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 15px;'>";
                foreach (($data["units"] ?? []) as $u) {
                    $units_html .= BattleReportRenderer::render_unit_card($u, 0, 0, "", true);
                }
                $units_html .= "</div>";

                if ($role === "owner") {
                    $title = "Truppenrückzug: Allianz beendet";
                    $main = "Da die Allianz mit <b>$host</b> nicht mehr besteht, haben deine Truppen das Königreich <b>$target</b> verlassen und den Rückmarsch angetreten.$units_html";
                    $sub = "Ankunft in $travel_str";
                    $type = "support";
                } else {
                    $title = "Unterstützung verloren";
                    $main = "Aufgrund einer Beendigung der Gildenallianz haben die Truppen von <b>$owner</b> dein Königreich <b>$target</b> verlassen.$units_html";
                    $sub = "Deine Verteidigung wurde geschwächt.";
                    $type = "error";
                }

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box($title, $main, 0, 0, $sub, $type) .
                    "</div>";

            case "round_reset":
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Runden-Reset erfolgt!",
                        "Ein Administrator hat die Welt neugestartet.<br>Alle Königreiche, Truppen und Ressourcen wurden zurückgesetzt. Viel Erfolg in der neuen Runde!",
                        0, 0,
                        "Frischer Start für alle Herrscher."
                    ) .
                    "</div>";

            case "chat_conversation_ended":
                $user = e($data["user_name"] ?? "Dein Chatpartner");
                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Konversation beendet",
                        "Der Spieler <b>$user</b> hat die Konversation mit dir gelöscht und den Chat beendet."
                    ) .
                    "</div>";

            case "daily_hero_received":
                $kname = e($data["kingdom_name"] ?? "deinem Königreich");
                $text = "<div style='margin: 10px; text-align: center;'><img src='images/icons/icon_hero.png' alt='Held'></div>";
                $text .= "Ein legendärer <b>Held</b> hat von deinen Taten gehört und sich entschlossen, deinem Königreich <b>$kname</b> beizutreten!";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Göttliche Fügung",
                        $text,
                        0, 0,
                        "Er steht ab sofort in deiner Garnison zur Verfügung.",
                        "success"
                    ) .
                    "</div>";

            case "world_event_tier_unlocked":
                $dmg = fnum((int)($data["total_damage"] ?? 0), true);
                $coins = (int)($data["coins"] ?? 0);
                $gold = (int)($data["gold"] ?? 0);

                $rewards = [];
                if ($coins > 0) $rewards[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " <b>+$coins Münzen</b>";
                if ($gold > 0) $rewards[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " <b>+" . fnum($gold) . "</b>";

                $rewards_html = !empty($rewards) ? implode(" und ", $rewards) : implode(" und ", $data["rewards_text"] ?? []);

                $text = "Dein Angriff auf den Welten-Boss hat eine neue Belohnungsstufe freigeschaltet:<br><br>" .
                    "<div style='text-align: center;'>$rewards_html</div><br>" .
                    "Die Schätze wurden deinen Lagern gutgeschrieben.";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Event: Neue Stufe erreicht!",
                        $text,
                        0, 0,
                        "Gesamtschaden: $dmg",
                        "success"
                    ) . "</div>";

            case "world_event_spawned":
                $type = $data["event_type"] ?? "BOSS_HP";
                $m_name = e($data["monster_name"] ?? "Monstrum");
                $m_icon = e($data["monster_icon"] ?? "icon_lich");

                $text = "<div style='margin: 10px; text-align: center;'><img src='images/icons/$m_icon.png' alt=''></div>";
                $text .= ($type === "BOSS_HP")
                    ? "Ein gewaltiger Boss ist im <b>Auge des Sturms</b> erschienen! Alle Herrscher sind aufgerufen, ihre Truppen zu senden, um die Bestie gemeinsam zu fällen."
                    : "Im <b>Auge des Sturms</b> hat ein neues Event begonnen! Zeige deine Stärke und sichere dir Belohnungen anhand deines persönlich angerichteten Schadens.";
                $text .= " Jeder der mitmacht, erhält Belohnungen! Weitere Infos auf der Event-Seite.";
                $text .= "<div style='margin: 10px; text-align: center;'><button data-on-click='redirect' data-url='events.php'>Zum Event</button></div>";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box("$m_name gesichtet!", $text) .
                    "</div>";

            case "world_event_boss_defeated":
                $m_name = e($data["monster_name"] ?? "Monster");
                $m_icon = e($data["monster_icon"] ?? "icon_lich");

                $text = "<div style='margin: 10px; text-align: center;'><img src='images/icons/$m_icon.png' alt=''></div>";
                $text .= "Ein gewaltiges Jubeln bricht in allen Reichen aus! Die Bestie <b>$m_name</b> wurde besiegt.<br><br>";
                $text .= "Alle Teilnehmer werden nach Ablauf des Zeitlimits für ihren Mut belohnt.";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box("DAS MONSTER IST GEFALLEN!", $text, 0, 0, "", "success") .
                    "</div>";

            case "world_event_hp_reward":
                $kname = e($data["kingdom_name"] ?? "Königreich");
                $units_html = "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";
                foreach (($data["units"] ?? []) as $u) {
                    $units_html .= BattleReportRenderer::render_unit_card($u, 0, 0, "", true);
                }
                $units_html .= "</div>";

                $text = "Deine Truppen aus <b>$kname</b> waren am Sieg beteiligt! Du erhältst folgende Belohnungen:" . $units_html;

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Event-Abschluss",
                        $text,
                        0, 0,
                        "",
                        "success",
                        $data["resources"] ?? []
                    ) .
                    "</div>";

            case "world_event_dmg_reward":
                $dmg = fnum((int)($data["total_damage"] ?? 0), true);
                $sub = ((int)($data["total_damage"] ?? 0) >= WORLD_EVENT_REWARD_MIN_TRESHOLD)
                    ? "Alle Belohnungen wurden deinen Lagern und deiner Schatzkammer bereits während deiner Angriffe gutgeschrieben."
                    : "Du hast die Mindest-Schadensschwelle für Belohnungen leider nicht erreicht.";

                $text = "Das Schadens-Event im <b>Auge des Sturms</b> ist beendet!<br>Für deinen Gesamtschaden von <b>$dmg</b> hast du folgende Gesamt-Prämien erzielt:";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Event-Abschluss",
                        $text,
                        0, 0,
                        $sub,
                        "neutral",
                        $data["loot"] ?? []
                    ) .
                    "</div>";

            case "world_event_boss_escaped":
                $m_name = e($data["monster_name"] ?? "Die Bestie");
                $text = "Das Zeitlimit ist abgelaufen und das Monster <b>$m_name</b> konnte in die Schatten entkommen!";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box(
                        "Event beendet",
                        $text,
                        0, 0,
                        "Ohne den finalen Schlag konnten keine Schätze geborgen werden. Bereitet euch besser auf das nächste Mal vor!",
                        "error"
                    ) .
                    "</div>";

            case "attack_aborted_guild":
                $opp_name = e($data["opponent_name"] ?? "Ein Mitspieler");
                $kname = e($data["target_kname"] ?? "Königreich");
                $tx = (int)($data["target_x"] ?? 0);
                $ty = (int)($data["target_y"] ?? 0);
                $c_link = "<a href='map.php?startx=$tx&starty=$ty' data-on-click='mapJump' data-x='$tx' data-y='$ty'>$tx:$ty</a>";

                $title = "Angriff abgebrochen: Gilden-Bündnis";
                $main = "Deine Truppen erreichten <b>$kname</b> ($c_link). Da <b>$opp_name</b> in der Zwischenzeit deiner Gilde beigetreten ist, wurde der Angriff abgebrochen.";
                $sub = "Deine Soldaten treten kampflos den Heimweg an.";

                return "<div class='battle-report'>" .
                    BattleReportRenderer::render_outcome_box($title, $main, 0, 0, $sub) .
                    "</div>";

            default:
                return $data["text"] ?? $data["message"] ?? "Keine Nachricht.";
        }
    }

    public static function parse_chat_coordinates(string $text): string
    {
        $coord_num = '(?:100|[1-9][0-9]|0?[1-9])';
        $pattern = '/(<[^>]+>)|\b(' . $coord_num . ')\s*[:|]\s*(' . $coord_num . ')\b(?!\s*(?:uhr|sek|min))/iu';

        return preg_replace_callback($pattern, function ($matches) {
            if (!empty($matches[1])) {
                return $matches[1];
            }

            $x = (int)$matches[2];
            $y = (int)$matches[3];

            if ($x >= 1 && $x <= MAX_X && $y >= 1 && $y <= MAX_Y) {
                $display_text = $matches[2] . ':' . $matches[3];
                $url = "ajax/share_coords_preview.php?x=$x&y=$y";
                return "<a href='map.php?startx=$x&starty=$y' data-on-click='openOverlay' data-url='$url' data-title='Koordinaten $display_text' class='chat-coord-link'>$display_text</a>";
            }

            return $matches[0];
        }, $text);
    }

    public static function format_chat_message(string $raw_text, ?bool $use_filter = null): string
    {
        $filter = ($use_filter !== null) ? $use_filter : (!empty($_SESSION["chat_filter"]));

        $text = e($raw_text);
        $text = parse_chat_quotes($text);
        $text = nl2br($text);

        if ($filter) {
            $text = filter_chat_message($text);
        }

        $text = self::parse_chat_coordinates($text);

        return wrap_emojis($text);
    }
}
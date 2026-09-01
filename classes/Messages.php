<?php

class Messages
{
    private object $mysqli;
    private User $user;
    private string $view = "";

    public function __construct(object $db_conn, User $user)
    {
        $this->mysqli = $db_conn;
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
    }

    public function get_server_history_paged(?int $oldest_id = null, string $category = "Alle", int $limit = 20): array
    {
        $uid = $this->user->get_user_id();
        $params = [$uid];
        $category_sql = "";

        if ($category !== "Alle") {
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

        // Wenn keine oldest_id da ist, laden wir die absolut neuesten 20
        if ($oldest_id === null) {
            $query = "SELECT * FROM messages 
                  WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
                  AND deleted = 0 
                  ORDER BY id DESC LIMIT ?";
            $result = $this->mysqli->execute_query($query, [$sender_id, $uid, $uid, $sender_id, $limit]);
        } else {
            // Lade Nachrichten, die älter sind als die aktuelle oldest_id
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
                $image_path = $chat_partner->get_avatar();

                $this->view .= "
                    <tr class='tr-hover$old_conversation'>
                        <td class='td-cursor' 
                            data-on-click='redirect' 
                            data-url='messages.php?action=read&s=" . e($row["participant_id"]) . "'>
                            <div class='image-and-user'>
                                <img class='user-image' src='$image_path' alt='Nutzerbild'>
                                <span>$sender_name</span>
                                " . ($num_unread_messages > 0
                        ? "<span class='msg-badge'>{$this->show_messages_indicator($num_unread_messages)}</span>"
                        : ""
                    ) . "
                            </div>
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
            return "Du hast keine Servernachrichten!";
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

            $display_message = e($row["message"]);
            $display_message = parse_chat_quotes($display_message);
            $display_message = nl2br($display_message);
            if ($_SESSION["chat_filter"]) {
                $display_message = filter_chat_message($display_message);
            }
            $display_message = wrap_emojis($display_message);

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
                            " . $display_message . "
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
                            " . $display_message . "
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

                if (!$is_me && $row["id"] > $last_read_id && !$unread_line_shown) {
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

                $msg = e($row["message"]);
                $msg = parse_chat_quotes($msg);
                $msg = nl2br($msg);
                if ($_SESSION["chat_filter"]) {
                    $msg = filter_chat_message($msg);
                }
                $msg = wrap_emojis($msg);

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
                    $msg
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

            if (!$is_me && $row["id"] > $last_read_id && !$unread_line_shown) {
                $html .= "<div id='new-message-line' class='error'>Neue Gilden-Nachrichten</div>";
                $unread_line_shown = true;
            }

            $class = $is_me ? "receiver-bubble" : "sender-bubble";

            $msg = e($row["message"]);
            $msg = parse_chat_quotes($msg);
            $msg = nl2br($msg);
            if ($_SESSION["chat_filter"]) $msg = filter_chat_message($msg);
            $display_msg = wrap_emojis($msg);

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
                $display_msg
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

    private function render_message_template(array $data): string
    {
        $type = $data["type"] ?? "text";

        switch ($type) {
            case "battle":
                $html = "<div class='battle-report'>";
                $html .= BattleReportRenderer::render_vs_grid($data["atk_units"], $data["def_units"], $data["atk_label"], $data["def_label"]);
                $html .= BattleReportRenderer::render_outcome_box(
                    $data["title"],
                    $data["main_text"],
                    $data["wall_before"] ?? 0,
                    $data["wall_after"] ?? 0,
                    $data["sub_text"] ?? "",
                    $data["result_type"] ?? "neutral",
                    $data["loot"] ?? []
                );
                $html .= "</div>";
                return $html;

            case "trade_received":
                $html = "<div class='battle-report'>";
                $html .= BattleReportRenderer::render_outcome_box(
                    "Warenlieferung",
                    $data["text"],
                    0, 0, "", "neutral", $data["resources"]
                );
                $html .= "</div>";
                return $html;

            case "plunder":
                $html = "<div class='battle-report'>";

                $coords = "(<a href='#' data-on-click='mapJump' data-x='{$data['target_x']}' data-y='{$data['target_y']}'>{$data['target_x']}:{$data['target_y']}</a>)";

                $main_text = "Unsere Räuber haben ein verlassenes Lager $coords überfallen und Ressourcen erbeutet:";

                $main_text .= BattleReportRenderer::render_resource_list($data["loot"]);

                if ($data["is_empty"]) {
                    $main_text .= "<br><b>Das Lager wurde komplett geleert.</b>";
                }

                $main_text .= "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; justify-content: center;'>";
                $main_text .= BattleReportRenderer::render_unit_card("Räuber", $data["raiders_sent"], $data["raiders_lost"], "icon_robber");
                $main_text .= "</div>";

                if ($data["raiders_lost"] > 0) {
                    $main_text .= "<div style='margin-top: 10px; color: #ff4d4d; font-size: 0.9em;'>";
                    $main_text .= "⚠️ <b>Verluste:</b> {$data['raiders_lost']} Räuber wurden bei Kämpfen im Hinterhalt verletzt oder getötet.";
                    $main_text .= "</div>";
                }

                $sub_text = ($data["raiders_sent"] > $data["raiders_lost"])
                    ? "Die Überlebenden treten mit der Beute den Rückweg an."
                    : "Niemand kehrte lebend zurück, die Beute ging verloren!";

                $html .= BattleReportRenderer::render_outcome_box(
                    "Erfolgreiche Plünderung",
                    $main_text,
                    0, 0,
                    $sub_text,
                    "normal"
                );

                $html .= "</div>";
                return $html;

            default:
                return $data["text"] ?? "Keine Nachricht.";
        }
    }
}
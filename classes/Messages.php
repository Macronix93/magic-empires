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
        $limit = SHOW_MESSAGES_LIMIT;
        $uid = $this->user->get_user_id();

        $query = "SELECT * FROM server_messages WHERE receiverid = ? ORDER BY id DESC LIMIT ?";
        $result = $this->mysqli->execute_query($query, [$uid, $limit + 1]);

        if ($result->num_rows == 0) {
            return "Du hast keine Servernachrichten!";
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $has_more = (count($rows) > $limit);

        if ($has_more) {
            array_pop($rows);
        }

        $html = "";
        $first_unread_displayed = false;

        foreach ($rows as $row) {
            if ($row["hasread"] == 0 && !$first_unread_displayed) {
                $first_unread_displayed = true;

                $html .= "<div id='new-message-line' class='error'>Neue Nachrichten seit " . date("d.m.Y H:i", $row["date"]) . "</div>";
            }

            $html .= "<div class='server-bubble' data-category='{$row["category"]}' id='msg-{$row["id"]}'>
                            <div class='message-border'>
                                Am " . date("d.m.Y H:i:s", $row["date"]) . "
                                <img src='images/icons/icon_delete.png' 
                                 class='ressource-icons' 
                                 data-on-click='deleteServerMsg' 
                                 data-id='{$row["id"]}' 
                                 style='cursor: pointer;' alt=''>
                            </div>
                            {$row["message"]}
                        </div>";

            if ($row["hasread"] == 0) {
                $this->mysqli->execute_query("UPDATE server_messages SET hasread = 1 WHERE id = ?", [$row["id"]]);
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

    public function delete_marked_messages(int $sender_id): void
    {
        $this->mysqli->execute_query("DELETE FROM messages WHERE ((senderid = ? AND receiverid = ?) OR (receiverid = ? AND senderid = ?)) AND deleted = 1",
            [$this->user->get_user_id(), $sender_id, $sender_id, $this->user->get_user_id()]
        );
    }

    public function show_messages_with_chatpartner(int $sender_id, string $chat_partner): string
    {
        $limit = SHOW_MESSAGES_LIMIT;
        $result = $this->get_chat_history_paged($sender_id, null, $limit + 1);

        $has_more = false;
        if (count($result) > $limit) {
            $has_more = true;
            array_shift($result);
        }

        $tab_token = time() . "_" . rand(1000, 9999);
        $_SESSION["active_chat_token"] = $tab_token;

        if (!$has_more) {
            $this->view .= "<style>#load-older-btn { display: none !important; }</style>";
        }
        $this->view .= "<div id='chat-config' 
                             data-has-more='" . ($has_more ? 'true' : 'false') . "' 
                             data-token='$tab_token'></div>";
        $this->view .= "<div id='chat-tab-token' data-token='$tab_token' style='display:none;'></div>";

        $chat_partner_image = "";
        $my_chat_image = $this->user->get_avatar();
        $partner = new User($sender_id, $chat_partner);
        $first_sender_message_displayed = false;
        $unread_message_ids = [];

        if (empty($result)) {
            $this->view .= "<div id='chat-empty-placeholder' class='info-box' style='margin: 0; justify-content: center;'>Schreibe eine Nachricht, um den Chat zu beginnen.</div>";
            return $this->view;
        }

        $is_admin = $this->user->is_admin();

        foreach ($result as $row) {
            $message_id = $row["id"];
            $message = $row["message"];
            $display_message = ($_SESSION["chat_filter"]) ? filter_chat_message($message) : $message;
            $display_message = wrap_emojis($display_message);
            $has_read = $row["hasread"];
            $date = $row["date"];
            $is_me = ($row["senderid"] == $this->user->get_user_id());
            $delete_icon = ($is_me || $is_admin) ? "<img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' data-on-click='deleteChatMsg' data-id='" . e($row["id"]) . "' style='cursor: pointer;'>" : "";

            if ($row["senderid"] == $sender_id) {
                if (empty($chat_partner_image)) {
                    $chat_partner_image = $partner->get_avatar() ?? "";
                }

                if (!$has_read && !$first_sender_message_displayed) {
                    $first_sender_message_displayed = true;
                    $this->view .= "<div id='new-message-line' class='error'>Neue Nachrichten seit " . date("d.m.Y \u\m H:i:s", $date) . "</div>";
                }

                $this->view .= "<div class='sender-bubble' id='msg-" . $message_id . "'>
                            <div class='message-border'>
                                <span class='msg-header-left'>
                                    <img class='user-image' src='$chat_partner_image' alt=''> 
                                    <span>" . $row["sender"] . " am " . date("d.m.Y \u\m H:i:s", $date) . "</span>
                                </span>
                                $delete_icon
                            </div>
                            " . $display_message . "
                        </div>";
            } else {
                $this->view .= "<div class='receiver-bubble' id='msg-" . $message_id . "'>
                            <div class='message-border'>
                                <span class='msg-header-left'>
                                    <img class='user-image' src='$my_chat_image' alt=''> 
                                    <span>Du am " . date("d.m.Y \u\m H:i:s", $date) . "</span>
                                </span>
                                $delete_icon
                            </div>
                            " . $display_message . "
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

        return $this->view;
    }

    public function show_world_chat(): string
    {
        $limit = MAX_WORLD_CHAT_MESSAGES_SHOWN;
        $result = $this->mysqli->execute_query("SELECT * FROM world_chat WHERE deleted = 0 ORDER BY id DESC LIMIT ?", [$limit]);
        $rows = array_reverse($result->fetch_all(MYSQLI_ASSOC));

        $html = "<div id='messages-section' data-chat-type='world'>";

        if (empty($rows)) {
            $html .= "
            <div id='chat-empty-placeholder' class='info-box' style='margin: 0; justify-content: center;'>
                Im Welt-Chat wurde noch nichts geschrieben. Sei der Erste!
            </div>";
        } else {
            foreach ($rows as $row) {
                $is_me = ($row["userid"] == $this->user->get_user_id());
                $class = $is_me ? "receiver-bubble" : "sender-bubble";

                $is_admin = $this->user->is_admin();
                $delete_icon = ($is_me || $is_admin) ? "<img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' 
                                                            data-on-click='deleteWorldChatMsg' data-id='{$row["id"]}' style='cursor: pointer;'>" : "";

                $use_filter = ($_SESSION["chat_filter"] ?? 1);
                $msg = ($use_filter == 1) ? filter_chat_message($row["message"]) : $row["message"];
                $msg = wrap_emojis($msg);

                $u = new User($row["userid"], $row["username"]);
                $avatar = $u->get_avatar();

                $html .= "<div class='$class' id='world-msg-{$row["id"]}'>
                    <div class='message-border'>
                        <span class='msg-header-left'>
                            <img class='user-image' src='$avatar' alt=''> 
                            <span>" . ($is_me ? 'Du' : $row["username"]) . " am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "</span>
                        </span>
                        $delete_icon
                    </div>
                    $msg
                  </div>";
            }
        }
        $html .= "</div>";
        $html .= "
                    <div id='newmessage-section'>
                        <form id='world-chat-form'>
                            <textarea id='message-input' 
                                      name='text' 
                                      rows='3' 
                                      maxlength='" . MAX_MESSAGE_LENGTH . "' 
                                      style='resize: vertical; margin-right: 10px;'></textarea>
                            <div class='emoji-picker-container'>
                                <div id='emoji-menu' class='emoji-menu'>";

        foreach (get_chat_emojis() as $emoji) {
            $html .= "<span data-on-click='pickEmoji'>$emoji</span>";
        }

        $html .= "      </div>
                    <button type='button' class='emoji-trigger' data-on-click='toggleEmojis' title='Emoji einfügen'>🙂</button>
                </div>
                
                <input type='button' 
                       data-on-click='sendWorldMessage' 
                       value='Absenden\n[ENTER]' />
            </form>
        </div>";

        return $html;
    }
}
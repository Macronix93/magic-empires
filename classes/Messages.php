<?php

class Messages
{
    private object $mysqli;
    private User $user;
    private string $view = "";
    private int $rows_per_page = 10;

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

    public function get_server_history_paged(int $oldest_id = null, string $category = "Alle", int $limit = 20): array
    {
        $uid = $this->user->get_user_id();
        $params = [$uid];
        $category_sql = "";

        if ($category !== "Alle") {
            $category_sql = " AND category = ? ";
            $params[] = $category;
        }

        if ($oldest_id === null) {
            $query = "SELECT * FROM servermessages WHERE receiverid = ? $category_sql ORDER BY id DESC LIMIT ?";
        } else {
            $query = "SELECT * FROM servermessages WHERE receiverid = ? $category_sql AND id < ? ORDER BY id DESC LIMIT ?";
            $params[] = $oldest_id;
        }

        $params[] = $limit;
        $result = $this->mysqli->execute_query($query, $params);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function get_chat_history_paged(int $sender_id, int $oldest_id = null, int $limit = 20): array
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
                        <button class='msg-back-button' onclick='window.location.href=\"messages.php\";'>
                            Zurück
                        </button>
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
                                <td class='td-center td-gradient' colspan='2'>
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
                        <td class='td-cursor' onclick='window.location.href=\"messages.php?action=read&s={$row["participant_id"]}\";'>
                            <div class='image-and-user'>
                                <img class='user-image' src='$image_path' alt='Nutzerbild'>
                                <span>$sender_name</span>
                                " . ($num_unread_messages > 0
                        ? "<span class='msg-badge'>{$this->show_messages_indicator($num_unread_messages)}</span>"
                        : ""
                    ) . "
                            </div>
                        </td>
                        <td class='td-cursor' onclick='window.location.href=\"messages.php?action=read&s={$row["participant_id"]}\";'>
                            am " . date("d.m.Y \u\m H:i:s", $latest_timestamp) . "
                        </td>
                        <td class='td-center'>
                            <img src='images/icons/icon_delete.png' 
                            class='ressource-icons' 
                            alt='Löschen' 
                            onclick='conversationDeletionDialog(\"{$row["participant_id"]}\", \"$sender_name\")' 
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

        return ($number > 5) ? "5+" : (string)$number;
    }

    function show_server_inbox(): string
    {
        // Get all server messages for the user
        $query = "SELECT * FROM servermessages WHERE receiverid = ? ORDER BY date DESC";
        $result = $this->mysqli->execute_query($query, [$this->user->get_user_id()]);

        if ($result->num_rows == 0) {
            $this->view .= "Du hast keine Servernachrichten!";
        } else {
            $first_sender_msg_displayed = false;

            foreach ($result as $row) {
                if ($row["hasread"] == 0 && !$first_sender_msg_displayed) {
                    $first_sender_msg_displayed = true;

                    $this->view .= "
                                <div id='new-message-line' class='error'>
                                    Neue Nachrichten seit dem " . date("d.m.Y \u\m H:i:s", $row["date"]) . "
                                </div>
                    ";
                }

                $message_id = $row["id"];

                $this->view .= "
                            <div class='server-bubble' data-category='{$row["category"]}' id='msg-$message_id'>
                                <div class='message-border'>
                                    Am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "
                                    <img src='images/icons/icon_delete.png' 
                                    class='ressource-icons' 
                                    alt='Löschen' 
                                    onclick='deleteServerMessage(\"$message_id\")' 
                                    style='cursor: pointer;'>
                                </div>
                                " . $row["message"] . "
                            </div>
                ";

                $this->mysqli->execute_query("UPDATE servermessages SET hasread = 1 WHERE id = ?", [$row["id"]]);
            }
        }

        return $this->view;
    }

    public function get_unread_private_count(): int
    {
        $result = $this->mysqli->execute_query("SELECT COUNT(*) AS unreadcount FROM messages WHERE receiverid = ? AND hasread = 0 AND deleted = 0",
            [$this->user->get_user_id()]);
        return $result->fetch_assoc()["unreadcount"];
    }

    public function get_unread_server_count(): int
    {
        $result = $this->mysqli->execute_query("SELECT COUNT(*) AS unreadcount FROM servermessages WHERE receiverid = ? AND hasread = 0",
            [$this->user->get_user_id()]);
        return $result->fetch_assoc()["unreadcount"];
    }

    public function delete_marked_messages(int $sender_id): void
    {
        $this->mysqli->execute_query("DELETE FROM messages WHERE senderid = ? AND receiverid = ? AND deleted = 1",
            [$sender_id, $this->user->get_user_id()]
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

        if (!$has_more) {
            $this->view .= "<style>#load-older-btn { display: none !important; }</style>";
            $this->view .= "<script>canLoadMore = false;</script>";
        } else {
            $this->view .= "<script>canLoadMore = true;</script>";
        }

        $chat_partner_image = "";
        $my_chat_image = $this->user->get_avatar();
        $partner = new User($sender_id, $chat_partner);
        $first_sender_message_displayed = false;
        $unread_message_ids = [];

        if (empty($result)) {
            return "<div class='info-box'>Schreibe eine Nachricht, um den Chat zu beginnen.</div>";
        }

        foreach ($result as $row) {
            $message_id = $row["id"];
            $message = $row["message"];
            $has_read = $row["hasread"];
            $date = $row["date"];

            if ($row["senderid"] == $sender_id) {
                if (empty($chat_partner_image)) {
                    $chat_partner_image = $partner->get_avatar() ?? "";
                }

                if (!$has_read && !$first_sender_message_displayed) {
                    $first_sender_message_displayed = true;
                    $this->view .= "<div id='new-message-line' class='error'>Neue Nachrichten seit " . date("d.m.Y H:i", $date) . "</div>";
                }

                $this->view .= "<div class='sender-bubble' id='msg-" . $message_id . "'>
                            <div class='image-and-user message-border'>
                                <img class='user-image' src='$chat_partner_image' alt=''> " . $row["sender"] . " am " . date("d.m.Y H:i", $date) . "
                            </div>
                            " . $message . "
                        </div>";
            } else {
                $this->view .= "<div class='receiver-bubble' id='msg-" . $message_id . "'>
                            <div class='image-and-user message-border'>
                                <img class='user-image' src='$my_chat_image' alt=''> Du am " . date("d.m.Y H:i", $date) . "
                                <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' onclick='deleteChatMessage(\"$message_id\")' style='cursor: pointer;'>
                            </div>
                            " . $message . "
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
}
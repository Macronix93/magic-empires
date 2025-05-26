<?php

class Messages
{
    private object $mysqli;
    private User $user;
    private string $view = "";

    public function __construct(object $db_conn)
    {
        $this->mysqli = $db_conn;
        $this->user = User::get_instance();
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
                                <td class='td-center td-gradient' style='word-break: break-word'>
                                    <b>Chatpartner</b>
                                </td>
                                <td class='td-center td-gradient' style='word-break: break-word'>
                                    <b>Letzte Nachricht</b>
                                </td>
                                <td class='td-center td-gradient' style='word-break: break-word'>
                                    <b>Aktion</b>
                                </td>
                            </tr>
            ";

            foreach ($result as $row) {
                $num_unread_messages = $row["unreadcount"];
                $sender_name = $row["sendername"];
                $latest_timestamp = $row["latest_message_date"];
                $old_conversation = time() - $latest_timestamp > CONV_INACTIVITY_TIME ? " tr-inactive" : "";
                $image_path = $this->user->get_avatar($sender_name);

                $this->view .= "
                    <tr class='tr-hover$old_conversation'>
                        <td class='td-cursor' onclick='window.location.href=\"messages.php?action=read&s={$row["participant_id"]}\";'>
                            <div class='image-and-user'>
                                <img class='user-image' src='$image_path' alt='Nutzerbild'>$sender_name " . $this->show_messages_indicator($num_unread_messages) . "
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
        return ($number == 0) ? "" : "<img src='images/icons/icon_" . ($number > 5 ? "more_than_5" : $number) . ".png' class='menu-icons' style='width: 16px; height: 16px;' alt='' />";
    }

    function show_server_inbox(): string
    {
        // Get all server messages for the user
        $query = "SELECT * FROM servermessages WHERE receiverid = ? ORDER BY date";
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
        $query = "SELECT * FROM messages WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) AND deleted = 0";
        $result = $this->mysqli->execute_query($query,
            [$sender_id, $this->user->get_user_id(), $this->user->get_user_id(), $sender_id]);
        $chat_partner_image = "";
        $my_chat_image = $this->user->get_avatar($this->user->get_user_name());
        $first_sender_message_displayed = false;

        foreach ($result as $row) {
            $message_id = $row["id"];
            $message = $row["message"];
            $has_read = $row["hasread"];
            $date = $row["date"];

            // The other side has written
            if ($row["senderid"] == $sender_id) {
                if (empty($chat_partner_image)) {
                    $chat_partner_image = $this->user->get_avatar($chat_partner) ?? "";
                }

                if (!$has_read && !$first_sender_message_displayed) {
                    $first_sender_message_displayed = true;

                    $this->view .= "<div id='new-message-line' class='error'>
                                        Neue Nachrichten seit dem " . date("d.m.Y \u\m H:i:s", $date) . "
                                    </div>";
                }

                $this->view .= "<div class='sender-bubble' id='msg-" . $message_id . "'>
                                    <div class='image-and-user message-border'>
                                        <img class='user-image' src='$chat_partner_image' alt='Nutzerbild'> " . $row["sender"] . " am " . date("d.m.Y \u\m H:i:s", $date) . "
                                    </div>
                                    " . $message . "
                                </div>
                ";
            } else {
                // You have written
                $this->view .= "<div class='receiver-bubble' id='msg-" . $message_id . "'>
                                    <div class='image-and-user message-border'>
                                        <img class='user-image' src='$my_chat_image' alt='Nutzerbild'> Du am " . date("d.m.Y \u\m H:i:s", $date) . "
                                        <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' onclick='deleteChatMessage(\"$message_id\")' style='cursor: pointer;'>
                                    </div>
                                    " . $message . "
                                </div>
                ";
            }

            if (!$has_read && $row["receiverid"] == $this->user->get_user_id()) {
                $this->mysqli->execute_query("UPDATE messages SET hasread = 1 WHERE id = ?", [$message_id]);
            }
        }

        return $this->view;
    }
}
<?php
require_once("includes/core.php");

check_user_login($user);

function show_private_inbox(): string
{
    $db = Database::get_instance();
    $db_instance = $db->get_connection();
    $user = User::get_instance();
    $view = "";

    // Get all conversations for the user
    $query = "
                SELECT participant, 
                    MAX(date) AS latest_message_date
                FROM (
                    SELECT senderid AS participant, date
                    FROM messages
                        WHERE receiverid = ?
                        UNION
                        SELECT receiverid AS participant, date
                        FROM messages
                        WHERE senderid = ?
                        AND deleted = 0
                    ) AS combined
                GROUP BY participant
                ORDER BY latest_message_date DESC
    ";
    $result = $db_instance->execute_query($query, [$user->get_user_id(), $user->get_user_id()]);

    $view .= '<div style="display: flex; margin: 10px 0; align-items: center;"><button style="min-width: 8%;" onclick="window.location.href=\'messages.php\';">Zurück</button></div>';

    if ($result->num_rows > 0) {
        $view .= '<table class="table">
         <tr>
             <td class="td-center td-gradient" style="word-break: break-word">
                 <b>Chatpartner</b>
             </td>
             <td class="td-center td-gradient" style="word-break: break-word">
                 <b>Letzte Nachricht</b>
             </td>
             <td class="td-center td-gradient" style="word-break: break-word">
                 <b>Aktion</b>
             </td>
         </tr>';

        $query = "
                    SELECT 
                        u.username AS sendername,
                        COUNT(CASE WHEN m.hasread = 0 AND m.deleted = 0 THEN 1 END) AS unreadcount
                    FROM users u
                    LEFT JOIN messages m
                        ON u.id = m.senderid
                        AND m.receiverid = ?
                    WHERE u.id = ?
        ";

        foreach ($result as $row) {
            $result_2 = $db_instance->execute_query($query, [$user->get_user_id(), $row["participant"]]);
            $row_2 = $result_2->fetch_assoc();
            $num_unread_messages = $row_2["unreadcount"];
            $sender_name = $row_2["sendername"];
            $old_conversation = time() - $row["latest_message_date"] > CONV_INACTIVITY_TIME ? " tr-inactive" : "";
            $image_path = $user->get_avatar($sender_name);

            $view .= "<tr class='tr-hover$old_conversation'>
                <td class='td-cursor' onclick='window.location.href=\"messages.php?action=read&s={$row["participant"]}\";'><div class='image-and-user'><img class='user-image' src='$image_path' alt='Nutzerbild'>$sender_name " . show_messages_indicator($num_unread_messages) . "</div></td>
                <td class='td-cursor' onclick='window.location.href=\"messages.php?action=read&s={$row["participant"]}\";'>am " . date("d.m.Y \u\m H:i:s", $row["latest_message_date"]) . "</td>
                <td class='td-center'><img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' onclick='conversationDeletionDialog(\"{$row["participant"]}\", \"$sender_name\")' style='cursor: pointer;'></td>
            </tr>";
        }

        $view .= "</table>";
    } else {
        $view = "Du hast keine Konversationen!";
    }

    $view .= "<br>
    <form action='messages.php' method='GET'>
        <input type='hidden' name='action' value='new'>
        <input type='submit' value='Neue Konversation' style='margin-top: 5px;'>
    </form>";

    return $view;
}

function show_server_inbox(): string
{
    $db = Database::get_instance();
    $db_instance = $db->get_connection();
    $user = User::get_instance();
    $view = "";

    // Get all server messages for the user
    $query = "SELECT * FROM servermessages WHERE receiverid = ?";
    $result = $db_instance->execute_query($query, [$user->get_user_id()]);

    $view .= '<div style="display: flex; margin: 10px 0; align-items: center;">
                <button style="min-width: 8%;" onclick="window.location.href=\'messages.php\';">Zurück</button>
              </div>';

    // Category Tabs
    $view .= '<div class="tab">
                <div class="tablinks active" onclick="filterServerMessages(this)">Alle</div>
                <div class="tablinks" onclick="filterServerMessages(this)">Krieg</div>
                <div class="tablinks" onclick="filterServerMessages(this)">Handel</div>
              </div>';

    if ($result->num_rows > 0) {
        $firstSenderMessageDisplayed = false;
        $view .= '<div id="messages-section">';

        foreach ($result as $row) {
            if ($row["hasread"] == 0 && !$firstSenderMessageDisplayed) {
                $view .= "<div id='new-message-line' class='error'>Neue Nachrichten seit dem " . date("d.m.Y \u\m H:i:s", $row["date"]) . "</div>";
                $firstSenderMessageDisplayed = true;
            }

            $message_id = $row["id"];

            $view .= "<div class='server-bubble' data-category='{$row["category"]}' id='msg-$message_id'>
                        <div class='message-border'>
                            Am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "
                            <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' onclick='deleteServerMessage(\"$message_id\")' style='cursor: pointer;'>
                        </div>
                        " . $row["message"] . "
                      </div>";

            $db_instance->execute_query("UPDATE servermessages SET hasread = 1 WHERE id = ?", [$row["id"]]);
        }

        $view .= '</div>';
        $view .= "<script>
                    document.addEventListener('DOMContentLoaded', function () {
                        scrollDown();
                    });
                  </script>";
    } else {
        $view .= "Du hast keine Servernachrichten!";
    }

    return $view;
}


function get_unread_server_messages(): int
{
    $user = User::get_instance();
    $result = Database::get_instance()->get_connection()->execute_query("SELECT COUNT(*) AS unreadcount FROM servermessages WHERE receiverid = ? AND hasread = 0",
        [$user->get_user_id()]);
    return $result->fetch_assoc()["unreadcount"];
}

function get_unread_private_messages(): int
{
    $user = User::get_instance();
    $result = Database::get_instance()->get_connection()->execute_query("SELECT COUNT(*) AS unreadcount FROM messages WHERE receiverid = ? AND hasread = 0 AND deleted = 0",
        [$user->get_user_id()]);
    return $result->fetch_assoc()["unreadcount"];
}

// Starting a new conversation (or insert message in existing conversation)
if (isset($_POST["sendpm"])) {
    $receiver = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\p{Mn}/u'], ['', ' ', ''], $_POST["receiver"]);
    $_SESSION["msgreceiver"] = $receiver;
    $text = nl2br(htmlspecialchars($_POST["text"], ENT_QUOTES, "UTF-8"));
    $error = get_error($text, $receiver);

    if ($error == null) {
        // Prevent HTML Injection
        $user_id = $user->get_user_id();
        $receiver = htmlspecialchars($receiver, ENT_QUOTES, "UTF-8");
        $text_to_output = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\s+/u', '/\p{Mn}/u'], ['', ' ', ' ', ''], $text);
        $current_time = time();

        // Query to check if the user exists
        $query = "SELECT COUNT(*) AS userexists FROM users WHERE username = ?";
        $result = $db_instance->execute_query($query, [$receiver]);
        $row = $result->fetch_assoc();
        $user_exists = $row["userexists"];

        if ($user_exists == 0) {
            $error = "Dieser Spieler existiert nicht!";
        } else {
            $message_timeframe_end = $_SESSION["message_timeframe_end"] ?? 0;
            $message_count = $_SESSION["message_count"] ?? 0;

            if ($current_time > $message_timeframe_end) {
                $_SESSION["message_count"] = 0;
                $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;
                $message_count = 0;
            }

            // Check if we can send a new message
            if ($message_count >= MAX_MESSAGES_RATELIMIT) {
                // Calculate remaining time to wait
                $remaining_time_in_seconds = $message_timeframe_end - $current_time;
                $response["counter"] = $remaining_time_in_seconds;
                $response["error"] = "Du schickst zu viele Nachrichten! Warte bitte: ";
            } else {
                $_SESSION["message_count"] = ++$message_count;
                $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;

                // Get receiver's ID based on receiver name
                $result = $db_instance->execute_query("SELECT id FROM users WHERE username = ?", [$receiver]);
                $receiver_id = $result->fetch_assoc()["id"];

                // Insert message into the database
                $query = "INSERT INTO messages (senderid, sender, receiverid, receiver, date, message) VALUES (?, ?, ?, ?, ?, ?)";
                $db_instance->execute_query($query, [$user->get_user_id(), $user->get_user_name(), $receiver_id, $receiver, $current_time, $text_to_output]);

                change_location("messages.php?action=read&s=$receiver_id");
            }
        }
    }
}

if (isset($_GET["action"])) {
    if ($_GET["action"] == "new") {
        $receiver = isset($_GET["s"]) ? htmlspecialchars($_GET["s"]) : "";
        $receiver_value = isset($_GET["receiver"]) ? htmlspecialchars($_GET["s"]) : (isset($_POST["receiver"]) ? htmlspecialchars($_POST["receiver"]) : '');
        $receiver_text = '<label>
                                <input type="text" name="receiver" maxlength="16" value="' . $receiver_value . '">
                            </label>';
        $message = isset($_POST["text"]) ? htmlspecialchars($_POST["text"]) : "";

        if (isset($_POST["text"]) && $error == null) {
            $view .= show_private_inbox();
        } else {
            $view .= "<form id=\"newmessage\"
                  action=\"messages.php?action=new\"
                  method=\"POST\">
                <table class=\"table\">
                    <tr>
                        <td style=\"width: 25%;\">
                            <b>Empfänger:</b>
                        </td>
                        <td>
                            <label>
                                <input type=\"text\" name=\"receiver\" maxlength=\"16\" value=\"$receiver_value\">
                            </label>
                            <button type=\"button\" onclick=\"openPopup('userlist.php')\">
                                Spielerliste
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td><b>Nachricht:</b></td>
                        <td>
                            <label>
                                <textarea name=\"text\" rows=\"8\"
                                          maxlength=\"" . MAX_MESSAGE_LENGTH . "\"
                                          style=\"resize: vertical;\">$message</textarea>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan=\"2\">
                            <input type=\"submit\" name=\"sendpm\" value=\"Abschicken\">
                        </td>
                    </tr>
                </table>
            </form>";
        }
    } else if ($_GET["action"] == "read") {
        $inbox_header = "Privatnachrichten";
        $current_time = time();
        $message_timeframe_end = $_SESSION["message_timeframe_end"] ?? 0;
        $message_count = $_SESSION["message_count"] ?? 0;

        // Check if the current time is past the message timeframe
        if ($current_time > $message_timeframe_end) {
            $_SESSION["message_count"] = 0;
            $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;
        }

        $sender_id = htmlspecialchars($_GET['s']);

        if ($sender_id != null) {
            $_SESSION["msgreceiver"] = $sender_id;

            // Get chat partner name based on id
            $result = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$sender_id]);
            $chat_partner = $result->fetch_assoc()["username"] ?? "";

            // Check if conversation between the two exists
            $query = "SELECT * FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)";
            $result = $db_instance->execute_query($query, [$sender_id, $user->get_user_id(), $user->get_user_id(), $sender_id]);

            if ($result->num_rows == 0) {
                $error = "Du hast keine Konversation mit diesem Nutzer!";
            } else {
                // Delete messages that are marked for deletion
                $result = $db_instance->execute_query("SELECT id FROM messages WHERE senderid = ? AND receiverid = ? AND deleted = 1", [$sender_id, $user->get_user_id()]);

                foreach ($result as $row) {
                    $db_instance->execute_query("DELETE FROM messages WHERE id = ?", [$row["id"]]);
                }

                // Show messages between chatpartner and user
                $query = "SELECT * FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?) AND deleted = 0";
                $result = $db_instance->execute_query($query, [$sender_id, $user->get_user_id(), $user->get_user_id(), $sender_id]);
                $chat_partner_image = "";
                $my_chat_image = $user->get_avatar($user->get_user_name());
                $firstSenderMessageDisplayed = false;

                $view = '<div style="display: flex; margin: 10px 0; align-items: center;"><button style="min-width: 8%;" onclick="window.location.href=\'messages.php?privmsgs\';">Zurück</button>
                            <h3 style="width: 85%; margin: 0;">Konversation mit 
                                <a href="javascript:void(0);" 
                                 onclick="openPopup(\'userinfo.php?userid=' . $_SESSION['msgreceiver'] . '\');" 
                                 class="popup" 
                                 style="cursor: pointer;">
                                 ' . $chat_partner . '
                                </a>
                            </h3></div>';
                $view .= '<div id="messages-section">';

                foreach ($result as $row) {
                    // The other side has written
                    if ($row["senderid"] == $sender_id) {
                        if (empty($chat_partner_image)) {
                            $chat_partner_image = $user->get_avatar($chat_partner) ?? "";
                        }

                        if ($row["hasread"] == 0 && !$firstSenderMessageDisplayed) {
                            $view .= "<div id='new-message-line' class='error'>Neue Nachrichten seit dem " . date("d.m.Y \u\m H:i:s", $row["date"]) . "</div>";
                            $firstSenderMessageDisplayed = true;
                        }

                        $view .= "<div class='sender-bubble' id='msg-" . $row["id"] . "'>
                            <div class='image-and-user message-border'>
                                <img class='user-image' src='$chat_partner_image' alt='Nutzerbild'> " . $row["sender"] . " am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "
                            </div>
                            " . $row["message"] . "
                        </div>";
                    } else {
                        // You have written
                        $view .= "<div class='receiver-bubble' id='msg-" . $row["id"] . "'>
                            <div class='image-and-user message-border'>
                                <img class='user-image' src='$my_chat_image' alt='Nutzerbild'> Du am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "
                                <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' onclick='deleteChatMessage(\"{$row['id']}\")' style='cursor: pointer;'>
                            </div>
                            " . $row["message"] . "
                        </div>";
                    }

                    if ($row["hasread"] == 0 && $row["receiverid"] == $user->get_user_id()) {
                        $db_instance->execute_query("UPDATE messages SET hasread = 1 WHERE id = ?", [$row["id"]]);
                    }
                }
                $view .= "</div><div id='newmessage-section'>
                                    <form name=\"newmessage\"
                                          id='newmessage'
                                          action=\"messages.php?action=read&s=" . $_SESSION['msgreceiver'] . "\"
                                          method=\"POST\">
                                           <input type=\"hidden\" name=\"receiver\" value=\"" . $_SESSION['msgreceiver'] . "\">
                                            <textarea id=\"message-input\" name=\"text\" rows=\"5\"
                                                      maxlength=\"" . MAX_MESSAGE_LENGTH . "\"
                                                      style=\"resize: vertical; margin-right: 10px;\">" . (isset($_POST["text"]) ? htmlspecialchars($_POST["text"]) : '') . "</textarea>
                                            <input type=\"submit\" name=\"sendpm\" value=\"Absenden\n[ENTER]\"/>
                                    </form>
                                </div>";
                $view .= "<script type='text/javascript'>
                            document.addEventListener('DOMContentLoaded', function () {
                                initializeChat();
                            });
                        </script>";
            }
        } else {
            $error = "Der Spieler existiert nicht!";
        }
    } else if ($_GET["action"] == "delete") {
        /*$sender_id = htmlspecialchars($_GET["s"]);

        if (empty($sender_id)) {
            change_location("messages.php");
        } else {
            $query = "SELECT * FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)";
            $result = $db_instance->execute_query($query, [$sender_id, $user->get_user_id(), $user->get_user_id(), $sender_id]);

            if ($result->num_rows == 0) {
                $error = "Du hast keine Konversation mit diesem Nutzer!";
            } else {
                $query = "DELETE FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)";
                $db_instance->execute_query($query, [$sender_id, $user->get_user_id(), $user->get_user_id(), $sender_id]);

                change_location("messages.php");
            }
        }*/
    } else {
        change_location("messages.php");
    }
} else {
    if (isset($_GET["servermsgs"])) {
        $view = show_server_inbox();

        $inbox_header = "Servernachrichten";
    } else if (isset($_GET["privmsgs"])) {
        $view = show_private_inbox();

        $inbox_header = "Privatnachrichten";
    } else {
        $view .= "<div style='
                display: flex; 
                flex-direction: column; 
                align-items: center;
                text-align: left;
                margin: 0 10px 0 10px;
                padding: 0 10px 0 10px;
                border-radius: 10px;
            '>
            <a href='messages.php?privmsgs' style='
                    background: #2c3440; 
                    padding: 12px 15px; 
                    color: #fff; 
                    border-radius: 5px; 
                    transition: background 0.3s;
                    width: 220px;
                ' 
                onmouseover='this.style.background=\"#4a5565\"' 
                onmouseout='this.style.background=\"#2c3440\"'>
                <span>📩 Privatnachrichten</span>
                <span>" . show_messages_indicator(get_unread_private_messages()) . "</span>
            </a>
            <a href='messages.php?servermsgs' style='
                    background: #2c3440; 
                    padding: 12px 15px; 
                    color: #fff; 
                    border-radius: 5px; 
                    margin-top: 10px;
                    transition: background 0.3s;
                    width: 220px;
                '
                onmouseover='this.style.background=\"#4a5565\"' 
                onmouseout='this.style.background=\"#2c3440\"'>
                <span>🖥️ Servernachrichten</span>
                <span>" . show_messages_indicator(get_unread_server_messages()) . "</span>
            </a>
          </div>";
    }
}

/*
 * HTML Section
 */
$title = "Nachrichten";
$header = $inbox_header ?? "Nachrichten";
$script_files = ["counter", "chat", "userinfo"];
$view = show_error_box($error, false) . $view;

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include('layout/base.php');
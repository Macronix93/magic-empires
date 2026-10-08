<?php
require_once("includes/core.php");

$user->check_user_login();
$messages = new Messages($user);

// Starting a new conversation (or insert message in existing conversation)
if (isset($_POST["sendpm"])) {
    $raw_receiver = trim($_POST["receiver"] ?? "");
    $receiver_name = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\p{Mn}/u'], ['', ' ', ''], $raw_receiver);

    $raw_text = $_POST["text"] ?? "";
    $cleaned_text = preg_replace([
        '/[^\P{Cc}\r\n]+|[\p{Cf}\p{Mn}]+/u',
        '/[ \t]+/u',
        '/^[ \t]+/m',
        '/[ \t]+$/m'
    ], ['', ' ', '', ''], $raw_text);
    $cleaned_text = trim($cleaned_text);

    $text_only = preg_replace('/\[\/?quote.*?]/i', '', $cleaned_text);
    $line_breaks_count = substr_count($cleaned_text, "\n");
    $length = mb_strlen($cleaned_text, "UTF-8");

    if (empty($receiver_name)) {
        $error = "Bitte einen Empfänger angeben!";
    } else if (empty($cleaned_text) || empty(trim($text_only))) {
        $error = "Bitte eine Nachricht eingeben!";
    } else if ($length > MAX_MESSAGE_LENGTH) {
        $error = "Die Nachricht darf maximal " . MAX_MESSAGE_LENGTH . " Zeichen lang sein!";
    } else if ($line_breaks_count > MAX_LINE_BREAK_COUNT) {
        $error = "Dein Text darf maximal " . MAX_LINE_BREAK_COUNT . " Zeilenumbrüche beinhalten!";
    } else {
        $sender_id = $user->get_user_id();
        $sender_name = $user->get_user_name();

        if (is_numeric($receiver_name)) {
            $result = $db_instance->execute_query("SELECT id, username FROM users WHERE id = ?", [(int)$receiver_name]);
        } else {
            $result = $db_instance->execute_query("SELECT id, username FROM users WHERE username = ?", [$receiver_name]);
        }

        if ($result->num_rows == 0) {
            $error = "Dieser Spieler existiert nicht!";
        } else {
            $receiver_data = $result->fetch_assoc();
            $receiver_id = (int)$receiver_data["id"];
            $receiver_username = $receiver_data["username"];

            if ($receiver_id === $sender_id) {
                $error = "Du kannst dir selbst keine Nachricht schicken!";
            } else {
                $current_time = time();
                $message_timeframe_end = $_SESSION["message_timeframe_end"] ?? 0;
                $message_count = $_SESSION["message_count"] ?? 0;

                if ($current_time > $message_timeframe_end) {
                    $_SESSION["message_count"] = 0;
                    $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;
                    $message_count = 0;
                }

                // Rate-Limit Check
                if ($message_count >= MAX_MESSAGES_RATELIMIT) {
                    $remaining_time_in_seconds = $message_timeframe_end - $current_time;
                    $error = "Du schickst zu viele Nachrichten! Warte bitte: " . $remaining_time_in_seconds . " Sek.";
                } else {
                    $_SESSION["message_count"] = ++$message_count;

                    $messages->send_message($sender_id, $sender_name, $receiver_id, $receiver_username, $current_time, $cleaned_text);

                    change_location("messages.php?action=read&s=$receiver_id");
                    exit;
                }
            }
        }
    }
}

if (isset($_GET["action"])) {
    if ($_GET["action"] == "new") {
        $receiver_value = isset($_GET["receiver"]) ? e($_GET["receiver"]) : (isset($_POST["receiver"]) ? e($_POST["receiver"]) : "");
        $message = isset($_POST["text"]) ? e($_POST["text"]) : "";

        if (isset($_POST["text"]) && $error == null) {
            $view = $messages->show_private_inbox();
        } else {
            $view .= "
                <div class='msg-back-button-container'>
                    <button class='msg-back-button' data-on-click='redirect' data-url='messages.php?privmsgs'>Zurück</button>
                </div>
            ";
            $view .= "<form id='newmessage'
                              action='messages.php?action=new'
                              method='POST'>
                            <input type='hidden' name='sendpm' value='1'>
                            <table class='table'>
                                <tr>
                                    <td style='width: 28%;'>
                                        <b>Empfänger:</b>
                                    </td>
                                    <td>
                                        <label>
                                            <input type='text' name='receiver' maxlength='16' value='$receiver_value'>
                                        </label>
                                        <button type='button' data-on-click='openOverlay' data-url='userlist.php' data-title='Spielerliste'>
                                        Spielerliste
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <b>Nachricht:</b>
                                    </td>
                                    <td>
                                        <label>
                                            <textarea name='text' rows='8' maxlength='" . MAX_MESSAGE_LENGTH . "' style='resize: vertical;'>$message</textarea>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan='2'>
                                        <input type='submit' name='sendpm' value='Abschicken'>
                                    </td>
                                </tr>
                            </table>
                        </form>
            ";
        }
    } else if ($_GET["action"] == "read") {
        $inbox_header = "Privatnachrichten";

        if (!isset($_GET["s"])) {
            change_location("messages.php?privmsgs");
        } else {
            $sender_id = (int)$_GET["s"];

            if ($sender_id == null) {
                $error = "Der Spieler existiert nicht!";
                $view = $messages->show_private_inbox();
            } else {
                // Get chat partner name based on id
                $result = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$sender_id]);
                $chat_partner = $result->fetch_assoc()["username"] ?? "";

                // Set all unread msgs as read
                $db_instance->execute_query(
                    "UPDATE messages SET hasread = 1 WHERE senderid = ? AND receiverid = ? AND hasread = 0",
                    [$sender_id, $user->get_user_id()]
                );

                // Check if conversation between the two exists
                $query = "SELECT * FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)";
                $result = $db_instance->execute_query($query, [$sender_id, $user->get_user_id(), $user->get_user_id(), $sender_id]);

                if ($result->num_rows == 0) {
                    $error = "Du hast keine Konversation mit diesem Nutzer!";
                    $view = $messages->show_private_inbox();
                } else {
                    // Delete messages that are marked for deletion
                    $messages->delete_marked_messages($sender_id);

                    $view .= "<div class='info-box event-error' style='display: none;'></div>";
                    $view .= "<div class='msg-back-button-container'><button class='msg-back-button' data-on-click='redirect' data-url='messages.php?privmsgs'>Zurück</button>
                            <h3 style='width: 100%; margin: 0;'>
                                <a href='#' 
                                 data-on-click='openOverlay' 
                                 data-url='userinfo.php?userid=" . e($sender_id) . "' 
                                 data-title='Spieler-Info'
                                 class='popup' 
                                 style='cursor: pointer;'>
                                 $chat_partner
                                </a>
                            </h3></div>";

                    // Show messages between chatpartner and user
                    $view .= $messages->show_messages_with_chatpartner($sender_id, $chat_partner);

                    $view .= "
                            <div id='newmessage-section'>
                                <form name=\"newmessage\"
                                      id='newmessage'
                                      action=\"messages.php?action=read&s=" . $sender_id . "\"
                                      method=\"POST\">
                                        <input type=\"hidden\" name=\"receiver\" value=\"" . $sender_id . "\">
                                        <textarea id=\"message-input\" 
                                              name=\"text\" 
                                              rows=\"3\"
                                              maxlength=\"" . MAX_MESSAGE_LENGTH . "\"
                                              style=\"resize: vertical; margin-right: 10px;\">" . (isset($_POST["text"]) ? e($_POST["text"]) : '') . "</textarea>
                                        <div class=\"emoji-picker-container\">
                                        <div id=\"emoji-menu\" class=\"emoji-menu\">";

                    foreach (Messages::get_chat_emojis() as $emoji) {
                        $view .= "<span data-on-click=\"pickEmoji\">$emoji</span>";
                    }

                    $view .= "</div>
                                            <button type=\"button\" class=\"emoji-trigger\" data-on-click=\"toggleEmojis\" title=\"Emoji einfügen\">🙂</button>
                                        </div>
                                        <input type=\"submit\" name=\"sendpm\" value=\"Absenden\n[ENTER]\"/>
                                </form>
                            </div>
                    ";
                }
            }
        }
    } else if ($_GET["action"] == "delete") {
        $chat_partner_id = (int)$_GET["s"];
        $user_id = $user->get_user_id();

        if (empty($chat_partner_id)) {
            change_location("messages.php?privmsgs");
        } else {
            $res_p = $db_instance->execute_query("SELECT username, ip FROM users WHERE id = ?", [$chat_partner_id]);
            $partner_data = $res_p->fetch_assoc();
            $partner_name = $partner_data["username"] ?? "Unbekannt";
            $partner_ip = $partner_data["ip"] ?? "0.0.0.0";

            $res_me = $db_instance->execute_query("SELECT username, ip FROM users WHERE id = ?", [$user_id]);
            $me_data = $res_me->fetch_assoc();
            $my_name = $me_data["username"];
            $my_ip = $me_data["ip"] ?? "0.0.0.0";

            $query_msgs = "SELECT sender, date, message FROM messages 
                   WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?) 
                   ORDER BY date";
            $res_msgs = $db_instance->execute_query($query_msgs, [$chat_partner_id, $user_id, $user_id, $chat_partner_id]);

            if ($res_msgs->num_rows == 0) {
                $error = "Du hast keine Konversation mit diesem Nutzer!";
                $view = $messages->show_private_inbox();
            } else {
                $log_dir = __DIR__ . "/logs/chat_backups/";

                if (!is_dir($log_dir)) {
                    mkdir($log_dir, 0755, true);
                }

                $filename = "chat_log_{$user_id}_vs_{$chat_partner_id}_" . date("Y-m-d_H-i-s") . ".log";

                $log_text = "=== CHAT BACKUP (Geloescht am " . date("d.m.Y H:i:s") . ") ===\n";
                $log_text .= "Loeschender User: $my_name (ID: $user_id | IP: $my_ip)\n";
                $log_text .= "Chat-Partner:    $partner_name (ID: $chat_partner_id | IP: $partner_ip)\n";
                $log_text .= "--------------------------------------------------\n\n";

                foreach ($res_msgs as $m) {
                    $msg_date = date("d.m.Y H:i:s", $m["date"]);

                    $clean_msg = str_replace(["<br>", "<br />"], "\n", $m["message"]);
                    $clean_msg = strip_tags($clean_msg);
                    $clean_msg = trim($clean_msg);

                    if ($clean_msg !== "") {
                        $log_text .= "[$msg_date] {$m["sender"]}: $clean_msg\n";
                    }
                }

                file_put_contents($log_dir . $filename, $log_text);

                if ($partner_data) {
                    $notice_json = [
                        "template" => "chat_conversation_ended",
                        "user_name" => $my_name
                    ];
                    Messages::send_server_message($chat_partner_id, $partner_name, MessageCategories::CATEGORY_DEFAULT, $notice_json);
                }

                $query = "DELETE FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)";
                $db_instance->execute_query($query, [$chat_partner_id, $user_id, $user_id, $chat_partner_id]);

                change_location("messages.php?privmsgs");
            }
        }
    } else {
        change_location("messages.php");
    }
}

if (isset($_GET["worldchat"])) {
    $view .= $messages->show_world_chat();

    $max_id = $db_instance->query("SELECT MAX(id) FROM world_chat")->fetch_row()[0] ?? 0;
    $db_instance->execute_query("UPDATE users SET last_world_chat_id = ? WHERE id = ?", [$max_id, $user->get_user_id()]);

    $inbox_header = "Welt-Chat";
} else if (isset($_GET["servermsgs"])) {
    $res_max_id = $db_instance->execute_query(
        "SELECT MAX(id) FROM server_messages WHERE receiverid = ?",
        [$user->get_user_id()]
    );
    $current_max_id = (int)($res_max_id->fetch_column() ?? 0);

    $view .= "<div class='msg-back-button-container'>
                <button class='msg-back-button' data-on-click='redirect' data-url='messages.php'>Zurück</button>
                <button class='btn-delete' data-on-click='confirmDeleteAllServer' data-max-id='$current_max_id'>Alle löschen</button>
            </div>
    ";

    // Category Tabs
    $view .= "<div class='tab'>";
    $view .= "<div class='tablinks active' data-on-click='filterServer' data-category='-1'>Alle</div>";

    foreach (MessageCategories::get_labels() as $cat_id => $cat_name) {
        if ($cat_id === MessageCategories::CATEGORY_DEFAULT) continue;

        $view .= "<div class='tablinks' data-on-click='filterServer' data-category='$cat_id'>$cat_name</div>";
    }

    $view .= "</div>";

    $view .= "<div id='messages-section' class='large-height'>";
    $view .= $messages->show_server_inbox();
    $view .= "</div>";

    $inbox_header = "Servernachrichten";
} else if (isset($_GET["privmsgs"])) {
    $view = $messages->show_private_inbox();

    $inbox_header = "Privatnachrichten";
} else if (!isset($_GET["action"])) {
    $private = $messages->get_unread_private_count();
    $server = $messages->get_unread_server_count();
    $world = $messages->get_unread_world_count();

    $view .= "<div class='msg-button-container'>";

    // Private Messages Button
    $view .= "<a href='messages.php?privmsgs' class='msg-button'>
    <div class='msg-left'>
        <span>📩</span>
        <span>Privatnachrichten</span>
    </div>";
    if ($private > 0) {
        $view .= "<span class='msg-badge'>" . $messages->show_messages_indicator($private) . "</span>";
    }
    $view .= "</a>";

    // Server Messages Button
    $view .= "<a href='messages.php?servermsgs' class='msg-button'>
    <div class='msg-left'>
        <span>🖥️</span>
        <span>Servernachrichten</span>
    </div>";
    if ($server > 0) {
        $view .= "<span class='msg-badge'>" . $messages->show_messages_indicator($server) . "</span>";
    }
    $view .= "</a>";

    // Support Button
    $is_staff = ($user->get_user_admin_level() > 0);
    $uid = $user->get_user_id();

    $support_unread_query = $is_staff
        ? "SELECT COUNT(*) FROM support_messages sm 
           JOIN support_tickets t ON sm.ticketid = t.id 
           WHERE sm.hasread = 0 
             AND sm.senderid != ? 
             AND (
                 (sm.is_admin_reply = 0 AND t.status = 1 AND t.userid != ?) 
                 OR 
                 (sm.is_admin_reply = 1 AND t.userid = ?)
             )"
        : "SELECT COUNT(*) FROM support_messages sm 
           JOIN support_tickets t ON sm.ticketid = t.id 
           WHERE t.userid = ? AND sm.is_admin_reply = 1 AND sm.hasread = 0 AND sm.senderid != ?";

    $params_support = $is_staff ? [$uid, $uid, $uid] : [$uid, $uid];
    $support_unread = $db_instance->execute_query($support_unread_query, $params_support)->fetch_row()[0];

    $view .= "<a href='support.php' class='msg-button'>
    <div class='msg-left'>
        <span>🛠️</span>
        <span>Support-Tickets</span>
    </div>";
    if ($support_unread > 0) {
        $view .= "<span class='msg-badge'>" . $messages->show_messages_indicator($support_unread) . "</span>";
    }
    $view .= "</a>";

    $view .= "</div>";
}


/*
 * HTML Section
 */
$title = "Nachrichten";
$header = $inbox_header ?? "Nachrichten";
$script_files = ["timer", "chat", "userinfo", "guild"];

include("layout/base.php");
<?php
require_once("includes/core.php");

$user->check_user_login();
$messages = new Messages($user);

// Starting a new conversation (or insert message in existing conversation)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf_token()) {
        change_location("messages.php", error: "Ungültiger Sicherheits-Token!");
    }

    if (isset($_POST["sendpm"])) {
        $from_read_id = (get_str("action") === "read") ? get_int("s") : 0;
        $fail_redirect = function (string $msg) use ($from_read_id): never {
            if ($from_read_id > 0) {
                change_location("messages.php?action=read&s=$from_read_id", error: $msg);
            }

            set_form_flash("pm_new", ["receiver" => post_str("receiver"), "text" => post_str("text")]);
            change_location("messages.php?action=new", error: $msg);
        };

        $raw_receiver = trim(post_str("receiver"));
        $receiver_name = (strlen($raw_receiver) <= MAX_USERNAME_LENGTH * 4)
            ? Messages::sanitize_text($raw_receiver, false, false)
            : '';
        $validation = Messages::clean_and_validate_chat_text(post_str("text"));

        if (empty($receiver_name)) {
            $fail_redirect("Bitte einen gültigen Empfänger angeben!");
        }
        if (isset($validation["error"])) {
            $fail_redirect($validation["error"]);
        }

        $cleaned_text = $validation["text"];
        $sender_id = $user->get_user_id();
        $sender_name = $user->get_user_name();

        $result = is_numeric($receiver_name)
            ? $db_instance->execute_query("SELECT id, username FROM users WHERE id = ?", [(int)$receiver_name])
            : $db_instance->execute_query("SELECT id, username FROM users WHERE username = ?", [$receiver_name]);

        if ($result->num_rows === 0) {
            $fail_redirect("Dieser Spieler existiert nicht!");
        }

        $receiver_data = $result->fetch_assoc();
        $receiver_id = (int)$receiver_data["id"];
        $receiver_username = $receiver_data["username"];
        if ($receiver_id === $sender_id) {
            $fail_redirect("Du kannst dir selbst keine Nachricht schicken!");
        }

        $wait = Messages::check_rate_limit();
        if ($wait > 0) {
            $fail_redirect("Du schickst zu viele Nachrichten! Warte bitte: $wait Sek.");
        }

        $messages->send_message($sender_id, $sender_name, $receiver_id, $receiver_username, time(), $cleaned_text);

        change_location("messages.php?action=read&s=$receiver_id");
    }

    if (isset($_POST["delete_conversation"])) {
        $chat_partner_id = post_int("partner_id");
        $user_id = $user->get_user_id();

        if (empty($chat_partner_id)) {
            change_location("messages.php?privmsgs");
        }

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

        if ($res_msgs->num_rows === 0) {
            change_location("messages.php?privmsgs", error: "Du hast keine Konversation mit diesem Nutzer!");
        }

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

        change_location("messages.php?privmsgs", success: "Die Konversation wurde erfolgreich gelöscht.");
    }
}

if (isset($_GET["action"])) {
    $msg_action = get_str("action");

    if ($msg_action === "new") {
        $pm_draft = consume_form_flash("pm_new");
        $receiver_value = isset($_GET["receiver"]) ? e(get_str("receiver")) : e((string)($pm_draft["receiver"] ?? ""));
        $message = e((string)($pm_draft["text"] ?? ""));

        $view .= "
            <div class='msg-back-button-container'>
                <button data-on-click='navigate' data-url='messages.php?privmsgs'>Zurück</button>
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
                                    <input type='text' name='receiver' maxlength='" . MAX_USERNAME_LENGTH . "' value='$receiver_value'>
                                </label>
                                <button type='button' data-on-click='openOverlay' data-url='ajax/userlist.php' data-title='Spielerliste'>
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
    } else if ($msg_action === "read") {
        $inbox_header = "Privatnachrichten";

        if (!isset($_GET["s"])) {
            change_location("messages.php?privmsgs");
        }

        $sender_id = get_int("s");
        if ($sender_id <= 0) {
            change_location("messages.php?privmsgs", error: "Der Spieler existiert nicht!");
        }

        // Get chat partner name based on id
        $result = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$sender_id]);
        $chat_partner = $result->fetch_assoc()["username"] ?? "";

        // Check if conversation between the two exists
        $query = "SELECT 1 FROM messages WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) AND deleted = 0 LIMIT 1";
        $result = $db_instance->execute_query($query, [$sender_id, $user->get_user_id(), $user->get_user_id(), $sender_id]);

        if ($result->num_rows === 0) {
            change_location("messages.php?privmsgs", error: "Du hast keine Konversation mit diesem Nutzer!");
        }

        $view .= "<div class='info-box event-error' style='display: none;'></div>";
        $view .= "<div class='msg-back-button-container'><button data-on-click='navigate' data-url='messages.php?privmsgs'>Zurück</button>
                    <h3 style='width: 100%; margin: 0;'>
                        <a href='#' 
                         data-on-click='openOverlay' 
                         data-url='ajax/userinfo.php?userid=" . e($sender_id) . "' 
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
            <form name='newmessage' id='newmessage' action='messages.php?action=read&s=" . $sender_id . "' method='POST'>
                <input type='hidden' name='receiver' value='" . $sender_id . "'>
                <textarea id='message-input' name='text' rows='3' maxlength='" . MAX_MESSAGE_LENGTH . "' style='resize: vertical; margin-right: 10px;'></textarea>
                <div class='emoji-picker-container'>
                    <div id='emoji-menu' class='emoji-menu'>";

        foreach (Messages::get_chat_emojis() as $emoji) {
            $view .= "<span data-on-click='pickEmoji'>$emoji</span>";
        }

        $view .= "      </div>
                    <button type='button' class='emoji-trigger' data-on-click='toggleEmojis' title='Emoji einfügen'>🙂</button>
                </div>
                <input type='submit' name='sendpm' value='Absenden\n[ENTER]'/>
            </form>
        </div>";
    } else {
        change_location("messages.php");
    }
}

if (isset($_GET["worldchat"])) {
    $view .= $messages->show_world_chat();

    $inbox_header = "Welt-Chat";
} else if (isset($_GET["servermsgs"])) {
    $res_max_id = $db_instance->execute_query(
        "SELECT MAX(id) FROM server_messages WHERE receiverid = ?",
        [$user->get_user_id()]
    );
    $current_max_id = (int)($res_max_id->fetch_column() ?? 0);

    $view .= "<div class='msg-back-button-container'>
                <button data-on-click='navigate' data-url='messages.php'>Zurück</button>
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

    $view .= "<div id='messages-section' class='large-height' data-chat-type='server'>";
    $view .= $messages->show_server_inbox();
    $view .= "</div>";

    $inbox_header = "Servernachrichten";
} else if (isset($_GET["privmsgs"])) {
    $view = $messages->show_private_inbox();

    $inbox_header = "Privatnachrichten";
} else if (!isset($_GET["action"])) {
    $unreads = $user->get_unread_counts();
    $private = $unreads["pms"];
    $server = $unreads["server"];
    $support_unread = $unreads["support"];

    $view .= "<div class='msg-button-container'>";

    // Private Messages Button
    $view .= "<a href='messages.php?privmsgs' class='msg-button'>
    <div class='msg-left'>
        <span>📩</span>
        <span>Privatnachrichten</span>
    </div>";
    if ($private > 0) {
        $view .= "<span class='msg-badge'>" . Messages::show_messages_indicator($private) . "</span>";
    }
    $view .= "</a>";

    // Server Messages Button
    $view .= "<a href='messages.php?servermsgs' class='msg-button'>
    <div class='msg-left'>
        <span>🖥️</span>
        <span>Servernachrichten</span>
    </div>";
    if ($server > 0) {
        $view .= "<span class='msg-badge'>" . Messages::show_messages_indicator($server) . "</span>";
    }
    $view .= "</a>";

    // Support Button
    $view .= "<a href='support.php' class='msg-button'>
    <div class='msg-left'>
        <span>🛠️</span>
        <span>Support-Tickets</span>
    </div>";
    if ($support_unread > 0) {
        $view .= "<span class='msg-badge'>" . Messages::show_messages_indicator($support_unread) . "</span>";
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
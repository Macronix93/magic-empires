<?php
require_once("includes/core.php");
check_user_login($user);

$support = new Support($db_instance, $user);
$is_staff = ($user->get_user_admin_level() > 0);
$uid = $user->get_user_id();

function get_support_error($text): ?string
{
    $text_plain = trim(strip_tags($text));
    $line_breaks_count = substr_count($text, '<br />');
    $text_without_line_breaks = preg_replace('/<br\s*\/?>/i', '', $text);

    if (empty($text_plain)) {
        return "Bitte eine Nachricht eingeben!";
    } else if (strlen($text_without_line_breaks) > MAX_MESSAGE_LENGTH) {
        return "Die Nachricht darf maximal " . MAX_MESSAGE_LENGTH . " Zeichen lang sein!";
    } else if ($line_breaks_count > MAX_LINE_BREAK_COUNT) {
        return "Dein Text darf maximal " . MAX_LINE_BREAK_COUNT . " Zeilenumbrüche beinhalten!";
    }

    return null;
}

// --- ACTIONS ---
if (isset($_POST["open_ticket"]) && !$support->has_active_ticket($uid)) {
    $raw_subject = trim($_POST["subject"] ?? "");
    $raw_text = $_POST["text"] ?? "";

    $val_error = get_support_error($raw_text);

    if (empty($raw_subject)) {
        $error = "Bitte einen Betreff angeben!";
    } else if ($val_error) {
        $error = $val_error;
    } else {
        // Rate Limit Check
        $current_time = time();

        if ($current_time > ($_SESSION["message_timeframe_end"] ?? 0)) {
            $_SESSION["message_count"] = 0;
            $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;
        }

        if (($_SESSION["message_count"] ?? 0) >= MAX_MESSAGES_RATELIMIT) {
            $error = "Du schickst zu viele Nachrichten! Bitte warte kurz.";
        } else if (mb_strlen($raw_subject) > MAX_SUPPORT_TICKET_SUBJECT_LENGTH) {
            $error = "Der Betreff ist zu lang (max. " . MAX_SUPPORT_TICKET_SUBJECT_LENGTH . " Zeichen)!";
        } else {
            $sub = e(mb_substr($raw_subject, 0, MAX_SUPPORT_TICKET_SUBJECT_LENGTH));
            $msg = filter_chat_message(nl2br(e($raw_text)));

            $_SESSION["message_count"]++;
            $support->create_ticket($sub, $msg);

            change_location("support.php");
            exit;
        }
    }
}

// Close ticket
if (isset($_GET["close"]) && is_numeric($_GET["close"])) {
    $tid = (int)$_GET["close"];
    $res = $db_instance->execute_query("SELECT userid FROM support_tickets WHERE id = ?", [$tid]);
    $ticket_data = $res->fetch_assoc();

    if ($is_staff || ($ticket_data && $ticket_data["userid"] == $uid)) {
        $reason = $is_staff ? "Durch Support" : "Durch Benutzer";
        $support->close_ticket($tid, $reason);
    }

    change_location("support.php");
    exit;
}

// Delete ticket
if (isset($_GET["delete"]) && is_numeric($_GET["delete"])) {
    $tid = (int)$_GET["delete"];
    $support->delete_ticket($tid);

    change_location("support.php");
    exit;
}

// Send message in existing ticket
if (isset($_POST["send_msg"]) && is_numeric($_POST["tid"])) {
    $tid = (int)$_POST["tid"];
    $raw_text = $_POST["text"] ?? "";

    // Check access
    $res = $db_instance->execute_query("SELECT userid, status FROM support_tickets WHERE id = ?", [$tid]);
    $t_check = $res->fetch_assoc();

    $val_error = get_support_error($raw_text);

    if (!$t_check || (!$is_staff && $t_check["userid"] != $uid)) {
        $error = "Zugriff verweigert!";
    } else if ($t_check["status"] == 0) {
        $error = "Dieses Ticket ist bereits geschlossen!";
    } else if ($val_error) {
        $error = $val_error;
    } else {
        // Rate Limit Check
        $current_time = time();

        if ($current_time > ($_SESSION["message_timeframe_end"] ?? 0)) {
            $_SESSION["message_count"] = 0;
            $_SESSION["message_timeframe_end"] = $current_time + MESSAGES_RATE_INTERVAL;
        }

        if (($_SESSION["message_count"] ?? 0) >= MAX_MESSAGES_RATELIMIT) {
            $error = "Du schickst zu viele Nachrichten! Bitte warte kurz.";
        } else {
            $msg = filter_chat_message(nl2br(e($raw_text)));
            $_SESSION["message_count"]++;
            $support->add_message($tid, $msg, $is_staff);

            change_location("support.php?tid=$tid");
            exit;
        }
    }
}

/*
 * HTML Section
 */
$title = "Support";
$header = "Support-System";
$script_files = ["support"];

if (!empty($error)) {
    $view .= show_error_box($error);
}

if (isset($_GET["tid"])) {
    // TICKET DETAILS
    $tid = (int)$_GET["tid"];
    $res = $db_instance->execute_query("
                        SELECT t.*, u.username, a.username AS assigned_admin 
                        FROM support_tickets t 
                        JOIN users u ON t.userid = u.id 
                        LEFT JOIN users a ON t.assigned_to = a.id 
                        WHERE t.id = ?", [$tid]);
    $ticket = $res->fetch_assoc();

    if (!$ticket || (!$is_staff && $ticket["userid"] != $uid)) {
        $view .= show_error_box("Zugriff verweigert.");
    } else {

        if ($is_staff) {
            if ($ticket["assigned_admin"] && $ticket["assigned_to"] != $uid) {
                $view .= show_warning_box("Dieses Ticket wird bereits von <b>" . e($ticket["assigned_admin"]) . "</b> bearbeitet.");
            }

            // Admin reads User Messages
            $db_instance->execute_query("UPDATE support_messages SET hasread = 1 WHERE ticketid = ? AND is_admin_reply = 0", [$tid]);
        } else {
            // User reads Admin Messages
            $db_instance->execute_query("UPDATE support_messages SET hasread = 1 WHERE ticketid = ? AND is_admin_reply = 1", [$tid]);
        }

        if ($ticket["status"] != 1) {
            $view .= show_warning_box("Dieses Ticket ist geschlossen (" . e($ticket["close_reason"]) . ").");
        }

        $view .= "<div class='msg-back-button-container'><button class='msg-back-button' data-on-click='navigate' data-url='support.php'>Zurück</button>";
        $view .= "<h3 style='margin: 0; flex: 1;'>Ticket #$tid: " . e($ticket["subject"]) . "</h3>";

        if ($ticket["status"] == 1) {
            $view .= "<div><button class='btn-delete' style='width: auto; padding: 5px 10px;' data-on-click='confirmCloseTicket' data-id='$tid'>Ticket schließen</button></div>";
        }
        $view .= "</div>";

        $view .= "<div id='messages-section' style='max-height: 400px; overflow-y: auto; display: flex; flex-direction: column;'>";
        $msgs = $db_instance->execute_query("SELECT m.*, u.username FROM support_messages m JOIN users u ON m.senderid = u.id WHERE m.ticketid = ? ORDER BY m.created_at",
            [$tid]);

        foreach ($msgs as $m) {
            $is_me = ($m["senderid"] == $uid);
            $class = $is_me ? "receiver-bubble" : "sender-bubble";
            $display_name = $is_me ? "Du" : e($m["username"]);
            $support_label = ($m["is_admin_reply"]) ? " <small style='color: var(--link-color)'>(Support)</small>" : "";

            $sender_user = new User($m["senderid"], $m["username"]);
            $avatar_path = $sender_user->get_avatar();

            $view .= "<div class='$class' id='msg-{$m["id"]}'>
                        <div class='message-border'>
                            <span class='msg-header-left'>
                                <img class='user-image' src='" . e($avatar_path) . "' alt='Avatar'>
                                <span>$display_name $support_label am " . date("d.m.Y \u\m H:i:s", $m["created_at"]) . "</span>
                            </span>
                        </div>
                        {$m["message"]}
                    </div>";
        }
        $view .= "</div>";

        if ($ticket["status"] == 1) {
            $view .= "<div id='newmessage-section'>
                        <form method='POST' action='support.php' id='newmessage'>
                            <input type='hidden' name='tid' value='$tid'>
                            <input type='hidden' name='send_msg' value='1'>
                            <textarea id='message-input' name='text' rows='3' placeholder='Deine Antwort...' required style='margin-right: 10px;'></textarea>
                            
                            <div class=\"emoji-picker-container\">
                                <div id=\"emoji-menu\" class=\"emoji-menu\">";
            foreach (get_chat_emojis() as $emoji) {
                $view .= "<span data-on-click=\"pickEmoji\">$emoji</span>";
            }
            $view .= "          </div>
                                <button type=\"button\" class=\"emoji-trigger\" data-on-click=\"toggleEmojis\" title=\"Emoji einfügen\">🙂</button>
                            </div>

                            <input type='submit' name='send_msg' value='Absenden'>
                        </form>
                      </div>";
        }
    }
} else {
    // TICKET LIST
    $view .= "<div class='msg-back-button-container'><button class='msg-back-button' data-on-click='redirect' data-url='messages.php'>Zurück</button></div>";

    // Pagination variables
    $rows_per_page = SUPPOR_TICKET_ROWS_PER_PAGE;
    $current_page = max(1, (int)($_GET["currentpage"] ?? 1));
    $offset = ($current_page - 1) * $rows_per_page;

    if (!$support->has_active_ticket($uid)) {
        $view .= "<div class='box-container'>
                        <div class='box-header'>Neues Support-Ticket</div>
                        <div class='box-content box-content-bg' style='padding: 15px;'>
                            <form method='POST' action='support.php' id='newticketform'>
                                <input type='hidden' name='open_ticket' value='1'>
                                <input type='text' name='subject' placeholder='Betreff' maxlength='" . MAX_SUPPORT_TICKET_SUBJECT_LENGTH . "' style='width: 100%; margin-bottom: 10px;' required>
                                <textarea name='text' id='new-ticket-text' rows='4' placeholder='Beschreibe dein Problem...' required></textarea>
                                <br><input type='submit' value='Absenden'>
                            </form>
                        </div>
                      </div><br>";
    }

    if (!$is_staff) {


        $total_items = $db_instance->execute_query("SELECT COUNT(*) FROM support_tickets WHERE userid = ?", [$uid])->fetch_row()[0];

        $sql = "SELECT t.*, u.username FROM support_tickets t JOIN users u ON t.userid = u.id WHERE t.userid = ? ORDER BY t.status DESC, t.updated_at DESC LIMIT ?, ?";
        $params = [$uid, $offset, $rows_per_page];
    } else {
        $view .= "<h3 style='margin-top: 0;'>Alle Support-Anfragen</h3>";
        $total_items = $db_instance->execute_query("SELECT COUNT(*) FROM support_tickets")->fetch_row()[0];

        $sql = "SELECT t.*, u.username, a.username AS assigned_admin 
                FROM support_tickets t 
                JOIN users u ON t.userid = u.id 
                LEFT JOIN users a ON t.assigned_to = a.id 
                ORDER BY t.status DESC, t.updated_at DESC LIMIT ?, ?";
        $params = [$offset, $rows_per_page];
    }
    $total_pages = ceil($total_items / $rows_per_page);
    $tickets = $db_instance->execute_query($sql, $params);

    if ($tickets->num_rows > 0) {
        $view .= "<table class='table'>
                    <tr>
                        <td class='td-center td-gradient' style='width: 5%;'><b>Status</b></td>
                        <td class='td-center td-gradient'><b>Betreff</b></td>
                        <td class='td-center td-gradient'><b>User</b></td>
                        <td class='td-center td-gradient' colspan='2' style='width: 40%;'><b>Letztes Update</b></td>
                    </tr>";

        foreach ($tickets as $t) {
            $closed_ticket = $t["status"] == 0 ? " tr-inactive" : "";
            $status = $t["status"] == 1 ? "<img src='images/icons/icon_question.png' class='ressource-icons' alt='Offen'>" :
                "<img src='images/icons/icon_lock.png' class='ressource-icons' alt='Geschlossen'>";

            $assigned_info = "";
            if ($is_staff) {
                $assigned_info = $t["assigned_admin"]
                    ? "<br><small style='opacity: 0.7;'>Bearbeiter: " . e($t["assigned_admin"]) . "</small>"
                    : "<br><small class='error' style='opacity: 0.7;'>[Unbearbeitet]</small>";
            }

            $view .= "<tr class='tr-hover$closed_ticket'>
                        <td class='td-cursor td-center' data-on-click='redirect' data-url='support.php?tid={$t["id"]}'>$status</td>
                        <td class='td-cursor' data-on-click='redirect' data-url='support.php?tid={$t["id"]}'>" . e($t["subject"]) . "$assigned_info</td>
                        " . ($is_staff ? "<td class='td-cursor' data-on-click='redirect' data-url='support.php?tid={$t["id"]}'>" . e($t["username"]) . "</td>" : "") . "
                        <td class='td-cursor' data-on-click='redirect' data-url='support.php?tid={$t["id"]}'>am " . date("d.m.Y \u\m H:i:s", $t["updated_at"]) . "</td>
                        <td class='td-center'>
                            <img src='images/icons/icon_delete.png' class='ressource-icons' style='cursor: pointer;' 
                                 data-on-click='confirmDeleteTicket' data-id='{$t["id"]}' title='Ticket löschen' alt=''>
                        </td>
                      </tr>";
        }
        $view .= "</table>";

        // Pagination Bar
        if ($total_pages > 1) {
            $view .= '<div class="pagination-container"><div class="pagination-bar">';

            if ($current_page > 1) {
                $view .= "<a href='support.php?currentpage=1' class='page-link'>&laquo;</a>";
                $prev = $current_page - 1;
                $view .= "<a href='support.php?currentpage=$prev' class='page-link'>&lsaquo;</a>";
            }

            $range = 2;
            for ($x = ($current_page - $range); $x <= ($current_page + $range); $x++) {
                if ($x > 0 && $x <= $total_pages) {
                    if ($x == $current_page) {
                        $view .= "<span class='page-link active'>$x</span>";
                    } else {
                        $view .= "<a href='support.php?currentpage=$x' class='page-link'>$x</a>";
                    }
                }
            }

            if ($current_page < $total_pages) {
                $next = $current_page + 1;
                $view .= "<a href='support.php?currentpage=$next' class='page-link'>&rsaquo;</a>";
                $view .= "<a href='support.php?currentpage=$total_pages' class='page-link'>&raquo;</a>";
            }

            $view .= '</div></div>';
        }
    } else {
        $view .= "<p style='text-align:center;'>Keine Tickets vorhanden.</p>";
    }
}

include("layout/base.php");
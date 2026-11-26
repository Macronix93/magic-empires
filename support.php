<?php
require_once("includes/core.php");

$user->check_user_login();

$support = new Support($user);
$is_staff = ($user->get_user_admin_level() > 0);
$uid = $user->get_user_id();

// --- ACTIONS ---
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf_token()) {
        change_location("support.php", error: "Ungültiger Sicherheits-Token!");
    }

    if (isset($_POST["open_ticket"])) {
        $raw_subject = trim(post_str("subject"));
        $raw_text = post_str("text");
        $sub_clean = Messages::sanitize_text($raw_subject, false, false);
        $text_clean = Messages::sanitize_text($raw_text);
        $val_error = Support::validate_message_text($text_clean);

        $fail_open = function (string $msg) use ($raw_subject, $raw_text): never {
            set_form_flash("support_ticket", ["subject" => $raw_subject, "text" => $raw_text]);
            change_location("support.php?tab=my", error: $msg);
        };

        if (empty($raw_subject)) $fail_open("Bitte einen Betreff angeben!");
        if ($val_error) $fail_open($val_error);
        if (empty($sub_clean) || mb_strlen($sub_clean) < 3) $fail_open("Bitte einen gültigen Betreff angeben!");
        if (empty($text_clean) || mb_strlen($text_clean) < 10) $fail_open("Deine Nachricht ist zu kurz!");
        if (mb_strlen($raw_subject) > MAX_SUPPORT_TICKET_SUBJECT_LENGTH) {
            $fail_open("Der Betreff ist zu lang (max. " . MAX_SUPPORT_TICKET_SUBJECT_LENGTH . " Zeichen)!");
        }

        $sub = mb_substr($sub_clean, 0, MAX_SUPPORT_TICKET_SUBJECT_LENGTH);
        $msg = Messages::filter_chat_message(nl2br(e($text_clean)));

        $db_instance->begin_transaction();
        try {
            $db_instance->execute_query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$uid]);

            if ($support->has_active_ticket($uid)) {
                throw new RuntimeException("Du hast bereits ein offenes Support-Ticket!");
            }
            $wait = Messages::check_rate_limit();
            if ($wait > 0) {
                throw new RuntimeException("Du schickst zu viele Nachrichten! Bitte warte noch $wait Sek.");
            }

            $support->create_ticket($sub, $msg);

            $db_instance->commit();

            change_location("support.php");
        } catch (RuntimeException $e) {
            $db_instance->rollback();

            $fail_open($e->getMessage());
        } catch (Throwable $e) {
            $db_instance->rollback();
            $logger->error("support open_ticket error: " . $e->getMessage());

            $fail_open("Interner Fehler beim Erstellen des Tickets.");
        }
    }

    if (isset($_POST["close_ticket"], $_POST["tid"]) && is_numeric($_POST["tid"])) {
        $tid = post_int("tid");
        $ticket_data = $db_instance->execute_query("SELECT userid FROM support_tickets WHERE id = ?", [$tid])->fetch_assoc();

        if ($is_staff || ($ticket_data && $ticket_data["userid"] == $uid)) {
            $support->close_ticket($tid, $is_staff ? "Durch Support" : "Durch Benutzer");
        }

        change_location("support.php");
    }

    if (isset($_POST["delete_ticket"], $_POST["tid"]) && is_numeric($_POST["tid"])) {
        $support->delete_ticket(post_int("tid"));

        change_location("support.php");
    }

    if (isset($_POST["send_msg"], $_POST["tid"]) && is_numeric($_POST["tid"])) {
        $tid = post_int("tid");
        $raw_text = post_str("text");
        $text_clean = Messages::sanitize_text($raw_text, true, true);
        $val_error = Support::validate_message_text($text_clean);

        $t_check = $db_instance->execute_query("SELECT userid, status FROM support_tickets WHERE id = ?", [$tid])->fetch_assoc();
        if (!$t_check) {
            change_location("support.php", error: "Das Ticket existiert nicht mehr oder wurde gelöscht.");
        }
        if (!$is_staff && $t_check["userid"] != $uid) {
            change_location("support.php", error: "Zugriff verweigert!");
        }
        if (!$t_check["status"]) {
            change_location("support.php?tid=$tid", error: "Dieses Ticket ist bereits geschlossen!");
        }
        if ($val_error) {
            change_location("support.php?tid=$tid", error: $val_error);
        }
        $wait = Messages::check_rate_limit();
        if ($wait > 0) {
            change_location("support.php?tid=$tid", error: "Du schickst zu viele Nachrichten! Bitte warte noch $wait Sek.");
        }

        $msg = Messages::filter_chat_message(nl2br(e($text_clean)));

        $is_acting_as_staff = ($is_staff && (int)$t_check["userid"] !== $uid);

        $support->add_message($tid, $msg, $is_acting_as_staff);

        change_location("support.php?tid=$tid");
    }

    change_location("support.php");
}

/*
 * HTML Section
 */
$title = "Support";
$header = "Support-System";
$script_files = ["timer", "support", "userinfo"];

if (isset($_GET["tid"])) {
    // TICKET DETAILS
    $tid = get_int("tid");

    $res = $db_instance->execute_query("
                        SELECT t.*, u.username, a.username AS assigned_admin 
                        FROM support_tickets t 
                        JOIN users u ON t.userid = u.id 
                        LEFT JOIN users a ON t.assigned_to = a.id 
                        WHERE t.id = ?", [$tid]);
    $ticket = $res->fetch_assoc();

    if (!$ticket) {
        change_location("support.php", error: "Dieses Ticket wurde gelöscht oder existiert nicht.");
    }

    if (!$is_staff && $ticket["userid"] != $uid) {
        change_location("support.php", error: "Zugriff verweigert.");
    }

    $is_acting_as_staff = ($is_staff && (int)$ticket["userid"] !== $uid);

    if ($is_acting_as_staff) {
        if ($ticket["assigned_admin"] && $ticket["assigned_to"] != $uid) {
            $view .= show_warning_box("Dieses Ticket wird bereits von <b>" . e($ticket["assigned_admin"]) . "</b> bearbeitet.") . "<br>";
        }
    }

    $msgs = $db_instance->execute_query(
        "SELECT m.*, u.username FROM support_messages m JOIN users u ON m.senderid = u.id WHERE m.ticketid = ? ORDER BY m.id",
        [$tid]
    )->fetch_all(MYSQLI_ASSOC);

    $db_instance->execute_query(
        "UPDATE support_messages SET hasread = 1 WHERE ticketid = ? AND senderid != ? AND hasread = 0",
        [$tid, $uid]
    );

    if (!$ticket["status"]) {
        $view .= show_warning_box("Dieses Ticket ist geschlossen (" . e($ticket["close_reason"]) . ").") . "<br>";
    }

    $view .= "<div class='info-box event-error' id='support-chat-error' style='display: none;'></div>";
    $view .= "<div class='msg-back-button-container'><button data-on-click='navigate' data-url='support.php'>Zurück</button>";
    $view .= "<h3 style='margin: 0; flex: 1;'>Ticket #$tid: " . e($ticket["subject"]) . "</h3>";

    if ($ticket["status"]) {
        $view .= "<div><button class='btn-delete' style='width: auto; padding: 5px 10px;' data-on-click='confirmCloseTicket' data-id='$tid'>Ticket schließen</button></div>";
    }

    $view .= "</div>";

    $last_msg_id = 0;
    $unread_line_shown = false;

    $view .= "<div id='chat-loading-wrapper'>";
    $view .= "<div id='messages-section' data-chat-type='support' data-ticket-id='$tid' data-ticket-open='" . (int)$ticket["status"] . "'>";

    foreach ($msgs as $m) {
        $last_msg_id = (int)$m["id"];
        $is_unread_foreign = ((int)$m["senderid"] !== $uid && empty($m["hasread"]));

        if ($is_unread_foreign && !$unread_line_shown) {
            $unread_line_shown = true;
            $view .= "<div id='new-message-line' class='error'>Neue Nachrichten seit " . date("d.m.Y H:i", (int)$m["created_at"]) . "</div>";
        }

        $view .= Support::render_message_bubble($m, $user, $is_staff);
    }

    $view .= "</div></div>";
    $view .= "<div id='support-chat-meta' data-last-id='$last_msg_id' style='display: none;'></div>";

    if ($ticket["status"]) {
        $view .= "
            <div id='newmessage-section'>
                <form method='POST' action='support.php' id='newmessage'>
                    <input type='hidden' name='tid' value='$tid'>
                    <input type='hidden' name='send_msg' value='1'>
                    <textarea id='message-input' name='text' rows='3' maxlength='" . MAX_MESSAGE_LENGTH . "' placeholder='Deine Antwort...' required style='resize: vertical; margin-right: 10px;'></textarea>
                    <div class='emoji-picker-container'>
                        <div id='emoji-menu' class='emoji-menu'>";

        foreach (Messages::get_chat_emojis() as $emoji) {
            $view .= "<span data-on-click='pickEmoji'>$emoji</span>";
        }

        $view .= "</div>
                        <button type='button' class='emoji-trigger' data-on-click='toggleEmojis' title='Emoji einfügen'>🙂</button>
                    </div>
                    <input type='submit' name='send_msg' value=\"Absenden\n[ENTER]\">
                </form>
            </div>";
    }
} else {
    // TICKET LIST
    $view .= "<div class='msg-back-button-container'><button data-on-click='navigate' data-url='messages.php'>Zurück</button></div>";

    $active_tab = get_str("tab", $is_staff ? "all" : "my");
    if (!in_array($active_tab, ["all", "my"], true)) {
        $active_tab = $is_staff ? "all" : "my";
    }

    if ($is_staff) {
        $view .= "
            <div class='tab' id='support-tabs' style='max-width: 600px; margin: 0 auto 20px auto;'>
                <div class='tablinks " . ($active_tab === "all" ? "active" : "") . "' data-on-click='switchSupportTab' data-tab='all'>Alle Tickets</div>
                <div class='tablinks " . ($active_tab === "my" ? "active" : "") . "' data-on-click='switchSupportTab' data-tab='my'>Meine Tickets</div>
            </div>";
    }

    $rows_per_page = SUPPORT_TICKET_ROWS_PER_PAGE;

    // Helper Ticket Tables
    $render_tickets_table = function (mysqli_result $tickets, bool $is_my_view) {
        $html = "<table class='table' style='max-width: 700px;'>
            <tr>
                <td class='td-center td-gradient' colspan='2'><b>Betreff</b></td>
                " . (!$is_my_view ? "<td class='td-center td-gradient'><b>User</b></td>" : "") . "
                <td class='td-center td-gradient' colspan='2' style='width: 50%;'><b>Letztes Update</b></td>
            </tr>";

        foreach ($tickets as $t) {
            $unread = (int)($t["unread_count"] ?? 0);
            $unread_badge = ($unread > 0)
                ? "<span class='msg-badge' style='margin-left: 8px; flex-shrink: 0;'>" . ($unread > 9 ? "9+" : $unread) . "</span>"
                : "";

            $closed_ticket = !$t["status"] ? " tr-inactive" : "";
            $status = $t["status"]
                ? "<img src='images/icons/icon_question.png' class='ressource-icons' alt='Offen'>"
                : "<img src='images/icons/icon_lock.png' class='ressource-icons' alt='Geschlossen'>";
            $assigned_info = "";
            if (!$is_my_view) {
                $assigned_info = !empty($t["assigned_admin"])
                    ? "<br><small style='opacity: 0.7;'>Bearbeiter: " . e($t["assigned_admin"]) . "</small>"
                    : "<br><small class='error' style='opacity: 0.7;'>[Unbearbeitet]</small>";
            }

            $html .= "<tr class='tr-hover$closed_ticket'>
                <td class='td-cursor td-center' data-on-click='navigate' data-url='support.php?tid={$t["id"]}'>$status</td>
                <td class='td-cursor' data-on-click='navigate' data-url='support.php?tid={$t["id"]}' style='width: 58%;'>
                    <div style='display: flex; justify-content: space-between; align-items: center; gap: 6px;'>
                        <div>" . e($t["subject"]) . "$assigned_info</div>
                        $unread_badge
                    </div>
                </td>
                " . (!$is_my_view ? "<td class='td-cursor' data-on-click='navigate' data-url='support.php?tid={$t["id"]}'>" . e($t["username"]) . "</td>" : "") . "
                <td class='td-cursor' data-on-click='navigate' data-url='support.php?tid={$t["id"]}'>am " . date("d.m.Y \u\m H:i:s", $t["updated_at"]) . "</td>
                <td class='td-center' style='width: 40px;'>
                    <img src='images/icons/icon_delete.png' class='ressource-icons' style='cursor: pointer;' 
                         data-on-click='confirmDeleteTicket' data-id='{$t["id"]}' title='Ticket löschen' alt=''>
                </td>
            </tr>";
        }

        $html .= "</table>";
        return $html;
    };

    // Helper Pagination
    $render_pagination_bar = function (int $total_pages, int $current_page, string $tab, string $page_param = "currentpage") {
        if ($total_pages <= 1) return "";

        $html = '<div class="pagination-container"><div class="pagination-bar">';
        if ($current_page > 1) {
            $html .= "<a href='support.php?tab=$tab&$page_param=1' class='page-link'>&laquo;</a>";
            $prev = $current_page - 1;
            $html .= "<a href='support.php?tab=$tab&$page_param=$prev' class='page-link'>&lsaquo;</a>";
        }

        $range = 2;
        for ($x = ($current_page - $range); $x <= ($current_page + $range); $x++) {
            if ($x > 0 && $x <= $total_pages) {
                $active = ($x == $current_page) ? "active" : "";
                $html .= "<a href='support.php?tab=$tab&$page_param=$x' class='page-link $active'>$x</a>";
            }
        }
        if ($current_page < $total_pages) {
            $next = $current_page + 1;
            $html .= "<a href='support.php?tab=$tab&$page_param=$next' class='page-link'>&rsaquo;</a>";
            $html .= "<a href='support.php?tab=$tab&$page_param=$total_pages' class='page-link'>&raquo;</a>";
        }
        $html .= '</div></div>';
        return $html;
    };

    // --- TAB: ALL TICKETS (Only for staff) ---
    if ($is_staff) {
        $all_page = max(1, get_int("currentpage", 1));
        $all_offset = ($all_page - 1) * $rows_per_page;
        $total_all = (int)$db_instance->execute_query("SELECT COUNT(*) FROM support_tickets")->fetch_row()[0];
        $total_all_pages = (int)ceil($total_all / $rows_per_page);

        $sql_all = "SELECT t.*, u.username, a.username AS assigned_admin,
                    (SELECT COUNT(*) FROM support_messages sm
                     WHERE sm.ticketid = t.id AND sm.hasread = 0 AND sm.senderid != ?
                       AND ((sm.is_admin_reply = 0 AND t.status = 1 AND t.userid != ?)
                         OR (sm.is_admin_reply = 1 AND t.userid = ?))) AS unread_count
                    FROM support_tickets t
                    JOIN users u ON t.userid = u.id
                    LEFT JOIN users a ON t.assigned_to = a.id
                    ORDER BY t.status DESC, unread_count DESC, t.updated_at DESC LIMIT ?, ?";
        $res_all = $db_instance->execute_query($sql_all, [$uid, $uid, $uid, $all_offset, $rows_per_page]);

        $view .= "<div id='support_tab_all' class='js-support-tab' style='display: " . ($active_tab === "all" ? "block" : "none") . ";'>";
        $view .= "<div class='title-border'>Eingegangene Support-Anfragen</div>";

        if ($res_all->num_rows > 0) {
            $view .= $render_tickets_table($res_all, false);
            $view .= $render_pagination_bar($total_all_pages, $all_page, "all", "currentpage");
        } else {
            $view .= "<span class='no-event'>Keine Tickets vorhanden.</span>";
        }

        $view .= "</div>";
    }

    // --- TAB: MY TICKETS ---
    $my_page = max(1, get_int("mypage", 1));
    $my_offset = ($my_page - 1) * $rows_per_page;
    $total_my = (int)$db_instance->execute_query("SELECT COUNT(*) FROM support_tickets WHERE userid = ?", [$uid])->fetch_row()[0];
    $total_my_pages = (int)ceil($total_my / $rows_per_page);

    $sql_my = "SELECT t.*, u.username,
                (SELECT COUNT(*) FROM support_messages sm
                 WHERE sm.ticketid = t.id AND sm.hasread = 0 AND sm.senderid != ? AND sm.is_admin_reply = 1) AS unread_count
                FROM support_tickets t
                JOIN users u ON t.userid = u.id
                WHERE t.userid = ?
                ORDER BY t.status DESC, unread_count DESC, t.updated_at DESC LIMIT ?, ?";
    $res_my = $db_instance->execute_query($sql_my, [$uid, $uid, $my_offset, $rows_per_page]);

    $view .= "<div id='support_tab_my' class='js-support-tab' style='display: " . ($active_tab === "my" ? "block" : "none") . ";'>";

    if (!$support->has_active_ticket($uid)) {
        $ticket_draft = consume_form_flash("support_ticket");
        $draft_subject = e((string)($ticket_draft["subject"] ?? ""));
        $draft_text = e((string)($ticket_draft["text"] ?? ""));

        $view .= "
            <div class='box-container' style='max-width: 600px; margin: 0 auto 20px auto;'>
                <div class='box-header'>Neues Support-Ticket</div>
                <div class='box-content box-content-bg' style='padding: 15px;'>
                    <form method='POST' action='support.php' id='newticketform'>
                        <input type='hidden' name='open_ticket' value='1'>
                        <input type='text' name='subject' placeholder='Betreff' value='$draft_subject' maxlength='" . MAX_SUPPORT_TICKET_SUBJECT_LENGTH . "' enterkeyhint='next' style='width: 100%; margin-bottom: 10px;' required>
                        <textarea name='text' id='new-ticket-text' rows='4' placeholder='Beschreibe dein Problem...' enterkeyhint='enter' required>$draft_text</textarea>
                        <input type='submit' value='Absenden' style='margin-top: 15px;'>
                    </form>
                </div>
            </div>";
    }

    if ($res_my->num_rows > 0) {
        $view .= $render_tickets_table($res_my, true);
        $view .= $render_pagination_bar($total_my_pages, $my_page, "my", "mypage");
    } else {
        $view .= "<span class='no-event'>Keine eigenen Tickets vorhanden.</span>";
    }
    $view .= "</div>";
}

include("layout/base.php");
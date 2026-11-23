<?php

class Support
{
    private mysqli $db;
    private User $user;

    public function __construct(User $user)
    {
        $this->db = Database::get_instance()->get_connection();
        $this->user = $user;
    }

    public function has_active_ticket(int $uid): bool
    {
        $res = $this->db->execute_query("SELECT id FROM support_tickets WHERE userid = ? AND status = 1", [$uid]);
        return $res->num_rows > 0;
    }

    public function create_ticket(string $subject, string $message): int
    {
        $now = time();
        $uid = $this->user->get_user_id();

        $this->db->execute_query(
            "INSERT INTO support_tickets (userid, subject, created_at, updated_at) VALUES (?, ?, ?, ?)",
            [$uid, $subject, $now, $now]
        );
        $tid = $this->db->insert_id;

        $this->add_message($tid, $message, false);

        return $tid;
    }

    public function add_message(int $tid, string $message, bool $is_admin): int
    {
        $uid = $this->user->get_user_id();
        $now = time();
        $clean_msg = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\s+/u', '/\p{Mn}/u'], ['', ' ', ' ', ''], $message) ?? '';
        $t_res = $this->db->execute_query("SELECT userid FROM support_tickets WHERE id = ?", [$tid]);
        $ticket_creator_id = (int)($t_res->fetch_column() ?? 0);
        $is_staff_reply = ($is_admin && $uid !== $ticket_creator_id);

        $this->db->execute_query(
            "INSERT INTO support_messages (ticketid, senderid, message, is_admin_reply, hasread, created_at) VALUES (?, ?, ?, ?, 0, ?)",
            [$tid, $uid, $clean_msg, (int)$is_staff_reply, $now]
        );
        $msg_id = (int)$this->db->insert_id;

        if ($is_staff_reply) {
            $sql = "UPDATE support_tickets SET updated_at = ?, assigned_to = IFNULL(assigned_to, ?) WHERE id = ?";
            $params = [$now, $uid, $tid];
        } else {
            $sql = "UPDATE support_tickets SET updated_at = ? WHERE id = ?";
            $params = [$now, $tid];
        }

        $this->db->execute_query($sql, $params);
        return $msg_id;
    }

    public function close_ticket(int $tid, ?string $reason = null): void
    {
        $this->db->execute_query(
            "UPDATE support_tickets SET status = 0, closed_at = ?, close_reason = ? WHERE id = ?",
            [time(), $reason, $tid]
        );
    }

    public function delete_ticket(int $tid): void
    {
        $res = $this->db->execute_query("SELECT userid FROM support_tickets WHERE id = ?", [$tid]);
        $ticket = $res->fetch_assoc();

        if ($this->user->is_admin() || ($ticket && $ticket["userid"] == $this->user->get_user_id())) {
            $this->db->execute_query("DELETE FROM support_tickets WHERE id = ?", [$tid]);
        }
    }

    public static function validate_message_text(string $text): ?string
    {
        if (strlen($text) > MAX_MESSAGE_LENGTH * 8) {
            return "Die Nachricht darf maximal " . MAX_MESSAGE_LENGTH . " Zeichen lang sein!";
        }

        $text_clean = trim($text);
        $line_breaks_count = substr_count($text_clean, "\n");

        if ($text_clean === "") {
            return "Bitte eine Nachricht eingeben!";
        } else if (mb_strlen($text_clean, "UTF-8") > MAX_MESSAGE_LENGTH) {
            return "Die Nachricht darf maximal " . MAX_MESSAGE_LENGTH . " Zeichen lang sein!";
        } else if ($line_breaks_count > MAX_LINE_BREAK_COUNT) {
            return "Dein Text darf maximal " . MAX_LINE_BREAK_COUNT . " Zeilenumbrüche beinhalten!";
        }
        return null;
    }

    public static function render_message_bubble(array $m, User $viewer_user, bool $viewer_is_staff): string
    {
        $msg_id = (int)$m["id"];
        $sender_id = (int)$m["senderid"];
        $is_me = ($sender_id === $viewer_user->get_user_id());
        $bubble_class = $is_me ? "receiver-bubble" : "sender-bubble";

        $sender_user = $is_me ? $viewer_user : new User($sender_id, $m["username"]);
        $avatar_url = e($sender_user->get_avatar());
        $support_label = !empty($m["is_admin_reply"]) ? " <small style='color: var(--link-color);'>(Support)</small>" : "";

        if ($is_me) {
            $sender_display = "Du$support_label";
        } else if ($viewer_is_staff) {
            $sender_display = "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=$sender_id' data-title='Spieler-Info'>" . e($m["username"]) . "</a>$support_label";
        } else {
            $sender_display = e($m["username"]) . $support_label;
        }

        $date_str = date(DATE_FORMAT_CHAT, (int)$m["created_at"]);
        $pop_id = "sup_av_" . $msg_id . "_" . mt_rand(100, 999);
        $body_html = Messages::wrap_emojis($m["message"]);

        return "
        <div class='$bubble_class' id='support-msg-$msg_id'>
            <div class='message-border'>
                <span class='msg-header-left'>
                    <div class='avatar-container popup' id='$pop_id'>
                        <img class='user-image' src='$avatar_url' alt='Avatar'>
                        <div id='{$pop_id}_box' class='popupbox avatar-popup'>
                            <img src='$avatar_url' style='width: 80px; height: 80px; border-radius: 5px;' alt='Avatar'>
                        </div>
                    </div>
                    <span>$sender_display <small class='msg-date'>$date_str</small></span>
                </span>
            </div>
            <div class='chat-text'>$body_html</div>
        </div>";
    }
}
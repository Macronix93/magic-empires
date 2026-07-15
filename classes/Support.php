<?php

class Support
{
    private mysqli $db;
    private User $user;

    public function __construct(mysqli $db, User $user)
    {
        $this->db = $db;
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

    public function add_message(int $tid, string $message, bool $is_admin): void
    {
        $uid = $this->user->get_user_id();
        $now = time();
        $clean_msg = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\s+/u', '/\p{Mn}/u'], ['', ' ', ' ', ''], $message);

        $this->db->execute_query(
            "INSERT INTO support_messages (ticketid, senderid, message, is_admin_reply, created_at) VALUES (?, ?, ?, ?, ?)",
            [$tid, $uid, $clean_msg, $is_admin ? 1 : 0, $now]
        );

        if ($is_admin) {
            $sql = "UPDATE support_tickets SET updated_at = ?, assigned_to = IFNULL(assigned_to, ?) WHERE id = ?";
            $params = [$now, $uid, $tid];
        } else {
            $sql = "UPDATE support_tickets SET updated_at = ? WHERE id = ?";
            $params = [$now, $tid];
        }

        $this->db->execute_query($sql, $params);
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
}
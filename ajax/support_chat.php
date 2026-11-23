<?php
require_once("../includes/ajax_core.php");

$uid = $user->get_user_id();
$is_staff = ($user->get_user_admin_level() > 0);
$support = new Support($user);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $tid = post_int("tid");
    $raw_text = post_str("text");

    $t_check = $db_instance->execute_query(
        "SELECT userid, status FROM support_tickets WHERE id = ?",
        [$tid]
    )->fetch_assoc();

    if (!$t_check) {
        send_json(["error" => "Das Ticket existiert nicht mehr oder wurde gelöscht."]);
    }

    if (!$is_staff && (int)$t_check["userid"] !== $uid) {
        send_json(["error" => "Zugriff verweigert!"]);
    }

    if (empty($t_check["status"])) {
        send_json(["error" => "Dieses Ticket ist bereits geschlossen!", "closed" => true]);
    }

    $val_error = Support::validate_message_text($raw_text);
    if ($val_error !== null) {
        send_json(["error" => $val_error]);
    }

    $remaining_seconds = Messages::check_rate_limit();
    if ($remaining_seconds > 0) {
        send_json([
            "error" => "Du schickst zu viele Nachrichten! Warte bitte:",
            "counter" => $remaining_seconds
        ]);
    }

    $msg = Messages::filter_chat_message(nl2br(e(trim($raw_text))));
    $is_acting_as_staff = ($is_staff && (int)$t_check["userid"] !== $uid);
    $new_id = $support->add_message($tid, $msg, $is_acting_as_staff);

    $row = $db_instance->execute_query(
        "SELECT m.*, u.username FROM support_messages m JOIN users u ON m.senderid = u.id WHERE m.id = ?",
        [$new_id]
    )->fetch_assoc();

    send_json([
        "success" => true,
        "lastId" => $new_id,
        "html" => $row ? Support::render_message_bubble($row, $user, $is_staff) : ""
    ]);
}

// --- GET: Live-Polling ---
$tid = get_int("tid");
$last_id = max(0, get_int("last_id"));
if ($tid <= 0) {
    send_json(["error" => "Ungültige Ticket-ID."]);
}

$ticket = $db_instance->execute_query(
    "SELECT userid, status, close_reason FROM support_tickets WHERE id = ?",
    [$tid]
)->fetch_assoc();

if (!$ticket) {
    send_json(["deleted" => true]);
}

if (!$is_staff && (int)$ticket["userid"] !== $uid) {
    send_json(["error" => "Zugriff verweigert!"]);
}

session_write_close();

$res = $db_instance->execute_query(
    "SELECT m.*, u.username
     FROM support_messages m
     JOIN users u ON m.senderid = u.id
     WHERE m.ticketid = ? AND m.id > ?
     ORDER BY m.id",
    [$tid, $last_id]
);

$html = "";
$new_last_id = $last_id;
$has_foreign = false;

while ($m = $res->fetch_assoc()) {
    $mid = (int)$m["id"];
    if ($mid > $new_last_id) $new_last_id = $mid;
    if ((int)$m["senderid"] !== $uid) {
        $has_foreign = true;
    }
    $html .= Support::render_message_bubble($m, $user, $is_staff);
}

if ($has_foreign) {
    $db_instance->execute_query(
        "UPDATE support_messages SET hasread = 1 WHERE ticketid = ? AND senderid != ? AND hasread = 0",
        [$tid, $uid]
    );
}

send_json([
    "html" => $html,
    "lastId" => $new_last_id,
    "status" => (int)$ticket["status"],
    "closeReason" => (string)($ticket["close_reason"] ?? "")
]);
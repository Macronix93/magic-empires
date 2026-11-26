<?php
require_once("../includes/ajax_core.php");
require_post();

$u_id = $user->get_user_id();
$is_admin = $user->is_admin();
$type = post_str("type", "private");

$allowed_types = ["private", "world", "guild", "server"];
if (!in_array($type, $allowed_types, true)) {
    send_json(["success" => false, "error" => "Ungültiger Chat-Typ!"]);
}

$msg_id = post_int("m_id");

if ($type === "server") {
    // Delete ALL server messages
    $is_delete_all = isset($_POST["delete_all"]) || isset($_POST["all"]);

    if ($is_delete_all) {
        $category = post_int("category", -1);
        $max_id = post_int("max_id");

        if ($max_id > 0) {
            if ($category === -1) {
                $db_instance->execute_query(
                    "DELETE FROM server_messages WHERE receiverid = ? AND id <= ?",
                    [$u_id, $max_id]
                );
            } else {
                $db_instance->execute_query(
                    "DELETE FROM server_messages WHERE receiverid = ? AND category = ? AND id <= ?",
                    [$u_id, $category, $max_id]
                );
            }
        }

        send_json(["success" => true]);
    }

    // Delete ONE server message
    $res = $db_instance->execute_query("SELECT receiverid FROM server_messages WHERE id = ?", [$msg_id]);
    $row = $res->fetch_assoc();

    if (!$row) {
        send_json(["success" => false, "error" => "Diese Nachricht existiert nicht!"]);
    }

    if ((int)$row["receiverid"] !== $u_id) {
        send_json(["success" => false, "error" => "Diese Nachricht kannst du nicht löschen!"]);
    }

    $db_instance->execute_query("DELETE FROM server_messages WHERE id = ?", [$msg_id]);

    send_json(["success" => true, "id" => $msg_id]);
}

if ($msg_id <= 0) {
    send_json(["success" => false, "error" => "Ungültige Nachrichten-ID"]);
}

if ($type === "world") {
    $res = $db_instance->execute_query("SELECT userid FROM world_chat WHERE id = ?", [$msg_id]);
    $row = $res->fetch_assoc();

    if (!$row) {
        send_json(["success" => false, "error" => "Diese Nachricht existiert nicht!"]);
    }

    if (!$is_admin && (int)$row["userid"] !== $u_id) {
        send_json(["success" => false, "error" => "Diese Nachricht kannst du nicht löschen!"]);
    }

    $db_instance->execute_query("UPDATE world_chat SET deleted = 1 WHERE id = ?", [$msg_id]);

    send_json(["success" => true, "id" => $msg_id]);
} else if ($type === "guild") {
    $guild_id = $user->get_user_guild_id();
    if ($guild_id <= 0) {
        send_json(["success" => false, "error" => "Keine Gilde"]);
    }

    $res = $db_instance->execute_query("SELECT userid, guild_id FROM guild_chat WHERE id = ?", [$msg_id]);
    $msg = $res->fetch_assoc();

    if (!$msg || (int)$msg["guild_id"] !== $guild_id) {
        send_json(["success" => false, "error" => "Nachricht nicht gefunden"]);
    }

    $is_owner = ((int)$msg["userid"] === $u_id);
    $my_rank = $user->get_guild_rank_id();
    $is_officer = ($my_rank > 0 && $my_rank <= GuildRanks::GUILD_OFFICER);

    if ($is_admin || $is_owner || $is_officer) {
        $db_instance->execute_query("UPDATE guild_chat SET deleted = 1 WHERE id = ?", [$msg_id]);

        send_json(["success" => true, "id" => $msg_id]);
    }

    send_json(["success" => false, "error" => "Keine Berechtigung"]);
} else {
    $res = $db_instance->execute_query("SELECT senderid FROM messages WHERE id = ?", [$msg_id]);
    $row = $res->fetch_assoc();

    if (!$row) {
        send_json(["success" => false, "error" => "Diese Nachricht existiert nicht!"]);
    }

    $is_owner = ((int)$row["senderid"] === $u_id);
    if (!$is_owner && !$is_admin) {
        send_json(["success" => false, "error" => "Diese Nachricht kannst du nicht löschen!"]);
    }

    $db_instance->execute_query("UPDATE messages SET deleted = 1 WHERE id = ?", [$msg_id]);

    send_json(["success" => true, "id" => $msg_id]);
}
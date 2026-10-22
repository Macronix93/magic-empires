<?php
require_once("../includes/core.php");

if (!isset($_SERVER["HTTP_X_REQUESTED_WITH"]) || $_SERVER["HTTP_X_REQUESTED_WITH"] !== "XMLHttpRequest") {
    change_location("messages.php");
    exit;
}

if (!$user->is_logged_in() || $user->get_user_id() <= 0) {
    echo json_encode(["success" => false, "error" => "Nicht eingeloggt"]);
    exit;
}

$u_id = $user->get_user_id();
$is_admin = $user->is_admin();
$type = $_GET["type"] ?? "private";

if ($type === "server") {
    // Delete ALL server messages
    if (isset($_GET["delete_all"]) || isset($_GET["all"])) {
        $category = (int)($_GET["category"] ?? -1);
        $max_id = (int)($_GET["max_id"] ?? 0);

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
        echo json_encode(["success" => true]);
        exit;
    }

    // Delete ONE server message
    $msg_id = (int)($_GET["m_id"] ?? 0);
    $res = $db_instance->execute_query("SELECT receiverid FROM server_messages WHERE id = ?", [$msg_id]);
    $row = $res->fetch_assoc();

    if (!$row) {
        echo json_encode(["success" => false, "error" => "Diese Nachricht existiert nicht!"]);
        exit;
    }

    if ((int)$row["receiverid"] !== $u_id) {
        echo json_encode(["success" => false, "error" => "Diese Nachricht kannst du nicht löschen!"]);
        exit;
    }

    $db_instance->execute_query("DELETE FROM server_messages WHERE id = ?", [$msg_id]);
    echo json_encode(["success" => true, "id" => $msg_id]);
    exit;
}

$msg_id = (int)($_GET["m_id"] ?? 0);

if ($msg_id <= 0) {
    echo json_encode(["success" => false, "error" => "Ungültige Nachrichten-ID"]);
    exit;
}

if ($type === "world") {
    $res = $db_instance->execute_query("SELECT userid FROM world_chat WHERE id = ?", [$msg_id]);
    $row = $res->fetch_assoc();

    if (!$row) {
        echo json_encode(["success" => false, "error" => "Diese Nachricht existiert nicht!"]);
        exit;
    }

    if (!$is_admin && (int)$row["userid"] !== $u_id) {
        echo json_encode(["success" => false, "error" => "Diese Nachricht kannst du nicht löschen!"]);
        exit;
    }

    $db_instance->execute_query("UPDATE world_chat SET deleted = 1 WHERE id = ?", [$msg_id]);
    echo json_encode(["success" => true, "id" => $msg_id]);
} else if ($type === "guild") {
    $guild_id = $user->get_user_guild_id();
    if ($guild_id <= 0) {
        echo json_encode(["success" => false, "error" => "Keine Gilde"]);
        exit;
    }

    $res = $db_instance->execute_query("SELECT userid, guild_id FROM guild_chat WHERE id = ?", [$msg_id]);
    $msg = $res->fetch_assoc();

    if (!$msg || (int)$msg["guild_id"] !== $guild_id) {
        echo json_encode(["success" => false, "error" => "Nachricht nicht gefunden"]);
        exit;
    }

    $is_owner = ((int)$msg["userid"] === $u_id);
    $my_rank = $user->get_guild_rank_id();
    $is_officer = ($my_rank > 0 && $my_rank <= GuildRanks::GUILD_OFFICER);

    if ($is_admin || $is_owner || $is_officer) {
        $db_instance->execute_query("UPDATE guild_chat SET deleted = 1 WHERE id = ?", [$msg_id]);
        echo json_encode(["success" => true, "id" => $msg_id]);
    } else {
        echo json_encode(["success" => false, "error" => "Keine Berechtigung"]);
    }
} else {
    $res = $db_instance->execute_query("SELECT senderid FROM messages WHERE id = ?", [$msg_id]);
    $row = $res->fetch_assoc();

    if (!$row) {
        echo json_encode(["success" => false, "error" => "Diese Nachricht existiert nicht!"]);
        exit;
    }

    $is_owner = ((int)$row["senderid"] === $u_id);
    if (!$is_owner && !$is_admin) {
        echo json_encode(["success" => false, "error" => "Diese Nachricht kannst du nicht löschen!"]);
        exit;
    }

    $db_instance->execute_query("UPDATE messages SET deleted = 1 WHERE id = ?", [$msg_id]);
    echo json_encode(["success" => true, "id" => $msg_id]);
}
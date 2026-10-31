<?php
require_once("../includes/core.php");

if (!isset($_SERVER["HTTP_X_REQUESTED_WITH"]) || $_SERVER["HTTP_X_REQUESTED_WITH"] !== "XMLHttpRequest") {
    change_location("messages.php");
    exit;
}

if (!$user->is_logged_in() || $user->get_user_id() <= 0) {
    echo json_encode(["error" => "redirect", "chatPartner" => "privmsgs"]);
    exit;
}

$type = $_GET["type"] ?? "private";
$allowed_types = ["private", "world", "guild"];
if (!in_array($type, $allowed_types, true)) {
    echo json_encode(["error" => "Ungültiger Chat-Typ"]);
    exit;
}

$u_id = $user->get_user_id();
$last_id = (int)($_GET["last_id"] ?? 0);
$oldest_id = (int)($_GET["oldest_id"] ?? 0);

if ($type === "guild" && isset($_GET["mark_all_read"])) {
    $guild_id = $user->get_user_guild_id();
    if ($guild_id > 0) {
        $max_msg = (int)($db_instance->execute_query(
            "SELECT MAX(id) FROM guild_chat WHERE guild_id = ?", [$guild_id]
        )->fetch_row()[0] ?? 0);
        if ($max_msg > 0) {
            $db_instance->execute_query("UPDATE users SET last_guild_chat_id = ? WHERE id = ?", [$max_msg, $u_id]);
        }
    }
    echo json_encode(["success" => true]);
    exit;
}

$session_token = $_SESSION["active_chat_token"] ?? "";
session_write_close();

$html = "";
$new_last_id = $last_id;
$deleted_ids = [];
$reaction_updates = [];
$extra_data = [];

$get_visible_ids = function (string $table, string $extra_where = "", array $extra_params = []) use ($db_instance, $oldest_id): array {
    $where = "deleted = 0";
    $params = [];
    if (!empty($extra_where)) {
        $where .= " AND ($extra_where)";
        $params = $extra_params;
    }
    if ($oldest_id > 0) {
        $where .= " AND id >= ?";
        $params[] = $oldest_id;
        $sql = "SELECT id FROM $table WHERE $where ORDER BY id DESC LIMIT 100";
    } else {
        $sql = "SELECT id FROM $table WHERE $where ORDER BY id DESC LIMIT 30";
    }
    $res = $db_instance->execute_query($sql, $params);
    return array_column($res->fetch_all(MYSQLI_ASSOC), "id");
};

if ($type === "world") {
    if ($last_id > 0) {
        $result = $db_instance->execute_query(
            "SELECT * FROM world_chat WHERE id > ? AND deleted = 0 ORDER BY id LIMIT ?",
            [$last_id, MAX_WORLD_CHAT_MESSAGES_SHOWN]
        );

        while ($row = $result->fetch_assoc()) {
            $new_last_id = (int)$row["id"];
            if ((int)$row["userid"] === $u_id) continue;

            $html .= Messages::render_chat_bubble(
                "world",
                (int)$row["id"],
                (int)$row["userid"],
                $row["username"],
                $row["message"],
                (int)$row["date"],
                $user
            );
        }

        $del_res = $db_instance->execute_query(
            "SELECT id FROM world_chat WHERE id > (? - 50) AND deleted = 1",
            [$last_id]
        );
        while ($del_row = $del_res->fetch_assoc()) {
            $deleted_ids[] = (int)$del_row["id"];
        }

        if ($new_last_id > $last_id) {
            $db_instance->execute_query("UPDATE users SET last_world_chat_id = ? WHERE id = ?", [$new_last_id, $u_id]);
        }
    }

    $visible_ids = $get_visible_ids("world_chat");
    $reaction_updates = Messages::get_batch_reaction_badges("world_chat", $visible_ids, $user);
    $extra_data["worldUnread"] = new Messages($user)->get_unread_world_count();

} else if ($type === "guild") {
    $guild_id = $user->get_user_guild_id();
    if ($guild_id <= 0) {
        echo json_encode(["error" => "redirect"]);
        exit;
    }

    if ($last_id > 0) {
        $result = $db_instance->execute_query(
            "SELECT * FROM guild_chat WHERE guild_id = ? AND id > ? AND deleted = 0 ORDER BY id LIMIT ?",
            [$guild_id, $last_id, MAX_GUILD_CHAT_MESSAGES_SHOWN]
        );

        while ($row = $result->fetch_assoc()) {
            $new_last_id = (int)$row["id"];
            if ((int)$row["userid"] === $u_id) continue;

            $html .= Messages::render_chat_bubble(
                "guild",
                (int)$row["id"],
                (int)$row["userid"],
                $row["username"],
                $row["message"],
                (int)$row["date"],
                $user
            );
        }

        $del_res = $db_instance->execute_query(
            "SELECT id FROM guild_chat WHERE guild_id = ? AND id > (? - 50) AND deleted = 1",
            [$guild_id, $last_id]
        );
        while ($del_row = $del_res->fetch_assoc()) {
            $deleted_ids[] = (int)$del_row["id"];
        }

        if ($new_last_id > $last_id) {
            $db_instance->execute_query("UPDATE users SET last_guild_chat_id = ? WHERE id = ?", [$new_last_id, $u_id]);
        }
    }

    $visible_ids = $get_visible_ids("guild_chat", "guild_id = ?", [$guild_id]);
    $reaction_updates = Messages::get_batch_reaction_badges("guild_chat", $visible_ids, $user);
    $extra_data["guildUnread"] = new Messages($user)->get_unread_guild_count();
} else {
    $partner_id = (int)($_GET["s"] ?? 0);
    if ($partner_id <= 0) {
        echo json_encode([
            "html" => "",
            "lastId" => $last_id,
            "messagesToDelete" => [],
            "reactionUpdates" => [],
            "error" => ""
        ]);
        exit;
    }

    $result = $db_instance->execute_query(
        "SELECT * FROM messages WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) AND id > ? AND deleted = 0 ORDER BY id",
        [$partner_id, $u_id, $u_id, $partner_id, $last_id]
    );

    while ($row = $result->fetch_assoc()) {
        $new_last_id = (int)$row["id"];
        $is_me = ((int)$row["senderid"] === $u_id);

        $html .= Messages::render_chat_bubble(
            "private",
            (int)$row["id"],
            (int)$row["senderid"],
            $row["sender"],
            $row["message"],
            (int)$row["date"],
            $user
        );

        if (!$is_me) {
            $db_instance->execute_query("UPDATE messages SET hasread = 1 WHERE id = ?", [$row["id"]]);
        }
    }

    $del_res = $db_instance->execute_query(
        "SELECT id FROM messages WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) AND id > (? - 50) AND deleted = 1",
        [$partner_id, $u_id, $u_id, $partner_id, $last_id]
    );
    while ($del_row = $del_res->fetch_assoc()) {
        $deleted_ids[] = (int)$del_row["id"];
    }

    $visible_ids = $get_visible_ids("messages", "(senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)", [$partner_id, $u_id, $u_id, $partner_id]);
    $reaction_updates = Messages::get_batch_reaction_badges("chat", $visible_ids, $user);
    $extra_data["chatPartner"] = $partner_id;
}

echo json_encode(array_merge([
    "html" => $html,
    "lastId" => $new_last_id,
    "messagesToDelete" => $deleted_ids,
    "reactionUpdates" => $reaction_updates,
    "error" => ""
], $extra_data));
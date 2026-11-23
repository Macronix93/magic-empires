<?php
require_once("../includes/ajax_core.php");

$oldest_id = get_int("oldest_id");
$u_id = $user->get_user_id();

if ($oldest_id <= 0) {
    echo json_encode(["count" => 0, "hasMore" => false, "html" => ""]);
    exit;
}

$type = get_str("type", "private");

$allowed_types = ["private", "world", "guild", "server"];
if (!in_array($type, $allowed_types, true)) {
    echo json_encode(["success" => false, "error" => "Ungültiger Chat-Typ!"]);
    exit;
}

if ($type === "server") {
    $category = get_int("category", -1);
    $limit = SHOW_MESSAGES_LIMIT;
    $messages_obj = new Messages($user);
    $history = $messages_obj->get_server_history_paged($oldest_id, $category, $limit + 1);

    $has_more = (count($history) > $limit);
    if ($has_more) array_pop($history);

    $html = "";
    foreach ($history as $row) {
        $share_btn = "";

        if (!empty($row["data_json"])) {
            $data = json_decode($row["data_json"], true);
            $data["msg_date"] = (int)$row["date"];

            $content = Messages::render_message_template($data);

            $is_combat_cat = in_array((int)$row["category"], [MessageCategories::CATEGORY_PVP, MessageCategories::CATEGORY_PVE], true);

            if ($is_combat_cat && Messages::is_report_shareable($data["template"] ?? null)) {
                $share_btn = Messages::render_share_button((int)$row["id"]);
            }
        } else {
            $content = $row["message"];
        }

        $date_str = date("d.m.Y H:i:s", $row["date"]);
        $html .= "
        <div class='server-bubble' data-category='{$row["category"]}' id='msg-{$row["id"]}'>
            <div class='message-border'>
                Am $date_str
                <span style='display: flex; gap: 8px; align-items: center;'>
                    $share_btn
                    <img src='images/icons/icon_delete.png' 
                        class='ressource-icons' 
                        data-on-click='deleteServerMsg' 
                        data-id='{$row["id"]}' 
                        style='cursor: pointer;' alt=''>
                </span>
            </div>
            $content
        </div>";
    }

    echo json_encode([
        "html" => $html,
        "hasMore" => $has_more,
        "count" => count($history)
    ]);
    exit;
}


$rows = [];
$has_more = false;
$reaction_type = "chat";

if ($type === "world") {
    $limit = MAX_WORLD_CHAT_MESSAGES_SHOWN;
    $query = "SELECT * FROM world_chat WHERE id < ? AND deleted = 0 ORDER BY id DESC LIMIT ?";
    $result = $db_instance->execute_query($query, [$oldest_id, $limit + 1]);
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    $has_more = (count($rows) > $limit);
    if ($has_more) array_pop($rows);
    $rows = array_reverse($rows);

    $reaction_type = "world_chat";
} else if ($type === "guild") {
    $guild_id = $user->get_user_guild_id();
    if ($guild_id <= 0) {
        echo json_encode(["count" => 0, "hasMore" => false, "html" => ""]);
        exit;
    }

    $limit = MAX_GUILD_CHAT_MESSAGES_SHOWN;
    $query = "SELECT * FROM guild_chat WHERE guild_id = ? AND id < ? AND deleted = 0 ORDER BY id DESC LIMIT ?";
    $result = $db_instance->execute_query($query, [$guild_id, $oldest_id, $limit + 1]);
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    $has_more = (count($rows) > $limit);
    if ($has_more) array_pop($rows);
    $rows = array_reverse($rows);

    $reaction_type = "guild_chat";
} else {
    $partner_id = get_int("s");
    if ($partner_id <= 0) {
        echo json_encode(["count" => 0, "hasMore" => false, "html" => ""]);
        exit;
    }

    $limit = SHOW_MESSAGES_LIMIT;
    $messages_obj = new Messages($user);
    $history = $messages_obj->get_chat_history_paged($partner_id, $oldest_id, $limit + 1);

    $has_more = (count($history) > $limit);
    if ($has_more) array_shift($history);
    $rows = $history;

    $reaction_type = "chat";
}

// Render chat bubbles
$row_ids = array_column($rows, "id");
$reactions_map = Messages::fetch_reactions_for_ids($reaction_type, $row_ids, $u_id);

$html = "";
foreach ($rows as $row) {
    $msg_id = (int)$row["id"];
    $sender_id = (int)($row["senderid"] ?? $row["userid"]);
    $sender_name = $row["sender"] ?? $row["username"];

    $html .= Messages::render_chat_bubble(
        $type, $msg_id, $sender_id, $sender_name,
        $row["message"], (int)$row["date"], $user, null,
        $reactions_map[$msg_id] ?? []
    );
}

send_json([
    "html" => $html,
    "count" => count($rows),
    "hasMore" => $has_more
]);
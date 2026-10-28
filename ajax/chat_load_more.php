<?php
require_once("../includes/core.php");

if (!isset($_SERVER["HTTP_X_REQUESTED_WITH"]) || $_SERVER["HTTP_X_REQUESTED_WITH"] !== "XMLHttpRequest") {
    change_location("messages.php");
    exit;
}

if (!$user->is_logged_in() || $user->get_user_id() <= 0) {
    echo json_encode(["count" => 0, "hasMore" => false, "html" => ""]);
    exit;
}

$oldest_id = (int)($_GET["oldest_id"] ?? 0);
$u_id = $user->get_user_id();

if ($oldest_id <= 0) {
    echo json_encode(["count" => 0, "hasMore" => false, "html" => ""]);
    exit;
}

$type = $_GET["type"] ?? "private";

$allowed_types = ["private", "world", "guild", "server"];
if (!in_array($type, $allowed_types, true)) {
    echo json_encode(["success" => false, "error" => "Ungültiger Chat-Typ!"]);
    exit;
}

if ($type === "server") {
    $category = (int)($_GET["category"] ?? -1);
    $limit = SHOW_MESSAGES_LIMIT;
    $messages_obj = new Messages($user);
    $history = $messages_obj->get_server_history_paged($oldest_id, $category, $limit + 1);

    $has_more = (count($history) > $limit);
    if ($has_more) array_pop($history);

    $html = "";
    foreach ($history as $row) {
        if (!empty($row["data_json"])) {
            $data = json_decode($row["data_json"], true);
            $data["msg_date"] = (int)$row["date"];
            $content = $messages_obj->render_message_template($data);
        } else {
            $content = $row["message"];
        }

        $date_str = date("d.m.Y H:i:s", $row["date"]);
        $html .= "
        <div class='server-bubble' data-category='{$row["category"]}' id='msg-{$row["id"]}'>
            <div class='message-border'>
                Am $date_str
                <img src='images/icons/icon_delete.png' 
                     class='ressource-icons' 
                     data-on-click='deleteServerMsg' 
                     data-id='{$row["id"]}' 
                     style='cursor: pointer;' alt=''>
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
$recent_query = "";
$recent_params = [];

if ($type === "world") {
    $limit = MAX_WORLD_CHAT_MESSAGES_SHOWN;
    $query = "SELECT * FROM world_chat WHERE id < ? AND deleted = 0 ORDER BY id DESC LIMIT ?";
    $result = $db_instance->execute_query($query, [$oldest_id, $limit + 1]);
    $rows = $result->fetch_all(MYSQLI_ASSOC);

    $has_more = (count($rows) > $limit);
    if ($has_more) array_pop($rows);
    $rows = array_reverse($rows);

    $reaction_type = "world_chat";
    $recent_query = "SELECT id FROM world_chat ORDER BY id DESC LIMIT ?";
    $recent_params = [MAX_WORLD_CHAT_MESSAGES_SHOWN];
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
    $recent_query = "SELECT id FROM guild_chat WHERE guild_id = ? ORDER BY id DESC LIMIT ?";
    $recent_params = [$guild_id, MAX_GUILD_CHAT_MESSAGES_SHOWN];
} else {
    $partner_id = (int)($_GET["s"] ?? 0);
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
    $recent_query = "SELECT id FROM messages WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) ORDER BY id DESC LIMIT ?";
    $recent_params = [$partner_id, $u_id, $u_id, $partner_id, SHOW_MESSAGES_LIMIT];
}

// Render chat bubbles
$html = "";
foreach ($rows as $row) {
    $sender_id = (int)($row["senderid"] ?? $row["userid"]);
    $sender_name = $row["sender"] ?? $row["username"];

    $html .= Messages::render_chat_bubble(
        $type,
        (int)$row["id"],
        $sender_id,
        $sender_name,
        $row["message"],
        (int)$row["date"],
        $user
    );
}

// Update reactions
$reaction_updates = [];
if (!empty($recent_query)) {
    $res_recent = $db_instance->execute_query($recent_query, $recent_params);
    while ($r = $res_recent->fetch_assoc()) {
        $reaction_updates[$r["id"]] = Messages::render_reactions_bar($reaction_type, (int)$r["id"], $user, "badges_only");
    }
}

echo json_encode([
    "html" => $html,
    "count" => count($rows),
    "hasMore" => $has_more,
    "reactionUpdates" => $reaction_updates
]);
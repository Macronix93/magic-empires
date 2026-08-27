<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $type = $_GET["type"] ?? "private";
    $messages = new Messages($db_instance, $user);
    $html = "";

    if ($type === "private") {
        $sender_id = (int)$_GET["s"];
        $token = $_GET["token"] ?? "";
        $chat_partner = $_GET["partner_name"] ?? "Unbekannt";

        if ($token !== $_SESSION["active_chat_token"]) {
            echo json_encode(["error" => "redirect"]);
            exit;
        }

        $html = $messages->get_private_history_html($sender_id, $chat_partner);
    } else if ($type === "world") {
        $html = $messages->get_world_history_html();
    } else {
        return;
    }

    // Get last msg id
    preg_match_all('/id=["\'](?:world-)?msg-(\d+)["\']/', $html, $matches);
    $last_id = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;

    $unread_total = $user->get_unread_messages();
    $unread_world = $messages->get_unread_world_count();

    echo json_encode([
        "html" => $html,
        "lastId" => $last_id,
        "unreadCount" => $unread_total,
        "worldUnread" => $unread_world
    ]);
}
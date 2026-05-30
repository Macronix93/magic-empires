<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    if (isset($_GET["s"]) && isset($_GET["oldest_id"])) {
        $partner_id = (int)$_GET["s"];
        $oldest_id = (int)$_GET["oldest_id"];
        $limit = SHOW_MESSAGES_LIMIT;

        $res = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$partner_id]);
        $p_data = $res->fetch_assoc();
        $partner_name = $p_data["username"] ?? "Unbekannt";

        $messages_obj = new Messages($db_instance, $user);
        $history = $messages_obj->get_chat_history_paged($partner_id, $oldest_id, $limit + 1);

        $has_more = false;

        if (count($history) > $limit) {
            $has_more = true;
            array_shift($history);
        }

        $html = "";
        $my_avatar = $user->get_avatar();
        $partner = new User($partner_id, $partner_name);
        $partner_avatar = $partner->get_avatar();

        foreach ($history as $row) {
            $is_me = ($row["senderid"] == $user->get_user_id());
            $class = $is_me ? "receiver-bubble" : "sender-bubble";
            $img = $is_me ? $my_avatar : $partner_avatar;
            $name = $is_me ? "Du" : $row["sender"];
            $del_btn = $is_me ? "<img src='images/icons/icon_delete.png' class='ressource-icons' onclick='deleteChatMessage(\"{$row["id"]}\")' style='cursor:pointer;'>" : "";

            $html .= "<div class='$class' id='msg-{$row["id"]}'>
                        <div class='image-and-user message-border'>
                            <img class='user-image' src='$img' alt=''> $name am " . date("d.m.Y H:i", $row["date"]) . " $del_btn
                        </div>
                        {$row["message"]}
                      </div>";
        }

        echo json_encode([
            "html" => $html,
            "count" => count($history),
            "hasMore" => $has_more
        ]);
    }
} else {
    change_location("messages.php");
}

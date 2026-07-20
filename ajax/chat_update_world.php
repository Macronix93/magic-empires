<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $last_id = (int)($_GET["last_id"] ?? 0);
    $u_id = $user->get_user_id();
    $is_admin = $user->is_admin();
    $html = "";
    $deleted_ids = [];

    $query = "SELECT * FROM world_chat WHERE id > ? AND deleted = 0 ORDER BY id LIMIT ?";
    $result = $db_instance->execute_query($query, [$last_id, MAX_WORLD_CHAT_MESSAGES_SHOWN]);

    $new_last_id = $last_id;
    $use_filter = ($_SESSION["chat_filter"] ?? 1);

    while ($row = $result->fetch_assoc()) {
        $new_last_id = $row["id"];

        if ((int)$row["userid"] === $u_id) continue;

        $is_me = false;
        $del_icon = $is_admin ? "<img src='images/icons/icon_delete.png' class='ressource-icons' 
                                        data-on-click='deleteWorldChatMsg' data-id='{$row["id"]}' style='cursor: pointer;' alt=''>" : "";
        $class = "sender-bubble";

        $display_message = ($use_filter == 1) ? filter_chat_message($row["message"]) : $row["message"];
        $display_message = wrap_emojis($display_message);

        $sender = new User($row["userid"], $row["username"]);
        $avatar = $sender->get_avatar();

        $html .= "
            <div class='$class' id='world-msg-{$row["id"]}'>
                <div class='message-border'>
                    <span class='msg-header-left'>
                        <img class='user-image' src='$avatar' alt=''> 
                        <span>" . e($row["username"]) . " am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "</span>
                    </span>
                    $del_icon
                </div>
                $display_message
            </div>";
    }

    $del_query = "SELECT id FROM world_chat WHERE id > (? - 50) AND deleted = 1";
    $del_res = $db_instance->execute_query($del_query, [$last_id]);
    while ($del_row = $del_res->fetch_assoc()) {
        $deleted_ids[] = (int)$del_row["id"];
    }

    if ($new_last_id > $last_id) {
        $db_instance->execute_query(
            "UPDATE users SET last_world_chat_id = ? WHERE id = ?",
            [$new_last_id, $u_id]
        );
    }

    echo json_encode([
        "html" => $html,
        "lastId" => $new_last_id,
        "messagesToDelete" => $deleted_ids
    ]);
}
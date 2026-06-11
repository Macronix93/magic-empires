<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    if (isset($_GET["oldest_id"])) {
        $oldest_id = (int)$_GET["oldest_id"];
        $category = $_GET["category"] ?? 'Alle';
        $limit = SHOW_MESSAGES_LIMIT;

        $messages_obj = new Messages($db_instance, $user);
        $history = $messages_obj->get_server_history_paged($oldest_id, $category, $limit + 1);

        $has_more = false;

        if (count($history) > $limit) {
            $has_more = true;
            array_pop($history);
        }

        $html = "";
        foreach ($history as $row) {
            $html .= "
            <div class='server-bubble' data-category='{$row["category"]}' id='msg-{$row["id"]}'>
                <div class='message-border'>
                    Am " . date("d.m.Y H:i:s", $row["date"]) . "
                    <img src='images/icons/icon_delete.png' 
                         class='ressource-icons' 
                         data-on-click='deleteServerMsg' 
                         data-id='" . e($row["id"]) . "' 
                         style='cursor: pointer;' alt=''>
                </div>
                {$row["message"]}
            </div>";
        }

        echo json_encode([
            "html" => $html,
            "hasMore" => $has_more,
            "count" => count($history)
        ]);
    }
} else {
    change_location("messages.php");
}

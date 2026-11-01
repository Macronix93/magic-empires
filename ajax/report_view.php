<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $user->check_user_login();
    $id = (int)($_GET["id"] ?? 0);
    $hash = $_GET["h"] ?? '';

    $expected = substr(hash_hmac("sha256", (string)$id, AVATAR_SALT), 0, 8);
    if (!hash_equals($expected, $hash)) {
        echo show_error_box("Ungültiger oder abgelaufener Link.");
        exit;
    }

    $res = $db_instance->execute_query(
            "SELECT data_json, message, category, date FROM server_messages WHERE id = ? AND category = ?",
            [$id, MessageCategories::CATEGORY_WAR]
    );
    $row = $res->fetch_assoc();

    if (!$row) {
        echo show_error_box("Dieser Kampfbericht existiert nicht mehr.");
        exit;
    }

    $messages = new Messages($user);
    if (!empty($row["data_json"])) {
        $data = json_decode($row["data_json"], true);
        
        if (!Messages::is_report_shareable($data["template"] ?? null)) {
            echo show_error_box("Dieser Bericht kann nicht angezeigt werden.");
            exit;
        }

        $data["msg_date"] = (int)$row["date"];

        $content = $messages->render_message_template($data);
    } else {
        $content = $row["message"];
    }

    $date_str = date("d.m.Y \u\m H:i:s", (int)$row["date"]);
    ?>
    <div id="modal-title-data" data-title="Geteilter Kampfbericht" data-width="850px" style="display: none;"></div>
    <div class="shared-report-modal">
        <div class="server-bubble" style="margin: 0; box-sizing: border-box; text-align: left;">
            <div class="message-border">
                Am <?= $date_str ?>
            </div>
            <?= $content ?>
        </div>
    </div>
    <?php
}
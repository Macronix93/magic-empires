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

    $current_uid = $user->get_user_id();

    $res = $db_instance->execute_query(
            "SELECT shared_by_user_id, data_json, created_at FROM shared_battle_reports WHERE id = ? AND expires_at > ?",
            [$id, time()]
    );
    $row = $res->fetch_assoc();

    if ($row) {
        $data = json_decode($row["data_json"] ?? "{}", true);
        $date = (int)$row["created_at"];
        $is_owner = ($current_uid === (int)$row["shared_by_user_id"]);
    } else {
        $res_legacy = $db_instance->execute_query(
                "SELECT receiverid, data_json, message, date FROM server_messages WHERE id = ? AND category IN (?, ?)",
                [$id, MessageCategories::CATEGORY_PVP, MessageCategories::CATEGORY_PVE]
        );
        $row_legacy = $res_legacy->fetch_assoc();

        if (!$row_legacy) {
            echo show_error_box("Dieser geteilte Kampfbericht existiert nicht mehr.");
            exit;
        }

        $data = json_decode($row_legacy["data_json"] ?? "{}", true);
        $date = (int)$row_legacy["date"];
        $is_owner = ($current_uid === (int)$row_legacy["receiverid"]);
    }

    $messages = new Messages($user);

    if (!empty($data)) {
        $data["msg_date"] = $date;
        $data["is_shared_view"] = !$is_owner;

        $content = $messages->render_message_template($data);
    } else {
        $content = $row_legacy["message"] ?? "Bericht nicht lesbar.";
    }

    $date_str = date("d.m.Y H:i:s", $date);
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
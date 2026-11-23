<?php
require_once("../includes/ajax_core.php");

$id = get_int("id");
$hash = get_str("h");

$expected = substr(hash_hmac("sha256", (string)$id, APP_SECRET), 0, 8);
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

if (!$row) {
    echo show_error_box("Dieser geteilte Kampfbericht ist abgelaufen oder existiert nicht mehr.");
    exit;
}

$data = json_decode($row["data_json"] ?? "{}", true);
$date = (int)$row["created_at"];
$is_owner = ($current_uid === (int)$row["shared_by_user_id"]);

if (!empty($data) && is_array($data)) {
    $data["msg_date"] = $date;
    $data["is_shared_view"] = !$is_owner;

    $content = Messages::render_message_template($data);
} else {
    $content = "Bericht nicht lesbar.";
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
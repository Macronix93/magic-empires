<?php
require_once("../includes/ajax_core.php");

$msg_id = get_int("id");
$uid = $user->get_user_id();

$res = $db_instance->execute_query(
    "SELECT id, data_json FROM server_messages WHERE id = ? AND receiverid = ? AND category IN (?, ?)",
    [$msg_id, $uid, MessageCategories::CATEGORY_PVP, MessageCategories::CATEGORY_PVE]
);
$row = $res->fetch_assoc();

if (!$row) {
    echo show_error_box("Kampfbericht nicht gefunden oder keine Berechtigung.");
    exit;
}

$data = json_decode($row["data_json"] ?? "{}", true);
$label = Messages::get_report_share_label($data);
if ($label === null) {
    echo show_error_box("Diese Art von Bericht kann nicht geteilt werden.");
    exit;
}

$my_gid = $user->get_user_guild_id();

echo Messages::render_share_modal_form(
    "<b>⚔️ " . e($label) . "</b>",
    ["report_msg_id" => $msg_id],
    $user->get_user_guild_id(),
    true
);
<?php
require_once("../includes/ajax_core.php");

$msg_id = (int)($_GET["id"] ?? 0);
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
?>
<div style="padding: 10px; text-align: left; max-width: 440px; margin: 0 auto;">
    <div style="text-align: center; margin-bottom: 15px;">
        <span style="font-size: 16px; color: var(--link-color);"><b>⚔️ <?= e($label) ?></b></span>
    </div>
    <div id="share-coords-error" style="display: none; margin-bottom: 10px;"></div>
    <form id="form-share-coords" method="POST" action="ajax/share_coords_send.php"
          data-on-submit="submitShareCoords">
        <input type="hidden" name="report_msg_id" value="<?= $msg_id ?>">
        <label style="display: block; margin-bottom: 6px;"><b>Wo teilen?</b></label>
        <div style="display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; font-size: 14px;">
            <label style="display: flex; align-items: center; gap: 6px;">
                <input type="radio" name="share_target" value="world" checked data-on-change="toggleShareTarget">
                <span>Welt-Chat</span>
            </label>
            <?php if ($my_gid > 0): ?>
                <label style="display: flex; align-items: center; gap: 6px;">
                    <input type="radio" name="share_target" value="guild" data-on-change="toggleShareTarget">
                    <span>Gilden-Chat</span>
                </label>
            <?php endif; ?>
            <label style="display: flex; align-items: center; gap: 6px;">
                <input type="radio" name="share_target" value="private" data-on-change="toggleShareTarget">
                <span>Privatnachricht</span>
            </label>
        </div>
        <div id="share-recipient-wrap" style="display: none; margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 4px; font-size: 14px;"><b>Empfänger
                    (Spielername):</b></label>
            <div style="display: flex; gap: 6px; align-items: center;">
                <label for="share-recipient-input"></label><input type="text" name="recipient"
                                                                  id="share-recipient-input"
                                                                  placeholder="Name eingeben..."
                                                                  maxlength="<?= MAX_USERNAME_LENGTH ?>"
                                                                  style="flex: 1;">
                <button type="button" data-on-click="openOverlay" data-url="userlist.php" data-title="Spielerliste"
                        style="white-space: nowrap; padding: 5px 10px;">
                    Spielerliste
                </button>
            </div>
        </div>
        <div style="display: flex; justify-content: center; gap: 10px;">
            <input type="submit" id="btn-share-submit" value="In Chat einfügen" style="padding: 6px 25px;">
            <button type="button" data-on-click="closeOverlay">Abbrechen</button>
        </div>
    </form>
</div>
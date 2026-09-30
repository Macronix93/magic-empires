<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    check_user_login($user);

    $x = (int)($_GET["x"] ?? 0);
    $y = (int)($_GET["y"] ?? 0);

    if ($x < 1 || $x > MAX_X || $y < 1 || $y > MAX_Y) {
        echo show_error_box("Ungültige Koordinaten.");
        exit;
    }

    $query = "
        SELECT m.kingdomid, ft.fieldname,
               COALESCE(k.kingdomname, ak.kingdom_name, '') as kingdomname,
               COALESCE(k.username, ak.kingdom_name, u_mn.username, '') as username,
               mc.level as monster_lvl,
               mn.level as mine_lvl
        FROM map m
        JOIN field_types ft ON m.fieldtype = ft.fieldid
        LEFT JOIN kingdoms k ON m.kingdomid = k.id
        LEFT JOIN monster_camps mc ON m.mapx = mc.mapx AND m.mapy = mc.mapy
        LEFT JOIN abandoned_kingdoms ak ON m.mapx = ak.mapx AND m.mapy = ak.mapy
        LEFT JOIN mines mn ON m.mapx = mn.mapx AND m.mapy = mn.mapy
        LEFT JOIN users u_mn ON mn.claimed_user_id = u_mn.id
        WHERE m.mapx = ? AND m.mapy = ?
    ";
    $tile = $db_instance->execute_query($query, [$x, $y])->fetch_assoc();

    $fieldName = $tile["fieldname"] ?? "Unbekannt";
    $kid = (int)($tile["kingdomid"] ?? -1);

    $location_desc = "Freies Land ($fieldName)";
    if ($kid > 0) {
        $location_desc = e($tile["kingdomname"]) . " (" . e($tile["username"]) . ")";
    } else if ($kid === MapFieldTypes::MAP_FIELD_RESOURCE_TILE) {
        $location_desc = "Verlassenes Vorratslager";
    } else if ($kid === MapFieldTypes::MAP_FIELD_MONSTER_CAMP) {
        $location_desc = "Monstercamp (Stufe " . (int)$tile["monster_lvl"] . ")";
    } else if ($kid === MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM) {
        $location_desc = "Ruinen von " . e($tile["kingdomname"]);
    } else if ($kid === MapFieldTypes::MAP_FIELD_MINE) {
        $location_desc = "Erzmine (Stufe " . (int)$tile["mine_lvl"] . ")";
    } else if ($kid === MapFieldTypes::MAP_FIELD_WORLD_EVENT) {
        $location_desc = "Auge des Sturms";
    }

    $my_gid = $user->get_user_guild_id();
    $preset_msg = "$x:$y - $location_desc";
    ?>
    <div style="padding: 10px; text-align: left; max-width: 440px; margin: 0 auto;">
        <div style="text-align: center; margin-bottom: 15px;">
            <span style="font-size: 16px; color: var(--link-color);"><b>(<?= $x ?>:<?= $y ?>)</b> – <?= $location_desc ?></span>
        </div>

        <div id="share-coords-error" style="display: none; margin-bottom: 10px;"></div>

        <form id="form-share-coords" method="POST" action="ajax/share_coords_send.php"
              data-on-submit="submitShareCoords">
            <input type="hidden" name="x" value="<?= $x ?>">
            <input type="hidden" name="y" value="<?= $y ?>">
            <input type="hidden" name="message" value="<?= e($preset_msg) ?>">

            <label style="display: block; margin-bottom: 6px;"><b>Wo teilen?</b></label>
            <div style="display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; font-size: 14px;">
                <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <input type="radio" name="share_target" value="world" checked data-on-change="toggleShareTarget">
                    <span>Welt-Chat</span>
                </label>
                <?php if ($my_gid > 0): ?>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <input type="radio" name="share_target" value="guild"
                               data-on-change="toggleShareTarget">
                        <span>Gilden-Chat</span>
                    </label>
                <?php endif ?>
                <label style="cursor: pointer; display: flex; align-items: center; gap: 6px;">
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
                                                                      maxlength="24" style="flex: 1;">
                    <button type="button"
                            data-on-click="openSecondaryOverlay"
                            data-url="userlist.php"
                            data-title="Spielerliste"
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
    <?php
}
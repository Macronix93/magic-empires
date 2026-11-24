<?php
require_once("../includes/ajax_core.php");

[$x, $y] = Map::require_valid_coordinates();

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

$field_name = $tile["fieldname"] ?? "Unbekannt";
$kid = (int)($tile["kingdomid"] ?? -1);

$location_desc = "Freies Land ($field_name)";
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

echo Messages::render_share_modal_form(
        "<b>($x:$y)</b> – $location_desc",
        ["x" => $x, "y" => $y, "message" => "$x:$y - $location_desc"],
        $user->get_user_guild_id()
);
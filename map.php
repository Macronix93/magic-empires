<?php
require_once("includes/core.php");

check_user_login($user);

$current_k_id = $user->get_current_kingdom();
$kingdom = new Kingdom($current_k_id);
$map = new Map($user);

// Load biomes
$res_fts = $db_instance->query("SELECT * FROM field_types ORDER BY fieldid");
$field_types_data = [];
while ($ft = $res_fts->fetch_assoc()) {
    $field_types_data[(int)$ft["fieldid"]] = $ft;
}

$biomes_to_show = [5, 2, 3, 4, 1];
$biome_html = "";

foreach ($biomes_to_show as $fid) {
    if (!isset($field_types_data[$fid])) continue;
    $ft = $field_types_data[$fid];
    $color = $map->get_field_type_color($fid);

    $f_yield = round(BASE_FOOD_GAIN * $ft["foodrate"]);
    $w_yield = round(BASE_WOOD_GAIN * $ft["woodrate"]);
    $s_yield = round(BASE_STONE_GAIN * $ft["stonerate"]);
    $g_yield = round(BASE_GOLD_GAIN * $ft["goldrate"]);
    $traversal = convert_sec_to_str($ft["traversaltime"]);

    $pop_id = "pop_biome_" . $fid;

    $biome_html .= "
    <div class='map-legend-item popup' id='$pop_id'>
        <span class='map-legend-inner-item' style='background-color: $color;'></span> " . e($ft["fieldname"]) . "
        <div id='{$pop_id}_box' class='popupbox' style='text-align: left; min-width: 170px;'>
            <b>" . e($ft["fieldname"]) . "</b><br>
            <small style='opacity: 0.8;'>Marschzeit: $traversal / Feld</small>
            <hr style='margin: 6px 0; border: 0; border-top: 1px solid rgba(212, 175, 55, 0.4);'>
            <div style='display: flex; align-items: center; gap: 6px; margin-bottom: 2px;'>
                " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " <span>+$f_yield / Std.</span>
            </div>
            <div style='display: flex; align-items: center; gap: 6px; margin-bottom: 2px;'>
                " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " <span>+$w_yield / Std.</span>
            </div>
            <div style='display: flex; align-items: center; gap: 6px; margin-bottom: 2px;'>
                " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " <span>+$s_yield / Std.</span>
            </div>
            <div style='display: flex; align-items: center; gap: 6px;'>
                " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " <span>+$g_yield / Std.</span>
            </div>
        </div>
    </div>";
}

// Load troops
$res_troops = $db_instance->execute_query("SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?", [$current_k_id]);
$user_troops = [];
while ($t = $res_troops->fetch_assoc()) {
    $user_troops[(int)$t["soldierid"]] = (int)$t["soldiercount"];
}

// Load marching times
$res_ft_meta = $db_instance->query("SELECT fieldid, traversaltime FROM field_types");
$field_meta = [];
while ($ft = $res_ft_meta->fetch_assoc()) {
    $field_meta[(int)$ft["fieldid"]] = (int)$ft["traversaltime"];
}

$js_config = [
    "currentKingdom" => [
        "id" => $current_k_id,
        "ownerId" => $user->get_user_id(),
        "x" => $kingdom->get_kingdom_map_x(),
        "y" => $kingdom->get_kingdom_map_y(),
        "marchMultiplier" => $kingdom->get_march_speed_multiplier(),
        "troops" => $user_troops,
        "guildId" => $user->get_user_guild_id(),
        "guildSupportSpeedLvl" => Guild::get_user_guild_tech_level($user->get_user_id(), GuildTechTypes::GUILD_TECH_SUPPORT_SPEED)
    ],
    "fieldMeta" => $field_meta,
    "constants" => [
        "SOLDIER_SETTLER" => Soldiers::SOLDIER_SETTLER_WAGON,
        "SOLDIER_RAIDER" => Soldiers::SOLDIER_RAIDER,
        "SOLDIER_SCOUT" => Soldiers::SOLDIER_SCOUT,
        "MONSTER_CAMP_TRAVEL_BOOST" => MONSTER_CAMP_TRAVEL_BOOST,
        "MONSTER_CAMP_SCOUT_BOOST" => MONSTER_CAMP_SCOUT_BOOST,
        "PLAYER_KINGDOM_SCOUT_BOOST" => PLAYER_KINGDOM_SCOUT_BOOST,
        "GUILD_SUPPORT_TRAVEL_BOOST" => GUILD_SUPPORT_TRAVEL_BOOST,
        "GUILD_BONUS_SUPPORT_SPEED_PER_LVL" => GUILD_BONUS_SUPPORT_SPEED_PER_LVL,
        "MINE_TRAVEL_BOOST" => MINE_TRAVEL_BOOST
    ]
];

// --- RECALL TROOPS FROM MINES ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["recall_mine_troops"])) {
    $x = (int)($_POST["mine_x"] ?? 0);
    $y = (int)($_POST["mine_y"] ?? 0);

    if ($kingdom->recall_mine_troops($x, $y)) {
        $_SESSION["game_success"] = "Deine Schürfer haben die Mine verlassen und befinden sich mit der Beute auf dem Heimweg!";
    }

    change_location("map.php?startx=$x&starty=$y");
    exit;
}

ob_start();

// Coordinate logic
$get_x = $_GET["startx"] ?? null;
$get_y = $_GET["starty"] ?? null;
$coords_valid = false;

if (is_numeric($get_x) && is_numeric($get_y)) {
    $get_x = (int)$get_x;
    $get_y = (int)$get_y;

    if ($get_x >= 1 && $get_x <= MAX_X && $get_y >= 1 && $get_y <= MAX_Y) {
        $coords_valid = true;
        $x = $get_x;
        $y = $get_y;
    }
}

if ($coords_valid) {
    $result = $db_instance->execute_query("SELECT kingdomid FROM map WHERE mapx = ? AND mapy = ?", [$x, $y]);

    $field_id = ($result->num_rows != 0) ? $result->fetch_assoc()["kingdomid"] : -1;
} else {
    if ($get_x !== null || $get_y !== null) {
        $_SESSION["game_error"] = "Ungültige Koordinaten aufgerufen!";
    }

    $field_id = $user->get_current_kingdom();
    $result = $db_instance->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$field_id]);
    $row = $result->fetch_assoc();
    $x = $row["mapx"] ?? 1;
    $y = $row["mapy"] ?? 1;
}

// Map legend
echo "<div class='map-toolbar'>
        <div class='legend-group'>
            $biome_html
        </div>
        <div class='legend-divider'></div>
        <div class='legend-group'>
            <div class='map-legend-item'><img src='images/icons/icon_town.png' alt='' class='map-legend-entity-item'> Spieler</div>
            <div class='map-legend-item'><img src='images/icons/icon_gems.png' alt='' class='map-legend-entity-item'> Lager</div>
            <div class='map-legend-item'><img src='images/icons/icon_goblin.png' alt='' class='map-legend-entity-item'> Monster</div>
            <div class='map-legend-item'><img src='images/icons/icon_mine.png' alt='' class='map-legend-entity-item'> Minen</div>
            <div class='map-legend-item' title='Eigenes Königreich'><span class='map-legend-inner-item legend-own-kingdom'></span> Eigene</div>
            <div class='map-legend-item' title='Allianz / Eigene Gilde'><span class='map-legend-inner-item legend-ally-kingdom'></span> Allianz</div>
            <div class='map-legend-item' title='Feindliche Gilde'><span class='map-legend-inner-item legend-enemy-guild-kingdom'></span> Gegner</div>
        </div>
    </div>";

// Search
$show_path_checked = (isset($_COOKIE["me_map_show_path"]) && $_COOKIE["me_map_show_path"] === "1") ? "checked" : "";

echo '<div style="display: flex; justify-content: center; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 12px; font-size: 14px;">
        <form id="update-map" style="display: inline-flex; align-items: center; gap: 6px; margin: 0;">
            <b>X:</b><input type="text" inputmode="numeric" id="startx" name="startx" size="2" maxlength="3" value="' . $x . '" style="width: 45px; text-align: center;">
            <b>Y:</b><input type="text" inputmode="numeric" id="starty" name="starty" size="2" maxlength="3" value="' . $y . '" style="width: 45px; text-align: center;">
            <input type="submit" id="send-map-request" value="Los" style="padding: 2px 8px;">
            
            <label for="show-path-toggle" style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; margin-left: 8px;">
                <input type="checkbox" id="show-path-toggle" style="cursor: pointer; margin: 0;" ' . $show_path_checked . '>
                <span>Pfad</span>
            </label>
        </form>
        <span class="search-divider" style="opacity: 0.3;">|</span>
        <div id="map-filters" style="display: inline-flex; gap: 12px; align-items: center;">
            <label style="cursor:pointer; display: inline-flex; align-items: center; gap: 4px;"><input type="checkbox" id="filter-players" checked> Spieler</label>
            <label style="cursor:pointer; display: inline-flex; align-items: center; gap: 4px;"><input type="checkbox" id="filter-resources" checked> Lager</label>
            <label style="cursor:pointer; display: inline-flex; align-items: center; gap: 4px;"><input type="checkbox" id="filter-monsters" checked> Monster</label>
            <label style="cursor:pointer; display: inline-flex; align-items: center; gap: 4px;"><input type="checkbox" id="filter-ruins" checked> Ruinen</label>
            <label style="cursor:pointer; display: inline-flex; align-items: center; gap: 4px;"><input type="checkbox" id="filter-mines" checked> Minen</label>
        </div>
    </div>';

// Map Container
echo '<div id="map-container" 
            data-start-x="' . $x . '" 
            data-start-y="' . $y . '" 
            data-config=\'' . json_encode($js_config) . '\'
            style="height: var(--map-viewport-height); overflow: hidden;">';
echo '<div id="map-loader">
            <div class="loading-spinner"></div>
            <div class="loader-text">Kartograph zeichnet Karte...</div>
          </div>';
echo '<div id="coords-display" class="map-coords-overlay">X: ' . $x . ' | Y: ' . $y . '</div>';

echo '<div class="map-viewport" id="map-viewport">
            <canvas id="map-canvas" style="display: block;"></canvas>
          </div>';
echo '</div>';

// Info Box
echo '<div id="field-info">';
$map->render_field_info();
echo '</div>';

$view = ob_get_clean();

/*
 * HTML Section
 */
$title = "Landschaft";
$header = "Landschaft";
$head_extra = '<meta data-max-map-size=\'{"maxMapSize": ' . MAX_X . '}\' />';
$script_files = ["map", "userinfo", "timer"];

include("layout/base.php");
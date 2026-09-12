<?php
require_once("includes/core.php");

check_user_login($user);

$current_k_id = $user->get_current_kingdom();
$kingdom = new Kingdom($db_instance, $current_k_id);

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
        "GUILD_BONUS_SUPPORT_SPEED_PER_LVL" => GUILD_BONUS_SUPPORT_SPEED_PER_LVL
    ]
];

ob_start();

$map = new Map($db_instance, $user);

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
            <div class='map-legend-item' title='Hochland'><span class='map-legend-inner-item' style='background-color: {$map->get_field_type_color(5)};'></span> Hochland</div>
            <div class='map-legend-item' title='Küste'><span class='map-legend-inner-item' style='background-color: {$map->get_field_type_color(2)};'></span> Küste</div>
            <div class='map-legend-item' title='Wald'><span class='map-legend-inner-item' style='background-color: {$map->get_field_type_color(3)};'></span> Wald</div>
            <div class='map-legend-item' title='Wüste'><span class='map-legend-inner-item' style='background-color: {$map->get_field_type_color(4)};'></span> Wüste</div>
            <div class='map-legend-item' title='Gebirge'><span class='map-legend-inner-item' style='background-color: {$map->get_field_type_color(1)};'></span> Gebirge</div>
        </div>
        <div class='legend-divider'></div>
        <div class='legend-group'>
            <div class='map-legend-item'><img src='images/icons/icon_town.png' alt='' class='map-legend-entity-item'> Spieler</div>
            <div class='map-legend-item'><img src='images/icons/icon_gems.png' alt='' class='map-legend-entity-item'> Lager</div>
            <div class='map-legend-item'><img src='images/icons/icon_goblin.png' alt='' class='map-legend-entity-item'> Monster</div>
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
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
        "troops" => $user_troops
    ],
    "fieldMeta" => $field_meta,
    "constants" => [
        "SOLDIER_SETTLER" => Soldiers::SOLDIER_SETTLER_WAGON,
        "SOLDIER_RAIDER" => Soldiers::SOLDIER_RAIDER,
        "SOLDIER_SCOUT" => Soldiers::SOLDIER_SCOUT,
        "MONSTER_CAMP_TRAVEL_BOOST" => MONSTER_CAMP_TRAVEL_BOOST,
        "MONSTER_CAMP_SCOUT_BOOST" => MONSTER_CAMP_SCOUT_BOOST
    ]
];

ob_start();

$map = new Map($db_instance, $user);

if (isset($_SESSION["game_success"])) {
    echo show_passed_box($_SESSION["game_success"]);

    unset($_SESSION["game_success"]);
}

if (isset($_SESSION["game_error"])) {
    echo show_error_box($_SESSION["game_error"]);

    unset($_SESSION["game_error"]);
}

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
echo "<div class='map-legend' id='map-legend-fieldtypes' style='margin-bottom: 10px;'>
        <div class='legend-item'><span class='legend-inner-item' style='background-color: {$map->get_field_type_color(5)};'></span> Hochland</div>
        <div class='legend-item'><span class='legend-inner-item' style='background-color: {$map->get_field_type_color(2)};'></span> Küste</div>
        <div class='legend-item'><span class='legend-inner-item' style='background-color: {$map->get_field_type_color(3)};'></span> Wald</div>
        <div class='legend-item'><span class='legend-inner-item' style='background-color: {$map->get_field_type_color(4)};'></span> Wüste</div>
        <div class='legend-item'><span class='legend-inner-item' style='background-color: {$map->get_field_type_color(1)};'></span> Gebirge</div>
    </div>";

echo "<div class='map-legend' id='map-legend-fieldtypes'>
        <div class='legend-item'><img src='images/icons/icon_town.png' alt='Königreich' class='legend-entity-item'> Spieler</div>
        <div class='legend-item'><img src='images/icons/icon_gems.png' alt='Vorratslager' class='legend-entity-item'> Vorratslager</div>
        <div class='legend-item'><img src='images/icons/icon_goblin.png' alt='Monstercamp' class='legend-entity-item'> Monstercamp</div>
        <div class='legend-item'><span class='legend-inner-item legend-own-kingdom'></span> Eigenes Königreich</div>
    </div>";

// Search
echo '<form id="update-map" style="display: flex; flex-wrap: wrap;">
        X:<label>
            <input type="text" inputmode="numeric"  id="startx" name="startx" size="3" maxlength="3" value="' . $x . '">
        </label>
        Y:<label>
            <input type="text" inputmode="numeric"  id="starty" name="starty" size="3" maxlength="3" value="' . $y . '">
        </label>
        <input type="submit" id="send-map-request" value="Los">
        <span style="display: inline-flex; align-items: center; gap: 5px; vertical-align: middle;">
            <input type="checkbox" id="show-path-toggle" style="cursor: pointer; margin: 0;">
            <label for="show-path-toggle" style="font-size: 15px; cursor: pointer; user-select: none; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none;">
                Laufweg
            </label>
        </span>
    </form>
    <div id="map-filters" style="display: flex; justify-content: center; gap: 15px; margin-bottom: 10px; flex-wrap: wrap; font-size: 14px; padding: 8px; border-radius: 5px;">
        <label style="cursor:pointer;"><input type="checkbox" id="filter-players" checked> Spieler</label>
        <label style="cursor:pointer;"><input type="checkbox" id="filter-resources" checked> Vorratslager</label>
        <label style="cursor:pointer;"><input type="checkbox" id="filter-monsters" checked> Monstercamps</label>
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
$map->render_field_info($field_id);
echo '</div>';

$view = ob_get_clean();

/*
 * HTML Section
 */
$title = "Landschaft";
$header = "Landschaft";
$head_extra = '<meta data-max-map-size=\'{"maxMapSize": ' . MAX_X . '}\' />';
$script_files = ["map", "userinfo"];

include("layout/base.php");
<?php
require_once("includes/core.php");

check_user_login($user);

ob_start();

$map = new Map($db_instance, $user);

// Coordinate logic
if (!empty($_GET["startx"]) && !empty($_GET["starty"]) && is_numeric($_GET["startx"]) && is_numeric($_GET["starty"])) {
    $x = (int)$_GET["startx"];
    $y = (int)$_GET["starty"];
    $result = $db_instance->execute_query("SELECT kingdomid FROM map WHERE mapx = ? AND mapy = ?", [$x, $y]);
    $field_id = ($result->num_rows != 0) ? $result->fetch_assoc()["kingdomid"] : -1;
} else {
    $field_id = $user->get_current_kingdom();
    $result = $db_instance->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$field_id]);
    $row = $result->fetch_assoc();
    $x = $row["mapx"] ?? 1;
    $y = $row["mapy"] ?? 1;
}

// Map legend
echo "<div class='map-legend'>
        <div class='legend-item'><span class='legend-inner-item' style='background-color: {$map->get_field_type_color(5)};'></span> Hochland</div>
        <div class='legend-item'><span class='legend-inner-item' style='background-color: {$map->get_field_type_color(2)};'></span> Küste</div>
        <div class='legend-item'><span class='legend-inner-item' style='background-color: {$map->get_field_type_color(3)};'></span> Wald</div>
        <div class='legend-item'><span class='legend-inner-item' style='background-color: {$map->get_field_type_color(4)};'></span> Wüste</div>
        <div class='legend-item'><span class='legend-inner-item' style='background-color: {$map->get_field_type_color(1)};'></span> Gebirge</div>
    </div>";

// Search
echo '<form id="update-map">
        X: <label>
            <input type="text" id="startx" name="startx" size="3" maxlength="3" value="' . $x . '">
        </label>
        Y: <label>
            <input type="text" id="starty" name="starty" size="3" maxlength="3" value="' . $y . '">
        </label>
        <input type="submit" id="send-map-request" value="Anzeigen">
        <span style="margin-left: 10px; display: inline-flex; align-items: center; gap: 5px; vertical-align: middle;">
            <input type="checkbox" id="show-path-toggle" style="cursor: pointer; margin: 0;">
            <label for="show-path-toggle" style="font-size: 15px; cursor: pointer; user-select: none; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none;">
                Weg anzeigen
            </label>
        </span>
    </form><br>';

// Map Container
echo '<div id="map-container" data-start-x="' . $x . '" data-start-y="' . $y . '" style="height: var(--map-viewport-height); overflow: hidden;">';
echo '<div id="map-loader">
            <div class="loading-spinner"></div>
            <div class="loader-text">Kartograph zeichnet Karte...</div>
          </div>';
echo '<div id="coords-display" class="map-coords-overlay">X: ' . $x . ' | Y: ' . $y . '</div>';

echo '<div class="map-viewport" id="map-viewport">
            <div id="map-grid"></div>
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
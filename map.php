<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$view = "";

ob_start();

$map = new Map($db_instance);
$field_id = -1;
$x = 1;
$y = 1;

if (!empty($_GET["startx"]) && !empty($_GET["starty"])) {
    if (!is_numeric($_GET["startx"]) || $_GET["startx"] < 0 || $_GET["startx"] > MAX_X) {
        $map->set_start_x(1);
    } else {
        $map->set_start_x($_GET["startx"]);
    }

    if (!is_numeric($_GET["starty"]) || $_GET["starty"] < 0 || $_GET["starty"] > MAX_Y) {
        $map->set_start_y(1);
    } else {
        $map->set_start_y($_GET["starty"]);
    }

    // If there is a kingdom on the field - get the data
    $x = $_GET["startx"];
    $y = $_GET["starty"];
    $result = $db_instance->execute_query("SELECT kingdomid FROM map WHERE mapx = ? AND mapy = ?", [$x, $y]);

    if ($result->num_rows != 0) {
        $field_id = $result->fetch_assoc()["kingdomid"];
    }

    // Calculate start coordinates
    $map->set_start_x(max(1, min($x - 5, 91)));
    $map->set_start_y(max(1, min($y - 5, 91)));

    echo "gesetzt: " . $field_id;
} else {
    // Get the coords of the current kingdom
    $result = $db_instance->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$_SESSION["kingdomid"]]);
    $row = $result->fetch_assoc();
    $x = $row["mapx"];
    $y = $row["mapy"];

    // Calculate start coordinates
    $map->set_start_x(max(1, min($x - 5, 91)));
    $map->set_start_y(max(1, min($y - 5, 91)));
    $field_id = $user->get_current_kingdom();
}

// Pass x and y directly as numbers
echo "<input type='hidden' id='highlightedfield'>
      <script type='text/javascript'>
          document.addEventListener('DOMContentLoaded', function() {
                let x = " . $x . ";
                let y = " . $y . ";
                let fieldToHighlight = document.getElementById('highlightedfield');
                fieldToHighlight.setAttribute('data-x', x || '0');
                fieldToHighlight.setAttribute('data-y', y || '0');
                highlightEnteredCoordinates(x || 0, y || 0);
          });
      </script>";

// Show info about the fields
echo "<div style='padding-bottom: 5px; display: flex; justify-content: center; gap: 5px; flex-wrap: wrap;'>
                            <div class='legend-item'><div class='legend-inner-item' style='background-color: {$map->get_field_type_color(5)};'></div><span>Hochland</span></div>
                            <div class='legend-item'><div class='legend-inner-item' style='background-color: {$map->get_field_type_color(2)};'></div><span>Küste</span></div>
                            <div class='legend-item'><div class='legend-inner-item' style='background-color: {$map->get_field_type_color(3)};'></div><span>Wald</span></div>
                            <div class='legend-item'><div class='legend-inner-item' style='background-color: {$map->get_field_type_color(4)};'></div><span>Wüste</span></div>
                            <div class='legend-item'><div class='legend-inner-item' style='background-color: {$map->get_field_type_color(1)};'></div><span>Gebirge</span></div>
                        </div>";
echo "<div id='map-container'>";
$map->render_map($map->get_start_x(), $map->get_start_y());
echo "</div>";
echo '<div id="field-info">';
$map->render_field_info($field_id);
echo '</div>
        <br>
        <form id="update-map">
            X: <label>
                <input type="text" id="startx" name="startx" size="3" maxlength="3">
            </label>
            Y: <label>
                <input type="text" id="starty" name="starty" size="3" maxlength="3">
            </label>
            <input type="button" id="send-map-request" value="Anzeigen" onclick="sendUpdateMapRequest()">
        </form>';

$view = ob_get_clean();

/*
 * HTML Section
 */
$title = "Landschaft";
$header = "Landschaft";
$head_extra = '<meta data-max-map-size=\'{"maxMapSize": ' . MAX_X . '}\' />';
$script_files = ["map", "userinfo"];

include('layout/base.php');
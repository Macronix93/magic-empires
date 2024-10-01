<?php
global $db_instance;
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $map = new Map($db_instance);

    // Retrieve start_x and start_y parameters from GET request
    $startx = clamp_value($_GET["startx"] ?? 1);
    $starty = clamp_value($_GET["starty"] ?? 1);

    // Render the map table HTML
    ob_start();
    $map->render_map($startx, $starty);
    $html = ob_get_clean();

    echo $html;
} else {
    change_location("map.php");
}
<?php
if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    global $db_instance;
    require_once("functions.php");

    $map = new Map($db_instance);

    // Retrieve startx and starty parameters from GET request
    $startx = clampValue($_GET["startx"] ?? 1);
    $starty = clampValue($_GET["starty"] ?? 1);

    // Render the map table HTML
    ob_start();
    $map->renderMap($startx, $starty);
    $html = ob_get_clean();

    echo $html;
} else {
    header("Location: map.php");
}
<?php
global $db_instance;
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $map = new Map($db_instance);

    // Render the field info table HTML
    ob_start();
    $map->render_field_info($_GET["clickedfield"] ?? -1);
    $html = ob_get_clean();

    echo $html;
} else {
    change_location("map.php");
}
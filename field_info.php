<?php
if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    global $db_instance;
    require_once("functions.php");

    $map = new Map($db_instance);

    // Render the field info table HTML
    ob_start();
    $map->renderFieldInfo($_GET["clickedfield"] ?? -1);
    $html = ob_get_clean();

    echo $html;
} else {
    header("Location: map.php");
}
<?php
global $db_instance;
require_once("functions.php");

$map = new Map($db_instance);

// Retrieve startx and starty parameters from GET request
$startx = $_GET['startx'] ?? 1;
$starty = $_GET['starty'] ?? 1;

// Render the map table HTML
ob_start();
$map->renderMap($startx, $starty);
$html = ob_get_clean();

echo $html;
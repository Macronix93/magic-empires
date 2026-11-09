<?php
require_once("../includes/ajax_core.php");

$cat = $_GET["cat"] ?? "";
require_once("../includes/content/halloffame_data.php");

if (!isset($categories[$cat])) {
    echo json_encode(["success" => false, "error" => "Kategorie nicht gefunden"]);
    exit;
}

$html = render_hof_tab_content($categories[$cat], $db_instance, $user);

echo json_encode([
    "success" => true,
    "html" => $html
]);
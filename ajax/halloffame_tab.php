<?php
require_once("../includes/ajax_core.php");

$cat = get_str("cat");
require_once("../includes/content/halloffame_data.php");

if (!isset($categories[$cat])) {
    send_json(["success" => false, "error" => "Kategorie nicht gefunden"]);
}

$html = render_hof_tab_content($categories[$cat], $db_instance, $user);

send_json([
    "success" => true,
    "html" => $html
]);
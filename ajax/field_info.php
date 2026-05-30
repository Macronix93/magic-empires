<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $map = new Map($db_instance, $user);

    // 1. Koordinaten des eigenen Königreichs holen
    $res = $db_instance->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$user->get_current_kingdom()]);
    $my_row = $res->fetch_assoc();

    // 2. Ziel-Koordinaten bestimmen
    $target_x = isset($_GET["x"]) ? intval($_GET["x"]) : $my_row["mapx"];
    $target_y = isset($_GET["y"]) ? intval($_GET["y"]) : $my_row["mapy"];

    // 3. Pfad berechnen
    $pathData = $map->calculate_path($my_row["mapx"], $my_row["mapy"], $target_x, $target_y);

    // 4. HTML für Info-Box generieren
    ob_start();
    $map->render_field_info($_GET["clickedfield"] ?? -1);
    $html = ob_get_clean();

    // 5. Alles als JSON senden
    //header('Content-Type: application/json');
    echo json_encode([
        "html" => $html,
        "path" => $pathData["path"] ?? [] // Das Array mit den Koordinaten
    ]);
} else {
    change_location("map.php");
}
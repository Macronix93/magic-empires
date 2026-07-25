<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $map = new Map($db_instance, $user);

    $res = $db_instance->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$user->get_current_kingdom()]);
    $my_row = $res->fetch_assoc();
    $my_x = $my_row["mapx"] ?? 1;
    $my_y = $my_row["mapy"] ?? 1;

    $target_x = isset($_GET["x"]) ? intval($_GET["x"]) : $my_x;
    $target_y = isset($_GET["y"]) ? intval($_GET["y"]) : $my_y;

    $pathData = $map->calculate_path($my_row["mapx"], $my_row["mapy"], $target_x, $target_y);

    ob_start();
    $map->render_field_info();
    $html = ob_get_clean();

    echo json_encode([
        "html" => $html,
        "path" => $pathData["path"] ?? []
    ]);
} else {
    change_location("map.php");
}
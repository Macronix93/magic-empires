<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    check_user_login($user);

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
} else {
    change_location("halloffame.php");
}
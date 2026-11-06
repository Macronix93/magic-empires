<?php
require_once("../includes/core.php");

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $db_instance->execute_query("UPDATE users SET tutorial_done = 1 WHERE id = ?", [$user->get_user_id()]);

    $_SESSION["tutorial_done"] = 1;

    echo json_encode(["success" => true]);
}
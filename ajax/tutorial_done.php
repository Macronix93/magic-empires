<?php
require_once("../includes/core.php");

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_SERVER["HTTP_X_REQUESTED_WITH"])) {
    if (!verify_csrf_token()) {
        echo json_encode(["success" => false]);
        exit;
    }

    $user->check_user_login();
    $uid = $user->get_user_id();
    $db_instance->execute_query("UPDATE users SET tutorial_done = 1 WHERE id = ?", [$uid]);
    $_SESSION["tutorial_done"] = 1;

    if (AdventCalendar::is_active()) {
        AdventCalendar::ensure_user_gifts($uid);
    }

    $show_advent = AdventCalendar::should_show_intro($uid);
    $advent_html = $show_advent ? AdventCalendar::render_intro_modal() : "";

    echo json_encode([
        "success" => true,
        "show_advent" => $show_advent,
        "advent_html" => $advent_html
    ]);
    exit;
}

echo json_encode(["success" => false]);
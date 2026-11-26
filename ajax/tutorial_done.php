<?php
require_once("../includes/ajax_core.php");
require_post();

$uid = $user->get_user_id();
$db_instance->execute_query("UPDATE users SET tutorial_done = 1 WHERE id = ?", [$uid]);
$_SESSION["tutorial_done"] = true;

if (AdventCalendar::is_active()) {
    new AdventCalendar()->ensure_user_gifts($uid);
}

$show_advent = AdventCalendar::should_show_intro($uid);
$advent_html = $show_advent ? AdventCalendar::render_intro_modal() : "";

send_json([
    "success" => true,
    "show_advent" => $show_advent,
    "advent_html" => $advent_html
]);
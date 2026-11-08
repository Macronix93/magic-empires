<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $user->check_user_login();

    if (AdventCalendar::should_show_intro($user->get_user_id())) {
        echo AdventCalendar::render_intro_modal();
    }
}
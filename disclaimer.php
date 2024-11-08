<?php
require_once("includes/core.php");

if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

/*
 * HTML Section
 */
$title = "Credits";
$header = "Credits";
$view = 'Icons © by <a href="https://www.flaticon.com/">Flaticon</a> and many artists<br>
        Banner & Background Image © by <a href="https://github.com/Naseband">Naseband</a>';

include('layout/base.php');
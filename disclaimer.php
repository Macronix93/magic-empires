<?php
require_once("includes/core.php");

check_user_login($user);

/*
 * HTML Section
 */
$title = "Credits";
$header = "Credits";
$view = 'Icons © by <a href="https://www.flaticon.com/">Flaticon</a> and many artists<br>
        Banner & Background Image © by <a href="https://github.com/Naseband">Naseband</a>';

include("layout/base.php");
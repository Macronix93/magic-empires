<?php
require_once("includes/core.php");

check_user_login($user);

/*
 * HTML Section
 */
$title = "Credits";
$header = "Credits";
$view = 'Icons © by <a href="https://www.flaticon.com/">Flaticon</a> artists<br>
        Banner & Background Image © by <a href="https://chatgpt.com/">ChatGPT</a><br>
        Besonderen Dank gilt: <a href="https://github.com/Naseband">Naseband</a> (Hilfe, Balancing, Testing)';

include("layout/base.php");
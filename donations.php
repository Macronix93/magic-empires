<?php
require_once("includes/core.php");

if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}


/*
 * HTML Section
 */
$title = "Spenden";
$header = "Spenden";
$view = "";

include('layout/base.php');
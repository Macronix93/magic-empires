<?php
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
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
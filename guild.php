<?php
require_once("includes/core.php");

if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

/*
 * HTML Section
 */
$title = "Gilde";
$header = "Gilde";
$view = "";

include('layout/base.php');
<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$view = "";
$error = "";

if ($user->get_user_admin_level() == 0) {
    $error = "Du bist kein Administrator!";
}

/*
 * HTML Section
 */
$title = "Admin-Bereich";
$header = "Admin-Bereich";

if (!empty($error)) {
    $view = "<div class='info-box'>" . $error . "</div>" . $view;
}

include('layout/base.php');
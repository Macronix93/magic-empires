<?php
require_once("includes/core.php");

check_user_login($user);

$view .= "<div class='box-container'>
        <div class='box-header'>Test</div>
        <div class='box-content'><p>Langer Text Langer Text<br>Langer Text Langer<br>Text</p></div>
</div>";
$view .= "<div class='box-container'>
        <div class='box-header'>Test</div>
        <div class='box-content'>Test</div>
</div>";


/*
 * HTML Section
 */
$title = "Neuigkeiten";
$header = "Neuigkeiten";
$script_files = [];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
<?php
require_once("includes/core.php");

$title = "Regeln";
$header = "Regeln";

$view = get_include_contents("includes/content/rules_list.php");

if ($user->is_logged_in()) {
    include("layout/base.php");
} else {
    include("layout/guest_base.php");
}
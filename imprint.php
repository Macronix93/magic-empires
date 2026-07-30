<?php
require_once("includes/core.php");

$title = "Impressum";
$header = "Impressum";

$view = get_include_contents("includes/content/imprint_text.php");

if ($user->is_logged_in()) {
    include("layout/base.php");
} else {
    include("layout/guest_base.php");
}
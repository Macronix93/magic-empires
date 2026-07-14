<?php
require_once("includes/core.php");

$title = "FAQ";
$header = "FAQ - Frequently Asked Questions";

$view = get_include_contents("includes/content/faq_list.php");

if ($user->is_logged_in()) {
    include("layout/base.php");
} else {
    include("layout/guest_base.php");
}
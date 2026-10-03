<?php
require_once("includes/core.php");

$title = "FAQ";
$header = "Frequently Asked Questions";

$view = get_include_contents("includes/content/faq_list.php", [
    "is_logged_in" => $user->is_logged_in()
]);

if ($user->is_logged_in()) {
    include("layout/base.php");
} else {
    include("layout/guest_base.php");
}
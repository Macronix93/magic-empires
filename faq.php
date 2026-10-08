<?php
require_once("includes/core.php");

if (!isset($_SESSION["faq_opened_at"])) {
    $_SESSION["faq_opened_at"] = time();
}

$title = "FAQ";
$header = "Frequently Asked Questions";

$view = get_include_contents("includes/content/faq_list.php", [
    "is_logged_in" => $user->is_logged_in()
]);

if ($user->is_logged_in()) {
    $view .= '<div id="faq-ping-meta" data-delay="' . ACHIEVEMENT_FAQ_OPEN_TIME . '" style="display:none;"></div>';
    include("layout/base.php");
} else {
    include("layout/guest_base.php");
}
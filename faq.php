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
    $faq_delay_ms = (ACHIEVEMENT_FAQ_OPEN_TIME + 1) * 1000;

    $view .= '<script nonce="' . $nonce . '">
        setTimeout(() => {
            fetch("ajax/faq_ping.php", {
                method: "POST",
                headers: { "X-Requested-With": "XMLHttpRequest" }
            }).catch(() => {});
        }, parseInt("' . $faq_delay_ms . '", 10));
    </script>';

    include("layout/base.php");
} else {
    include("layout/guest_base.php");
}
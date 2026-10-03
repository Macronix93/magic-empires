<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    echo render_tutorial_modal($user, true);
} else {
    change_location("faq.php");
}
<?php
require_once("includes/core.php");

check_user_login($user);


/*
 * HTML Section
 */
$title = "Spenden";
$header = "Spenden";
$view = "Wenn ihr das Projekt unterstützen wollt: <a href='https://www.paypal.me/Macronix93'>Hier klicken!</a>";

include("layout/base.php");
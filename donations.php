<?php
require_once("includes/core.php");

check_user_login($user);

/*
 * HTML Section
 */
$title = "Spenden";
$header = "Spenden";
$view = "Gefällt dir, was du siehst?<br>Über eine Spende würde ich mich freuen (natürlich komplett freiwillig!):<br><br>
            <a href='https://www.paypal.me/Macronix93'><img src='images/paypal_button.png' alt='PayPal-Spendenbutton'/></a>";

include("layout/base.php");
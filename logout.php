<?php
require_once("functions.php");

// Check if user is not logged in
if (!isset($_SESSION["userid"])) {
    changeLocation("login.php", 0);
    //header("Location: login.php");
    die;
}

// Logout successful
changeLocation("login.php", 2);

echo "<p style='text-align: center'>Du hast dich erfolgreich ausgeloggt!<br><br>Du wirst zum Login weitergeleitet.</p>";

session_destroy();

unset($_SESSION["currlogin"]);
unset($_SESSION["lastlogin"]);
unset($_SESSION["userid"]);
unset($_SESSION["username"]);
unset($_SESSION["kingdomid"]);
unset($_SESSION["justloggedin"]);
unset($_SESSION["lastactivity"]);
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/banner.html");
?>
</body>
</html>

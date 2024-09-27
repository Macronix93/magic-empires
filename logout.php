<?php
require_once("includes/core.php");

// Check if user is not logged in
if (!isset($_SESSION["userid"])) {
    change_location("login.php");
    die;
}

// Logout successful
change_location("login.php", 2);

echo "<p style='text-align: center'>Du hast dich erfolgreich ausgeloggt!<br><br>Du wirst zum Login weitergeleitet.</p>";

session_destroy();
$_SESSION = [];

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

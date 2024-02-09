<?php
require_once("functions.php");
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.php");
?>
<body>
<?php
if (!isset($_SESSION["userid"])) {
    changeLocation("login.php", 0);
    die;
}

echo "<p style='text-align: center'>Du hast dich erfolgreich ausgeloggt!<br><br>Du wirst zum Login weitergeleitet.</p>";
changeLocation("login.php", 2);
session_destroy();
?>
</body>
</html>

<?php
global $user;
require_once("functions.php");

if (isset($_GET["logout"])) {
    if ($user->isLoggedIn()) {
        //echo "<p style='text-align: center'>Du hast dich erfolgreich ausgeloggt!</p>";

        session_destroy();

        unset($_SESSION["currlogin"]);
        unset($_SESSION["lastlogin"]);
        unset($_SESSION["userid"]);
        unset($_SESSION["username"]);
        unset($_SESSION["kingdomid"]);
        unset($_SESSION["justloggedin"]);
        unset($_SESSION["lastactivity"]);
    } else {
        $user->error = "Du bist nicht eingeloggt!<br><br>";
    }
} else {
    if ($user->isLoggedIn()) {
        changeLocation("index.php", 0);
        exit;
    }

    // Set form vars to null
    $name = $pass = "";

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        if (empty($_POST["username"]) || empty($_POST["password"])) {
            $user->error = "Bitte beide Felder ausfüllen!<br><br>";
        } else {
            $name = makeSecure($_POST["username"]);
            $pass = makeSecure($_POST["password"]);
        }

        if ($user->error == NULL) {
            $user->loginUser($name, $pass);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/banner.html");

// Show login form
$user->showLoginForm($user->error);
?>
</body>
</html>
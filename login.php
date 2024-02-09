<?php
global $user;
require_once("functions.php");
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.php");
?>
<body>
<?php
if ($user->isLoggedIn()) {
    //header("Location: index.php");
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

// Show login form
$user->showLoginForm($user->error);
?>
</body>
</html>
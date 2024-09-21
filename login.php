<?php
global $db_instance, $user;
require_once("includes/core.php");

// Set error variable to empty string
$error = "";

if (isset($_GET["logout"])) {
    if ($user->isLoggedIn()) {
        session_unset();
        session_destroy();
    } else {
        changeLocation("login.php");
    }
} else {
    if ($user->isLoggedIn()) {
        changeLocation("index.php");
        exit;
    }

    // Set form vars to null
    $name = "";
    $pass = "";

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Check if user submitted the form
        if (isset($_POST["login"])) {
            $name = makeSecure($_POST["username"]);
            $pass = makeSecure($_POST["password"]);
        }

        if (empty($name) || empty($pass)) {
            $error = "Bitte beide Felder ausfüllen!";
        } else {
            $result = $db_instance->execute_query("SELECT id, password, status FROM users WHERE username = ? LIMIT 1", [$name]);
            $row = $result->fetch_assoc();
            $found = $result->num_rows == 1;

            // Check if user exists
            if ($found) {
                $userid = $row["id"];
                $password = $row["password"];
                $status = $row["status"];

                if (!password_verify($pass, $password)) {
                    $error .= "Falsches Passwort!";
                } else if (!$status) {
                    $error .= "Account nicht aktiviert durch Aktivierungslink!";
                } else {
                    if (empty($error)) {
                        unset($_POST);
                        $user->loginUser($userid);
                    }
                }
            } else {
                $error .= "Dieser Nickname existiert nicht!";
            }
        }

        // Add additional space for error message
        $error .= "<br><br>";
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
$user->showLoginForm($error);
?>
</body>
</html>
<?php
global $db_instance, $user;
require_once("includes/core.php");

// Set error variable to empty string
$error = "";

if (isset($_GET["logout"])) {
    if ($user->is_logged_in()) {
        session_unset();
        session_destroy();
    } else {
        change_location("login.php");
    }
} else {
    if ($user->is_logged_in()) {
        change_location("index.php");
        exit;
    }

    // Set form vars to null
    $name = "";
    $pass = "";

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Retrieve and sanitize form inputs
        $name = make_secure($_POST["username"] ?? "");
        $pass = make_secure($_POST["password"] ?? "");

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

                if (!$status) {
                    $error .= "Account noch nicht aktiviert durch Aktivierungslink!";
                } else if (!password_verify($pass, $password)) {
                    $error .= "Falsches Passwort!";
                } else {
                    if (empty($error)) {
                        unset($_POST);
                        $user->login_user($userid);
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
$user->show_login_form($error);
?>
</body>
</html>
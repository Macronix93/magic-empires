<?php
global $db_instance, $user;
require_once("functions.php");
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
$name = "";
$email = "";
$pass = "";

// Check if user submitted the form
if (isset($_POST["submit"])) {
    $name = $_POST["username"];
    $email = $_POST["email"];
    $pass = $_POST["password"];
}

// Set Error variables to NULL
$nameErr = $emailErr = $passErr = $captchaErr = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $json = file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=6Lf1Ok4UAAAAAG9oYNxP0_LDyUZfcie2XWhyZKBe&response=' . $_POST['g-recaptcha-response']);
    $data = json_decode($json);

    if (!$data->success) {
        $captchaErr = "Bitte den Botschutz akzeptieren!";
    }

    if (empty($_POST["username"])) {
        $nameErr = "Bitte einen Nickname angeben!";
    } else {
        if (strlen($name) < MIN_USERNAME_LENGTH || strlen($name) > MAX_USERNAME_LENGTH) {
            $nameErr = "Nickname muss zwischen " . MIN_USERNAME_LENGTH . " und " . MAX_USERNAME_LENGTH . " Zeichen lang sein!";
        }

        $name = preg_replace('/\s+/', ' ', trim($name));
        if (!preg_match("/^[a-zA-Z0-9 ]+$/", $name)) {
            $nameErr = "Nickname darf nur Buchstaben/Zahlen enthalten!";
        }

        $name = makeSecure($_POST["username"]);

        $stmt = $db_instance->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->store_result();

        // Check if user exists
        if ($stmt->num_rows == 1) {
            $stmt->free_result();
            $stmt->close();
            $db_instance->close();

            $nameErr = "Dieser Nickname existiert bereits!";
        }
    }

    if (empty($_POST["email"])) {
        $emailErr = "Bitte E-Mail angeben!";
    } else {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emailErr = "Falsches E-Mail Format!";
        } else {
            $email = makeSecure($_POST["email"]);
        }
    }

    if (empty($_POST["password"])) {
        $passErr = "Bitte ein Passwort angeben!";
    } else {
        if (strlen($pass) < MIN_PASSWORD_LENGTH || strlen($pass) > MAX_PASSWORD_LENGTH) {
            $passErr = "Passwort muss zwischen " . MIN_PASSWORD_LENGTH . " und " . MAX_PASSWORD_LENGTH . " Zeichen lang sein!";
        } else {
            $pass = makeSecure($_POST["password"]);
        }
    }

    // We check if the user name already exist
    if ($nameErr == NULL && $passErr == NULL && $emailErr == NULL && $captchaErr == NULL) {
        $_POST["username"] = "";
        $_POST["email"] = "";
        $_POST["password"] = "";

        $user->registerUser($name, $email, $pass);
    }
}

// Show register form
$user->showRegisterForm($nameErr, $emailErr, $passErr, $captchaErr);
?>
<script src='https://www.google.com/recaptcha/api.js'></script>
</body>
</html>

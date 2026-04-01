<?php
require_once("includes/core.php");
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/banner.html");

$name = "";
$email = "";
$pass = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $json = file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . getenv("LOCALHOST_SERVER_KEY") . '&response=' . $_POST["g-recaptcha-response"]);
    $data = json_decode($json);

    $name = $_POST["username"];

    if (preg_match('/\s/', $name)) {
        $error .= "Benutzername darf keine Leerzeichen enthalten!<br>";
    } else {
        $name = make_secure($_POST["username"] ?? "");
        $email = make_secure($_POST["email"] ?? "");
        $pass = make_secure($_POST["password"] ?? "");

        // Validate username
        if (empty($name)) {
            $error .= "Bitte einen Benutzernamen angeben!<br>";
        } else {
            $pattern = '/^' . preg_quote(strtolower($name), '/') . '$/i'; // Case-insensitive pattern
            $bad_names_matches = preg_grep($pattern, get_bad_names());

            if (!preg_match("/^[a-zA-Z0-9 ]+$/", $name)) {
                $error .= "Benutzername darf nur Buchstaben/Zahlen enthalten!<br>";
            } else if (preg_match_all(regex_pattern(), $name, $matches) || !empty($bad_names_matches)) {
                $error .= "Dieser Benutzername ist nicht erlaubt!<br>";
            } else if (strlen($name) < MIN_USERNAME_LENGTH || strlen($name) > MAX_USERNAME_LENGTH) {
                $error .= "Benutzername muss zwischen " . MIN_USERNAME_LENGTH . " und " . MAX_USERNAME_LENGTH . " Zeichen lang sein!<br>";
            }
        }
    }

    // Validate email
    if (empty($email)) {
        $error .= "Bitte E-Mail angeben!<br>";
    } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error .= "Falsches E-Mail Format!<br>";
    }

    // Validate password
    if (empty($pass)) {
        $error .= "Bitte ein Passwort angeben!<br>";
    } else if (strlen($pass) < MIN_PASSWORD_LENGTH || strlen($pass) > MAX_PASSWORD_LENGTH) {
        $error .= "Passwort muss zwischen " . MIN_PASSWORD_LENGTH . " und " . MAX_PASSWORD_LENGTH . " Zeichen lang sein!<br>";
    }

    // Validate recaptcha
    if (!$data->success) {
        $error .= "Bitte den Botschutz akzeptieren!<br>";
    }

    // Register user if no errors
    if (empty($error)) {
        // Check if username already exists
        $result = $db_instance->execute_query("SELECT id FROM users WHERE username = ?", [$name]);

        if ($result->num_rows !== 0) {
            $error .= "Dieser Benutzername existiert bereits!<br>";
        } else {
            unset($_POST);
            $user->register_user($name, $email, $pass);
        }
    }
}

// Show register form
?>
<div class="form">
    <form class="login-register" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
        <fieldset>
            <legend><b>Registrieren</b></legend>
            <?php
            echo(!empty($user->get_reg_status()) ? $user->get_reg_status() : '');
            if (!empty($error)) {
                $error_messages = explode("<br>", $error);
                foreach ($error_messages as $e) {
                    if (trim($e) !== "") {
                        echo show_error_box($e);
                    }
                }
            }
            ?>
            <table class="table" style="width: 50%;">
                <tr>
                    <td><b>Benutzername:</b></td>
                    <td>
                        <label>
                            <input style="padding:3px" class="regis" type="text" name="username"
                                   value="<?= $_POST["username"] ?? ""; ?>">
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <b>E-Mail:</b>
                    </td>
                    <td>
                        <label>
                            <input class="regis" type="text" name="email"
                                   value="<?= $_POST["email"] ?? ""; ?>">
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <b>Passwort:</b>
                    </td>
                    <td>
                        <label>
                            <input class="regis" type="password" name="password"
                        </label>
                    </td>
                </tr>
            </table>
            <br>
            <div class="g-recaptcha"
                 data-sitekey="<?= getenv("LOCALHOST_CLIENT_KEY") ?>"
                 data-callback="onSubmit"></div>
            <br><br>
            <input type='submit' name='submit' value='Registrieren' style="width:125px; height:50px;"/>
            <br><br>
            <hr>
            <i>Du bist bereits registriert? Logge dich <a href='login.php'><b>hier</b></a> ein.</i>
        </fieldset>
    </form>
</div>

<script src="https://www.google.com/recaptcha/api.js"></script>

</body>
</html>

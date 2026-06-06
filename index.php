<?php
require_once("includes/core.php");

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    unset($_SESSION["captcha_passed"]);
}

$success = "";
$error = "";
$mode = $_GET["action"] ?? "login";

// ACCOUNT ACTIVATION
if (!empty($_GET["key"])) {
    $activation_key = make_secure($_GET["key"]);
    $result = $db_instance->execute_query("SELECT id, username, status FROM users WHERE activationkey = ? LIMIT 1", [$activation_key]);

    if ($result && $result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        $user_id = $user_data["id"];
        $username = $user_data["username"];

        if (!$user_data["status"]) {
            $db_instance->execute_query("UPDATE users SET status = true, activationkey = '' WHERE id = ?", [$user_id]);
            $kingdom = new Kingdom($db_instance);
            $main_kingdom = $kingdom->create_kingdom($user_id, $username);

            if ($main_kingdom) {
                // Update last rank
                $query_rank = "UPDATE users JOIN (SELECT id, (@rank := @rank + 1) AS new_rank 
                        FROM (SELECT id FROM users ORDER BY score DESC) 
                        AS ranked_users CROSS JOIN (SELECT @rank := 0) AS init) AS r ON users.id = r.id 
                        SET users.lastrank = r.new_rank 
                        WHERE users.id = ?
                ";
                $db_instance->execute_query($query_rank, [$user_id]);
                $db_instance->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$main_kingdom, $user_id]);

                $success = "Dein Account wurde erfolgreich aktiviert!<br>Du kannst dich jetzt einloggen.";
            } else {
                $error = "Account aktiviert, aber kein freier Platz<br>auf der Karte gefunden. Support kontaktieren!<br>support@magic-empires.de";
            }
        } else {
            $error = "Dieser Account ist bereits aktiviert.";
        }
    } else {
        $error = "Ungültiger oder abgelaufener Aktivierungsschlüssel.";
    }
}

// LOGOUT
if (isset($_GET["logout"])) {
    if ($user->is_logged_in()) {
        if ($_GET["logout"] === "inactive") {
            $error .= "Du wurdest aus Inaktivitätsgründen automatisch ausgeloggt!";
        } else if ($_GET["logout"] === "session") {
            $error .= "Deine Session ist abgelaufen. Bitte logge dich erneut ein!";
        }

        // Update anti spam
        $db_instance->execute_query("UPDATE users SET msgcount = ?, lastsentmsgend = ? WHERE id = ?", [$_SESSION["message_count"], $_SESSION["message_timeframe_end"], $user->get_user_id()]);

        session_unset();
        session_destroy();

        if (empty($_GET["logout"])) {
            $success = "Du hast dich erfolgreich ausgeloggt!";
        }
    }
} else {
    if ($user->is_logged_in()) {
        change_location("overview.php");
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // LOGIN
    if (isset($_POST["login"])) {
        $name = make_secure($_POST["username"] ?? "");
        $pass = make_secure($_POST["password"] ?? "");

        if (empty($name) || empty($pass)) {
            $error .= "Bitte beide Felder ausfüllen!";
        } else {
            $result = $db_instance->execute_query("SELECT id, password, status, adminlevel FROM users WHERE username = ? LIMIT 1", [$name]);

            if ($result && $result->num_rows == 1) {
                $row = $result->fetch_assoc();

                if (MAINTENANCE_MODE && $row["adminlevel"] == 0) {
                    $error .= "Der Server befindet sich im Wartungsmodus!";
                } else {
                    if (!$row["status"]) {
                        $error .= "Account noch nicht aktiviert durch Aktivierungslink!";
                    } else if (!password_verify($pass, $row["password"])) {
                        $logger->security("Login failed for user: $name");
                        $error .= "Nutzername oder Passwort ist falsch!";
                    } else {
                        unset($_POST);

                        $user->login_user($row["id"]);
                    }
                }
            } else {
                $logger->security("Login failed for user: $name");
                $error .= "Nutzername oder Passwort ist falsch!";
            }
        }
    }

    // REGISTER
    if (isset($_POST["register"])) {
        $mode = "register";

        if (!isset($_SESSION["captcha_passed"]) || $_SESSION["captcha_passed"] !== true) {
            $response = $_POST["g-recaptcha-response"] ?? "";
            $json = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=" . getenv("LOCALHOST_SERVER_KEY") . "&response=" . $response);
            $data = json_decode($json);

            if ($data->success) {
                $_SESSION["captcha_passed"] = true;
            } else {
                $error .= "Bitte den Botschutz akzeptieren!<br>";
            }
        }

        $name = $_POST["username"];
        if (preg_match('/\s/', $name)) {
            $error .= "Benutzername darf keine Leerzeichen enthalten!<br>";
        } else {
            $name = make_secure($_POST["username"] ?? "");
            $email = make_secure($_POST["email"] ?? "");
            $pass = make_secure($_POST["password"] ?? "");

            if (empty($name)) {
                $error .= "Bitte einen Benutzernamen angeben!<br>";
            } else {
                $pattern = '/^' . preg_quote(strtolower($name), '/') . '$/i';
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

        if (empty($email)) {
            $error .= "Bitte E-Mail angeben!<br>";
        } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error .= "Falsches E-Mail Format!<br>";
        } else {
            $domain = substr(strrchr($email, "@"), 1);
            if (!checkdnsrr($domain) && !checkdnsrr($domain, "A")) {
                $error .= "Die E-Mail existiert nicht oder kann keine Mails empfangen!<br>";
            }
        }

        if (empty($pass)) {
            $error .= "Bitte ein Passwort angeben!<br>";
        } else if (strlen($pass) < MIN_PASSWORD_LENGTH || strlen($pass) > MAX_PASSWORD_LENGTH) {
            $error .= "Passwort muss zwischen " . MIN_PASSWORD_LENGTH . " und " . MAX_PASSWORD_LENGTH . " Zeichen lang sein!<br>";
        }

        if (empty($error)) {
            $result = $db_instance->execute_query("SELECT id FROM users WHERE username = ? OR email = ?", [$name, $email]);

            if ($result->num_rows > 0) {
                $error .= "Benutzername oder E-Mail existiert bereits!<br>";
            } else {
                $user->register_user($name, $email, $pass);
                $logger->log_game("ACCOUNT", "REGISTER", ["email" => $email, "username" => $name]);
                $success = $user->get_reg_status();
                $mode = "login";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<?php include_once("layout/head.html"); ?>
<body>
<?php include_once("layout/banner.html"); ?>

<div class="form">
    <?php if ($mode === "login"): ?>
        <form class="login-register" method="POST" action="index.php">
            <fieldset>
                <legend><b>Login</b></legend>
                <?php
                if (!empty($success)) {
                    if (str_contains($success, "info-box")) {
                        echo $success;
                    } else {
                        echo show_passed_box($success);
                    }
                }
                if (!empty($error)) echo show_error_box($error);
                ?>
                <table class="table">
                    <tr>
                        <td style="min-width: 165px;"><b>Benutzername:</b></td>
                        <td><label><input type="text" name="username" value="<?= $_POST["username"] ?? ""; ?>"
                                          style="width: 100%;"></label></td>
                    </tr>
                    <tr>
                        <td><b>Passwort:</b></td>
                        <td><label><input type="password" name="password" style="width: 100%;"></label></td>
                    </tr>
                </table>
                <input type="submit" name="login" value="Einloggen" style="width:125px; height:50px; margin: 15px 0;"/>
                <hr>
                <i>Du bist noch nicht registriert? Registriere dich <a href="index.php?action=register"><b>hier</b></a>.</i>
            </fieldset>
        </form>

    <?php else: ?>
        <form class="login-register" method="POST" action="index.php?action=register">
            <fieldset>
                <legend><b>Registrieren</b></legend>
                <?php
                if (!empty($success)) echo $success;
                if (!empty($error)) {
                    $error_messages = explode("<br>", $error);
                    foreach ($error_messages as $e) {
                        if (trim($e) !== "") echo show_error_box($e);
                    }
                }
                ?>
                <table class="table">
                    <tr>
                        <td style="min-width: 165px;"><b>Benutzername:</b></td>
                        <td><label><input class="regis" type="text" name="username"
                                          value="<?= $_POST["username"] ?? ""; ?>" autocomplete="username"
                                          style="width: 100%;"></label></td>
                    </tr>
                    <tr>
                        <td><b>E-Mail:</b></td>
                        <td><label><input class="regis" type="text" name="email" value="<?= $_POST["email"] ?? ""; ?>"
                                          autocomplete="email" style="width: 100%;"></label></td>
                    </tr>
                    <tr>
                        <td><b>Passwort:</b></td>
                        <td><label><input class="regis" type="password" name="password" autocomplete="new-password"
                                          style="width: 100%;"></label></td>
                    </tr>
                    <tr>
                        <td><b>Botschutz:</b></td>
                        <td style="display: flex; justify-content: flex-start; align-items: center; padding: 10px;">
                            <?php if (!isset($_SESSION["captcha_passed"]) || $_SESSION["captcha_passed"] !== true): ?>
                                <div class="g-recaptcha" data-sitekey="<?= getenv("LOCALHOST_CLIENT_KEY") ?>"
                                     data-size="compact"></div>
                            <?php else: echo "✅"; endif; ?>
                        </td>
                    </tr>
                </table>
                <input type="submit" name="register" value="Registrieren"
                       style="width:125px; height:50px; margin: 15px 0;"/>
                <hr>
                <i>Du bist bereits registriert? Logge dich <a href="index.php"><b>hier</b></a> ein.</i>
            </fieldset>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
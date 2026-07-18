<?php

use Random\RandomException;

require_once("includes/core.php");

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    unset($_SESSION["captcha_passed"]);
}

if (isset($_GET["banned"])) {
    $error = "Du wurdest soeben gebannt! Grund: " . e($_GET["banned"]);
}

$success = "";
$error = "";
$warning = "";
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
    $mode = "login";
    $logout_type = $_GET["logout"];

    $url_hash = $_GET["v"] ?? "";
    $cookie_hash = $_COOKIE["logout_verify"] ?? "";

    $is_system_logout = (!empty($url_hash) && $url_hash === $cookie_hash);
    $is_manual_logout = (empty($logout_type) && $user->is_logged_in());

    if ($is_system_logout) {
        if ($logout_type === "inactive") {
            $warning = "Du wurdest aus Inaktivitätsgründen automatisch ausgeloggt!";
        } else if ($logout_type === "session") {
            $warning = "Deine Session ist abgelaufen. Bitte logge dich erneut ein!";
        } else if ($logout_type === "maintenance") {
            $warning = "Der Server befindet sich im Wartungsmodus!";
        } else if ($logout_type === "deleted") {
            $success = "Dein Account wurde erfolgreich gelöscht!";
        }
    } else if ($is_manual_logout) {
        $success = "Du hast dich erfolgreich ausgeloggt!";
    }

    if ($user->is_logged_in()) {
        // DB Cleanup
        $db_instance->execute_query("UPDATE users SET msgcount = ?, lastsentmsgend = ? WHERE id = ?",
                [$_SESSION["message_count"] ?? 0, $_SESSION["message_timeframe_end"] ?? 0, $user->get_user_id()]);

        // Stop Session safely
        $_SESSION = array();

//        if (ini_get("session.use_cookies")) {
//            $params = session_get_cookie_params();
//
//            setcookie(session_name(), '', time() - 42000,
//                    $params["path"], $params["domain"],
//                    $params["secure"], $params["httponly"]
//            );
//        }

        session_destroy();
    }

    setcookie("logout_verify", "", time() - 3600, "/");

    if (empty($success) && empty($warning)) {
        header("Location: index.php");
        exit;
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
            $result = $db_instance->execute_query("SELECT id, password, status, adminlevel, is_banned, ban_reason FROM users WHERE username = ? LIMIT 1", [$name]);

            if ($result && $result->num_rows == 1) {
                $row = $result->fetch_assoc();

                if ($row["is_banned"] == 1) {
                    $error .= "Dein Account wurde gesperrt!<br>Grund: " . e($row["ban_reason"]);
                } else if (MAINTENANCE_MODE && $row["adminlevel"] == 0) {
                    $warning = "Der Server befindet sich im Wartungsmodus!";
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

        $current_ip = $_SERVER["REMOTE_ADDR"];
        $ip_check = $db_instance->execute_query("SELECT id FROM users WHERE ip = ? AND is_banned = 1 LIMIT 1", [$current_ip]);

        if ($ip_check->num_rows > 0) {
            $error .= "Deine IP-Adresse ist für Neuregistrierungen gesperrt!<br>";
        } else {
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
                    $bad_names_list = get_bad_names();
                    $bad_names_matches = preg_grep($pattern, $bad_names_list);

                    if (!preg_match("/^[a-zA-Z0-9äöüÄÖÜß_-]+$/u", $name)) {
                        $error .= "Erlaubte Zeichen: Buchstaben, Zahlen, _ und -<br>";
                    } else if (!empty($bad_names_matches) || contains_bad_words($name, $bad_names_list) || preg_match_all(regex_pattern(), $name, $matches)) {
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
                if (str_ends_with(strtolower($email), "@magic-empires.de")) {
                    $error .= "Diese E-Mail-Adresse ist nicht gestattet!<br>";
                } else {
                    $domain = substr(strrchr($email, "@"), 1);

                    if (!checkdnsrr($domain) && !checkdnsrr($domain, "A")) {
                        $error .= "Die E-Mail existiert nicht oder kann keine Mails empfangen!<br>";
                    }
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
                    try {
                        $user->register_user($name, $email, $pass);

                        $logger->log_game("ACCOUNT", "REGISTER", ["email" => $email, "username" => $name]);
                        $success = $user->get_reg_status();
                        $mode = "login";
                    } catch (RandomException $e) {
                        $logger->error("Registrierung fehlgeschlagen (RandomException): " . $e->getMessage());
                        $error .= "Ein interner Systemfehler ist aufgetreten. Bitte versuche es in wenigen Minuten erneut.<br>";
                        $mode = "register";
                    }
                }
            }
        }
    }
}

if (MAINTENANCE_MODE) {
    $maintenance_text = "Der Server befindet sich im Wartungsmodus!";

    if (empty($warning)) {
        $warning = $maintenance_text;
    } else if (!str_contains($warning, $maintenance_text)) {
        $warning .= "<br>" . $maintenance_text;
    }
}
$online_limit = time() - AFK_SECONDS;
$res_online = $db_instance->execute_query("SELECT COUNT(*) FROM users WHERE lastactivity > ?", [$online_limit]);
$count_online = $res_online->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="de">
<?php include_once("layout/head.html"); ?>
<body>
<div class="header img">
    <img src="images/header.png" alt="Header"/>
</div>

<div class="middle-container" style="margin: auto; width: 1100px; max-width: 95%;">
    <div class="big-box-container">
        <div class="landing-messages">
            <?php
            if (!empty($success)) {
                echo (str_contains($success, "info-box")) ? $success : show_passed_box($success);
            }

            if (!empty($error)) {
                $errors = explode("<br>", $error);
                foreach ($errors as $e) {
                    if (trim($e) !== "") echo show_error_box($e);
                }
            }

            if (!empty($warning)) {
                $warning_messages = explode("<br>", $warning);
                foreach ($warning_messages as $w) {
                    if (trim($w) !== "") echo show_warning_box($w);
                }
            }
            ?>
        </div>

        <div class="landing-main">
            <div class="landing-hero">
                <div class="hero-header">
                    <h1 style="text-align: center;">Willkommen bei<br>Magic Empires!</h1>
                    <p style="margin-top: 0;">
                        Schreibe deine eigene Geschichte in einer Welt voller Magie und Strategie.
                        Errichte prachtvolle Königreiche, erforsche vergessene Technologien und führe deine
                        Truppen in epische Schlachten.
                    </p>
                    <p>
                        Ob als friedlicher Händler auf dem Marktplatz oder als furchtloser Eroberer –
                        dein Schicksal liegt in deinen Händen.
                    </p>
                    <div class="ready-msg"><b class="passed">Bereit für den Kampf?</b></div>
                </div>

                <div class="hero-footer">
                    <hr>
                    <?php if ($mode === "login"): ?>
                        <i>Noch kein Konto? <a href="index.php?action=register"
                                               style="color: var(--link-color); text-decoration: underline;"><b>Hier
                                    kostenlos registrieren!</b></a></i>
                    <?php else: ?>
                        <i>Bereits registriert? <a href="index.php?action=login"
                                                   style="color: var(--link-color); text-decoration: underline;"><b>Zum
                                    Login!</b></a></i>
                    <?php endif; ?>
                </div>
            </div>

            <div class="landing-login-box">
                <div class="form" style="padding: 0;">
                    <?php if ($mode === "login"): ?>
                        <form class="login-register" method="POST" action="index.php"
                              style="max-width: 100%;">
                            <fieldset class="box-content-bg">
                                <legend><b>Login</b></legend>
                                <table class="table" style="width: 100%;">
                                    <tr>
                                        <td><label>
                                                <input type="text" name="username" placeholder="Benutzername"
                                                       style="width: 100%;">
                                            </label></td>
                                    </tr>
                                    <tr>
                                        <td><label>
                                                <input type="password" name="password" placeholder="Passwort"
                                                       style="width: 100%;">
                                            </label></td>
                                    </tr>
                                </table>
                                <input type="submit" name="login" value="Einloggen"
                                       style="width:150px; height:40px; margin: 10px 0;"/>
                                <a href="forgotpassword.php" style="display: block; font-size: 13px; opacity: 0.7;">Passwort
                                    vergessen?</a><br>
                                <a href="index.php?action=register"
                                   style="display: block; font-size: 13px; opacity: 0.7;">Hier
                                    registrieren!</a>
                            </fieldset>
                        </form>
                    <?php else: ?>
                        <form class="login-register" method="POST" action="index.php?action=register"
                              style="max-width: 100%;">
                            <fieldset class="box-content-bg">
                                <legend><b>Registrieren</b></legend>
                                <table class="table" style="width: 100%;">
                                    <tr>
                                        <td><label>
                                                <input type="text" name="username" placeholder="Benutzername"
                                                       style="width: 100%;" value="<?= e($_POST["username"] ?? "") ?>">
                                            </label></td>
                                    </tr>
                                    <tr>
                                        <td><label>
                                                <input type="text" name="email" placeholder="E-Mail Adresse"
                                                       style="width: 100%;" value="<?= e($_POST["email"] ?? "") ?>">
                                            </label></td>
                                    </tr>
                                    <tr>
                                        <td><label>
                                                <input type="password" name="password" placeholder="Passwort"
                                                       style="width: 100%;">
                                            </label></td>
                                    </tr>
                                    <tr>
                                        <td style="display: flex; justify-content: center; padding: 10px;">
                                            <?php if (isset($_SESSION["captcha_passed"]) && $_SESSION["captcha_passed"] === true): ?>
                                                <div style="background: rgba(11, 218, 81, 0.1); padding: 10px; border-radius: 5px; text-align: center; width: 100%;">
                                                    <span class="passed">✔</span> <b>Botschutz verifiziert</b>
                                                </div>
                                                <input type="hidden" name="captcha_already_passed" value="1">
                                            <?php else: ?>
                                                <div class="g-recaptcha"
                                                     data-sitekey="<?= getenv("LOCALHOST_CLIENT_KEY") ?>"></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                                <input type="submit" name="register" value="Registrieren"
                                       style="height:40px; margin: 10px 0;"/>
                                <a href="index.php" style="display: block; font-size: 13px; opacity: 0.7;">Zurück zum
                                    Login</a>
                            </fieldset>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="landing-sidebar">
                    <div class="box-container">
                        <div class="box-header">Spieler online</div>
                        <div class="box-content box-content-bg">
                            <div class="box"
                                 style="justify-content: center; text-align: center; gap: 5px; pointer-events: none;">
                                Gesamt: <b><?= $count_online ?></b>
                            </div>
                        </div>
                    </div>
                    <div class="box-container">
                        <div class="box-header">Info</div>
                        <div class="box-content">
                            <a href="news.php" class="box">
                                <img src="images/icons/icon_news.png" class="menu-icons" alt="Neuigkeiten"/> Neuigkeiten
                            </a>
                            <a href="rules.php" class="box">
                                <img src="images/icons/icon_rules.png" class="menu-icons" alt="Spielregeln"/>
                                Spielregeln
                            </a>
                            <a href="faq.php" class="box">
                                <img src="images/icons/icon_faq.png" class="menu-icons" alt="FAQ"/> FAQ
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
<footer>
    <?php include_once("layout/copyright.php"); ?>
</footer>
</body>
</html>
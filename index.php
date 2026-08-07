<?php

use Random\RandomException;

require_once("includes/core.php");

$maintenance_text = "Der Server befindet sich im Wartungsmodus!";

if (!empty(MAINTENANCE_REASON)) {
    $maintenance_text .= "<br>Grund: " . e(MAINTENANCE_REASON);
}

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
$now = time();

// ACCOUNT ACTIVATION
if (!empty($_GET["key"])) {
    $activation_key = make_secure($_GET["key"]);
    $result = $db_instance->execute_query("SELECT id, username, status FROM users WHERE activationkey = ? LIMIT 1", [$activation_key]);

    if ($result && $result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        $user_id = $user_data["id"];
        $username = $user_data["username"];

        if (!$user_data["status"]) {
            $max_news_id = $db_instance->query("SELECT MAX(id) FROM news")->fetch_row()[0] ?? 0;
            $max_chat_id = $db_instance->query("SELECT MAX(id) FROM world_chat")->fetch_row()[0] ?? 0;

            $db_instance->execute_query(
                    "UPDATE users SET status = true, activationkey = '', last_news_read = ?, last_world_chat_id = ? WHERE id = ?",
                    [$max_news_id, $max_chat_id, $user_id]
            );

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
            $warning = $maintenance_text;
        } else if ($logout_type === "deleted") {
            $success = "Dein Account wurde erfolgreich gelöscht!";
        }
    } else if ($is_manual_logout) {
        $success = "Du hast dich erfolgreich ausgeloggt!";
    }

    if ($user->is_logged_in()) {
        if (isset($_COOKIE["me_remember"])) {
            list($uid, $token) = explode(':', $_COOKIE["me_remember"], 2);
            $token_hash = hash("sha256", $token);

            $db_instance->execute_query("DELETE FROM user_remember_tokens WHERE userid = ? AND token_hash = ?", [(int)$uid, $token_hash]);

            setcookie("me_remember", '', time() - 3600, '/');
        }

        // DB Cleanup
        $db_instance->execute_query("UPDATE users SET msgcount = ?, lastsentmsgend = ? WHERE id = ?",
                [$_SESSION["message_count"] ?? 0, $_SESSION["message_timeframe_end"] ?? 0, $user->get_user_id()]);

        // Stop Session safely
        $_SESSION = array();

        session_destroy();
    }

    setcookie("logout_verify", "", $now - 3600, "/");

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
                    $warning = $maintenance_text;
                } else {
                    if (!$row["status"]) {
                        $error .= "Account noch nicht aktiviert durch Aktivierungslink!";
                    } else if (!password_verify($pass, $row["password"])) {
                        $logger->security("Login failed for user: $name");

                        $error .= "Nutzername oder Passwort ist falsch!";
                    } else {
                        $keep_logged_in = isset($_POST["remember_me"]);

                        unset($_POST);

                        $user->login_user($row["id"]);

                        if ($keep_logged_in) {
                            $user->create_remember_me_token();
                        }

                        change_location("overview.php");
                        exit;
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

        $query_check = "
            SELECT 
                (SELECT COUNT(*) FROM users WHERE status = 1) AS active_players,
                (SELECT 1 FROM users WHERE ip = ? AND is_banned = 1 LIMIT 1) AS is_ip_banned
        ";
        $res_check = $db_instance->execute_query($query_check, [$current_ip]);
        $check_data = $res_check->fetch_assoc();

        $current_active_players = (int)($check_data["active_players"] ?? 0);
        $is_ip_banned = !empty($check_data["is_ip_banned"]);

        if ($is_ip_banned) {
            $error .= "Deine IP-Adresse ist für Neuregistrierungen gesperrt!<br>";
        } else if ($current_active_players >= MAX_PLAYER_LIMIT) {
            $error .= "Das Spielerlimit von " . MAX_PLAYER_LIMIT . " wurde erreicht. Aktuell sind leider keine Neuanmeldungen möglich.<br>";
        } else {
            if (!isset($_POST["accept_rules"])) {
                $error .= "Du musst die Spielregeln akzeptieren!<br>";
            }

            if (!isset($_SESSION["captcha_passed"]) || $_SESSION["captcha_passed"] !== true) {
                $response = $_POST["g-recaptcha-response"] ?? "";
                $json = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=" . getenv("SERVER_KEY") . "&response=" . $response);
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
                $pass_repeat = make_secure($_POST["password_repeat"] ?? "");

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
                    } else if (mb_strlen($name) < MIN_USERNAME_LENGTH || mb_strlen($name) > MAX_USERNAME_LENGTH) {
                        $error .= "Benutzername muss zwischen " . MIN_USERNAME_LENGTH . " und " . MAX_USERNAME_LENGTH . " Zeichen lang sein!<br>";
                    } else if (is_name_monotonous($name)) {
                        $error .= "Dieser Benutzername ist zu eintönig!<br>";
                    }
                }
            }

            if (empty($email)) {
                $error .= "Bitte E-Mail angeben!<br>";
            } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error .= "Falsches E-Mail Format!<br>";
            } else if (strlen($email) > MAX_EMAIL_LENGTH) {
                $error .= "Die E-Mail Adresse ist zu lang (max. " . MAX_EMAIL_LENGTH . " Zeichen)!<br>";
            } else {
                if (str_ends_with(strtolower($email), "@magic-empires.de") ||
                        str_ends_with(strtolower($email), "@sylvan-giese.de")) {
                    $error .= "Diese E-Mail-Adresse ist nicht gestattet!<br>";
                } else {
                    $domain = substr(strrchr($email, "@"), 1);

                    if (!checkdnsrr($domain) && !checkdnsrr($domain, "A")) {
                        $error .= "Die E-Mail existiert nicht oder kann keine Mails empfangen!<br>";
                    } else {
                        $res_block = $db_instance->execute_query("SELECT blocked_until FROM blocked_emails WHERE email = ? AND blocked_until > ?", [$email, $now]);

                        if ($res_block->num_rows > 0) {
                            $row_block = $res_block->fetch_assoc();
                            $wait_until = date("d.m.Y", $row_block["blocked_until"]);

                            $error .= "Diese E-Mail Adresse ist noch bis zum $wait_until gesperrt.<br>";
                        }
                    }
                }
            }

            if (empty($pass)) {
                $error .= "Bitte ein Passwort angeben!<br>";
            } else if ($pass !== $pass_repeat) {
                $error .= "Die Passwörter stimmen nicht überein!<br>";
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
    if (empty($warning)) {
        $warning = $maintenance_text;
    } else if (!str_contains($warning, "Wartungsmodus")) {
        $warning .= "<br>" . $maintenance_text;
    }
}
$online_limit = $now - TIMEOUT_MAX_SECONDS;
$res_online = $db_instance->execute_query("SELECT COUNT(*) FROM users WHERE lastactivity > ? AND status = 1", [$online_limit]);
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
        <?php if (!empty($success) || !empty($error) || !empty($warning)): ?>
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
                    echo show_warning_box($warning);
                }
                ?>
            </div>
        <?php endif; ?>

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
                                    <tr>
                                        <td style="padding: 5px 10px; text-align: left;">
                                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px;">
                                                <input type="checkbox" name="remember_me" style="width: auto;">
                                                Angemeldet bleiben
                                            </label>
                                        </td>
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
                        <?php
                        $res_ui_count = $db_instance->execute_query("SELECT COUNT(*) FROM users WHERE status = 1");
                        $ui_count = (int)$res_ui_count->fetch_row()[0];
                        ?>

                        <?php if ($ui_count >= MAX_PLAYER_LIMIT): ?>
                            <div class="info-box event-warning" style="margin-top: 20px;">
                                <span>
                                    <b>Server voll:</b><br>Wir haben aktuell die maximale Kapazität von <b><?= MAX_PLAYER_LIMIT ?></b> Spielern erreicht.
                                    Bitte versuche es später erneut oder schau in die <a href="news.php"
                                                                                         style="text-decoration: underline;">News</a>.
                                </span>
                            </div>
                        <?php else: ?>
                            <form class="login-register" method="POST" action="index.php?action=register"
                                  style="max-width: 100%;">
                                <fieldset class="box-content-bg">
                                    <legend><b>Registrieren</b></legend>
                                    <table class="table" style="width: 100%;">
                                        <tr>
                                            <td><label>
                                                    <input type="text" name="username" placeholder="Benutzername"
                                                           style="width: 100%;"
                                                           value="<?= e($_POST["username"] ?? "") ?>">
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
                                            <td><label>
                                                    <input type="password" name="password_repeat"
                                                           placeholder="Passwort wiederholen"
                                                           style="width: 100%;">
                                                </label></td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 10px; text-align: left; font-size: 14px;">
                                                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                                    <input type="checkbox" name="accept_rules" value="1"
                                                           style="width: auto;" <?= isset($_POST["accept_rules"]) ? "checked" : '' ?>>
                                                    <span>Ich akzeptiere die <a href="rules.php" target="_blank"
                                                                                style="text-decoration: underline; color: var(--link-color);">Regeln</a>.</span>
                                                </label>
                                            </td>
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
                                                         data-sitekey="<?= getenv("CLIENT_KEY") ?>"></div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </table>
                                    <input type="submit" name="register" value="Registrieren"
                                           style="height:40px; margin: 10px 0;"/>
                                    <a href="index.php" style="display: block; font-size: 13px; opacity: 0.7;">Zurück
                                        zum
                                        Login</a>
                                </fieldset>
                            </form>
                        <?php endif; ?>
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
                            <a href="imprint.php" class="box">
                                <img src="images/icons/icon_imprint.png" class="menu-icons" alt="Impressum"/> Impressum
                            </a>
                            <a href="privacy.php" class="box">
                                <img src="images/icons/icon_privacy.png" class="menu-icons" alt="Datenschutz"/>
                                Datenschutz
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
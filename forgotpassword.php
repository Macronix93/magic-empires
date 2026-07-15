<?php

use Random\RandomException;

require_once("includes/core.php");

if ($user->is_logged_in()) {
    change_location("overview.php");
    exit;
}

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["request_reset"])) {
    $email = make_secure($_POST["email"] ?? "");

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Bitte eine gültige E-Mail-Adresse eingeben.";
    } else {
        $result = $db_instance->execute_query("SELECT id, username FROM users WHERE email = ? LIMIT 1", [$email]);

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();

            try {
                $token = bin2hex(random_bytes(32));
            } catch (RandomException $e) {
                $token = bin2hex(openssl_random_pseudo_bytes(32));
            }

            $expires = time() + 3600;

            $db_instance->execute_query("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?", [$token, $expires, $row["id"]]);

            $reset_link = "https://" . $_SERVER["HTTP_HOST"] . BASE_URL . "resetpassword.php?token=" . $token;

            $subject = "Magic Empires - Passwort zurücksetzen";
            $message = "Hallo " . e($row["username"]) . ",<br><br>
                        du hast angefordert, dein Passwort zurückzusetzen. Klicke dazu auf den folgenden Link:<br>
                        <a href='$reset_link'>$reset_link</a><br><br>
                        Dieser Link ist für 1 Stunde gültig. Wenn du dies nicht warst, ignoriere diese Mail.";

            if (send_mail($email, $subject, $message)) {
                $success = "Ein Link zum Zurücksetzen wurde an deine E-Mail gesendet.";
            } else {
                $error = "Mail konnte nicht gesendet werden. Support kontaktieren.";
            }
        } else {
            $success = "Falls diese E-Mail existiert, wurde ein Link gesendet.";
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
    <form class="login-register" method="POST" action="forgotpassword.php">
        <fieldset>
            <legend><b>Passwort vergessen</b></legend>
            <?php
            if (!empty($success)) echo show_passed_box($success);
            if (!empty($error)) echo show_error_box($error);
            ?>
            <p style="margin-top: 0;">Gib deine E-Mail-Adresse ein, um einen Reset-Link zu erhalten.</p>
            <table class="table" style="margin-bottom: 15px;">
                <tr>
                    <td style="min-width: 165px;"><b>E-Mail:</b></td>
                    <td><label><input type="text" name="email" style="width: 100%;"></label></td>
                </tr>
            </table>
            <input type="submit" name="request_reset" value="Link anfordern"
                   style="width:125px; height:50px; margin-bottom: 15px;"/>
            <hr>
            <i>Du kennst dein Passwort wieder? <a href="index.php"><b>Zum Login</b></a>.</i>
        </fieldset>
        <?php include_once("layout/copyright.php"); ?>
    </form>
</div>
</body>
</html>
<?php
require_once("includes/core.php");

if ($user->is_logged_in()) {
    change_location("overview.php");
}

$flash = consume_auth_flash();
$error = $flash["error"] ?? "";
$success = $flash["success"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf_token()) {
        set_auth_flash(["error" => "Ungültiger Sicherheits-Token!"]);
        change_location("forgotpassword.php");
    }

    if (isset($_POST["request_reset"])) {
        $email = make_secure(post_str("email"));
        $ip = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_auth_flash(["error" => "Bitte eine gültige E-Mail-Adresse eingeben."]);
            change_location("forgotpassword.php");
        }

        $wait = max(RateLimiter::is_blocked("forgot_ip", $ip), RateLimiter::is_blocked("forgot_mail", strtolower($email)));
        if ($wait > 0) {
            set_auth_flash(["error" => "Zu viele Anfragen! Bitte warte noch " . convert_sec_to_str($wait) . "."]);
            change_location("forgotpassword.php");
        }

        RateLimiter::hit("forgot_ip", $ip, 5, 1800, 1800);
        RateLimiter::hit("forgot_mail", strtolower($email), 2, 1800, 1800);

        $result = $db_instance->execute_query("SELECT id, username FROM users WHERE email = ? LIMIT 1", [$email]);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            try {
                $token = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                $token = bin2hex(openssl_random_pseudo_bytes(32));
            }

            $expires = time() + 3600;
            $db_instance->execute_query("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?", [$token, $expires, $row["id"]]);

            $reset_link = "https://" . $_SERVER["HTTP_HOST"] . BASE_URL . "resetpassword.php?token=" . $token;
            $subject = "Passwort zurücksetzen";
            $message = "
                <h2 style='color: #d4af37; text-align: center; margin-top: 0;'>Passwort vergessen?</h2>
                <p>Werte/r <b>" . e($row["username"]) . "</b>,</p>
                <p>uns erreichte eine Anfrage, das Passwort für Euren Zugang zu Magic Empires zurückzusetzen.</p>
                <p>Über diesen Button gelangt Ihr zur Seite, auf der Ihr ein neues Passwort generieren könnt:</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='$reset_link'
                       style='background: #781e14; background: linear-gradient(0deg, #4b140a 0%, #781e14 100%); color: #ffffff; padding: 15px 25px; text-decoration: none; border-radius: 3px;
                              border: 2px solid #a57c00; font-weight: bold; display: inline-block; text-shadow: 1px 1px 2px #000;'>
                        PASSWORT ZURÜCKSETZEN
                    </a>
                </div>
                <p style='font-size: 13px; opacity: 0.7;'>Hinweis: Dieser Link ist für 1 Stunde gültig. Falls Ihr diese Anfrage nicht gestellt habt, könnt Ihr diese E-Mail ignorieren.</p>
            ";

            if (!send_mail($email, $subject, $message)) {
                set_auth_flash(["error" => "Mail konnte nicht gesendet werden. Support kontaktieren (support@magic-empires.de)."]);
                change_location("forgotpassword.php");
            }
        }

        set_auth_flash(["success" => "Falls diese E-Mail existiert, wurde ein Link gesendet."]);
        change_location("forgotpassword.php");
    }

    change_location("forgotpassword.php");
}
?>
<!DOCTYPE html>
<html lang="de" class="<?= WINTER_THEME_ACTIVE ? "theme-winter" : '' ?>">
<?php include_once("layout/head.php"); ?>
<body>
<?php include_once("layout/banner.html"); ?>
<div id="middle-flash-container" class="middle-flash-container landing-flash">
    <?php
    if (!empty($success)) echo show_passed_box($success);
    if (!empty($error)) echo show_error_box($error);
    ?>
</div>
<div class="form">
    <form class="login-register" method="POST" action="forgotpassword.php">
        <fieldset class="box-content-bg">
            <legend><b>Passwort vergessen</b></legend>
            <p style="margin-top: 0;">Gib deine E-Mail-Adresse ein, um einen Reset-Link zu erhalten.</p>
            <table class="table" style="margin-bottom: 15px;">
                <tr>
                    <td style="min-width: 165px;"><b>E-Mail:</b></td>
                    <td><label><input type="text" name="email" style="width: 100%;"></label></td>
                </tr>
            </table>
            <input type="submit" name="request_reset" value="Link anfordern"
                   style="width: 125px; margin-bottom: 15px;"/>
            <hr>
            <i>Du kennst dein Passwort wieder? <a href="index.php"><b>Zum Login</b></a>.</i>
        </fieldset>
        <?php include_once("layout/copyright.php"); ?>
    </form>
</div>
<script nonce="<?= $nonce ?>">
    document.addEventListener("DOMContentLoaded", () => {
        const flashContainer = document.getElementById("middle-flash-container");
        if (!flashContainer) return;

        const boxes = flashContainer.querySelectorAll(".info-box");
        const dismissBox = (box) => {
            if (!box || box.classList.contains("fade-out")) return;
            box.classList.add("fade-out");
            box.addEventListener("animationend", () => box.remove(), {once: true});
        };

        boxes.forEach(box => {
            setTimeout(() => dismissBox(box), 4000);
            box.addEventListener("click", () => dismissBox(box));
        });
    });
</script>
</body>
</html>
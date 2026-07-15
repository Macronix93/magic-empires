<?php
require_once("includes/core.php");

if ($user->is_logged_in()) {
    change_location("overview.php");
    exit;
}

$token = $_GET["token"] ?? "";
if (empty($token)) {
    change_location("index.php");
    exit;
}

$error = "";
$success = "";
$token_valid = false;
$user_data = null;

$result = $db_instance->execute_query("SELECT id FROM users WHERE reset_token = ? AND reset_expires > ? LIMIT 1", [$token, time()]);

if ($result && $result->num_rows > 0) {
    $token_valid = true;
    $user_data = $result->fetch_assoc();
} else {
    $error = "Der Link ist ungültig oder abgelaufen.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["reset_now"]) && $token_valid) {
    $new_plain_password = generate_safe_password(8);
    $new_hash = password_hash($new_plain_password, PASSWORD_BCRYPT);

    $db_instance->execute_query("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?", [$new_hash, $user_data["id"]]);

    $success = "Dein neues Passwort lautet: <br><br><b style='font-size: 24px; color: var(--link-color); border: 1px dashed; padding: 5px;'>$new_plain_password</b><br><br>Bitte notiere es dir sofort und logge dich damit ein!";

    $token_valid = false;
}
?>
<!DOCTYPE html>
<html lang="de">
<?php include_once("layout/head.html"); ?>
<body>
<?php include_once("layout/banner.html"); ?>

<div class="form">
    <form class="login-register" method="POST" action="resetpassword.php?token=<?= e($token) ?>">
        <fieldset>
            <legend><b>Passwort zurücksetzen</b></legend>
            <?php
            if (!empty($success)) {
                echo show_passed_box($success);
            }
            if (!empty($error)) echo show_error_box($error);
            ?>
            <?php if ($token_valid && empty($success)): ?>
                <p style="margin-top: 0;">Klicke auf den Button, um ein neues, sicheres Passwort für deinen Account zu
                    generieren.</p>

                <div style="margin: 20px 0;">
                    <input type="submit" name="reset_now" value="Passwort jetzt generieren"
                           style="width:220px; height:50px; cursor: pointer; font-weight: bold;"/>
                </div>

                <p style="font-size: 13px; opacity: 0.8;">
                    <i>Hinweis: Das System erstellt ein zufälliges Passwort und zeigt es dir im nächsten Schritt an.</i>
                </p>
            <?php endif; ?>
            <hr>
            <a href="index.php"><b>Zurück zum Login</b></a>
        </fieldset>
        <?php include_once("layout/copyright.php"); ?>
    </form>
</div>
</body>
</html>
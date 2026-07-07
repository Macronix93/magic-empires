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
    $pass1 = $_POST["pass1"] ?? "";
    $pass2 = $_POST["pass2"] ?? "";

    if (strlen($pass1) < MIN_PASSWORD_LENGTH) {
        $error = "Das Passwort muss mindestens " . MIN_PASSWORD_LENGTH . " Zeichen lang sein!";
    } else if ($pass1 !== $pass2) {
        $error = "Die Passwörter stimmen nicht überein!";
    } else {
        $new_hash = password_hash(make_secure($pass1), PASSWORD_BCRYPT);

        $db_instance->execute_query("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?", [$new_hash, $user_data["id"]]);

        $success = "Passwort erfolgreich geändert! Du wirst zum Login weitergeleitet...";
        change_location("index.php", 3);
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<?php include_once("layout/head.html"); ?>
<body>
<?php include_once("layout/banner.html"); ?>

<div class="form">
    <form class="login-register" method="POST" action="reset_password.php?token=<?= e($token) ?>">
        <fieldset>
            <legend><b>Neues Passwort vergeben</b></legend>
            <?php
            if (!empty($success)) echo show_passed_box($success);
            if (!empty($error)) echo show_error_box($error);
            ?>

            <?php if ($token_valid && empty($success)): ?>
                <p style="margin-top: 0;">Gib jetzt dein neues Passwort ein.</p>
                <table class="table" style="margin-bottom: 15px;">
                    <tr>
                        <td style="min-width: 150px;"><b>Neues Passwort:</b></td>
                        <td><label><input type="password" name="pass1" style="width: 100%;"></label></td>
                    </tr>
                    <tr>
                        <td><b>Wiederholen:</b></td>
                        <td><label><input type="password" name="pass2" style="width: 100%;"></label></td>
                    </tr>
                </table>
                <input type="submit" name="reset_now" value="Speichern"
                       style="width:125px; height:50px; margin-bottom: 15px;"/>
            <?php endif; ?>

            <hr>
            <a href="index.php"><b>Zurück zum Login</b></a>
        </fieldset>
        <?php include_once("layout/copyright.php"); ?>
    </form>
</div>
</body>
</html>
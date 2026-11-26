<?php
require_once("includes/core.php");

if ($user->is_logged_in()) {
    change_location("overview.php");
}

$token = get_str("token");
if (empty($token)) {
    change_location("index.php");
}

$flash = consume_auth_flash();
$toast_error = $flash["error"] ?? "";
$new_password = $flash["new_password"] ?? "";
$token_error = "";
$token_valid = false;
$user_data = null;

if ($new_password === "") {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token_error = "Der Link ist ungültig oder abgelaufen.";
    } else {
        $result = $db_instance->execute_query(
                "SELECT id, username FROM users WHERE reset_token = ? AND reset_expires > ? LIMIT 1",
                [$token, time()]
        );

        if ($result && $result->num_rows > 0) {
            $token_valid = true;
            $user_data = $result->fetch_assoc();
        } else {
            $token_error = "Der Link ist ungültig oder abgelaufen.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $redir = "resetpassword.php?token=" . urlencode($token);

    if (!verify_csrf_token()) {
        set_auth_flash(["error" => "Ungültiger Sicherheits-Token!"]);
        change_location($redir);
    }

    if (isset($_POST["reset_now"])) {
        if (!$token_valid) {
            change_location($redir);
        }

        $new_plain_password = generate_safe_password(8);
        $new_hash = password_hash($new_plain_password, PASSWORD_BCRYPT);
        $db_instance->execute_query(
                "UPDATE users SET sessionid = '', password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?",
                [$new_hash, $user_data["id"]]
        );
        $db_instance->execute_query("DELETE FROM user_remember_tokens WHERE userid = ?", [$user_data["id"]]);

        PhpBBBridge::update_password($user_data["username"] ?? "", $new_hash);

        set_auth_flash(["new_password" => $new_plain_password]);
        change_location($redir);
    }

    change_location($redir);
}
?>
<!DOCTYPE html>
<html lang="de" class="<?= WINTER_THEME_ACTIVE ? "theme-winter" : '' ?>">
<?php include_once("layout/head.php"); ?>
<body>
<?php include_once("layout/banner.html"); ?>

<?php if (!empty($toast_error)): ?>
    <div id="middle-flash-container" class="middle-flash-container landing-flash">
        <?= show_error_box($toast_error) ?>
    </div>
<?php endif; ?>

<div class="form">
    <form class="login-register" method="POST" action="resetpassword.php?token=<?= e($token) ?>">
        <fieldset class="box-content-bg">
            <legend><b>Passwort zurücksetzen</b></legend>
            <?php
            if ($new_password !== "") {
                $copy_label = Messages::wrap_emojis("📋 Kopieren");
                $pw_html = "Dein neues Passwort lautet:<br><br>
                <div style='display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap; flex-direction: column;'>
                    <b id='new-reset-password' style='font-size: 24px; color: var(--link-color); border: 1px dashed; padding: 5px 12px; letter-spacing: 1px;'>" . e($new_password) . "</b>
                    <button type='button' id='btn-copy-password' style='padding: 8px 14px; min-width: 120px; max-height: 39px;'>$copy_label</button>
                </div><br>
                Bitte notiere es dir sofort und logge dich damit ein!";

                echo show_passed_box($pw_html, false);
            }
            if (!empty($token_error)) echo show_error_box($token_error);
            ?>
            <?php if ($token_valid && $new_password === ""): ?>
                <p style="margin-top: 0;">Klicke auf den Button, um ein neues, sicheres Passwort für deinen Account zu
                    generieren.</p>
                <div style="margin: 20px 0;">
                    <input type="submit" name="reset_now" value="Passwort jetzt generieren"
                           style="width: 220px; height: 50px; cursor: pointer; font-weight: bold;"/>
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

<?php if (!empty($toast_error) || $new_password !== ""): ?>
    <script nonce="<?= $nonce ?>">
        document.addEventListener("DOMContentLoaded", () => {
            const flashContainer = document.getElementById("middle-flash-container");
            if (flashContainer) {
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
            }

            const copyBtn = document.getElementById("btn-copy-password");
            const pwEl = document.getElementById("new-reset-password");
            if (copyBtn && pwEl) {
                const originalHtml = copyBtn.innerHTML;
                let resetTimer = null;

                copyBtn.addEventListener("click", async () => {
                    if (!navigator.clipboard?.writeText) return;

                    try {
                        await navigator.clipboard.writeText(pwEl.textContent.trim());
                        copyBtn.disabled = true;
                        copyBtn.textContent = "✔ Kopiert!";

                        clearTimeout(resetTimer);
                        resetTimer = setTimeout(() => {
                            copyBtn.innerHTML = originalHtml;
                            copyBtn.disabled = false;
                        }, 2500);
                    } catch (_) {
                    }
                });
            }
        });
    </script>
<?php endif; ?>
</body>
</html>
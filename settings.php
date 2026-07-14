<?php

use Random\RandomException;

require_once("includes/core.php");

check_user_login($user);

$uid = $user->get_user_id();
$res_user = $db_instance->execute_query("SELECT linked_user FROM users WHERE id = ?", [$uid]);
$user_data = $res_user->fetch_assoc();

// Generate a random token
if (!isset($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (RandomException $e) {
        $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true));
    }
}

// Add the token as a hidden input in the form
$csrf_token = $_SESSION['csrf_token'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Ungültiger Token!";
    } else {
        if (isset($_POST['submit_avatar'])) {
            if (isset($_FILES['image'])) {
                $file_name = $_FILES['image']['name'];
                $file_tmp = $_FILES['image']['tmp_name'];
                $file_size = $_FILES['image']['size'];
                $file_error = $_FILES['image']['error'];
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                $max_file_size = MAX_UPLOAD_FILE_SIZE * 1024; // Bytes

                if ($file_error !== 0) {
                    $error = "Es ist ein Fehler beim Hochladen aufgetreten!";
                } else if ($file_size > $max_file_size) {
                    $error = "Datei-Größe überschreitet die maximal erlaubte Größe von " . MAX_UPLOAD_FILE_SIZE . " KB!";
                } else {
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                    if (!in_array($file_ext, $allowed_extensions)) {
                        $error = "Ungültiger Datei-Typ! Erlaubt sind JPG, JPEG, PNG, oder GIF.";
                    } else {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime_type = $finfo->file($file_tmp);
                        $allowed_mimes = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/x-png', 'image/gif'];

                        if (!in_array($mime_type, $allowed_mimes)) {
                            $error = "Der Datei-Inhalt entspricht keinem gültigen Bild!";
                        } else if (getimagesize($file_tmp) === false) {
                            $error = "Die Bild-Datei ist beschädigt oder manipuliert!";
                        } else {
                            $nsfw_result = check_image_content($file_tmp);

                            if ($nsfw_result === "loading") {
                                $error = "Ladefehler... Bitte versuche es in 20 Sekunden nochmal.";
                            } else if (is_string($nsfw_result) && str_starts_with($nsfw_result, "error")) {
                                $error = "Inhaltsprüfung fehlgeschlagen: " . $nsfw_result;
                            } else {
                                $nsfw_score = (float)$nsfw_result;

                                if ($nsfw_score > 0.8) {
                                    $error = "Dein Bild wurde als unangemessen eingestuft.";

                                    //$logger->log_file("NSFW Blockiert", ["user" => $user->get_user_id(), "score" => $nsfw_score]);
                                } else {
                                    $hashed_name = substr(hash("sha256", $user->get_user_id() . AVATAR_SALT), 0, 12);
                                    $file_path = UPLOADS_FILE_PATH . $hashed_name;

                                    array_map("unlink", glob(UPLOADS_FILE_PATH . $hashed_name . ".*"));

                                    if (move_uploaded_file($file_tmp, $file_path . "." . $file_ext)) {
                                        $view = "Nutzerbild wurde erfolgreich hochgeladen!";

                                        $logger->log_game("ACCOUNT", "AVATAR_UPLOAD", [
                                            "filename" => $file_name,
                                            "extension" => $file_ext,
                                            "mime" => $mime_type,
                                            "size" => $file_size
                                        ]);

                                        unset($_SESSION['csrf_token']);
                                    } else {
                                        $error = "Fehler beim Hochladen der Datei auf den Server!";
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                $error = "Keine Datei ausgewählt!";
            }
        }

        // Change Password
        if (isset($_POST['change_password'])) {
            $old_pw = $_POST['old_pw'] ?? "";
            $new_pw = $_POST['new_pw'] ?? "";
            $new_pw_confirm = $_POST['new_pw_confirm'] ?? "";

            $res = $db_instance->execute_query("SELECT password FROM users WHERE id = ?", [$uid]);
            $current_hash = $res->fetch_column();

            if (!password_verify($old_pw, $current_hash)) {
                $error = "Dein aktuelles Passwort ist nicht korrekt.";
            } else if (strlen($new_pw) < MIN_PASSWORD_LENGTH) {
                $error = "Das neue Passwort muss mindestens " . MIN_PASSWORD_LENGTH . " Zeichen haben.";
            } else if ($new_pw !== $new_pw_confirm) {
                $error = "Die neuen Passwörter stimmen nicht überein.";
            } else {
                $new_hash = password_hash($new_pw, PASSWORD_BCRYPT);
                $db_instance->execute_query("UPDATE users SET password = ? WHERE id = ?", [$new_hash, $uid]);
                $view .= show_passed_box("Passwort erfolgreich geändert!");

                $logger->log_game("ACCOUNT", "PASSWORD_CHANGE");
            }
        }

        // Change Mail
        if (isset($_POST['change_email'])) {
            $new_email = trim($_POST['new_email']);
            $confirm_pw = $_POST['confirm_pw_email'] ?? "";

            $res = $db_instance->execute_query("SELECT password FROM users WHERE id = ?", [$uid]);
            $current_hash = $res->fetch_column();

            if (!password_verify($confirm_pw, $current_hash)) {
                $error = "Passwort-Bestätigung fehlgeschlagen.";
            } else if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $error = "Ungültiges E-Mail Format.";
            } else {
                $db_instance->execute_query("UPDATE users SET email = ? WHERE id = ?", [$new_email, $uid]);
                $view .= show_passed_box("Deine E-Mail Adresse wurde auf " . e($new_email) . " geändert.");

                $logger->log_game("ACCOUNT", "EMAIL_CHANGE", ["email" => $new_email]);
            }
        }

        // Change Kingdom Name
        if (isset($_POST['rename_kingdom'])) {
            $new_k_name = trim($_POST['new_kingdom_name'] ?? '');
            $current_k_id = $user->get_current_kingdom();

            if (mb_strlen($new_k_name) < MIN_KINGDOM_NAME_LENGTH || mb_strlen($new_k_name) > MAX_KINGDOM_NAME_LENGTH) {
                $error = "Der Name muss zwischen " . MIN_KINGDOM_NAME_LENGTH . " und " . MAX_KINGDOM_NAME_LENGTH . " Zeichen lang sein.";
            } else if (contains_bad_words($new_k_name)) {
                $error = "Der Name enthält unzulässige Begriffe.";
            } else if (!preg_match('/^[a-zA-Z0-9\s\[\]\-_.]+$/u', $new_k_name)) {
                $error = "Der Name enthält ungültige Sonderzeichen. Erlaubt sind: [ ] - _ .";
            } else {
                $db_instance->execute_query("UPDATE kingdoms SET kingdomname = ? WHERE id = ?", [$new_k_name, $current_k_id]);
                $logger->log_game("ECONOMY", "KINGDOM_RENAME", ["new_name" => $new_k_name], $current_k_id);

                $view .= show_passed_box("Dein Königreich wurde erfolgreich in '" . e($new_k_name) . "' umbenannt!");
            }
        }

        // IP Sharing Partner
        if (isset($_POST['update_sharing'])) {
            $partner = trim($_POST['partner_name'] ?? '');

            if (empty($partner)) {
                $db_instance->execute_query("UPDATE users SET linked_user = NULL WHERE id = ?", [$uid]);

                $view .= show_passed_box("IP-Sharing Partner wurde entfernt.");
                $user_data['linked_user'] = NULL;
            } else {
                $res = $db_instance->execute_query("SELECT id FROM users WHERE username = ? LIMIT 1", [$partner]);
                $partner_data = $res->fetch_assoc();

                if (!$partner_data) {
                    $error = "Ein Spieler mit dem Namen '" . e($partner) . "' existiert nicht!";
                } elseif ($partner === $user->get_user_name()) {
                    $error = "Du kannst dich nicht selbst als Partner eintragen!";
                } else {
                    $db_instance->execute_query("UPDATE users SET linked_user = ? WHERE id = ?", [$partner, $uid]);

                    $view .= show_passed_box("Partner '" . e($partner) . "' wurde erfolgreich hinterlegt.");
                    $user_data['linked_user'] = $partner;
                }
            }
        }

        // Delete Account
        if (isset($_POST['delete_account'])) {
            $confirm_pw = $_POST['confirm_pw_delete'] ?? "";
            $confirm_word = $_POST['confirm_word'] ?? "";

            $res = $db_instance->execute_query("SELECT password, username FROM users WHERE id = ?", [$uid]);
            $u_data = $res->fetch_assoc();

            if (!password_verify($confirm_pw, $u_data['password'])) {
                $error = "Passwort-Bestätigung zur Löschung fehlgeschlagen.";
            } else if ($confirm_word !== "LÖSCHEN") {
                $error = "Bestätigungswort falsch.";
            } else {
                $db_instance->execute_query("DELETE FROM users WHERE id = ?", [$uid]);

                $logger->log_game("ACCOUNT", "SELF_DELETION", ["user" => $u_data['username']]);

                session_destroy();
                change_location("index.php?logout=deleted");
                exit;
            }
        }
    }
}


/*
 * HTML Section
 */
$title = "Einstellungen";
$header = "Einstellungen";

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

$view .= '<div style="display: flex; align-items: center;  justify-content: center; flex-direction: column; max-width: 550px; width: 100%; margin: 0 auto;">';
$view .= '
<div class="box-container">
    <div class="box-header">Profilbild anpassen</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        <div style="text-align: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.1);">
            <p style="margin-top: 0;">Aktuelles Profilbild:</p>
            <img src="' . $user->get_avatar() . '" 
                 alt="Aktueller Avatar" 
                 style="width: 60px; height: 60px; border: 2px solid var(--border-gold); border-radius: 5px; background: rgba(0,0,0,0.3);">
        </div>
        <form action="settings.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <p>Neues Benutzerbild hochladen (Max. ' . MAX_UPLOAD_FILE_SIZE . ' KB):</p>
            <input type="file" name="image" id="image" required><br><br>
            <input type="submit" name="submit_avatar" value="Bild hochladen">
        </form>
    </div>
</div>';

$view .= '
<div class="box-container">
    <div class="box-header">Passwort ändern</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <table class="table" style="width: 100%;">
                <tr><td>Aktuelles Passwort:</td><td><input type="password" name="old_pw" required></td></tr>
                <tr><td>Neues Passwort:</td><td><input type="password" name="new_pw" required></td></tr>
                <tr><td>Wiederholung:</td><td><input type="password" name="new_pw_confirm" required></td></tr>
            </table><br>
            <input type="submit" name="change_password" value="Passwort aktualisieren">
        </form>
    </div>
</div>';

$view .= '
<div class="box-container">
    <div class="box-header">E-Mail Adresse ändern</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <table class="table" style="width: 100%;">
                <tr><td>Neue E-Mail:</td><td><input type="email" name="new_email" required></td></tr>
                <tr><td>Bestätigung (Passwort):</td><td><input type="password" name="confirm_pw_email" required></td></tr>
            </table><br>
            <input type="submit" name="change_email" value="E-Mail speichern">
        </form>
    </div>
</div>';

$current_k_res = $db_instance->execute_query("SELECT kingdomname FROM kingdoms WHERE id = ?", [$user->get_current_kingdom()]);
$current_k_name = $current_k_res->fetch_column();

$view .= '
<div class="box-container">
    <div class="box-header">Königreich umbenennen</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <p>Aktueller Name: <b>' . e($current_k_name) . '</b></p>
            <input type="text" name="new_kingdom_name" maxlength="25" placeholder="Neuer Name..." required style="width: 100%; margin-bottom: 10px;">
            <input type="submit" name="rename_kingdom" value="Namen speichern">
        </form>
        <p style="font-size: 12px; opacity: 0.6; margin-top: 10px;">
            Erlaubte Sonderzeichen: [ ] - _ . (sowie Zahlen & Buchstaben)
        </p>
    </div>
</div>';

$current_partner_text = ($user_data['linked_user'])
    ? "Aktuell eingetragen: <b class='passed'>" . e($user_data['linked_user']) . "</b>"
    : "Aktuell <b>kein</b> Partner eingetragen.";

$view .= '
<div class="box-container">
    <div class="box-header">IP-Sharing (Max. 2 Spieler)</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        <p style="margin-top:0;">' . $current_partner_text . '</p>
        <p style="">Spielst du mit jemandem aus dem gleichen Haushalt? Gib hier den Namen an, um Sperren zu vermeiden:</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <input type="text" name="partner_name" value="' . e($user_data['linked_user'] ?? '') . '" placeholder="Name des Mitspielers..." style="width: 100%; margin-bottom: 10px;">
            <input type="submit" name="update_sharing" value="Partner speichern">
        </form>
        <p style="font-size: 11px; margin-top: 10px;">Hinweis: Um den Eintrag zu löschen, das Feld leeren und speichern.</p>
    </div>
</div>';

$view .= '
<div class="box-container">
    <div class="box-header" style="background: #a62121; color: white; border-color: transparent;">Account löschen</div>
    <div class="box-content" style="padding: 10px; background: rgba(166,33,33,0.1);">
        <p class="error"><b>Vorsicht:</b> Das Löschen deines Accounts kann nicht rückgängig gemacht werden!</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <table class="table" style="width: 100%;">
                <tr><td>Passwort zur Bestätigung:</td><td><input type="password" name="confirm_pw_delete" required></td></tr>
                <tr><td>Tippe das Wort <b>LÖSCHEN</b>:</td><td><input type="text" name="confirm_word" required></td></tr>
            </table><br>
            <input type="submit" name="delete_account" value="Account unwiderruflich löschen">
        </form>
    </div>
</div>';
$view .= '</div>';

include("layout/base.php");
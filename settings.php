<?php

use Random\RandomException;

require_once("includes/core.php");

check_user_login($user);

$uid = $user->get_user_id();
$res_user = $db_instance->execute_query("SELECT linked_user, last_avatar_change FROM users WHERE id = ?", [$uid]);
$user_data = $res_user->fetch_assoc();

$allowed_tabs = ["profile", "game", "account"];
$active_tab = $_GET['tab'] ?? ($_COOKIE['me_settings_tab'] ?? 'profile');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['submit_avatar']) || isset($_POST['change_username']) || isset($_POST['change_password']) || isset($_POST['change_email'])) {
        $active_tab = "profile";
    } else if (isset($_POST['update_display_settings']) || isset($_POST['rename_kingdom']) || isset($_POST['update_sharing']) || isset($_POST['update_privacy'])) {
        $active_tab = "game";
    } else if (isset($_POST['delete_account'])) {
        $active_tab = "account";
    }
}

if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = "profile";
}

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
                $days_since_avatar = (time() - $user_data['last_avatar_change']) / 86400;

                if ($days_since_avatar < AVATAR_CHANGE_COOLDOWN_DAYS && !$user->is_admin()) {
                    $wait = ceil(AVATAR_CHANGE_COOLDOWN_DAYS - $days_since_avatar);
                    $error = "Du kannst dein Profilbild erst in $wait Tagen wieder ändern.";
                } else {
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
                                    } else {
                                        $hashed_name = substr(hash("sha256", $user->get_user_id() . AVATAR_SALT), 0, 12);
                                        $file_path = UPLOADS_FILE_PATH . $hashed_name;

                                        array_map("unlink", glob(UPLOADS_FILE_PATH . $hashed_name . ".*"));

                                        if (move_uploaded_file($file_tmp, $file_path . "." . $file_ext)) {
                                            $db_instance->execute_query("UPDATE users SET last_avatar_change = ? WHERE id = ?", [time(), $uid]);

                                            $view = show_passed_box("Nutzerbild wurde erfolgreich hochgeladen!");

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
                }
            } else {
                $error = "Keine Datei ausgewählt!";
            }
        }

        // Change Name
        if (isset($_POST['change_username'])) {
            $raw_name = $_POST['new_username'] ?? "";
            $confirm_pw = $_POST['confirm_pw_name'] ?? "";

            $res = $db_instance->execute_query("SELECT password, last_username_change FROM users WHERE id = ?", [$uid]);
            $u_data = $res->fetch_assoc();

            $days_since_change = (time() - $u_data['last_username_change']) / 86400;

            if (!password_verify($confirm_pw, $u_data['password'])) {
                $error = "Passwort-Bestätigung fehlgeschlagen.";
            } else if ($days_since_change < USERNAME_CHANGE_COOLDOWN_DAYS) {
                $wait = ceil(USERNAME_CHANGE_COOLDOWN_DAYS - $days_since_change);
                $error = "Du kannst deinen Namen erst in $wait Tagen wieder ändern.";
            } else {
                if (preg_match('/\s/', $raw_name)) {
                    $error = "Benutzername darf keine Leerzeichen enthalten!";
                } else {
                    $clean_name = preg_replace('/\p{C}/u', '', $raw_name);
                    $clean_name = preg_replace('/\s+/u', ' ', $clean_name);
                    $new_name = trim($clean_name);

                    $bad_names_list = get_bad_names();
                    $pattern_exact = '/^' . preg_quote(strtolower($new_name), '/') . '$/i';
                    $bad_names_matches = preg_grep($pattern_exact, $bad_names_list);

                    if (empty($new_name)) {
                        $error = "Bitte einen Benutzernamen angeben!";
                    } else if (!preg_match("/^[a-zA-Z0-9äöüÄÖÜß_-]+$/u", $new_name)) {
                        $error = "Erlaubte Zeichen: Buchstaben, Zahlen, _ und -";
                    } else if (mb_strlen($new_name) < MIN_USERNAME_LENGTH || mb_strlen($new_name) > MAX_USERNAME_LENGTH) {
                        $error = "Benutzername muss zwischen " . MIN_USERNAME_LENGTH . " und " . MAX_USERNAME_LENGTH . " Zeichen lang sein!";
                    } else if (is_name_monotonous($new_name)) {
                        $error = "Dieser Benutzername ist zu eintönig!";
                    } else if (!empty($bad_names_matches)) {
                        $error = "Dieser Name ist reserviert oder nicht erlaubt!";
                    } else if (contains_bad_words($new_name, $bad_names_list) || preg_match_all(regex_pattern(), $new_name, $matches)) {
                        $error = "Dieser Benutzername ist nicht erlaubt!";
                    } else {
                        $check = $db_instance->execute_query("SELECT id FROM users WHERE username = ? AND id != ?", [$new_name, $uid]);

                        if ($check->num_rows > 0) {
                            $error = "Dieser Name ist bereits vergeben.";
                        } else {
                            $db_instance->execute_query("UPDATE users SET username = ?, last_username_change = ? WHERE id = ?", [$new_name, time(), $uid]);
                            $_SESSION["username"] = $new_name;
                            $view .= show_passed_box("Dein Name wurde erfolgreich in '" . e($new_name) . "' geändert.");

                            $logger->log_game("ACCOUNT", "USERNAME_CHANGE", ["new_name" => $new_name]);
                        }
                    }
                }
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
            $new_email = trim($_POST['new_email'] ?? "");
            $confirm_pw = $_POST['confirm_pw_email'] ?? "";
            $now = time();

            $res = $db_instance->execute_query("SELECT password FROM users WHERE id = ?", [$uid]);
            $current_hash = $res->fetch_column();

            if (!password_verify($confirm_pw, $current_hash)) {
                $error = "Passwort-Bestätigung fehlgeschlagen.";
            } else if (empty($new_email)) {
                $error = "Bitte eine neue E-Mail Adresse angeben.";
            } else if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $error = "Falsches E-Mail Format!";
            } else if (strlen($new_email) > MAX_EMAIL_LENGTH) {
                $error = "Die E-Mail Adresse ist zu lang (max. " . MAX_EMAIL_LENGTH . " Zeichen)!";
            } else {
                $lower_mail = strtolower($new_email);

                if (str_ends_with($lower_mail, "@magic-empires.de") || str_ends_with($lower_mail, "@sylvan-giese.de")) {
                    $error = "Diese E-Mail-Adresse ist nicht gestattet!";
                } else {
                    $domain = substr(strrchr($new_email, "@"), 1);

                    if (!checkdnsrr($domain) && !checkdnsrr($domain, "A")) {
                        $error = "Die E-Mail Domain existiert nicht oder kann keine Mails empfangen!";
                    } else {
                        $res_block = $db_instance->execute_query("SELECT blocked_until FROM blocked_emails WHERE email = ? AND blocked_until > ?", [$new_email, $now]);

                        if ($res_block->num_rows > 0) {
                            $row_block = $res_block->fetch_assoc();
                            $wait_until = date("d.m.Y", $row_block["blocked_until"]);
                            $error = "Diese E-Mail Adresse ist noch bis zum $wait_until gesperrt.";
                        } else {
                            $check_unique = $db_instance->execute_query("SELECT id FROM users WHERE email = ? AND id != ?", [$new_email, $uid]);

                            if ($check_unique->num_rows > 0) {
                                $error = "Diese E-Mail Adresse wird bereits von einem anderen Account verwendet.";
                            } else {
                                $db_instance->execute_query("UPDATE users SET email = ? WHERE id = ?", [$new_email, $uid]);

                                $view .= show_passed_box("Deine E-Mail Adresse wurde erfolgreich auf " . e($new_email) . " geändert.");
                                $logger->log_game("ACCOUNT", "EMAIL_CHANGE", ["new_email" => $new_email]);
                            }
                        }
                    }
                }
            }
        }

        // Change Kingdom Name
        if (isset($_POST['rename_kingdom'])) {
            $raw_input = $_POST['new_kingdom_name'] ?? '';

            $clean_name = preg_replace('/\p{C}/u', '', $raw_input);
            $clean_name = preg_replace('/\s+/u', ' ', $clean_name);
            $new_k_name = trim($clean_name);

            $current_k_id = $user->get_current_kingdom();

            $res_k = $db_instance->execute_query("SELECT last_name_change, kingdomname FROM kingdoms WHERE id = ?", [$current_k_id]);
            $k_data = $res_k->fetch_assoc();

            $days_since_k_change = (time() - $k_data['last_name_change']) / 86400;

            if ($days_since_k_change < KINGDOM_NAME_CHANGE_COOLDOWN_DAYS) {
                $wait_k = ceil(KINGDOM_NAME_CHANGE_COOLDOWN_DAYS - $days_since_k_change);

                $error = "Dieses Königreich wurde erst kürzlich umbenannt. Du musst noch $wait_k Tage warten.";
            } else if (mb_strlen($new_k_name) < MIN_KINGDOM_NAME_LENGTH || mb_strlen($new_k_name) > MAX_KINGDOM_NAME_LENGTH) {
                $error = "Der Name muss zwischen " . MIN_KINGDOM_NAME_LENGTH . " und " . MAX_KINGDOM_NAME_LENGTH . " Zeichen lang sein.";
            } else if (contains_bad_words($new_k_name)) {
                $error = "Der Name enthält unzulässige Begriffe.";
            } else if (is_name_monotonous($new_k_name)) {
                $error = "Der Name ist zu eintönig oder enthält zu viele Wiederholungen.";
            } else if (!preg_match('/^[a-zA-Z0-9äöüÄÖÜß\s\[\]\-_.]+$/u', $new_k_name)) {
                $error = "Der Name enthält ungültige Sonderzeichen. Erlaubt sind: [ ] - _ .";
            } else {
                $db_instance->execute_query("UPDATE kingdoms SET kingdomname = ?, last_name_change = ? WHERE id = ?",
                    [$new_k_name, time(), $current_k_id]);
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

        // Update privacy settings
        if (isset($_POST['update_privacy'])) {
            $filter_val = isset($_POST['chat_filter']) ? 1 : 0;
            $db_instance->execute_query("UPDATE users SET chat_filter = ? WHERE id = ?", [$filter_val, $uid]);
            $_SESSION['chat_filter'] = $filter_val;

            $view .= show_passed_box("Privatsphäre-Einstellungen gespeichert.");
        }

        // Update display settings
        if (isset($_POST['update_display_settings'])) {
            $raw_pagesize = trim($_POST['overview_pagesize'] ?? '');

            if (!is_numeric($raw_pagesize)) {
                $pagesize = OVERVIEW_PAGESIZE_DEFAULT;
            } else {
                $pagesize = (int)$raw_pagesize;
                $pagesize = max(OVERVIEW_PAGESIZE_MIN, min(OVERVIEW_PAGESIZE_MAX, $pagesize));
            }

            $list_view = isset($_POST['use_list_view']) ? "1" : "0";

            setcookie("me_overview_pagesize", (string)$pagesize, time() + 31536000, "/", "", false, false);
            setcookie("me_list_view", $list_view, time() + 31536000, "/", "", false, false);

            $_COOKIE["me_overview_pagesize"] = (string)$pagesize;
            $_COOKIE["me_list_view"] = $list_view;

            $view .= show_passed_box("Anzeige-Einstellungen erfolgreich gespeichert.");
        }

        // Delete Account
        if (isset($_POST['delete_account'])) {
            $confirm_pw = $_POST['confirm_pw_delete'] ?? "";
            $confirm_word = $_POST['confirm_word'] ?? "";

            $res = $db_instance->execute_query("SELECT password, username, email FROM users WHERE id = ?", [$uid]);
            $u_data = $res->fetch_assoc();

            if (!password_verify($confirm_pw, $u_data['password'])) {
                $error = "Passwort-Bestätigung zur Löschung fehlgeschlagen.";
            } else if ($confirm_word !== "LOESCHEN") {
                $error = "Bestätigungswort falsch.";
            } else {
                $deleted_username = $u_data['username'];

                $block_until = time() + (EMAIL_BLOCK_DAYS_AFTER_DELETION * 86400);
                $db_instance->execute_query("
                        INSERT INTO blocked_emails (email, blocked_until) 
                         VALUES (?, ?) 
                         ON DUPLICATE KEY UPDATE blocked_until = VALUES(blocked_until)",
                    [$u_data["email"], $block_until]
                );

                $db_instance->execute_query("
                    UPDATE events SET 
                        actionid = ?, 
                        arrivaltime = UNIX_TIMESTAMP() + (UNIX_TIMESTAMP() - buildingtime),
                        targetid = -1, 
                        is_processing = 0 
                    WHERE targetid IN (SELECT id FROM kingdoms WHERE userid = ?) 
                      AND actionid = ?", [ActionTypes::ACTION_RETURN_TROOPS, $uid, ActionTypes::ACTION_SEND_TROOPS]);

                $db_instance->execute_query("
                    UPDATE events e
                    JOIN kingdoms k_source ON e.targetid = k_source.id
                    SET 
                        e.actionid = ?,
                        e.arrivaltime = UNIX_TIMESTAMP() + (UNIX_TIMESTAMP() - e.buildingtime),
                        e.targetx = k_source.mapx,
                        e.targety = k_source.mapy,
                        e.kingdomid = e.targetid,
                        e.targetid = -1,
                        e.buildingname = 'Transport-Rückkehr',
                        e.is_processing = 0 
                    WHERE e.kingdomid IN (SELECT id FROM kingdoms WHERE userid = ?) 
                      AND e.userid != ?
                      AND e.actionid = ?", [ActionTypes::ACTION_RETURN_RESOURCES, $uid, $uid, ActionTypes::ACTION_RECEIVE_RESOURCES]);

                $logger->log_game("ACCOUNT", "SELF_DELETION", ["username" => $deleted_username, "email" => $u_data['email']]);

                // Check if user was in a guild and leader
                $guild_manager = new Guild($db_instance, $user, $user->get_user_guild_id());
                $guild_manager->handle_leader_deletion($uid);

                convert_user_kingdoms_to_ruins($db_instance, $uid);

                $db_instance->execute_query("DELETE FROM users WHERE id = ?", [$uid]);

                $em = new EventManager($user);
                $em->process_orphaned_support();

                $token = bin2hex(random_bytes(16));

                setcookie("logout_verify", $token, time() + 30, "/", "", false, true);
                session_destroy();
                change_location("index.php?logout=deleted&v=" . $token);
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
$script_files = ["settings", "timer"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

$tab_menu = "
<div class='tab' id='settings-tabs' style='margin: 0 auto 15px auto; max-width: 550px;'>
    <div class='tablinks " . ($active_tab === 'profile' ? 'active' : '') . "' data-on-click='switchSettingsTab' data-tab='profile'>Profil</div>
    <div class='tablinks " . ($active_tab === 'game' ? 'active' : '') . "' data-on-click='switchSettingsTab' data-tab='game'>Spiel & Anzeige</div>
    <div class='tablinks " . ($active_tab === 'account' ? 'active' : '') . "' data-on-click='switchSettingsTab' data-tab='account''>Account</div>
</div>";

$view = $tab_menu . $view;

$view .= '<div style="display: flex; align-items: center;  justify-content: center; flex-direction: column; max-width: 550px; width: 100%; margin: 0 auto;">';

$view .= "<div id='tab_profile' class='settings-tab' style='display: " . ($active_tab === 'profile' ? 'block' : 'none') . "; width: 100%;'>";

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
        <p style="font-size: 12px; opacity: 0.6; margin-top: 10px;">
            Hinweis: Das Profilbild kann nur alle ' . AVATAR_CHANGE_COOLDOWN_DAYS . ' Tage geändert werden.
        </p>
    </div>
</div>';

$view .= '
<div class="box-container">
    <div class="box-header">Benutzernamen ändern</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <table class="table" style="width: 100%;">
                <tr><td>Neuer Name:</td><td><input type="text" name="new_username" maxlength="16" required></td></tr>
                <tr><td>Passwort-Bestätigung:</td><td><input type="password" name="confirm_pw_name" required></td></tr>
            </table><br>
            <input type="submit" name="change_username" value="Namen ändern">
        </form>
        <p style="font-size: 12px; opacity: 0.6; margin-top: 10px;">
            Hinweis: Namensänderungen sind nur alle ' . USERNAME_CHANGE_COOLDOWN_DAYS . ' Tage möglich.
        </p>
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

$view .= "</div>";

$view .= "<div id='tab_game' class='settings-tab' style='display: " . ($active_tab === 'game' ? 'block' : 'none') . "; width: 100%;'>";

$cur_pagesize = (int)($_COOKIE["me_overview_pagesize"] ?? OVERVIEW_PAGESIZE_DEFAULT);
$cur_pagesize = max(OVERVIEW_PAGESIZE_MIN, min(OVERVIEW_PAGESIZE_MAX, $cur_pagesize));
$cur_list_view = (($_COOKIE["me_list_view"] ?? $_COOKIE["me_barracks_all_units"] ?? "0") === "1");

$view .= '
<div class="box-container">
    <div class="box-header">Ansicht & Anzeige</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <table class="table" style="width: 100%;">
                <tr>
                    <td>Einträge pro Seite (Übersicht):</td>
                    <td>
                        <input type="text" 
                               name="overview_pagesize" 
                               id="overview_pagesize" 
                               class="js-pagesize-input"
                               inputmode="numeric" 
                               pattern="[0-9]*" 
                               data-min="' . OVERVIEW_PAGESIZE_MIN . '" 
                               data-max="' . OVERVIEW_PAGESIZE_MAX . '" 
                               data-default="' . OVERVIEW_PAGESIZE_DEFAULT . '" 
                               value="' . $cur_pagesize . '" 
                               maxlength="2" 
                               style="width: 50px; text-align: center;" 
                               autocomplete="off" 
                               required>
                        <small style="opacity: 0.7; margin-left: 5px;">(' . OVERVIEW_PAGESIZE_MIN . ' - ' . OVERVIEW_PAGESIZE_MAX . ')</small>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="text-align: left; padding: 10px;">
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="use_list_view" value="1" style="width: auto;" ' . ($cur_list_view ? "checked" : "") . '>
                            <span>Listenansicht standardmäßig aktivieren<br><small style="opacity: 0.7;">(Kaserne, Truppenentsendung)</small></span>
                        </label>
                    </td>
                </tr>
            </table><br>
            <input type="submit" name="update_display_settings" value="Einstellungen speichern">
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
            Hinweis: Königreiche können nur alle ' . KINGDOM_NAME_CHANGE_COOLDOWN_DAYS . ' Tage umbenannt werden.<br>
            Erlaubte Sonderzeichen: [ ] - _ . (sowie Zahlen, Buchstaben & Umlaute)
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

$current_filter = ($_SESSION["chat_filter"] ?? 1);
$view .= '
<div class="box-container">
    <div class="box-header">Privatsphäre & Chat</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <label style="cursor:pointer;">
                <input type="checkbox" name="chat_filter" value="1" ' . ($current_filter ? "checked" : "") . '> 
                Schimpfwort-Filter in privaten Nachrichten aktivieren
            </label><br><br>
            <input type="submit" name="update_privacy" value="Speichern">
        </form>
    </div>
</div>';

$view .= "</div>";

$view .= "<div id='tab_account' class='settings-tab' style='display: " . ($active_tab === 'account' ? 'block' : 'none') . "; width: 100%;'>";

// Get user data
$uid = $user->get_user_id();
$query = "SELECT u.username, u.email, u.registerdate, u.lastlogin, u.adminlevel, u.score,
                k.kingdomname, k.mapx, k.mapy 
          FROM users u 
          JOIN kingdoms k ON u.mainkingdom = k.id 
          WHERE u.id = ?";
$res = $db_instance->execute_query($query, [$uid]);
$data = $res->fetch_assoc();

// Calculate rank
$rank_res = $db_instance->execute_query("SELECT COUNT(*) + 1 AS rank FROM users WHERE score > ?", [$data["score"]]);
$user_rank = $rank_res->fetch_column();

$time_diff = time() - $_SESSION["currlogin"];

$role = match ($data["adminlevel"]) {
    ADMIN_LEVEL_SUPPORTER => "Supporter",
    ADMIN_LEVEL_LIGHT_ADMIN => "Light Admin",
    ADMIN_LEVEL_FULL_ADMIN => "Full Admin",
    default => "User",
};

$view .= "<div class='title-border'>Account-Informationen</div>
        <table class='table' style='max-width: 600px; margin-bottom: 20px;'>
            <tr><td><b>Spieler-Name:</b></td><td>{$data["username"]}</td></tr>
            <tr><td><b>E-Mail Adresse:</b></td><td>{$data["email"]}</td></tr>
            <tr><td><b>Registriert seit:</b></td><td>" . date("d.m.Y H:i:s", $data["registerdate"]) . " Uhr</td></tr>
            <tr><td><b>Letzter Login:</b></td><td>" . date("d.m.Y H:i:s", $data["lastlogin"]) . " Uhr</td></tr>
            <tr><td><b>Login-Zeit:</td><td><span id='login-counter' data-start='$time_diff'></span></td></tr>
            <tr><td><b>Account-Level:</b></td><td>{$data["adminlevel"]} ($role)</td></tr>
        </table>";

$view .= '
<div class="box-container" style="border-color: #a62121;">
    <div class="box-header" style="background: #a62121; color: white; border-color: transparent; border-bottom: #340202 2px solid;">Account löschen</div>
    <div class="box-content box-content-bg-danger" style="padding: 10px;">
        <p class="error"><b>Vorsicht:</b> Das Löschen deines Accounts kann nicht rückgängig gemacht werden!</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <table class="table" style="width: 100%;">
                <tr><td>Passwort zur Bestätigung:</td><td><input type="password" name="confirm_pw_delete" required></td></tr>
                <tr><td>Tippe das Wort <b>LOESCHEN</b>:</td><td><input type="text" name="confirm_word" required></td></tr>
            </table><br>
            <input type="submit" name="delete_account" value="Account unwiderruflich löschen">
        </form>
    </div>
</div>';

$view .= "</div>";

$view .= '</div>';

include("layout/base.php");
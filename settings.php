<?php

use Random\RandomException;

require_once("includes/core.php");

check_user_login($user);

// Generate a random token
if (!isset($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (RandomException $e) {
    }
}

// Add the token as a hidden input in the form
$csrf_token = $_SESSION['csrf_token'];

if (isset($_POST['submit'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Ungültiger Token!";
    } else {
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
                                //$percent = round($nsfw_score * 100);
                                $error = "Dein Bild wurde als unangemessen eingestuft.";

                                // $logger->log_security("NSFW Blockiert", ["user" => $user->get_user_id(), "score" => $nsfw_score]);
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
}

/*$files = glob(UPLOADS_FILE_PATH . $user->get_user_name() . '*'); // Will find 2.txt, 2.php, 2.gif

// Process through each file in the list
// and output its extension
if (count($files) > 0) {
    foreach ($files as $file) {
        $info = pathinfo($file);
        echo "File found: extension: " . $info["extension"] . "<br>";
    }
} else {
    echo "nothing found";
}*/

/*
 * HTML Section
 */
$title = "Einstellungen";
$header = "Einstellungen";

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

$view .= '<form action="settings.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <p>Benutzerbild hochladen:</p>
            <input type="file" name="image" id="image" required>
            <input type="submit" name="submit" value="Hochladen">
        </form>';

include("layout/base.php");
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
            // Image file information
            $file_name = $_FILES['image']['name'];
            $file_tmp = $_FILES['image']['tmp_name'];
            $file_size = $_FILES['image']['size'];
            $file_error = $_FILES['image']['error'];
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

            // Max file size in bytes
            $max_file_size = MAX_UPLOAD_FILE_SIZE * 1024; // Bytes to KB: 1024 * KB number

            // Get file extension
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            // Validation
            if (!in_array($file_ext, $allowed_extensions)) {
                $error = "Ungültiger Datei-Typ! Erlaubt sind JPG, JPEG, PNG, oder GIF.";
            } elseif ($file_size > $max_file_size) {
                $error = "Datei-Größe überschreitet die maximal erlaubte Größe von " . MAX_UPLOAD_FILE_SIZE . " KB!";
            } elseif ($file_error !== 0) {
                $error = "Es ist ein Fehler beim Hochladen aufgetreten!";
            } else {
                $file_path = UPLOADS_FILE_PATH . $user->get_user_name();
                $files = glob(UPLOADS_FILE_PATH . $user->get_user_name() . '*');

                if (count($files) > 0) {
                    $info = pathinfo($files[0]);

                    unlink($file_path . "." . $info['extension']);
                }

                // Move the file from temp location to the uploads directory
                if (move_uploaded_file($file_tmp, $file_path . "." . $file_ext)) {
                    $view = "Nutzerbild wurde erfolgreich hochgeladen!";

                    unset($_SESSION['csrf_token']);
                } else {
                    $error = "Fehler beim Hochladen des Nutzerbildes!";
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
<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

// Generate a random token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Add the token as a hidden input in the form
$csrf_token = $_SESSION['csrf_token'];

$error = "";
$view = "";

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

            // Allowed file types (only images)
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

            // Max file size in bytes
            $max_file_size = MAX_UPLOAD_FILE_SIZE * 1024; // Bytes to KB: 1024 * KB number

            // Extract file extension
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            // Validation
            if (!in_array($file_ext, $allowed_extensions)) {
                $error = "Ungültiger Datei-Typ! Erlaubt sind JPG, JPEG, PNG, oder GIF.";
            } elseif ($file_size > $max_file_size) {
                $error = "Datei-Größe überschreitet die maximal erlaubte Größe von " . MAX_UPLOAD_FILE_SIZE . " KB!";
            } elseif ($file_error !== 0) {
                $error = "Es ist ein Fehler beim Hochladen aufgetreten!";
            } else {
                // Generate a unique name for the file
                $file_name = $user->get_user_name();
                $file_path = UPLOADS_FILE_PATH . $user->get_user_name();

                // Move the file from temp location to the uploads directory
                if (move_uploaded_file($file_tmp, $file_path)) {
                    $view = "Nutzerbild wurde erfolgreich hochgeladen!";

                    // Unset the token after a successful submission to avoid reuse
                    unset($_SESSION['csrf_token']);
                } else {
                    $error = "Fehler beim Verschieben der Datei!";
                }
            }
        } else {
            $error = "Keine Datei ausgewählt!";
        }
    }
}

/*
 * HTML Section
 */
$title = "Einstellungen";
$header = "Einstellungen";

if (!empty($error)) {
    $view = "<div class='info-box'>" . $error . "</div>" . $view;
}

$view .= '<form action="settings.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="' . $csrf_token . '">
            <p>Benutzerbild hochladen:</p>
            <input type="file" name="image" id="image" required>
            <input type="submit" name="submit" value="Hochladen">
        </form>';

include('layout/base.php');
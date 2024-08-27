<?php
global $db_instance, $user;

if (!empty($_GET["key"])) {
    require_once("functions.php");
    session_destroy();

    $stmt = $db_instance->prepare("SELECT status FROM users WHERE activationkey = ?");
    $stmt->bind_param('s', $_GET["key"]);
    $stmt->execute();
    $stmt->store_result();

    // User exists
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($status);
        $stmt->fetch();
        $stmt->close();

        if (!$status) {
            $userid = $user->getUserDatabaseID($_GET["key"]);

            $stmt = $db_instance->prepare("UPDATE users SET status = true, activationkey = '' WHERE id = ?");
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $result = $stmt->store_result();
            $stmt->close();

            if (!empty($result)) {
                echo "Dein Account wurde erfolgreich aktiviert! Klicke <a href = 'login.php'>hier</a>, um dich einzuloggen.";
            } else {
                echo "Bei der Aktivierung ist ein Problem aufgetreten!";
            }
        } else {
            echo "Dieser Benutzer ist bereits aktiviert!";
        }
    } else {
        echo "Ungültiger Aktivierungsschlüssel!";
    }
} else {
    changeLocation("login.php");
}
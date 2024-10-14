<?php
if (!empty($_GET["key"])) {
    require_once("includes/core.php");
    session_destroy();

    $result = $db_instance->query("SELECT status FROM users WHERE activationkey = ?", [$_GET["key"]]);

    // User exists
    if ($result->num_rows > 0) {
        $status = $result->fetch_assoc()["status"];

        if (!$status) {
            $user_id = $user->get_user_database_id($_GET["key"]);
            $result = $db_instance->query("UPDATE users SET status = true, activationkey = '' WHERE id = ?", [$user_id]);

            if (!empty($result)) {
                echo "Dein Account wurde erfolgreich aktiviert! Klicke <a href = 'login.php'>hier</a>, um dich einzuloggen.";
            } else {
                echo "Bei der Aktivierung ist ein Problem aufgetreten!";
            }
        } else {
            echo "Dieser Spieler ist bereits aktiviert!";
        }
    } else {
        echo "Ungültiger Aktivierungsschlüssel!";
    }
} else {
    change_location("login.php");
}
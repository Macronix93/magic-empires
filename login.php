<?php
require_once("includes/core.php");

$success = "";
//$error = "";

if (!empty($_GET["key"])) {
    $activation_key = make_secure($_GET["key"]);

    $result = $db_instance->execute_query("SELECT id, username, status FROM users WHERE activationkey = ? LIMIT 1", [$activation_key]);

    if ($result && $result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        $user_id = $user_data["id"];
        $username = $user_data["username"];

        if (!$user_data["status"]) {
            $db_instance->execute_query("UPDATE users SET status = true, activationkey = '' WHERE id = ?", [$user_id]);

            // Create kingdom for that user
            $kingdom = new Kingdom($db_instance);
            $main_kingdom = $kingdom->create_kingdom($user_id, $username);

            if ($main_kingdom) {
                // Update last rank
                $query_rank = "
                    UPDATE users 
                        JOIN (
                            SELECT id, (@rank := @rank + 1) AS new_rank
                            FROM (SELECT id FROM users ORDER BY score DESC) AS ranked_users
                            CROSS JOIN (SELECT @rank := 0) AS init
                        ) AS r ON users.id = r.id
                    SET users.lastrank = r.new_rank
                    WHERE users.id = ?
                ";
                $db_instance->execute_query($query_rank, [$user_id]);

                // Set users main kingdom
                $db_instance->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$main_kingdom, $user_id]);

                $success = "Dein Account wurde erfolgreich aktiviert!<br>Du kannst dich jetzt einloggen.";
            } else {
                $error = "Account aktiviert, aber kein freier Platz<br>auf der Karte gefunden. Support kontaktieren!";
            }
        } else {
            $error = "Dieser Account ist bereits aktiviert.";
        }
    } else {
        $error = "Ungültiger oder abgelaufener Aktivierungsschlüssel.";
    }
}

if (isset($_GET["logout"])) {
    if ($user->is_logged_in()) {
        if ($_GET["logout"] === "inactive") {
            $error .= "Du wurdest aus Inaktivitätsgründen automatisch ausgeloggt!";
        } else if ($_GET["logout"] === "session") {
            $error .= "Deine Session ist abgelaufen. Bitte logge dich erneut ein!";
        }

        // Update anti spam
        $db_instance->execute_query("UPDATE users SET msgcount = ?, lastsentmsgend = ? WHERE id = ?", [$_SESSION["message_count"], $_SESSION["message_timeframe_end"], $user->get_user_id()]);

        session_unset();
        session_destroy();
    }
} else {
    if ($user->is_logged_in()) {
        change_location("index.php");
        exit;
    }

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Retrieve and sanitize form inputs
        $name = make_secure($_POST["username"] ?? "");
        $pass = make_secure($_POST["password"] ?? "");

        if (empty($name) || empty($pass)) {
            $error .= "Bitte beide Felder ausfüllen!";
        } else {
            $result = $db_instance->execute_query("SELECT id, password, status, adminlevel FROM users WHERE username = ? LIMIT 1", [$name]);

            // Check if user exists
            if ($result && $result->num_rows == 1) {
                $row = $result->fetch_assoc();

                if (MAINTENANCE_MODE && $row["adminlevel"] == 0) {
                    $error .= "Der Server befindet sich im Wartungsmodus!";
                } else {
                    $user_id = $row["id"];
                    $password = $row["password"];
                    $status = $row["status"];

                    if (!$status) {
                        $error .= "Account noch nicht aktiviert durch Aktivierungslink!";
                    } else if (!password_verify($pass, $password)) {
                        $error .= "Nutzername oder Passwort ist falsch!";
                    } else {
                        if (empty($error)) {
                            unset($_POST);
                            $user->login_user($user_id);
                        }
                    }
                }
            } else {
                $error .= "Nutzername oder Passwort ist falsch!";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/banner.html");

// Show login form
?>
<div class="form">
    <form class="login-register" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
        <fieldset>
            <legend><b>Login</b></legend>
            <?php
            if (!empty($success)) echo show_passed_box($success);
            if (!empty($error)) echo show_error_box($error);
            ?>
            <table class="table">
                <tr>
                    <td style="min-width: 165px;"><b>Benutzername:</b></td>
                    <td>
                        <label>
                            <input type="text" name="username"
                                   value="<?= $_POST["username"] ?? ""; ?>" style="width: 100%;">
                        </label>
                    </td>
                </tr>
                <tr>
                    <td>
                        <b>Passwort:</b>
                    </td>
                    <td>
                        <label>
                            <input type="password" name="password" style="width: 100%;">
                        </label>
                    </td>
                </tr>
            </table>
            <input type='submit' name='login' value='Einloggen' style="width:125px; height:50px; margin: 15px 0;"/>
            <hr>
            <i>Du bist noch nicht registriert? Registriere dich <a href='register.php'><b>hier</b></a>.</i>
        </fieldset>
    </form>
</div>
</body>
</html>
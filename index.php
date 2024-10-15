<?php
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$view = "";

// Get some user data to show...
$result = $db_instance->execute_query("SELECT ip, email, score, guildid, registerdate, mainkingdom FROM users WHERE id = ?", [$_SESSION["userid"]]);
$row = $result->fetch_assoc();
$ip = $row["ip"];
$email = $row["email"];
$score = $row["score"];
$guild_id = $row["guildid"];
$register_date = $row["registerdate"];
$main_kingdom = $row["mainkingdom"];

$view .= '<img src="images/icons/icon_right_slow.png" class="popup" id="test1" alt="" style="width:24px;"/>
            <div id="test1_box" class="popupbox">Testbox hahaha <br>hahahah</div>
            <br>
            <a class="popup" id="test2">This is a test</a>
            <div id="test2_box" class="popupbox">E-Mail: ' . htmlspecialchars($email) . '</div>
            <br><br>';

$time_diff = time() - $_SESSION["currlogin"];

/* Check for existing IP
$ipPattern = explode('.', $_SERVER["REMOTE_ADDR"]);
$ipToCheck = $ipPattern[0] . "." . $ipPattern[1] . ".%";

$sql = "SELECT COUNT(*) FROM users WHERE ip LIKE ?";
$stmt = $db_instance->prepare($sql);
$stmt->bind_param('s', $ipToCheck);
$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();

if ($count > 0) {
    // The database contains at least one IP address matching the pattern xxx.xxx.*.*
    echo 'IP found.';
} else {
    // No matching IP address found
    echo 'IP not found.';
}*/

$view .= "Login-Zeit: <span id='counter'></span><br>Current IP Address: " . $_SERVER["REMOTE_ADDR"] . "<br>Stored IP Adress: " . $ip . "<br><br>";
$view .= "Haupt-KönigreichID: $main_kingdom<br>";
$view .= "E-Mail: $email<br>";
$view .= "Registriert seit: " . date('d.m.Y H:i:s', $register_date) . "<br>";
$view .= "Letzter Login: " . date('d.m.Y H:i:s', $_SESSION["lastlogin"]) . "<br>";
$view .= "Score: $score<br>";
$view .= "Gilde: $guild_id<br>";
$view .= "Admin-Level: " . $user->get_user_admin_level();


/*
 * HTML Section
 */
$title = "Übersicht";
$header = "Übersicht";
$script_files = ["counter"];

$view .= "
    <script type='text/javascript'>
        document.addEventListener('DOMContentLoaded', function() {
            startCountup(\"$time_diff\");
        });
    </script>
";

include('layout/base.php');
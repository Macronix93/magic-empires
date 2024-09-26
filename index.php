<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
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
?>
<div class="content-box">
    <div class="left-container">
        <?php
        include_once("layout/left.php");
        ?>
    </div>
    <div class="middle-container">
        <div class="big-box-container">
            <div class="big-box-header"><p>Übersicht</p></div>
            <div class="big-box-content">
                <?php
                // Get some user data to show...
                $result = $db_instance->execute_query("SELECT ip, email, score, guildid, registerdate, mainkingdom FROM users WHERE id = ?", [$_SESSION["userid"]]);
                $row = $result->fetch_assoc();
                $ip = $row["ip"];
                $email = $row["email"];
                $score = $row["score"];
                $guildid = $row["guildid"];
                $registerdate = $row["registerdate"];
                $mainkingdom = $row["mainkingdom"];
                ?>

                <img src='images/icons/icon_right_slow.png' class="popup" id="test1" alt="" style="width:24px;"/>
                <div id="test1_box" class="popupbox">Testbox hahaha <br>hahahah</a></div>
                <br>

                <a class="popup" id="test2">This is a test</a>
                <div id="test2_box" class="popupbox"><?php echo "E-Mail: $email<br>"; ?></a></div>
                <br><br>

                <?php
                $timediff = time() - $_SESSION["currlogin"];

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

                echo "Login-Zeit: <span id='counter'><script type='text/javascript'>startCountup($timediff)</script></span><br>Current IP Address: " . $_SERVER["REMOTE_ADDR"] . "<br>Stored IP Adress: " . $ip . "<br><br>";
                echo "Haupt-KönigreichID: $mainkingdom<br>";
                echo "E-Mail: $email<br>";
                echo "Registriert seit: " . date('d.m.Y H:i:s', $registerdate) . "<br>";
                echo "Letzter Login: " . date('d.m.Y H:i:s', $_SESSION["lastlogin"]) . "<br>";
                echo "Score: $score<br>";
                echo "Gilde: $guildid<br><br>";
                ?>
            </div>
        </div>
    </div>
    <div class="right-container">
        <?php
        include_once("layout/right.php");
        ?>
    </div>
</div>
<?php
include_once("layout/footer.php");
?>
</body>
</html>

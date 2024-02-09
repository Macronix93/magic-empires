<?php
global $db_instance, $user;
require_once("functions.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php", 0);
    exit;
}

include_once("layout/header.php");
?>

    <div class="content">
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
                        if ($_SESSION["justloggedin"]) {
                            //$user->checkUserEvents($user->getUserID());
                            $_SESSION["justloggedin"] = false;
                        }

                        // Get some user data to show...
                        $stmt = $db_instance->prepare("SELECT ip, email, score, guildid, registerdate, mainkingdom FROM users WHERE id = ?");
                        $stmt->bind_param('i', $_SESSION["userid"]);
                        $stmt->execute();
                        $stmt->bind_result($ip, $email, $score, $guildid, $registerdate, $mainkingdom);
                        $stmt->fetch();
                        $stmt->close();
                        ?>

                        <img src='images/icon_right.png' class="popup" id="test1" alt="" style="width:24px;"/>
                        <div id="test1_box" class="popupbox">Testbox hahaha <br>hahahah</a></div>
                        <br>

                        <a href="#" class="popup" id="test2">This is a test</a>
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

                        include_once("includes/countdown.php");
                        echo "Login-Zeit: <span id='counter'><script>startCountup($timediff)</script></span><br>Current IP Address: " . $_SERVER["REMOTE_ADDR"] . "<br>Stored IP Adress: " . $ip . "<br><br>";

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
    </div>
<?php
include_once("layout/footer.php");
?>
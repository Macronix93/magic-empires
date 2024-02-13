<?php

class User {
    public $error = "";
    private $mysqli;
    private $eventid = -1,
        $actionid = -1,
        $user = "",
        $kingdomid = -1,
        $buildingid = -1,
        $buildingtime = -1,
        $buildinglevel = -1,
        $buildingname = "",
        $recruittime = 0,
        $soldiergoal = 0,
        $soldierid = 0;

    // Constructor
    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
    }

    // Function to register a new user
    public function registerUser($name, $email, $pass): void {
        $password = password_hash($pass, PASSWORD_BCRYPT);
        $activationkey = md5($email . $name);

        $stmt = $this->mysqli->prepare("INSERT INTO users (username, password, activationkey, email, registerdate) VALUES (?, ?, ?, ?, UNIX_TIMESTAMP(NOW()))"); //lastlogin = UNIX_TIMESTAMP(NOW()) ???
        $stmt->bind_param('ssss', $name, $password, $activationkey, $email);
        $stmt->execute();
        $insertid = $stmt->insert_id;
        $stmt->close();

        // Try to create kingdom
        $kingdom = new Kingdoms($this->mysqli);
        $mainkingdom = $kingdom->createKingdom($insertid, $name);

        // Update mainkingdom in user table
        $this->mysqli->query("UPDATE users SET mainkingdom = '$mainkingdom' WHERE id = '$insertid'");

        // Create activation link to activate account
        $actual_link = "https://$_SERVER[HTTP_HOST]/magic-empires/" . "activation.php?key=" . $activationkey;

        $empfaenger = $email;
        $betreff = 'Magic-Empires - Registration';
        $nachricht = "Willkommen bei Magic-Empires!<br><br>Klicke auf diesen Link, um deinen Account zu aktivieren:<br><br><a href='" . $actual_link . "'>" . $actual_link . "</a><br><br>Viel Spaß beim Zocken! :)";
        $header = "Content-type:text/html;charset=UTF-8" . "\r\n" .
            'From: webmaster@magic-empires.de' . "\r\n" .
            'X-Mailer: PHP/' . phpversion();

        if (mail($empfaenger, $betreff, $nachricht, $header)) echo "mail gesendet!";

        echo "<div style='text-align: center;'><b style='color: mediumseagreen;'>Du hast dich erfolgreich registriert! Ein Aktivierungslink wurde an deine E-Mail gesendet.</b><br><br><a href='login.php'>Hier einloggen</a></div>";

        unset($_POST);

        $this->mysqli->close();
    }

    // Function to log in a user
    public function loginUser($name, $pass): void {
        $stmt = $this->mysqli->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->store_result();
        $found = $stmt->num_rows == 1;
        $userid = -1;
        $stmt->bind_result($userid);
        $stmt->fetch();
        $stmt->close();

        // Check if user exists
        if ($found) {
            $status = -1;
            $password = "";
            $email = "";
            $score = -1;
            $lastlogin = -1;
            $mainkingdom = -1;
            $guild = -1;

            // Fetch users data
            $stmt = $this->mysqli->prepare("SELECT username, status, password, email, lastlogin, score, mainkingdom, guildid FROM users WHERE id = ?");
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $stmt->bind_result($name, $status, $password, $email, $lastlogin, $score, $mainkingdom, $guild);
            $stmt->fetch();
            $stmt->close();

            echo $status;

            if (password_verify($pass, $password)) {
                if (!$status) {
                    $this->error = "<b class='error'>Bitte aktiviere deinen Account mit dem Aktivierungslink, der an deine E-Mail-Adresse geschickt wurde!</b><br><br>";
                } else {
                    $timestamp = time();

                    $stmt = $this->mysqli->prepare("UPDATE users SET ip = '{$_SERVER['REMOTE_ADDR']}', lastlogin = $timestamp, lastactivity = $timestamp WHERE id = ?");
                    $stmt->bind_param('i', $userid);
                    $stmt->execute();
                    $stmt->close();

                    $_SESSION["currlogin"] = time();
                    $_SESSION["lastlogin"] = $lastlogin;
                    $_SESSION["userid"] = $userid;
                    $_SESSION["username"] = $name;
                    $_SESSION["kingdomid"] = $mainkingdom;
                    $_SESSION["justloggedin"] = true;

                    changeLocation("index.php", 0);
                }
            } else {
                $this->error = "<b class='error'>Falsches Passwort!</b><br><br>";
            }
        } else {
            $this->error = "<b class='error'>Dieser Nickname existiert nicht!</b><br><br>";
        }

        $this->mysqli->close();
    }

    // Get the user ID by activation key
    public function getUserDatabaseID($activationkey) {
        $activationkey = mysqli_real_escape_string($this->mysqli, $activationkey);
        $query = "SELECT id FROM users WHERE activationkey = '$activationkey'";
        if (!$result = mysqli_query($this->mysqli, $query)) {
            exit(mysqli_error($this->mysqli));
        }
        $data = '';
        if (mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                $data = $row['id'];
            }
        }

        return $data;
    }

    // Check if user is logged in
    public function isLoggedIn(): bool {
        if (isset($_SESSION["userid"])) return true;
        else return false;
    }

    // Get ID of the user
    public function getUserID() {
        return $_SESSION["userid"] ?? "";
    }

    // Get the name of the user
    public function getUserName() {
        return $_SESSION["username"] ?? "";
    }

    public function getUserScore() {
        $result = $this->mysqli->query("SELECT score FROM users WHERE id = '{$_SESSION["userid"]}'");
        $row = $result->fetch_assoc();
        $score = $row["score"];
        $result->close();

        return $score;
    }

    // Get user events
    /*public function checkUserEvents($userid) {
        $stmt = $this->mysqli->prepare("SELECT * FROM events WHERE userid = ?");
        $stmt->bind_param('i', $userid);
        $stmt->execute();
        $stmt->bind_result($this->eventid, $this->actionid, $this->user, $this->kingdomid, $this->buildingid,
            $this->buildingtime, $this->buildinglevel, $this->buildingname, $this->soldierid, $this->starttime, $this->endtime, $this->soldiergoal);

        while ($stmt->fetch()) {
            switch ($this->actionid) {
                case ACTION_BUILD_BUILDING:
                {
                    if ($_SESSION["kingdomid"] == $this->kingdomid) {
                        echo "kingdom hat die richtige kingdomid, setze bID";
                        //$_SESSION["isbuilding"] = true;
                        $_SESSION["buildingID"] = $this->buildingid;
                    }
                    break;
                }
            }
        }

        $stmt->close();
    }*/

    // Get and execute events tied to the user
    public function processUserEvents($userid): void {
        $stmt = $this->mysqli->prepare("SELECT * FROM events WHERE userid = ?");
        $stmt->bind_param('i', $userid);
        $stmt->execute();
        $stmt->bind_result($this->eventid, $this->actionid, $this->user, $this->kingdomid, $this->buildingid,
            $this->buildingtime, $this->buildinglevel, $this->buildingname, $this->soldierid, $this->recruittime, $this->soldiergoal);
        $stmt->store_result();

        while ($stmt->fetch()) {
            switch ($this->actionid) {
                case ACTION_BUILD_BUILDING:
                    if ($_SESSION["kingdomid"] == $this->kingdomid && $this->buildingtime < time()) {
                        $result = $this->mysqli->query("SELECT buildingscore FROM buildinglist WHERE id = '$this->buildingid'");
                        $row = $result->fetch_assoc();
                        $score = $row["buildingscore"];
                        $result->close();

                        $this->mysqli->query("DELETE FROM events WHERE eventid = '$this->eventid'");

                        if ($this->buildinglevel == 0) { // Insert new building
                            $this->mysqli->query("INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel) VALUES ('$this->kingdomid', '$this->buildingid', '$this->buildingname', 1)");
                        } else { // Update current building
                            $this->mysqli->query("UPDATE buildings SET buildinglevel = buildinglevel+1 WHERE kingdomid = '$this->kingdomid' AND buildingid = '$this->buildingid'");
                        }

                        $this->mysqli->query("UPDATE users SET score = score+" . $score . " WHERE id = '$userid'") or die($this->mysqli->error);
                    }
                    break;
                case ACTION_BUILD_TROOPS:
                    //if ($_SESSION["kingdomid"] == $this->kingdomid) {
                    $soldiers = new Barracks($this->mysqli);
                    $soldiertime = $soldiers->getSoldierTime($this->soldierid);

                    $currenttime = time();
                    $totaldifference = $this->recruittime - $currenttime;
                    $numberLeftToRecruit = max(0, ceil($totaldifference / $soldiertime));
                    //$remainingTimeInSeconds = max(0, $totaldifference % $soldiertime);
                    $soldierdifference = $this->soldiergoal - $numberLeftToRecruit;

                    if ($soldierdifference != 0) {
                        //$newSoldierGoal = max(0, $this->soldiergoal - $soldierdifference);

                        $this->mysqli->query("UPDATE events SET soldiergoal = GREATEST(0, soldiergoal - $soldierdifference) WHERE kingdomid = '$this->kingdomid' AND soldierid = '$this->soldierid'");

                        // Update soldiers for kingdom
                        $query = "INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount)
                                      VALUES (?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE soldiercount = soldiercount + $soldierdifference";
                        $stmtSoldiers = $this->mysqli->prepare($query);
                        $soldierName = $soldiers->getSoldierName($this->soldierid);
                        $stmtSoldiers->bind_param("iisi", $this->kingdomid, $this->soldierid, $soldierName, $soldierdifference);
                        $stmtSoldiers->execute();
                        $stmtSoldiers->close();
                        $villCost = $soldierdifference * $soldiers->getSoldierVillagerCost($this->soldierid);

                        // Update kingdom villager count
                        $this->mysqli->query("UPDATE kingdoms SET villager = villager-$villCost WHERE id = '$this->kingdomid'");

                        // Update user score
                        $this->mysqli->query("UPDATE users SET score = score+($soldierdifference * " . $soldiers->getSoldierScoreGain($this->soldierid) . ") WHERE id = '$userid'");
                    }

                    if ($numberLeftToRecruit == 0) {
                        $this->mysqli->query("DELETE FROM events WHERE eventid = '$this->eventid'");
                    }
                    //}
                    break;
            }
        }

        $stmt->close();
    }

    // Show register and login forms
    function showRegisterForm($nameErr, $emailErr, $passErr, $captchaErr): void {
        ?>
        <div class="form">
            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <fieldset style="background-color:rgba(0, 0, 0, 0.7); width:25%; padding:20px;">

                    <legend><b>Registrieren</b></legend>

                    <span class="error"><?php echo $nameErr;
                        if (!empty($nameErr)) echo "<br>"; ?></span>
                    <span class="error"><?php echo $emailErr;
                        if (!empty($emailErr)) echo "<br>"; ?></span>
                    <span class="error"><?php echo $passErr;
                        if (!empty($passErr)) echo "<br>"; ?></span>
                    <span class="error"><?php echo $captchaErr; ?></span>

                    <br><br>

                    <table style="border: 0 solid transparent;">

                        <tr style="background: none">
                            <td style="color:#ffffff; padding-right:40px;"><b>Benutzername:</b></td>
                            <td><label>
                                    <input style="padding:3px" class="regis" type="text" name="username"
                                           value="<?php echo $_POST["username"] ?? ""; ?>">
                                </label></td>
                        </tr>

                        <tr style="background: none">
                            <td style="color:#ffffff"><b>E-Mail:</b></td>
                            <td><label>
                                    <input style="padding:3px" class="regis" type="text" name="email"
                                           value="<?php echo $_POST["email"] ?? ""; ?>">
                                </label></td>
                        </tr>

                        <tr style="background: none">
                            <td style="color:#ffffff"><b>Passwort:</b></td>
                            <td><label>
                                    <input style="padding:3px" class="regis" type="password" name="password"
                                </label>
                            </td>
                        </tr>

                    </table>

                    <br><br>

                    <div class="g-recaptcha" data-sitekey="6Lf1Ok4UAAAAANS2-TikRjXo-SDdelHVkGKj1PQT"></div>

                    <br><br>

                    <input type='submit' name='submit' value='Registrieren' style="width:125px; height:50px;"/>
                    <br><br>
                    ___________________________________<br><br>
                    <i>Du bist bereits registriert? Logge dich <a href='login.php'><b>hier</b></a> ein.</i>

                </fieldset>
            </form>
        </div>
        <?php
    }

    function showLoginForm($error): void {
        ?>
        <div class="form">
            <form id='login' action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method='post'
                  accept-charset='UTF-8'>
                <fieldset style="background-color:rgba(0, 0, 0, 0.7); width: 25%; padding:20px;">

                    <legend><b>Login</b></legend>

                    <span class="error"><?php echo $error; ?></span>

                    <table style="border: 0 solid transparent;">

                        <tr style="background: none">
                            <td style="color:#ffffff; padding-right:40px;"><b>Benutzername:</b></td>
                            <td><label>
                                    <input style="padding: 3px" type="text" name="username"
                                           value="<?php echo $_POST["username"] ?? ""; ?>">
                                </label></td>
                        </tr>

                        <tr style="background: none">
                            <td style="color:#ffffff"><b>Passwort:</b></td>
                            <td><label>
                                    <input style="padding: 3px" type="password" name="password">
                                </label></td>
                        </tr>

                    </table>

                    <br><br>

                    <input type='submit' name='login' value='Einloggen' style="width:125px;height:50px;"/>
                    <br><br>
                    ___________________________________<br><br>
                    <i>Du bist noch nicht registriert? Registriere dich <a href='register.php'><b>hier</b></a>.</i>

                </fieldset>
            </form>
        </div>
        <?php
    }
}

?>
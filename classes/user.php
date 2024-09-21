<?php

class User {
    public string $error = "";
    private $mysqli;
    private $current_kingdom;

    // Constructor
    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
        $this->current_kingdom = $_SESSION["kingdomid"] ?? null;
    }

    // Function to register a new user
    public function registerUser($name, $email, $pass): void {
        $password = password_hash($pass, PASSWORD_BCRYPT);
        $activationkey = md5($email . $name);

        $this->mysqli->execute_query("INSERT INTO users (username, password, activationkey, email, registerdate) VALUES (?, ?, ?, ?, UNIX_TIMESTAMP(NOW()))", [$name, $password, $activationkey, $email]);
        $insertid = $this->mysqli->insert_id;

        // Try to create kingdom
        $kingdom = new Kingdoms($this->mysqli);
        $mainkingdom = $kingdom->createKingdom($insertid, $name);

        // Update mainkingdom in user table
        $this->mysqli->execute_query("UPDATE users SET mainkingdom = '$mainkingdom' WHERE id = ?", [$insertid]);

        // Create activation link to activate account
        $actual_link = "https://$_SERVER[HTTP_HOST]/magic-empires/" . "activation.php?key=" . $activationkey;

        $empfaenger = $email;
        $betreff = 'Magic-Empires - Registration';
        $nachricht = "Willkommen bei Magic-Empires!<br><br>Klicke auf diesen Link, um deinen Account zu aktivieren:<br><br><a href='" . $actual_link . "'>" . $actual_link . "</a><br><br>Viel Spaß beim Zocken! :)";
        $header = "Content-type:text/html;charset=UTF-8" . "\r\n" .
            'From: webmaster@magic-empires.de' . "\r\n" .
            'X-Mailer: PHP/' . phpversion();

        if (mail($empfaenger, $betreff, $nachricht, $header)) echo "DEBUG: mail gesendet!";

        echo "<div style='text-align: center;'><b style='color: mediumseagreen;'>Du hast dich erfolgreich registriert! Ein Aktivierungslink wurde an deine E-Mail gesendet.</b><br><br><a href='login.php'>Hier einloggen</a></div>";

        // Update lastrank
        $query = "
                    UPDATE users 
                        JOIN (
                            SELECT id, (@rank := @rank + 1) AS new_rank
                            FROM (SELECT id FROM users ORDER BY score DESC) AS ranked_users
                            CROSS JOIN (SELECT @rank := 0) AS init
                        ) AS ranked_users ON users.id = ranked_users.id
                    SET users.lastrank = ranked_users.new_rank
                    WHERE users.id = ?
        ";
        $this->mysqli->execute_query($query, [$insertid]);
        $this->mysqli->close();
    }

    // Function to log in a user
    public function loginUser($userid): void {
        $timestamp = time();

        // Fetch users data
        $result = $this->mysqli->execute_query("SELECT username, lastlogin, score, mainkingdom, lastsentmsg FROM users WHERE id = ?", [$userid]);
        $row = $result->fetch_assoc();
        $_SESSION["currlogin"] = $timestamp;
        $_SESSION["userid"] = $userid;
        $_SESSION["lastlogin"] = $row["lastlogin"];
        $_SESSION["username"] = $row["username"];
        $_SESSION["kingdomid"] = $row["mainkingdom"];
        $_SESSION["lastsentmsg"] = $row["lastsentmsg"];

        // Update login time
        $this->mysqli->execute_query("UPDATE users SET ip = '{$_SERVER['REMOTE_ADDR']}', lastlogin = $timestamp, lastactivity = $timestamp WHERE id = ?", [$userid]);

        changeLocation("index.php");

        /*$result = $this->mysqli->execute_query("SELECT id FROM users WHERE username = ? LIMIT 1", [$name]);
        $row = $result->fetch_assoc();
        $found = $result->num_rows == 1;

        // Check if user exists
        if ($found) {
            $userid = $row["id"];

            // Fetch users data
            $result = $this->mysqli->execute_query("SELECT username, status, password, email, lastlogin, score, mainkingdom, guildid, lastsentmsg FROM users WHERE id = ?", [$userid]);
            $row = $result->fetch_assoc();
            $name = $row["username"];
            $status = $row["status"];
            $password = $row["password"];
            //$email = $row["email"];
            $lastlogin = $row["lastlogin"];
            $mainkingdom = $row["mainkingdom"];
            //$guild = $row["guildid"];
            $lastsentmsg = $row["lastsentmsg"];

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
                    $_SESSION["lastsentmsg"] = $lastsentmsg;

                    changeLocation("index.php");
                }
            } else {
                $this->error = "<b class='error'>Falsches Passwort!</b><br><br>";
            }
        } else {
            $this->error = "<b class='error'>Dieser Nickname existiert nicht!</b><br><br>";
        }

        $this->mysqli->close();*/
    }

    // Get the user ID by activation key
    public function getUserDatabaseID($activationkey) {
        $result = $this->mysqli->execute_query("SELECT id FROM users WHERE activationkey = ?", [$activationkey]);
        $data = "";
        if ($row = $result->fetch_assoc()) {
            $data = $row["id"];
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
    public function getUserName(): string {
        return $_SESSION["username"] ?? "";
    }

    public function getUserScore(): int {
        $result = $this->mysqli->execute_query("SELECT score FROM users WHERE id = ?", [$_SESSION["userid"]]);
        $row = $result->fetch_assoc();

        return $row["score"];
    }

    public function setLastBuiltBuilding($buildingname, $buildinglevel): void {
        if (!isset($_SESSION["last_built_building"])) {
            $_SESSION["last_built_building"] = array();
        }
        $_SESSION["last_built_building"][$this->current_kingdom] = [
            "buildingname" => $buildingname,
            "buildinglevel" => $buildinglevel
        ];
    }

    public function clearLastBuiltBuilding(): void {
        if (isset($_SESSION["last_built_building"][$this->current_kingdom])) {
            unset($_SESSION["last_built_building"][$this->current_kingdom]);
        }
    }

    public function getLastBuiltBuilding(): ?array {
        return $_SESSION["last_built_building"][$this->current_kingdom] ?? null;
    }

    public function setLastRecruitedSoldier($soldiername, $soldierdifference): void {
        if (!isset($_SESSION["last_recruited_soldier"])) {
            $_SESSION["last_recruited_soldier"] = array();
        }
        $_SESSION["last_recruited_soldier"][$this->current_kingdom] = [
            "soldiername" => $soldiername,
            "soldiercount" => $soldierdifference
        ];
    }

    public function clearLastRecruitedSoldier(): void {
        if (isset($_SESSION["last_recruited_soldier"][$this->current_kingdom])) {
            unset($_SESSION["last_recruited_soldier"][$this->current_kingdom]);
        }
    }

    public function getLastRecruitedSoldier(): ?array {
        return $_SESSION["last_recruited_soldier"][$this->current_kingdom] ?? null;
    }

    // Get and execute events tied to the user
    public function processUserEvents($userid): void {
        $result = $this->mysqli->execute_query("SELECT * FROM events WHERE userid = ?", [$userid]);

        foreach ($result as $row) {
            $eventid = $row["eventid"];
            $actionid = $row["actionid"];
            $kingdomid = $row["kingdomid"];
            $buildingid = $row["buildingid"];
            $buildingtime = $row["buildingtime"];
            $buildinglevel = $row["buildinglevel"];
            $buildingname = $row["buildingname"];
            $soldierid = $row["soldierid"];
            $recruittime = $row["recruittime"];
            $soldiergoal = $row["soldiergoal"];

            switch ($actionid) {
                case ACTION_BUILD_BUILDING:
                    if ($buildingtime < time()) {
                        $result = $this->mysqli->execute_query("SELECT buildingscore FROM buildinglist WHERE id = ?", [$buildingid]);
                        $row = $result->fetch_assoc();
                        $score = $row["buildingscore"] * $buildinglevel + 1;

                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$eventid]);

                        if ($buildinglevel == 0) { // Insert new building
                            $this->mysqli->execute_query("INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel) VALUES (?, ?, ?, ?)", [$kingdomid, $buildingid, $buildingname, 1]);
                        } else { // Update current building
                            $this->mysqli->execute_query("UPDATE buildings SET buildinglevel = buildinglevel + 1 WHERE kingdomid = ? AND buildingid = ?", [$kingdomid, $buildingid]);

                            $this->setLastBuiltBuilding($buildingname, $buildinglevel);
                        }
                        $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$score, $userid]);

                        switch ($buildingid) {
                            case BUILDING_STORAGE:
                                // Update storage values based on buildinglevel
                                $maxval = MAX_STORAGE_VALUE;
                                $updateval = (MAX_STORAGE_VALUE - STORAGE_STARTING_VALUE) / (MAX_BUILDING_LEVEL - 1);

                                if ($buildinglevel + 1 == MAX_BUILDING_LEVEL) {
                                    $query = "UPDATE kingdoms SET maxfood = $maxval, maxwood = $maxval, maxstone = $maxval, maxgold = $maxval  WHERE id = ?";
                                } else {
                                    $query = "UPDATE kingdoms SET maxfood = maxfood + $updateval, maxwood = maxwood + $updateval, maxstone = maxstone + $updateval, maxgold = maxgold + $updateval  WHERE id = ?";
                                }
                                $this->mysqli->execute_query($query, [$kingdomid]);
                                break;
                            case BUILDING_MILL:
                                $query = "
                                            SELECT ft.foodrate
                                            FROM map AS m 
                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid 
                                            WHERE m.kingdomid = ?
                                ";
                                $result = $this->mysqli->execute_query($query, [$kingdomid]);
                                $row = $result->fetch_assoc();
                                $foodrate = $row["foodrate"];

                                $query = "UPDATE kingdoms SET foodperhour = foodperhour + " . BASE_FOOD_GAIN * $foodrate . "  WHERE id = ?";
                                $this->mysqli->execute_query($query, [$kingdomid]);
                                break;
                            case BUILDING_SAWMILL:
                                $query = "
                                            SELECT ft.woodrate
                                            FROM map AS m 
                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid 
                                            WHERE m.kingdomid = ?
                                ";
                                $result = $this->mysqli->execute_query($query, [$kingdomid]);
                                $row = $result->fetch_assoc();
                                $woodrate = $row["woodrate"];

                                $query = "UPDATE kingdoms SET woodperhour = woodperhour + " . BASE_WOOD_GAIN * $woodrate . "  WHERE id = ?";
                                $this->mysqli->execute_query($query, [$kingdomid]);
                                break;
                            case BUILDING_STONEMINE:
                                $query = "
                                            SELECT ft.stonerate
                                            FROM map AS m 
                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid 
                                            WHERE m.kingdomid = ?
                                ";
                                $result = $this->mysqli->execute_query($query, [$kingdomid]);
                                $row = $result->fetch_assoc();
                                $stonerate = $row["stonerate"];

                                $query = "UPDATE kingdoms SET stoneperhour = stoneperhour + " . BASE_STONE_GAIN * $stonerate . "  WHERE id = ?";
                                $this->mysqli->execute_query($query, [$kingdomid]);
                                break;
                            case BUILDING_GOLDMINE:
                                $query = "
                                            SELECT ft.goldrate
                                            FROM map AS m 
                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid 
                                            WHERE m.kingdomid = ?
                                ";
                                $result = $this->mysqli->execute_query($query, [$kingdomid]);
                                $row = $result->fetch_assoc();
                                $goldrate = $row["goldrate"];

                                $query = "UPDATE kingdoms SET goldperhour = goldperhour + " . BASE_GOLD_GAIN * $goldrate . "  WHERE id = ?";
                                $this->mysqli->execute_query($query, [$kingdomid]);
                                break;
                        }
                    }
                    break;
                case ACTION_BUILD_TROOPS:
                    $soldiers = [];
                    $result = $this->mysqli->execute_query("SELECT id, requiredtime, soldiername, villager, scoregain FROM soldierlist");

                    foreach ($result as $row2) {
                        $soldier = new Soldier();
                        $soldier->setSoldierID($row2["id"]);
                        $soldier->setSoldierName($row2["soldiername"]);
                        $soldier->setSoldierVillagerCost($row2["villager"]);
                        $soldier->setSoldierTime($row2["requiredtime"]);
                        $soldier->setSoldierScoreGain($row2["scoregain"]);

                        $soldiers[] = $soldier;
                    }
                    $result->close();

                    $soldiertime = $soldiers[$soldierid]->getSoldierTime();
                    $currenttime = time();
                    $totaldifference = $recruittime - $currenttime;
                    $numberLeftToRecruit = max(0, ceil($totaldifference / $soldiertime));
                    $soldierdifference = $soldiergoal - $numberLeftToRecruit;

                    if ($soldierdifference != 0) {
                        $this->mysqli->execute_query("UPDATE events SET soldiergoal = soldiergoal - ? WHERE kingdomid = ? AND soldierid = ?", [$soldierdifference, $kingdomid, $soldierid]);

                        // Update soldiers for kingdom
                        $soldierName = $soldiers[$soldierid]->getSoldierName();
                        $query = "INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount)
                                      VALUES (?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE soldiercount = soldiercount + ?";
                        $this->mysqli->execute_query($query, [$kingdomid, $soldierid, $soldierName, $soldierdifference, $soldierdifference]);
                        $villCost = $soldierdifference * $soldiers[$soldierid]->getSoldierVillagerCost();

                        // Set last recruited soldier
                        $this->setLastRecruitedSoldier($soldierName, $soldierdifference);

                        // Update kingdom villager count
                        $this->mysqli->execute_query("UPDATE kingdoms SET villager = villager - $villCost WHERE id = ?", [$kingdomid]);

                        // Update user score
                        $this->mysqli->execute_query("UPDATE users SET score = score + (? * ?) WHERE id = ?", [$soldierdifference, $soldiers[$soldierid]->getSoldierScoreGain(), $userid]);
                    }

                    if ($numberLeftToRecruit == 0) {
                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$eventid]);
                    }
                    break;
                case ACTION_TRADING:
                    break;
            }
        }
    }

    // Show register and login forms
    function showRegisterForm($error): void {
        ?>
        <div class="form">
            <form class="login-register" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <fieldset>
                    <legend><b>Registrieren</b></legend>
                    <span class="error"><?php echo $error; ?></span>
                    <table class="table">
                        <tr>
                            <td style="color:#ffffff; padding-right:40px;"><b>Benutzername:</b></td>
                            <td>
                                <label>
                                    <input style="padding:3px" class="regis" type="text" name="username"
                                           value="<?php echo $_POST["username"] ?? ""; ?>">
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:#ffffff">
                                <b>E-Mail:</b>
                            </td>
                            <td>
                                <label>
                                    <input style="padding:3px" class="regis" type="text" name="email"
                                           value="<?php echo $_POST["email"] ?? ""; ?>">
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:#ffffff">
                                <b>Passwort:</b>
                            </td>
                            <td>
                                <label>
                                    <input style="padding:3px" class="regis" type="password" name="password"
                                </label>
                            </td>
                        </tr>
                    </table>
                    <br>
                    <div class="g-recaptcha" data-sitekey="6LeaqbQpAAAAABWbpK1bAEJ4FCAFjqbuPkNHtDzk"></div>
                    <!-- ME Schlüssel: 6Lf1Ok4UAAAAANS2-TikRjXo-SDdelHVkGKj1PQT-->
                    <br><br>
                    <input type='submit' name='submit' value='Registrieren' style="width:125px; height:50px;"/>
                    <br><br>
                    <hr>
                    <i>Du bist bereits registriert? Logge dich <a href='login.php'><b>hier</b></a> ein.</i>
                </fieldset>
            </form>
        </div>
        <?php
    }

    function showLoginForm($error): void {
        ?>
        <div class="form">
            <form class="login-register" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <fieldset>
                    <legend><b>Login</b></legend>
                    <span class="error"><?php echo $error; ?></span>
                    <table class="table">
                        <tr>
                            <td style="color:#ffffff; padding-right:40px;"><b>Benutzername:</b></td>
                            <td>
                                <label>
                                    <input style="padding: 3px" type="text" name="username"
                                           value="<?php echo $_POST["username"] ?? ""; ?>">
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td style="color:#ffffff">
                                <b>Passwort:</b>
                            </td>
                            <td>
                                <label>
                                    <input style="padding: 3px" type="password" name="password">
                                </label>
                            </td>
                        </tr>
                    </table>
                    <br>
                    <input type='submit' name='login' value='Einloggen' style="width:125px;height:50px;"/>
                    <br><br>
                    <hr>
                    <i>Du bist noch nicht registriert? Registriere dich <a href='register.php'><b>hier</b></a>.</i>
                </fieldset>
            </form>
        </div>
        <?php
    }
}

?>
<?php

class User
{
    private static User $instance;
    private object $mysqli;
    private string $reg_status;

    // Constructor
    private function __construct()
    {
        $this->mysqli = Database::get_instance()->get_connection();
    }

    public static function get_instance(): User
    {
        if (!isset(self::$instance)) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    // Function to register a new user
    public function register_user(string $name, string $email, string $pass): void
    {
        /*
        BETTER PASSWORD AND SALT ALGO:

        $salt = bin2hex(random_bytes(16));
        $combined_password = $salt . $pass;
        $hashed_password = password_hash($combined_password, PASSWORD_BCRYPT);



        LOGIN PAGE:

        $combined_password = $passentered . $salt;
        $isPasswordCorrect = password_verify($combined_password, $hashedPassword);

        if ($isPasswordCorrect) {
            // Password is correct, allow login
        } else {
            // Password is incorrect, deny login
        }
        */
        $password = password_hash($pass, PASSWORD_BCRYPT);
        $activation_key = md5($email . $name);

        $this->mysqli->execute_query("INSERT INTO users (username, password, activationkey, email, registerdate, sessionid) VALUES (?, ?, ?, ?, UNIX_TIMESTAMP(NOW()), ?)", [$name, $password, $activation_key, $email, session_id()]);
        $insert_id = $this->mysqli->insert_id;

        // Try to create kingdom
        $kingdom = new Kingdoms($this->mysqli);
        $main_kingdom = $kingdom->create_kingdom($insert_id, $name);

        if ($main_kingdom) {
            // Update mainkingdom in user table
            $this->mysqli->execute_query("UPDATE users SET mainkingdom = '$main_kingdom' WHERE id = ?", [$insert_id]);

            // Create activation link to activate account
            $actual_link = "https://$_SERVER[HTTP_HOST]/magic-empires/" . "activation.php?key=" . $activation_key;

            $receiver = $email;
            $subject = 'Magic-Empires - Registration';
            $message = "Willkommen bei Magic-Empires!<br><br>Klicke auf diesen Link, um deinen Account zu aktivieren:<br><br><a href='" . $actual_link . "'>" . $actual_link . "</a><br><br>Viel Spaß beim Zocken! :)";
            $header = "Content-type:text/html;charset=UTF-8" . "\r\n" .
                'From: webmaster@magic-empires.de' . "\r\n" .
                'X-Mailer: PHP/' . phpversion();

            if (mail($receiver, $subject, $message, $header)) {
                $this->reg_status = "<span class='passed'>Du hast dich erfolgreich registriert!<br>Ein Aktivierungslink wurde an deine E-Mail gesendet.</span><br>";

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
                $this->mysqli->execute_query($query, [$insert_id]);
            }
        } else {
            $this->reg_status = "<span class='error'>Es sind derzeit keine freien Plätze übrig!</span><br>";
            $this->mysqli->execute_query("DELETE FROM users WHERE id = ?", [$insert_id]);
        }

        //TODO: Else block for deleting user and kingdom, if mail couldn't be sent?!
    }

    // Function to log in a user
    public function login_user(int $user_id): void
    {
        $timestamp = time();

        // Fetch users data
        $result = $this->mysqli->execute_query("SELECT username, lastlogin, score, mainkingdom, msgcount, lastsentmsgend, adminlevel FROM users WHERE id = ?", [$user_id]);
        $row = $result->fetch_assoc();
        $_SESSION["currlogin"] = $timestamp;
        $_SESSION["userid"] = $user_id;
        $_SESSION["lastlogin"] = $row["lastlogin"];
        $_SESSION["username"] = $row["username"];
        $_SESSION["kingdomid"] = $row["mainkingdom"];
        $_SESSION["adminlevel"] = $row["adminlevel"];
        $_SESSION["score"] = $row["score"];
        $_SESSION["message_count"] = $row["msgcount"];
        $_SESSION["message_timeframe_end"] = $row["lastsentmsgend"];

        echo "set session id";

        // Update login time and session id
        $this->mysqli->execute_query("UPDATE users SET sessionid = ?, ip = '{$_SERVER['REMOTE_ADDR']}', lastlogin = $timestamp, lastactivity = $timestamp WHERE id = ?", [session_id(), $user_id]);

        change_location("index.php");
    }

    // Get current users session ID
    public function check_session_id(): void
    {
        $result = $this->mysqli->execute_query("SELECT sessionid FROM users WHERE id = ?", [$this->get_user_id()]);
        $dbSessionID = $result->fetch_assoc()["sessionid"];

        if ($dbSessionID !== session_id()) {
            session_destroy();
            change_location("login.php?logout");
            exit;
        }
    }

    // Get user avatar

    public function get_user_id(): int
    {
        return $_SESSION["userid"] ?? -1;
    }

    // Get the user ID by activation key

    public function get_avatar(string $user_name): string
    {
        $files = glob(__DIR__ . '/../' . UPLOADS_FILE_PATH . $user_name . ".*");

        if (!empty($files)) {
            $info = pathinfo($files[0]);
            return UPLOADS_FILE_PATH . $user_name . "." . $info["extension"];
        } else {
            return DEFAULT_AVATAR;
        }
    }

    // Check if user is logged in

    public function get_user_database_id(string $activation_key)
    {
        $result = $this->mysqli->execute_query("SELECT id FROM users WHERE activationkey = ?", [$activation_key]);
        return $result->fetch_assoc()["id"] ?? "";
    }

    public function is_logged_in(): bool
    {
        return isset($_SESSION["userid"]);
    }

    public function set_current_kingdom(int $kingdom_id): void
    {
        $_SESSION["kingdomid"] = $kingdom_id;
    }

    public function get_user_admin_level(): int
    {
        return $_SESSION["adminlevel"] ?? 0;
    }

    // Get the name of the user

    public function clear_last_built_building(int $kingdom_id): void
    {
        if (isset($_SESSION["last_built_building"][$kingdom_id])) {
            unset($_SESSION["last_built_building"][$kingdom_id]);
        }
    }

    public function get_last_built_building(int $kingdom_id): ?array
    {
        return $_SESSION["last_built_building"][$kingdom_id] ?? null;
    }

    public function clear_last_recruited_soldier(int $kingdom_id): void
    {
        if (isset($_SESSION["last_recruited_soldier"][$kingdom_id])) {
            unset($_SESSION["last_recruited_soldier"][$kingdom_id]);
        }
    }

    public function get_last_recruited_soldier(int $kingdom_id): ?array
    {
        return $_SESSION["last_recruited_soldier"][$kingdom_id] ?? null;
    }

    public function get_unread_messages(): int
    {
        $query = "
            SELECT COUNT(*) AS unread_count FROM (
                SELECT id FROM messages 
                WHERE receiverid = ? AND hasread = 0 AND deleted = 0
                UNION ALL
                SELECT id FROM servermessages 
                WHERE receiverid = ? AND hasread = 0
            ) AS combined_messages
        ";

        $result = $this->mysqli->execute_query($query, [$this->get_user_id(), $this->get_user_id()]);
        return $result->fetch_assoc()["unread_count"];
    }

    public function process_user_events(int $user_id): void
    {
        $result = $this->mysqli->execute_query("SELECT * FROM events WHERE userid = ?", [$user_id]);

        foreach ($result as $row) {
            $event_id = $row["eventid"];
            $action_id = $row["actionid"];
            $kingdom_id = $row["kingdomid"];
            $building_id = $row["buildingid"];
            $building_time = $row["buildingtime"];
            $building_level = $row["buildinglevel"];
            $building_name = $row["buildingname"];
            $soldier_id = $row["soldierid"];
            $recruit_time = $row["recruittime"];
            $soldier_goal = $row["soldiergoal"];
            $target_id = $row["targetid"];
            $target_x = $row["targetx"];
            $target_y = $row["targety"];
            $arrival_time = $row["arrivaltime"];

            switch ($action_id) {
                case ACTION_BUILD_BUILDING:
                    if ($building_time < time()) {
                        $result = $this->mysqli->execute_query("SELECT buildingscore FROM buildinglist WHERE id = ?", [$building_id]);
                        $score = $result->fetch_assoc()["buildingscore"] * $building_level + 1;

                        // Delete the event from the event table
                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);

                        if ($building_level == 0) { // Insert new building
                            $this->mysqli->execute_query("INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel) VALUES (?, ?, ?, ?)", [$kingdom_id, $building_id, $building_name, 1]);
                        } else { // Update current building
                            $this->mysqli->execute_query("UPDATE buildings SET buildinglevel = buildinglevel + 1 WHERE kingdomid = ? AND buildingid = ?", [$kingdom_id, $building_id]);

                            $this->set_last_built_building($kingdom_id, $building_name, $building_level);
                        }

                        $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$score, $user_id]);
                        $this->set_user_score($this->get_user_score() + $score);

                        switch ($building_id) {
                            case BuildingTypes::BUILDING_STORAGE:
                                // Update storage values based on buildinglevel
                                $max_val = MAX_STORAGE_VALUE;
                                $update_val = (MAX_STORAGE_VALUE - STORAGE_STARTING_VALUE) / (MAX_BUILDING_LEVEL - 1);

                                if ($building_level + 1 == MAX_BUILDING_LEVEL) {
                                    $query = "UPDATE kingdoms SET maxfood = $max_val, maxwood = $max_val, maxstone = $max_val, maxgold = $max_val  WHERE id = ?";
                                } else {
                                    $query = "UPDATE kingdoms SET maxfood = maxfood + $update_val, maxwood = maxwood + $update_val, maxstone = maxstone + $update_val, maxgold = maxgold + $update_val  WHERE id = ?";
                                }
                                $this->mysqli->execute_query($query, [$kingdom_id]);
                                break;
                            case BuildingTypes::BUILDING_MILL:
                                $query = "
                                            SELECT ft.foodrate
                                            FROM map AS m 
                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid 
                                            WHERE m.kingdomid = ?
                                ";
                                $result = $this->mysqli->execute_query($query, [$kingdom_id]);
                                $food_rate = $result->fetch_assoc()["foodrate"];

                                $query = "UPDATE kingdoms SET foodperhour = foodperhour + " . BASE_FOOD_GAIN * $food_rate . "  WHERE id = ?";
                                $this->mysqli->execute_query($query, [$kingdom_id]);
                                break;
                            case BuildingTypes::BUILDING_SAWMILL:
                                $query = "
                                            SELECT ft.woodrate
                                            FROM map AS m 
                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid 
                                            WHERE m.kingdomid = ?
                                ";
                                $result = $this->mysqli->execute_query($query, [$kingdom_id]);
                                $wood_rate = $result->fetch_assoc()["woodrate"];

                                $query = "UPDATE kingdoms SET woodperhour = woodperhour + " . BASE_WOOD_GAIN * $wood_rate . "  WHERE id = ?";
                                $this->mysqli->execute_query($query, [$kingdom_id]);
                                break;
                            case BuildingTypes::BUILDING_STONEMINE:
                                $query = "
                                            SELECT ft.stonerate
                                            FROM map AS m 
                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid 
                                            WHERE m.kingdomid = ?
                                ";
                                $result = $this->mysqli->execute_query($query, [$kingdom_id]);
                                $stone_rate = $result->fetch_assoc()["stonerate"];

                                $query = "UPDATE kingdoms SET stoneperhour = stoneperhour + " . BASE_STONE_GAIN * $stone_rate . "  WHERE id = ?";
                                $this->mysqli->execute_query($query, [$kingdom_id]);
                                break;
                            case BuildingTypes::BUILDING_GOLDMINE:
                                $query = "
                                            SELECT ft.goldrate
                                            FROM map AS m 
                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid 
                                            WHERE m.kingdomid = ?
                                ";
                                $result = $this->mysqli->execute_query($query, [$kingdom_id]);
                                $gold_rate = $result->fetch_assoc()["goldrate"];

                                $query = "UPDATE kingdoms SET goldperhour = goldperhour + " . BASE_GOLD_GAIN * $gold_rate . "  WHERE id = ?";
                                $this->mysqli->execute_query($query, [$kingdom_id]);
                                break;
                        }
                    }
                    break;
                case ACTION_BUILD_TROOPS:
                    $soldiers = [];
                    $result = $this->mysqli->execute_query("SELECT id, requiredtime, soldiername, villager, scoregain FROM soldierlist");

                    foreach ($result as $row_2) {
                        $soldier = new Soldier();
                        $soldier->set_soldier_id($row_2["id"]);
                        $soldier->set_soldier_name($row_2["soldiername"]);
                        $soldier->set_soldier_villager_cost($row_2["villager"]);
                        $soldier->set_soldier_time($row_2["requiredtime"]);
                        $soldier->set_soldier_score_gain($row_2["scoregain"]);

                        $soldiers[] = $soldier;
                    }

                    $soldier_time = $soldiers[$soldier_id]->get_soldier_time();
                    $current_time = time();
                    $total_difference = $recruit_time - $current_time;
                    $number_left_to_recruit = max(0, ceil($total_difference / $soldier_time));
                    $soldier_difference = $soldier_goal - $number_left_to_recruit;

                    if ($soldier_difference != 0) {
                        $this->mysqli->execute_query("UPDATE events SET soldiergoal = soldiergoal - ? WHERE kingdomid = ? AND soldierid = ?", [$soldier_difference, $kingdom_id, $soldier_id]);

                        // Update soldiers for kingdom
                        $soldier_name = $soldiers[$soldier_id]->get_soldier_name();
                        $query = "INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount)
                                      VALUES (?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE soldiercount = soldiercount + ?";
                        $this->mysqli->execute_query($query, [$kingdom_id, $soldier_id, $soldier_name, $soldier_difference, $soldier_difference]);
                        $vill_cost = $soldier_difference * $soldiers[$soldier_id]->get_soldier_villager_cost();

                        // Set last recruited soldier
                        $this->set_last_recruited_soldier($kingdom_id, $soldier_name, $soldier_difference);

                        // Update kingdom villager count and get current villager count
                        $this->mysqli->execute_query("UPDATE kingdoms SET villager = villager - $vill_cost WHERE id = ?", [$kingdom_id]);
                        apply_villager_cap($kingdom_id);

                        // Update user score
                        $score = $soldier_difference * $soldiers[$soldier_id]->get_soldier_score_gain();
                        $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$score, $user_id]);
                        $this->set_user_score($this->get_user_score() + $score);
                    }

                    if ($number_left_to_recruit == 0) {
                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
                    }
                    break;
                case ACTION_SEND_TROOPS:
                    if ($arrival_time < time()) {
                        $message = "Truppen sind angekommen bei Feld/königreich: $target_id ($target_x:$target_y)</br>";

                        // Fetch only the sent troops with their names in one query
                        $query = "
                            SELECT s.id, s.soldiername, st.soldiercount 
                            FROM senttroops st
                            JOIN soldierlist s ON st.soldierid = s.id
                            WHERE st.eventid = ?
                        ";
                        $result = $this->mysqli->execute_query($query, [$event_id]);

                        $soldiers = [];
                        $conquerer_count = 0;

                        foreach ($result as $row2) {
                            $soldier_id = $row2["id"];
                            $soldier_name = $row2["soldiername"];
                            $soldier_count = $row2["soldiercount"];

                            $soldiers[$soldier_id] = [
                                "name" => $soldier_name,
                                "count" => $soldier_count
                            ];

                            $message .= "$soldier_name: $soldier_count</br>";

                            // Check if there is a conqueror and count them
                            if ($soldier_name === "Eroberer") {
                                $conquerer_count = $soldier_count;
                            }
                        }

                        // Check if there are conquerors
                        $conquerer = $conquerer_count > 0;

                        if ($conquerer) {
                            if ($target_id == -1) {
                                $message .= "Angriff auf leeres Feld.</br>Versuche Feld einzunehmen...</br>";

                                // Calculate success rate
                                $success_rate = min(BASE_CONQUEST_CHANCE + ($conquerer_count * 0.05), MAX_CONQUEST_CHANCE);

                                if (mt_rand(0, 100) / 100 < $success_rate) {
                                    $message .= "Eroberung erfolgreich mit " . ($success_rate * 100) . " % Erfolgsrate!</br>";

                                    $conquerer_id = null;
                                    foreach ($soldiers as $soldier_id => $soldier_data) {
                                        if ($soldier_data["name"] === "Eroberer") {
                                            $conquerer_id = $soldier_id;
                                            break;
                                        }
                                    }

                                    // Reduce conqueror count by 1
                                    $conquerer_count--;

                                    if ($conquerer_count == 0) {
                                        $query = "DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?";
                                    } else {
                                        $query = "UPDATE senttroops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?";
                                    }
                                    $this->mysqli->execute_query($query, [$event_id, $conquerer_id]);

                                    // Create new kingdom
                                    $kingdom = new Kingdoms($this->mysqli);
                                    $new_kingdom = $kingdom->create_kingdom($this->get_user_id(), $this->get_user_name(), true, $target_x, $target_y);

                                    $message .= "Neues Königreich erstellt: $new_kingdom</br>";
                                } else {
                                    $message .= "Eroberung gescheitert mit " . (100 - $success_rate * 100) . " % ...</br>";
                                }
                            } else {
                                echo "Angriff auf anderen Spieler.<br>";
                            }
                        } else {
                            // TODO: Implement logic for other soldier types (e.g. thief = stealing stuff)
                            $message .= "Kein Eroberer dabei. Schicke Truppen zurück.</br>";
                        }

                        // Send server message to the player
                        send_server_message($this->get_user_id(), $this->get_user_name(), $message);

                        // Send troops back to users kingdom
                        $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?  WHERE eventid = ?", [ACTION_RETURN_TROOPS, time() + 10, $event_id]);
                    }
                    break;
                case ACTION_RETURN_TROOPS:
                    if ($arrival_time < time()) {
                        $message = "Deine Truppen sind vom Eroberungszug auf $target_id ($target_x:$target_y) zurückgekehrt!";

                        // Send server message to the player
                        send_server_message($this->get_user_id(), $this->get_user_name(), $message);

                        $result = $this->mysqli->execute_query("SELECT soldierid, soldiercount FROM senttroops WHERE eventid = ?", [$event_id]);

                        $soldiers = [];
                        foreach ($result as $row2) {
                            $soldier_id = $row2["soldierid"];
                            $soldiers[$soldier_id]["soldierid"] = $soldier_id;
                            $soldiers[$soldier_id]["soldiercount"] = $row2["soldiercount"];
                        }

                        foreach ($soldiers as $soldier) {
                            $query = "UPDATE soldiers SET soldiercount = soldiercount + ? WHERE kingdomid = ? AND soldierid = ?";
                            $this->mysqli->execute_query($query, [$soldier["soldiercount"], $kingdom_id, $soldier["soldierid"]]);
                        }

                        // Delete the event and senttroops
                        $this->mysqli->execute_query("DELETE FROM senttroops WHERE eventid = ?", [$event_id]);
                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
                    }
                    break;
                case ACTION_TRADING:
                    break;
            }
        }
    }

    public function set_last_built_building(int $kingdom_id, string $building_name, int $building_level): void
    {
        if (!isset($_SESSION["last_built_building"])) {
            $_SESSION["last_built_building"] = array();
        }
        $_SESSION["last_built_building"][$kingdom_id] = [
            "buildingname" => $building_name,
            "buildinglevel" => $building_level
        ];
    }

    public function set_user_score(int $score): void
    {
        $_SESSION["score"] = $score;
    }

    public function get_user_score(): int
    {
        //$result = $this->mysqli->execute_query("SELECT score FROM users WHERE id = ?", [$_SESSION["userid"]]);
        //return $result->fetch_assoc()["score"];
        return $_SESSION["score"] ?? 0;
    }

    public function set_last_recruited_soldier(int $kingdom_id, $soldier_name, $soldier_count): void
    {
        if (!isset($_SESSION["last_recruited_soldier"])) {
            $_SESSION["last_recruited_soldier"] = array();
        }
        $_SESSION["last_recruited_soldier"][$kingdom_id] = [
            "soldiername" => $soldier_name,
            "soldiercount" => $soldier_count
        ];
    }

    public function get_user_name(): string
    {
        return $_SESSION["username"] ?? "";
    }

    // Get and execute events tied to the user

    public function get_current_kingdom(): int
    {
        return $_SESSION["kingdomid"] ?? 0;
    }

    // Show register and login forms

    function show_register_form(string $error): void
    {
        ?>
        <div class="form">
            <form class="login-register" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <fieldset>
                    <legend><b>Registrieren</b></legend>
                    <?php echo(!empty($this->reg_status) ? $this->reg_status : ''); ?>
                    <span class="error"><?= !empty($error) ? $error . "<br>" : ""; ?></span>
                    <table class="table" style="width: 50%;">
                        <tr>
                            <td><b>Benutzername:</b></td>
                            <td>
                                <label>
                                    <input style="padding:3px" class="regis" type="text" name="username"
                                           value="<?= $_POST["username"] ?? ""; ?>">
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <b>E-Mail:</b>
                            </td>
                            <td>
                                <label>
                                    <input class="regis" type="text" name="email"
                                           value="<?= $_POST["email"] ?? ""; ?>">
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <b>Passwort:</b>
                            </td>
                            <td>
                                <label>
                                    <input class="regis" type="password" name="password"
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

    function show_login_form(string $error): void
    {
        ?>
        <div class="form">
            <form class="login-register" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <fieldset>
                    <legend><b>Login</b></legend>
                    <span class="error"><?= !empty($error) ? $error . "<br><br>" : ""; ?></span>
                    <table class="table" style="width: 50%;">
                        <tr>
                            <td><b>Benutzername:</b></td>
                            <td>
                                <label>
                                    <input type="text" name="username"
                                           value="<?= $_POST["username"] ?? ""; ?>">
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <b>Passwort:</b>
                            </td>
                            <td>
                                <label>
                                    <input type="password" name="password">
                                </label>
                            </td>
                        </tr>
                    </table>
                    <br>
                    <input type='submit' name='login' value='Einloggen' style="width:125px; height:50px;"/>
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
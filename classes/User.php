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

        // Update login time and session id
        $this->mysqli->execute_query("UPDATE users SET sessionid = ?, ip = '{$_SERVER['REMOTE_ADDR']}', lastlogin = $timestamp, lastactivity = $timestamp WHERE id = ?", [session_id(), $user_id]);

        change_location("index.php");
    }

    // Get current users session ID
    public function check_session_id(): void
    {
        $result = $this->mysqli->execute_query("SELECT sessionid FROM users WHERE id = ?", [$this->get_user_id()]);

        if ($result->fetch_assoc()["sessionid"] !== session_id()) {
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
                            $this->mysqli->execute_query("INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel) 
                                VALUES (?, ?, ?, ?)", [$kingdom_id, $building_id, $building_name, 1]);
                        } else { // Update current building
                            $this->mysqli->execute_query("UPDATE buildings SET buildinglevel = buildinglevel + 1 
                                WHERE kingdomid = ? AND buildingid = ?", [$kingdom_id, $building_id]);

                            $this->set_last_built_building($kingdom_id, $building_name, $building_level);
                        }

                        $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$score, $user_id]);
                        $this->set_user_score($this->get_user_score() + $score);

                        switch ($building_id) {
                            case BuildingTypes::BUILDING_WALL:
                                // Calculate new wall HP
                                $new_wall_hp = ($building_level + 1) * DEFAULT_WALL_HP;

                                $this->mysqli->execute_query("UPDATE kingdoms SET wallhp = ? WHERE id = ?", [$new_wall_hp, $kingdom_id]);
                                break;
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
                    // TODO: Create seperate classes for the battle action (e.g. Conquest)
                    $map = new Map($this->mysqli);
                    $kingdom = new Kingdoms($this->mysqli);
                    $kingdom->get_kingdom_info($this->get_current_kingdom());
                    $my_kingdom_x = $kingdom->get_kingdom_map_x();
                    $my_kingdom_y = $kingdom->get_kingdom_map_y();

                    if ($arrival_time < time()) {
                        //$return_time = $map->get_arrival_time($target_x, $target_y, $my_kingdom_x, $my_kingdom_y);
                        $return_time = 3;
                        $message = "";
                        $enemy_message = "";

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

                            // Check if there is a conqueror and count them
                            if ($soldier_name === "Eroberer") {
                                $conquerer_count = $soldier_count;
                            }
                        }

                        // Check if there are conquerors
                        $has_conquerer = $conquerer_count > 0;

                        // Is it a free map field?
                        if ($target_id == -1) {
                            $field_query = "
                                SELECT ft.fieldname
                                FROM map m
                                JOIN fieldtypes ft ON m.fieldtype = ft.fieldid
                                WHERE m.mapx = ? AND m.mapy = ? 
                                LIMIT 1
                            ";
                            $field_result = $this->mysqli->execute_query($field_query, [$target_x, $target_y]);
                            $field_name = $field_result->fetch_assoc()["fieldname"];

                            $message .= "Truppen sind angekommen bei $field_name ($target_x:$target_y).</br><br>";

                            if ($has_conquerer) {
                                $message .= "Es wird versucht, das Gebiet zu erobern...<br>";

                                // Calculate success rate
                                $success_rate = $this->get_conquering_rate($conquerer_count) * 100;

                                if ($this->is_conquered($success_rate)) {
                                    $message .= "Die Eroberung war erfolgreich ($success_rate %)!<br>";

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

                                    $message .= "Für die Eroberung hat sich ein Eroberer geopfert.<br>Ein neues Königreich ist entstanden: $new_kingdom<br>";
                                    $message .= "Die restlichen Truppen machen sich auf den Heimweg.";
                                } else {
                                    $message .= "Die Eroberung ist zu " . (100 - $success_rate) . " % gescheitert...<br>";
                                }
                            } else {
                                // TODO: Implement logic for other soldier types (e.g. thief = stealing stuff)

                                $message .= "Kein Eroberer dabei. Die Truppen machen sich auf den Heimweg.<br>";
                            }

                            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?  WHERE eventid = ?",
                                [ACTION_RETURN_TROOPS,
                                    time() + $return_time,
                                    $event_id]);
                        } else {
                            $enemy_kingdom = new Kingdoms($this->mysqli);
                            $enemy_kingdom->get_kingdom_info($target_id);
                            $enemy_user_id = $enemy_kingdom->get_kingdom_owner_id();
                            $enemy_user_name = $enemy_kingdom->get_kingdom_owner_name();
                            $enemy_kingdom_name = $enemy_kingdom->get_kingdom_name();
                            $enemy_kingdom_id = $enemy_kingdom->get_kingdom_id();

                            if ($user_id == $enemy_user_id) {
                                $message .= "Deine Truppen sind erfolgreich bei deinem Königreich $enemy_kingdom_name ($target_x:$target_y) angekommen.<br><br>";
                                $message .= "<table class='table'>
                                            <tr>
                                                <td class='td-center td-gradient'>Einheit</td>
                                                <td class='td-center td-gradient'>Anzahl</td>
                                            </tr>";

                                // Update/Insert troops to new kingdom
                                foreach ($soldiers as $soldier_id => $soldier_data) {
                                    $message .= "<tr>
                                                <td class='td-center'>{$soldier_data["name"]}</td>
                                                <td class='td-center'>{$soldier_data["count"]}</td>
                                              </tr>";

                                    $query = "
                                            INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
                                            VALUES (?, ?, ?, ?)
                                            ON DUPLICATE KEY UPDATE soldiercount = soldiercount + VALUES(soldiercount);
                                    ";
                                    $this->mysqli->execute_query($query, [$target_id, $soldier_id, $soldier_data["name"], $soldier_data["count"]]);
                                }
                                $message .= "</table>";

                                // Delete the event and senttroops
                                $this->mysqli->execute_query("DELETE FROM senttroops WHERE eventid = ?", [$event_id]);
                                $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
                            } else {
                                $initial_soldier_count = 0;
                                $initial_enemy_count = 0;

                                $enemy_score = $this->get_user_score($enemy_user_id);
                                $my_score = $this->get_user_score();

                                if ($this->has_noob_protection($my_score, $enemy_score)) {
                                    // Enemy is protected, return troops
                                    $this->mysqli->execute_query(
                                        "UPDATE events SET actionid = ?, arrivaltime = ? WHERE eventid = ?",
                                        [ACTION_RETURN_TROOPS, time() + $return_time, $event_id]
                                    );

                                    $message .= "Der Gegner steht unter Noob-Schutz! Die Truppen machen sich auf den Heimweg.";
                                } else {
                                    $message .= "Es hat ein Kampf stattgefunden mit Spieler $enemy_user_name ($enemy_kingdom_name)!
                                         <br>Kampfergebnis:<br><br>";
                                    $enemy_message .= "Du wurdest vom Spieler {$kingdom->get_kingdom_owner_name()} ({$kingdom->get_kingdom_name()}) angegriffen!
                                         <br>Kampfergebnis:<br><br>";

                                    $enemy_soldiers = [];
                                    $my_total_atk = [];
                                    $enemy_total_def = [];
                                    $soldier_type_atk = [];
                                    $soldier_type_def = [];

                                    // Get all soldier types
                                    $soldier_types = [];
                                    $result_all_soldiers = $this->mysqli->execute_query("SELECT * FROM soldierlist");
                                    foreach ($result_all_soldiers as $row_all_soldiers) {
                                        $soldier_types[$row_all_soldiers["id"]] = [
                                            "soldierid" => $row_all_soldiers["id"],
                                            "soldiername" => $row_all_soldiers["soldiername"],
                                            "attack" => $row_all_soldiers["attack"],
                                            "defense" => $row_all_soldiers["defense"],
                                            "score" => $row_all_soldiers["scoregain"]
                                        ];
                                    }

                                    // Initialize everything to zero
                                    foreach ($soldier_types as $id => $soldier) {
                                        if (!isset($soldiers[$id])) {
                                            $soldiers[$id]["count"] = 0;
                                        }
                                        $my_total_atk[$id] = 0;
                                        $enemy_total_def[$id] = 0;
                                        $soldier_type_atk[$id] = 0;
                                        $soldier_type_def[$id] = 0;
                                    }

                                    // Get enemy soldiers
                                    $result_enemy_soldiers = $this->mysqli->execute_query("SELECT * FROM soldiers WHERE kingdomid = ?", [$target_id]);
                                    foreach ($result_enemy_soldiers as $row_enemy_soldiers) {
                                        $enemy_soldiers[$row_enemy_soldiers["soldierid"]] = $row_enemy_soldiers["soldiercount"];
                                    }

                                    // Save initial number of soldiers for attacker and enemy
                                    $initial_soldiers = [];
                                    foreach ($soldier_types as $id => $soldier) {
                                        $initial_soldiers[$id] = array(
                                            "initial_my_soldiers" => $soldiers[$id]["count"] ?? 0,
                                            "initial_enemy_soldiers" => $enemy_soldiers[$id] ?? 0,
                                            "my_losses" => 0,
                                            "enemy_losses" => 0
                                        );
                                    }

                                    // Calculate defense bonus from enemy wall
                                    $wall = fetch_kingdom_building($enemy_kingdom_id, BuildingTypes::BUILDING_WALL);
                                    $current_wall_hp = $enemy_kingdom->get_wall_hp();
                                    $bonus_defense = $enemy_kingdom->calculate_wall_defense($enemy_kingdom->get_wall_hp(),
                                        $wall->get_building_level());

                                    foreach ($soldier_types as $id => $soldier) {
                                        $my_soldier_count = $initial_soldiers[$id]["initial_my_soldiers"];
                                        $enemy_soldier_count = $initial_soldiers[$id]["initial_enemy_soldiers"];
                                        $soldier_atk = $soldier["attack"];
                                        $soldier_def = $soldier["defense"] + $bonus_defense;

                                        $enemy_soldiers[$id] = $enemy_soldier_count;
                                        $initial_soldier_count += $my_soldier_count;
                                        $initial_enemy_count += $enemy_soldier_count;

                                        $my_total_atk[$id] += $my_soldier_count * $soldier_atk;
                                        $enemy_total_def[$id] += $enemy_soldier_count * $soldier_def;

                                        $soldier_type_atk[$id] = $soldier_atk;
                                        $soldier_type_def[$id] = $soldier_def;
                                    }

                                    $accumulated_damage = 0;
                                    foreach ($soldier_types as $attacker_id => $attacker_soldier) {
                                        if ($soldiers[$attacker_id]["count"] > 0) {
                                            foreach ($soldier_types as $defender_id => $defender_soldier) {
                                                if ($enemy_soldiers[$defender_id] > 0) {

                                                    // Calculate damage done (for wall hp)
                                                    $damage_done = min($my_total_atk[$attacker_id], $enemy_total_def[$defender_id]);
                                                    $accumulated_damage += $damage_done;

                                                    $outcome_for_me = ceil(
                                                        max($my_total_atk[$attacker_id] - $enemy_total_def[$defender_id], 0) / $soldier_type_atk[$attacker_id]
                                                    );
                                                    $outcome_for_enemy = ceil(
                                                        max($enemy_total_def[$defender_id] - $my_total_atk[$attacker_id], 0) / $soldier_type_def[$defender_id]
                                                    );

                                                    $soldiers[$attacker_id]["count"] = $outcome_for_me;
                                                    $enemy_soldiers[$defender_id] = $outcome_for_enemy;

                                                    // Calculate unit loss
                                                    $initial_soldiers[$attacker_id]["my_losses"] = $initial_soldiers[$attacker_id]["initial_my_soldiers"] - $soldiers[$attacker_id]["count"];
                                                    $initial_soldiers[$defender_id]["enemy_losses"] = $initial_soldiers[$defender_id]["initial_enemy_soldiers"] - $enemy_soldiers[$defender_id];

                                                    // Recalculate total ATK for type and DEF for enemy type
                                                    $my_total_atk[$attacker_id] = $soldiers[$attacker_id]["count"] * $soldier_type_atk[$attacker_id];
                                                    $enemy_total_def[$defender_id] = $enemy_soldiers[$defender_id] * $soldier_type_def[$defender_id];
                                                }
                                            }
                                        }
                                    }

                                    // Calculate the wall damage
                                    $enemy_defense_without_wall = 0;
                                    foreach ($soldier_types as $id => $soldier) {
                                        $enemy_defense_without_wall += $enemy_soldiers[$id] * $soldier["defense"];
                                    }

                                    $damage_diff = $accumulated_damage - $enemy_defense_without_wall;

                                    // Even if leftover is zero or negative, deal some minimal damage proportional to attack power
                                    $damage_to_wall = $damage_diff > 0
                                        ? (int)round(min($current_wall_hp, $damage_diff * 0.4))
                                        : (int)max(1, min($current_wall_hp, $accumulated_damage * 0.05)); // 5% of accumulated damage minimum
                                    $new_wall_hp = max(0, $current_wall_hp - $damage_to_wall);

                                    $message .= "<table class='table' style='width: 100%;'>
                                            <tr>
                                                <td class='td-center td-gradient'>Einheit</td>
                                                <td class='td-center td-gradient'>Eigene Truppen</td>
                                                <td class='td-center td-gradient'>Eigene Verluste</td>
                                                <td class='td-center td-gradient'>Gegn. Truppen</td>
                                                <td class='td-center td-gradient'>Gegn. Verluste</td>
                                            </tr>";
                                    $enemy_message .= "<table class='table' style='width: 100%;'>
                                            <tr>
                                                <td class='td-center td-gradient'>Einheit</td>
                                                <td class='td-center td-gradient'>Eigene Truppen</td>
                                                <td class='td-center td-gradient'>Eigene Verluste</td>
                                                <td class='td-center td-gradient'>Gegn. Truppen</td>
                                                <td class='td-center td-gradient'>Gegn. Verluste</td>
                                            </tr>";

                                    $my_loss_count = 0;
                                    $my_score_loss = 0;
                                    $enemy_loss_count = 0;
                                    $enemy_score_loss = 0;

                                    foreach ($soldier_types as $id => $soldier) {
                                        if ($initial_soldiers[$id]["initial_enemy_soldiers"] == 0 && $initial_soldiers[$id]["initial_my_soldiers"] == 0) {
                                            continue;
                                        }

                                        $enemy_count = $initial_soldiers[$id]["initial_enemy_soldiers"] == 0 ? "?" : $initial_soldiers[$id]["initial_enemy_soldiers"];
                                        $enemy_loss = $initial_soldiers[$id]["initial_enemy_soldiers"] == 0 ? "?" : $initial_soldiers[$id]["enemy_losses"];
                                        $message .= "<tr>
                                                <td class='td-center'>{$soldier["soldiername"]}</td>
                                                <td class='td-center'>{$initial_soldiers[$id]["initial_my_soldiers"]}</td>
                                                <td class='td-center'>{$initial_soldiers[$id]["my_losses"]}</td>
                                                <td class='td-center'>$enemy_count</td>
                                                <td class='td-center'>$enemy_loss</td>
                                              </tr>";
                                        $enemy_message .= "<tr>
                                                <td class='td-center'>{$soldier["soldiername"]}</td>
                                                <td class='td-center'>{$initial_soldiers[$id]["initial_enemy_soldiers"]}</td>
                                                <td class='td-center'>{$initial_soldiers[$id]["enemy_losses"]}</td>
                                                <td class='td-center'>{$initial_soldiers[$id]["initial_my_soldiers"]}</td>
                                                <td class='td-center'>{$initial_soldiers[$id]["my_losses"]}</td>
                                              </tr>";

                                        if ($initial_soldiers[$id]["initial_my_soldiers"] > 0) {
                                            if ($initial_soldiers[$id]["my_losses"] >= $initial_soldiers[$id]["initial_my_soldiers"]) {
                                                $message .= "Lösche my {$soldier["soldiername"]} von Event Table von Spieler {$this->get_user_name()}<br>";

                                                $this->mysqli->execute_query("DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?", [$event_id, $id]);
                                            } else {
                                                $my_survivors = $initial_soldiers[$id]["initial_my_soldiers"] - $initial_soldiers[$id]["my_losses"];

                                                if ($my_survivors != $initial_soldiers[$id]["initial_my_soldiers"]) {
                                                    $message .= "Update my {$soldier["soldiername"]} in Event Table auf $my_survivors von Spieler {$this->get_user_name()}<br>";

                                                    $this->mysqli->execute_query("UPDATE senttroops SET soldiercount = ? WHERE eventid = ? AND soldierid = ?",
                                                        [$my_survivors, $event_id, $id]);
                                                }
                                            }

                                            if ($soldier["soldiername"] == "Eroberer") {
                                                $message .= "Conqueror before: " . $conquerer_count;

                                                $conquerer_count -= $initial_soldiers[$id]["my_losses"];

                                                $message .= "Conqueror neukalkulieren... Survivors: " . $conquerer_count;

                                                $has_conquerer = $conquerer_count > 0;
                                            }

                                            $my_score_loss += $initial_soldiers[$id]["my_losses"] * $soldier["score"];

                                            $message .= "<br>Score lose for my {$soldier["soldiername"]}: " . ($initial_soldiers[$id]["my_losses"] * $soldier["score"]) . "<br>";
                                        }

                                        if ($initial_soldiers[$id]["initial_enemy_soldiers"] > 0) {
                                            if ($initial_soldiers[$id]["enemy_losses"] >= $initial_soldiers[$id]["initial_enemy_soldiers"]) {
                                                $message .= "Lösche enemy {$soldier["soldiername"]} in soldiers table von Gegner<br>";

                                                $this->mysqli->execute_query("DELETE FROM soldiers WHERE kingdomid = ? AND soldierid = ?", [$enemy_kingdom_id, $id]);
                                            } else {
                                                $enemy_survivors = $initial_soldiers[$id]["initial_enemy_soldiers"] - $initial_soldiers[$id]["enemy_losses"];

                                                if ($enemy_survivors != $initial_soldiers[$id]["initial_enemy_soldiers"]) {
                                                    $message .= "Update enemy {$soldier["soldiername"]} in soldiers Table auf $enemy_survivors<br>";

                                                    $this->mysqli->execute_query("UPDATE soldiers SET soldiercount = ? WHERE kingdomid = ? AND soldierid = ?",
                                                        [$enemy_survivors, $enemy_kingdom_id, $id]);
                                                }
                                            }

                                            $enemy_score_loss += $initial_soldiers[$id]["enemy_losses"] * $soldier["score"];

                                            $message .= "<br>Score lose for enemy {$soldier["soldiername"]}: " . ($initial_soldiers[$id]["enemy_losses"] * $soldier["score"]) . "<br>";
                                        }

                                        $my_loss_count += $initial_soldiers[$id]["my_losses"];
                                        $enemy_loss_count += $initial_soldiers[$id]["enemy_losses"];
                                    }

                                    $message .= "<tr>
                                            <td class='td-center'><b>Summe</b></td>
                                            <td class='td-center'>$initial_soldier_count</td>
                                            <td class='td-center'>$my_loss_count</td>
                                            <td class='td-center'>$initial_enemy_count</td>
                                            <td class='td-center'>$enemy_loss_count</td>
                                          </tr>";
                                    $message .= "</table><br>";
                                    $enemy_message .= "<tr>
                                            <td class='td-center'><b>Summe</b></td>
                                            <td class='td-center'>$initial_enemy_count</td>
                                            <td class='td-center'>$enemy_loss_count</td>
                                            <td class='td-center'>$initial_soldier_count</td>
                                            <td class='td-center'>$my_loss_count</td>
                                          </tr>";
                                    $enemy_message .= "</table><br>";

                                    // After-battle message
                                    if ($my_loss_count == 0) {
                                        $message .= "Wir haben den Kampf unbeschadet überstanden!<br>";
                                    } else if (($my_loss_count / $initial_soldier_count) >= 0.5 && ($my_loss_count / $initial_soldier_count) < 1) {
                                        $message .= "Wir haben mehr als die Hälfte unserer Truppen verloren...<br>";
                                    } else if (($my_loss_count / $initial_soldier_count) > 0 && ($my_loss_count / $initial_soldier_count) < 0.5) {
                                        $message .= "Wir haben ein paar unserer Truppen verloren.<br>";
                                    } else {
                                        $message .= "Wir wurden komplett vom Gegner aufgerieben...<br>";
                                    }

                                    if ($enemy_loss_count == 0) {
                                        $enemy_message .= "Wir haben den Kampf unbeschadet überstanden!<br>";
                                    } else if (($enemy_loss_count / $initial_enemy_count) >= 0.5 && ($enemy_loss_count / $initial_enemy_count) < 1) {
                                        $enemy_message .= "Wir haben mehr als die Hälfte unserer Truppen verloren...<br>";
                                    } else if (($enemy_loss_count / $initial_enemy_count) > 0 && ($enemy_loss_count / $initial_enemy_count) < 0.5) {
                                        $enemy_message .= "Wir haben ein paar unserer Truppen verloren.<br>";
                                    } else {
                                        $enemy_message .= "Wir wurden komplett vom Gegner aufgerieben...<br>";
                                    }

                                    if ($enemy_loss_count == $initial_enemy_count) {
                                        if ($has_conquerer) {
                                            $message .= "Es wird versucht, das Königreich zu erobern...<br>";

                                            $success_rate = $this->get_conquering_rate($conquerer_count) * 100;

                                            if ($this->is_conquered($success_rate)) {
                                                $message .= "Die Eroberung war zu $success_rate % erfolgreich!<br>";

                                                $conquerer_id = null;
                                                foreach ($soldiers as $soldier_id => $soldier_data) {
                                                    if ($soldier_data["name"] === "Eroberer") {
                                                        $conquerer_id = $soldier_id;
                                                        break;
                                                    }
                                                }

                                                // Reduce conqueror count and loss count by 1
                                                $conquerer_count--;
                                                $my_loss_count++;
                                                $my_score_loss += $soldier_types[$conquerer_id]["score"];

                                                if ($conquerer_count == 0) {
                                                    $query = "DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?";
                                                } else {
                                                    $query = "UPDATE senttroops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?";
                                                }
                                                $this->mysqli->execute_query($query, [$event_id, $conquerer_id]);

                                                // Check if it's the last kingdom of the user
                                                $count_kingdoms_result = $this->mysqli->execute_query("SELECT COUNT(*) FROM kingdoms WHERE userid = ?", [$enemy_user_id]);
                                                $kingdom_count = $count_kingdoms_result->fetch_column();

                                                // Does the enemy still have a kingdom?
                                                if ($kingdom_count > 1) {
                                                    // Set new main kingdom for the enemy (is done via get_kingdom_info ?)
                                                    // If there are still events for that kingdom, delete them

                                                    $this->mysqli->execute_query("UPDATE kingdoms SET userid = ?, username = ? WHERE id = ?",
                                                        [$this->get_user_id(), $this->get_user_name(), $enemy_kingdom_id]);
                                                    $this->mysqli->execute_query("DELETE FROM events WHERE kingdomid = ? AND userid = ?",
                                                        [$target_id, $enemy_user_id]);

                                                    $query = "
                                                        SELECT SUM((b.buildinglevel * (b.buildinglevel + 1) / 2) * bl.buildingscore) AS total_score_loss
                                                        FROM buildings b
                                                        JOIN buildinglist bl ON b.buildingid = bl.id
                                                        WHERE b.kingdomid = ?;
                                                    ";
                                                    $result = $this->mysqli->execute_query($query, [$target_id]);
                                                    $total_score_loss = intval($result->fetch_assoc()["total_score_loss"]) ?? 0;

                                                    $message .= "<br>total score loss for enemy: " . $total_score_loss . "<br>";
                                                    $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$total_score_loss, $enemy_user_id]);

                                                    $message .= "[DEBUG] Der Spieler $enemy_user_name hat noch weitere Kingdoms<br>";

                                                    // Get all remaining kingdoms of the player and choose one randomly, set it as new mainkingdom if the attacked kingdom was the mainkingdom
                                                    if ($target_id == $this->get_main_kingdom($enemy_user_id)) {
                                                        // Step 1: Get all eligible kingdom IDs
                                                        $result = $this->mysqli->execute_query("SELECT id FROM kingdoms WHERE userid = ? AND id != ?",
                                                            [$enemy_user_id, $target_id]);
                                                        $kingdom_ids = [];
                                                        while ($row = $result->fetch_assoc()) {
                                                            $kingdom_ids[] = $row["id"];
                                                        }

                                                        // Step 2: Pick one random ID
                                                        if (!empty($kingdom_ids)) {
                                                            $new_main_kingdom = $kingdom_ids[array_rand($kingdom_ids)];

                                                            // Now $new_main_kingdom contains the selected random kingdom
                                                            $message .= "[DEBUG] Neues Hauptkingdom für Enemy: " . $new_main_kingdom . "<br>";
                                                            $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$new_main_kingdom, $enemy_user_id]);
                                                        }
                                                    }
                                                } else {
                                                    // TODO: Give player a new kingdom after complete defeat
                                                    // Set players score back to 2 and give him a new kingdom [check if free field?]
                                                    // If there are still events for that user, delete them

                                                    $this->mysqli->execute_query("UPDATE kingdoms SET userid = ?, username = ? WHERE id = ?",
                                                        [$this->get_user_id(), $this->get_user_name(), $enemy_kingdom_id]);
                                                    $this->mysqli->execute_query("UPDATE users SET score = 2 WHERE id = ?", [$enemy_user_id]);
                                                    $this->mysqli->execute_query("DELETE FROM events WHERE userid = ?", [$enemy_user_id]);

                                                    $message .= "[DEBUG] Der Spieler $enemy_user_name wäre jetzt vernichtet (Neustart -> Neues Königreich generieren?)<br>";

                                                    $main_kingdom = $kingdom->create_kingdom($enemy_user_id, $enemy_user_name);

                                                    if ($main_kingdom) {
                                                        // Update mainkingdom in user table
                                                        $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?",
                                                            [$main_kingdom, $enemy_user_id]);
                                                    } else {
                                                        // TODO: What if there are no free map spots available?
                                                    }
                                                }

                                                $message .= "Für die Eroberung hat sich ein Eroberer geopfert.<br>";
                                                $enemy_message .= "Unser Königreich wurde vom Gegner eingenommen...";

                                                if ($initial_soldier_count != $my_loss_count) {
                                                    $message .= "Die verbleibenden Truppen machen sich auf den Rückweg.";
                                                }
                                            } else {
                                                $message .= "[DEBUG] Die Eroberung ist zu " . (100 - $success_rate) . " % gescheitert...<br>";
                                            }
                                        } else {
                                            // TODO: Implement logic for other soldier types (e.g. thief = stealing stuff)
                                            $message .= "[DEBUG] Kein Eroberer dabei.<br>";
                                        }
                                    }

                                    // Update player score based on units lost
                                    $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?",
                                        [$my_score_loss, $this->get_user_id()]);

                                    // Update enemy score based on units lost
                                    $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$enemy_score_loss, $enemy_user_id]);

                                    // Update Wall HP for kingdom
                                    $this->mysqli->execute_query("UPDATE kingdoms SET wallhp = ? WHERE id = ?",
                                        [$new_wall_hp, $enemy_kingdom_id]);

                                    // Send troops back to users kingdom if there are still any left
                                    if ($initial_soldier_count == $my_loss_count) {
                                        $message .= "all units lost, do not send troops back, delete event";

                                        // TODO: Delete send troops event for the player (all troops lost)
                                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
                                    } else {
                                        $message .= "send alive troops back";

                                        // TODO: Send troops back (alive ones, change event type to ACTION_RETURN_TROOPS)
                                        $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?  WHERE eventid = ?",
                                            [ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
                                    }

                                    $message .= "my score loss: " . $my_score_loss . " enemy score loss: " . $enemy_score_loss;

                                    // DEBUG: SENDING TROOPS ALWAYS BACK
                                    /*$this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?  WHERE eventid = ?",
                                        [ACTION_RETURN_TROOPS, time() + 2, $event_id]);*/
                                }

                                // Send server message to the enemy
                                send_server_message($enemy_user_id, $enemy_user_name, $enemy_message, CATEGORY_WAR);
                            }
                        }

                        // Send server message to the player
                        send_server_message($this->get_user_id(), $this->get_user_name(), $message, CATEGORY_WAR);
                    }
                    break;
                case ACTION_RETURN_TROOPS:
                    if ($arrival_time < time()) {
                        $enemy_kingdom = new Kingdoms($this->mysqli);

                        if ($target_id == -1) {
                            $field_query = "SELECT ft.fieldname
                                            FROM map m
                                            JOIN fieldtypes ft ON m.fieldtype = ft.fieldid
                                            WHERE m.mapx = ? AND m.mapy = ? 
                                            LIMIT 1";
                            $field_result = $this->mysqli->execute_query($field_query, [$target_x, $target_y]);

                            $field_name = $field_result->fetch_assoc()["fieldname"];
                        } else {
                            $enemy_kingdom->get_kingdom_info($target_id);

                            $field_name = " {$enemy_kingdom->get_kingdom_owner_name()} ({$enemy_kingdom->get_kingdom_name()})";
                        }

                        $message = "Deine Truppen sind vom Feldzug zu $field_name ($target_x:$target_y) zurückgekehrt!";

                        // Send server message to the player
                        send_server_message($this->get_user_id(), $this->get_user_name(), $message, CATEGORY_WAR);

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
        $this->mysqli->execute_query("UPDATE users SET score = ? WHERE id = ?", [$score, $this->get_user_id()]);
    }

    public function get_user_score(int $user_id = -1): int
    {
        if ($user_id == -1) {
            $result = $this->mysqli->execute_query("SELECT score FROM users WHERE id = ?", [$this->get_user_id()]);
        } else {
            $result = $this->mysqli->execute_query("SELECT score FROM users WHERE id = ?", [$user_id]);
        }

        return $result->fetch_assoc()["score"];
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

    public function get_current_kingdom(): int
    {
        return $_SESSION["kingdomid"] ?? -1;
    }

    private function get_conquering_rate(int $conquerer_count): float
    {
        return min(BASE_CONQUEST_CHANCE + ($conquerer_count * 0.95), MAX_CONQUEST_CHANCE); // * 0.05
    }

    private function is_conquered(int $success_rate): bool
    {
        return mt_rand(0, 100) < $success_rate;
    }

    public function get_user_name(): string
    {
        return $_SESSION["username"] ?? "";
    }

    public function has_noob_protection(int $attacker_score, int $defender_score): bool
    {
        $noob_mult = NOOB_PROTECTION_MULT;
        $min_score = $attacker_score * $noob_mult;
        $max_score = $attacker_score / $noob_mult;

        return $defender_score < $min_score || $defender_score > $max_score;
    }

    public function get_main_kingdom(int $user_id = -1): int
    {
        if ($user_id == -1) {
            $main_kingdom = $_SESSION["main_kingdomid"];
        } else {
            $result = $this->mysqli->execute_query("SELECT mainkingdom FROM users WHERE id = ?", [$user_id]);
            $main_kingdom = $result->fetch_assoc()["mainkingdom"];
        }
        return $main_kingdom;
    }

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
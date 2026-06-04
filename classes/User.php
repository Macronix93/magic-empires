<?php

class User
{
    private object $mysqli;
    private string $reg_status = "";
    private int $user_id;
    private string $user_name;
    private int $current_kingdom;


    public function __construct(int $user_id, string $user_name, int $current_kingdom = -1)
    {
        $this->mysqli = Database::get_instance()->get_connection();
        $this->user_id = $user_id;
        $this->user_name = $user_name;
        $this->current_kingdom = $current_kingdom;
    }

    public function register_user(string $name, string $email, string $pass): void
    {
        $password = password_hash($pass, PASSWORD_BCRYPT);
        $activation_key = md5($email . $name);

        // Create activation link to activate account
        $actual_link = "https://$_SERVER[HTTP_HOST]" . BASE_URL . "index.php?key=" . $activation_key;

        $subject = 'Magic-Empires - Registrierung';
        $message = "<h2>Willkommen bei Magic-Empires!</h2>
                    <p>Hallo " . htmlspecialchars($name) . ",</p>
                    <p>vielen Dank für deine Registrierung. Bitte klicke auf den folgenden Button, um deinen Account freizuschalten:</p>
                    <p><a href='" . $actual_link . "' style='display:inline-block; background:#781e14; color:#ffffff; padding:10px 20px; text-decoration:none; border-radius:5px;'>Account aktivieren</a></p>
                    <p>Sollte der Button nicht funktionieren, kopiere diesen Link in deinen Browser:<br>" . $actual_link . "</p>
        ";

        if (send_mail($email, $subject, $message)) {
            $this->mysqli->execute_query("INSERT INTO users (username, password, activationkey, email, registerdate, sessionid) 
                                                VALUES (?, ?, ?, ?, UNIX_TIMESTAMP(NOW()), ?)",
                [$name, $password, $activation_key, $email, session_id()]);

            unset($_POST);
            unset($_SESSION["captcha_passed"]);

            $this->reg_status = show_passed_box("Du hast dich erfolgreich registriert!<br>Ein Aktivierungslink wurde an deine E-Mail gesendet.");
        } else {
            $this->reg_status = show_error_box("Mail konnte nicht gesendet werden!");
        }
    }

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
        $this->mysqli->execute_query("UPDATE users SET sessionid = ?, ip = ?, lastlogin = ?, lastactivity = ? WHERE id = ?",
            [session_id(), $_SERVER["REMOTE_ADDR"], $timestamp, $timestamp, $user_id]);

        Logger::get_instance()->log_game("ACCOUNT", "LOGIN_SUCCESS");

        change_location("overview.php");
    }

    public function process_user_events(): void
    {
        $event_manager = new EventManager($this);
        $event_manager->process_all();

//        $result = $this->mysqli->execute_query("SELECT * FROM events WHERE userid = ?", [$this->user_id]);
//
//        foreach ($result as $row) {
//            $event_id = $row["eventid"];
//            $action_id = $row["actionid"];
//            $kingdom_id = $row["kingdomid"];
//            $building_id = $row["buildingid"];
//            $building_time = $row["buildingtime"];
//            $building_level = $row["buildinglevel"];
//            $building_name = $row["buildingname"];
//            $soldier_id = $row["soldierid"];
//            $recruit_time = $row["recruittime"];
//            $soldier_goal = $row["soldiergoal"];
//            $target_id = $row["targetid"];
//            $target_x = $row["targetx"];
//            $target_y = $row["targety"];
//            $arrival_time = $row["arrivaltime"];
//
//            switch ($action_id) {
//                case ActionTypes::ACTION_RESEARCH_TECH:
//                    if ($building_time < time()) {
//                        $kingdom = new Kingdom($this->mysqli, $kingdom_id);
//
//                        switch ($building_id) {
//                            case TechTypes::TECH_TYPE_WOOD_INC:
//                                $kingdom->set_kingdom_wood_per_hour($kingdom->get_kingdom_wood_per_hour() + RESEARCH_WOOD_INC);
//                                break;
//                            case TechTypes::TECH_TYPE_FOOD_INC:
//                                $kingdom->set_kingdom_food_per_hour($kingdom->get_kingdom_food_per_hour() + RESEARCH_FOOD_INC);
//                                break;
//                            case TechTypes::TECH_TYPE_STONE_INC:
//                                $kingdom->set_kingdom_stone_per_hour($kingdom->get_kingdom_stone_per_hour() + RESEARCH_STONE_INC);
//                                break;
//                            case TechTypes::TECH_TYPE_GOLD_INC:
//                                $kingdom->set_kingdom_gold_per_hour($kingdom->get_kingdom_gold_per_hour() + RESEARCH_GOLD_INC);
//                                break;
//                            case TechTypes::TECH_TYPE_STORAGE_INC:
//                                $kingdom->set_kingdom_max_food($kingdom->get_kingdom_max_food() + RESEARCH_STORAGE_INC);
//                                $kingdom->set_kingdom_max_wood($kingdom->get_kingdom_max_wood() + RESEARCH_STORAGE_INC);
//                                $kingdom->set_kingdom_max_stone($kingdom->get_kingdom_max_stone() + RESEARCH_STORAGE_INC);
//                                $kingdom->set_kingdom_max_gold($kingdom->get_kingdom_max_gold() + RESEARCH_STORAGE_INC);
//                                break;
//                            case TechTypes::TECH_TYPE_WALL_HP_INC:
//                                if ($kingdom->get_wall_hp() == $kingdom->get_wall_max_hp()) {
//                                    $kingdom->set_wall_hp($kingdom->get_wall_hp() + RESEARCH_WALL_HP_INC);
//                                }
//                                break;
//                        }
//
//                        $result = $this->mysqli->execute_query("SELECT techscore FROM techlist WHERE id = ?", [$building_id]);
//                        $score = $result->fetch_assoc()["techscore"] * $building_level + 1;
//
//                        // Delete the event from the event table
//                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
//
//                        if ($building_level == 0) { // Insert new tech
//                            $this->mysqli->execute_query("INSERT INTO techs (kingdomid, techid, techname, techlevel)
//                                 VALUES (?, ?, ?, ?)", [$kingdom_id, $building_id, $building_name, 1]);
//                        } else { // Update current tech
//                            $this->mysqli->execute_query("UPDATE techs SET techlevel = techlevel + 1
//                                 WHERE kingdomid = ? AND techid = ?", [$kingdom_id, $building_id]);
//                        }
//                        $this->set_last_researched_tech($kingdom_id, $building_name, $building_level);
//
//                        $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$score, $this->user_id]);
//                        $this->set_user_score($this->get_user_score() + $score);
//                    }
//                    break;
//                case ActionTypes::ACTION_BUILD_BUILDING:
//                    if ($building_time < time()) {
//                        $result = $this->mysqli->execute_query("SELECT buildingscore FROM buildinglist WHERE id = ?", [$building_id]);
//                        $score = $result->fetch_assoc()["buildingscore"] * $building_level + 1;
//
//                        // Delete the event from the event table
//                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
//
//                        if ($building_level == 0) { // Insert new building
//                            $this->mysqli->execute_query("INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel)
//                                VALUES (?, ?, ?, ?)", [$kingdom_id, $building_id, $building_name, 1]);
//                        } else { // Update current building
//                            $this->mysqli->execute_query("UPDATE buildings SET buildinglevel = buildinglevel + 1
//                                WHERE kingdomid = ? AND buildingid = ?", [$kingdom_id, $building_id]);
//                        }
//                        $this->set_last_built_building($kingdom_id, $building_name, $building_level);
//
//                        $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$score, $this->user_id]);
//                        $this->set_user_score($this->get_user_score() + $score);
//
//                        switch ($building_id) {
//                            case BuildingTypes::BUILDING_WALL:
//                                $new_wall_hp = ($building_level + 1) * DEFAULT_WALL_HP;
//
//                                $this->mysqli->execute_query("UPDATE kingdoms SET wallhp = ? WHERE id = ?", [$new_wall_hp, $kingdom_id]);
//                                break;
//                            case BuildingTypes::BUILDING_STORAGE:
//                                $new_level = $building_level + 1;
//                                $storage_bonus = round(STORAGE_STARTING_VALUE * pow(STORAGE_INC_FACTOR, $new_level - 1));
//                                $storage_bonus = floor($storage_bonus / 100) * 100;
//
//                                $query = "UPDATE kingdoms SET maxfood = maxfood + ?, maxwood = maxwood + ?, maxstone = maxstone + ?, maxgold = maxgold + ? WHERE id = ?";
//                                $this->mysqli->execute_query($query, [$storage_bonus, $storage_bonus, $storage_bonus, $storage_bonus, $kingdom_id]);
//                                break;
//                            case BuildingTypes::BUILDING_MILL:
//                                $query = "
//                                            SELECT ft.foodrate
//                                            FROM map AS m
//                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid
//                                            WHERE m.kingdomid = ?
//                                ";
//                                $result = $this->mysqli->execute_query($query, [$kingdom_id]);
//                                $food_rate = $result->fetch_assoc()["foodrate"];
//
//                                $query = "UPDATE kingdoms SET foodperhour = foodperhour + " . BASE_FOOD_GAIN * $food_rate . "  WHERE id = ?";
//                                $this->mysqli->execute_query($query, [$kingdom_id]);
//                                break;
//                            case BuildingTypes::BUILDING_SAWMILL:
//                                $query = "
//                                            SELECT ft.woodrate
//                                            FROM map AS m
//                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid
//                                            WHERE m.kingdomid = ?
//                                ";
//                                $result = $this->mysqli->execute_query($query, [$kingdom_id]);
//                                $wood_rate = $result->fetch_assoc()["woodrate"];
//
//                                $query = "UPDATE kingdoms SET woodperhour = woodperhour + " . BASE_WOOD_GAIN * $wood_rate . "  WHERE id = ?";
//                                $this->mysqli->execute_query($query, [$kingdom_id]);
//                                break;
//                            case BuildingTypes::BUILDING_STONEMINE:
//                                $query = "
//                                            SELECT ft.stonerate
//                                            FROM map AS m
//                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid
//                                            WHERE m.kingdomid = ?
//                                ";
//                                $result = $this->mysqli->execute_query($query, [$kingdom_id]);
//                                $stone_rate = $result->fetch_assoc()["stonerate"];
//
//                                $query = "UPDATE kingdoms SET stoneperhour = stoneperhour + " . BASE_STONE_GAIN * $stone_rate . "  WHERE id = ?";
//                                $this->mysqli->execute_query($query, [$kingdom_id]);
//                                break;
//                            case BuildingTypes::BUILDING_GOLDMINE:
//                                $query = "
//                                            SELECT ft.goldrate
//                                            FROM map AS m
//                                            INNER JOIN fieldtypes AS ft ON m.fieldtype = ft.fieldid
//                                            WHERE m.kingdomid = ?
//                                ";
//                                $result = $this->mysqli->execute_query($query, [$kingdom_id]);
//                                $gold_rate = $result->fetch_assoc()["goldrate"];
//
//                                $query = "UPDATE kingdoms SET goldperhour = goldperhour + " . BASE_GOLD_GAIN * $gold_rate . "  WHERE id = ?";
//                                $this->mysqli->execute_query($query, [$kingdom_id]);
//                                break;
//                        }
//                    }
//                    break;
//                case ActionTypes::ACTION_BUILD_TROOPS:
//                    $soldiers = [];
//                    $result = $this->mysqli->execute_query("SELECT id, requiredtime, soldiername, villager, scoregain FROM soldierlist");
//
//                    foreach ($result as $row_2) {
//                        $soldier = new Soldier();
//                        $soldier->set_soldier_id($row_2["id"]);
//                        $soldier->set_soldier_name($row_2["soldiername"]);
//                        $soldier->set_soldier_villager_cost($row_2["villager"]);
//                        $soldier->set_soldier_time($row_2["requiredtime"]);
//                        $soldier->set_soldier_score_gain($row_2["scoregain"]);
//
//                        $soldiers[] = $soldier;
//                    }
//
//                    $soldier_time = $soldiers[$soldier_id]->get_soldier_time();
//                    $current_time = time();
//                    $total_difference = $recruit_time - $current_time;
//                    $number_left_to_recruit = max(0, ceil($total_difference / $soldier_time));
//                    $soldier_difference = $soldier_goal - $number_left_to_recruit;
//
//                    if ($soldier_difference != 0) {
//                        $this->mysqli->execute_query("UPDATE events SET soldiergoal = soldiergoal - ? WHERE kingdomid = ? AND soldierid = ?", [$soldier_difference, $kingdom_id, $soldier_id]);
//
//                        // Update soldiers for kingdom
//                        $soldier_name = $soldiers[$soldier_id]->get_soldier_name();
//                        $query = "INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount)
//                                      VALUES (?, ?, ?, ?)
//                                      ON DUPLICATE KEY UPDATE soldiercount = soldiercount + ?";
//                        $this->mysqli->execute_query($query, [$kingdom_id, $soldier_id, $soldier_name, $soldier_difference, $soldier_difference]);
//                        $vill_cost = $soldier_difference * $soldiers[$soldier_id]->get_soldier_villager_cost();
//
//                        // Set last recruited soldier
//                        $this->set_last_recruited_soldier($kingdom_id, $soldier_name, $soldier_difference);
//
//                        // Update kingdom villager count and get current villager count
//                        $this->mysqli->execute_query("UPDATE kingdoms SET villager = villager - $vill_cost WHERE id = ?", [$kingdom_id]);
//                        apply_villager_cap($kingdom_id);
//
//                        // Update user score
//                        $score = $soldier_difference * $soldiers[$soldier_id]->get_soldier_score_gain();
//                        $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$score, $this->user_id]);
//                        $this->set_user_score($this->get_user_score() + $score);
//                    }
//
//                    if ($number_left_to_recruit == 0) {
//                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
//                    }
//                    break;
//                case ActionTypes::ACTION_SEND_TROOPS:
//                    $map = new Map($this->mysqli, $this);
//                    $home_kingdom = new Kingdom($this->mysqli, $kingdom_id);
//                    $my_kingdom_x = $home_kingdom->get_kingdom_map_x();
//                    $my_kingdom_y = $home_kingdom->get_kingdom_map_y();
//
//                    if ($arrival_time < time()) {
//                        $message = "";
//                        $enemy_message = "";
//                        $return_time = $map->get_arrival_time($target_x, $target_y, $my_kingdom_x, $my_kingdom_y);
//
//                        $conquest = new Conquest($this->mysqli);
//                        $conquest->set_event_id($event_id);
//                        $conquest->fetch_sent_troops();
//                        $has_conquerer = $conquest->has_conquerer();
//                        $conquerer_count = $conquest->get_conquerer_count();
//
//                        if ($target_id == -1) {
//                            $field_query = "
//                                SELECT ft.fieldname
//                                FROM map m
//                                JOIN fieldtypes ft ON m.fieldtype = ft.fieldid
//                                WHERE m.mapx = ? AND m.mapy = ?
//                                LIMIT 1
//                            ";
//                            $field_result = $this->mysqli->execute_query($field_query, [$target_x, $target_y]);
//                            $field_name = $field_result->fetch_assoc()["fieldname"];
//
//                            $message .= "Truppen sind angekommen bei $field_name ($target_x:$target_y).</br><br>";
//
//                            if ($has_conquerer) {
//                                // Calculate success rate
//                                $success_rate = $conquest->get_conquering_rate($conquerer_count);
//                                $message .= "Es wird versucht, das Gebiet zu erobern... (Chance: $success_rate %)<br>";
//
//                                if ($conquest->is_conquered($success_rate)) {
//                                    $message .= "Die Eroberung war erfolgreich!<br>";
//
//                                    // Fetch conquerer id from soldiers array
//                                    $conquerer_id = $conquest->fetch_conquerer_id();
//
//                                    // Reduce conqueror count by 1
//                                    $conquerer_count--;
//
//                                    if ($conquerer_count == 0) {
//                                        $query = "DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?";
//                                    } else {
//                                        $query = "UPDATE senttroops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?";
//                                    }
//                                    $this->mysqli->execute_query($query, [$event_id, $conquerer_id]);
//
//                                    // Create new kingdom
//                                    $kingdom = new Kingdom($this->mysqli);
//                                    $new_kingdom = $kingdom->create_kingdom($this->get_user_id(), $this->get_user_name(), true, $target_x, $target_y);
//
//                                    $message .= "Für die Eroberung hat sich ein Eroberer geopfert.<br>Ein neues Königreich ist entstanden: $new_kingdom<br>";
//                                    $message .= "Die restlichen Truppen machen sich auf den Heimweg.";
//                                } else {
//                                    $message .= "Die Eroberung ist gescheitert...<br>";
//                                }
//                            } else {
//                                // TODO: Implement logic for other soldier types (e.g. thief = stealing stuff)
//                                $message .= "Kein Eroberer dabei. Die Truppen machen sich auf den Heimweg.<br>";
//                            }
//
//                            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?  WHERE eventid = ?",
//                                [ActionTypes::ACTION_RETURN_TROOPS,
//                                    time() + $return_time,
//                                    $event_id]);
//                        } else {
//                            $enemy_kingdom = new Kingdom($this->mysqli, $target_id);
//                            $enemy_user_id = $enemy_kingdom->get_kingdom_owner_id();
//                            $enemy_user_name = $enemy_kingdom->get_kingdom_owner_name();
//                            $enemy_kingdom_name = $enemy_kingdom->get_kingdom_name();
//                            $enemy_kingdom_id = $enemy_kingdom->get_kingdom_id();
//                            $enemy_user = new User($enemy_user_id, $enemy_user_name);
//
//                            $conquest->set_target_id($target_id);
//                            $conquest->set_enemy_kingdom($enemy_kingdom);
//
//                            if ($this->user_id == $enemy_user_id) {
//                                $message .= "Deine Truppen sind erfolgreich bei deinem Königreich $enemy_kingdom_name ($target_x:$target_y) angekommen.<br><br>";
//                                $conquest->deploy_soldiers_to_kingdom();
//                                $message .= $conquest->get_my_message();
//                            } else {
//                                $enemy_score = $enemy_user->get_user_score();
//                                $my_score = $this->get_user_score();
//
//                                if ($conquest->has_noob_protection($my_score, $enemy_score)) {
//                                    // Enemy is protected, return troops
//                                    $this->mysqli->execute_query(
//                                        "UPDATE events SET actionid = ?, arrivaltime = ? WHERE eventid = ?",
//                                        [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]
//                                    );
//
//                                    $message .= "Der Gegner steht unter Noob-Schutz! Die Truppen machen sich auf den Heimweg.";
//                                } else {
//                                    $message .= "Es hat ein Kampf stattgefunden mit Spieler $enemy_user_name ($enemy_kingdom_name)!
//                                         <br>Kampfergebnis:<br><br>";
//                                    $enemy_message .= "Du wurdest vom Spieler {$home_kingdom->get_kingdom_owner_name()} ({$home_kingdom->get_kingdom_name()} {$home_kingdom->get_kingdom_map_x()}:{$home_kingdom->get_kingdom_map_y()}) angegriffen!
//                                         <br>Kampfergebnis:<br><br>";
//
//                                    $conquest->initialize_soldier_types();
//                                    $conquest->initialize_soldier_values();
//                                    $conquest->get_enemy_soldiers();
//                                    $conquest->set_initial_soldiers();
//                                    $conquest->calculate_wall_bonus();
//                                    $conquest->set_soldier_stats();
//                                    $conquest->calculate_battle_outcome();
//                                    $conquest->calculate_wall_damage();
//                                    $conquest->calculate_loss_counts();
//
//                                    $my_loss_count = $conquest->get_my_loss_count();
//                                    $my_score_loss = $conquest->get_my_score_loss();
//                                    $initial_soldier_count = $conquest->get_initial_soldier_count();
//                                    $enemy_loss_count = $conquest->get_enemy_loss_count();
//                                    $enemy_score_loss = $conquest->get_enemy_score_loss();
//                                    $initial_enemy_count = $conquest->get_initial_enemy_count();
//
//                                    $message .= "<table class='table' style='width: 100%;'>
//                                            <tr>
//                                                <td class='td-center td-gradient'>Einheit</td>
//                                                <td class='td-center td-gradient'>Eigene Truppen</td>
//                                                <td class='td-center td-gradient'>Eigene Verluste</td>
//                                                <td class='td-center td-gradient'>Gegn. Truppen</td>
//                                                <td class='td-center td-gradient'>Gegn. Verluste</td>
//                                            </tr>";
//                                    $message .= $conquest->get_my_message();
//                                    $enemy_message .= "<table class='table' style='width: 100%;'>
//                                            <tr>
//                                                <td class='td-center td-gradient'>Einheit</td>
//                                                <td class='td-center td-gradient'>Eigene Truppen</td>
//                                                <td class='td-center td-gradient'>Eigene Verluste</td>
//                                                <td class='td-center td-gradient'>Gegn. Truppen</td>
//                                                <td class='td-center td-gradient'>Gegn. Verluste</td>
//                                            </tr>";
//                                    $enemy_message .= $conquest->get_enemy_message();
//
//                                    $message .= "<tr>
//                                            <td class='td-center'><b>Summe</b></td>
//                                            <td class='td-center'>$initial_soldier_count</td>
//                                            <td class='td-center'>$my_loss_count</td>
//                                            <td class='td-center'>$initial_enemy_count</td>
//                                            <td class='td-center'>$enemy_loss_count</td>
//                                          </tr>";
//                                    $message .= "</table><br>";
//                                    $enemy_message .= "<tr>
//                                            <td class='td-center'><b>Summe</b></td>
//                                            <td class='td-center'>$initial_enemy_count</td>
//                                            <td class='td-center'>$enemy_loss_count</td>
//                                            <td class='td-center'>$initial_soldier_count</td>
//                                            <td class='td-center'>$my_loss_count</td>
//                                          </tr>";
//                                    $enemy_message .= "</table><br>";
//
//                                    // After-battle message
//                                    $message .= $conquest->append_my_after_battle_message();
//                                    $enemy_message .= $conquest->append_enemy_after_battle_message();
//
//                                    if ($enemy_loss_count == $initial_enemy_count) {
//                                        if ($has_conquerer) {
//                                            $success_rate = $conquest->get_conquering_rate($conquerer_count);
//                                            $message .= "Es wird versucht, das Königreich zu erobern... (Chance: $success_rate %)<br>";
//
//                                            if ($conquest->is_conquered($success_rate)) {
//                                                $message .= "Die Eroberung war erfolgreich!<br>";
//
//                                                $conquerer_id = $conquest->fetch_conquerer_id();
//
//                                                // Reduce conqueror count and loss count by 1
//                                                $conquerer_count--;
//                                                $my_loss_count++;
//                                                $my_score_loss += $conquest->get_soldier_types()[$conquerer_id]["score"];
//
//                                                if ($conquerer_count == 0) {
//                                                    $query = "DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?";
//                                                } else {
//                                                    $query = "UPDATE senttroops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?";
//                                                }
//                                                $this->mysqli->execute_query($query, [$event_id, $conquerer_id]);
//
//                                                // Check if it's the last kingdom of the user
//                                                $count_kingdoms_result = $this->mysqli->execute_query("SELECT COUNT(*) FROM kingdoms WHERE userid = ?", [$enemy_user_id]);
//                                                $kingdom_count = $count_kingdoms_result->fetch_column();
//
//                                                // Does the enemy still have a kingdom?
//                                                if ($kingdom_count > 1) {
//                                                    // Set new main kingdom for the enemy
//                                                    // If there are still events for that kingdom, delete them
//                                                    $this->mysqli->execute_query("DELETE FROM events WHERE kingdomid = ? AND userid = ?",
//                                                        [$target_id, $enemy_user_id]);
//
//                                                    $query = "
//                                                        SELECT SUM((b.buildinglevel * (b.buildinglevel + 1) / 2) * bl.buildingscore) AS total_score_loss
//                                                        FROM buildings b
//                                                        JOIN buildinglist bl ON b.buildingid = bl.id
//                                                        WHERE b.kingdomid = ?;
//                                                    ";
//                                                    $result = $this->mysqli->execute_query($query, [$target_id]);
//                                                    $total_score_loss = intval($result->fetch_assoc()["total_score_loss"]) ?? 0;
//
//                                                    $message .= "<br>total score loss for enemy: " . $total_score_loss . "<br>";
//                                                    $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$total_score_loss, $enemy_user_id]);
//
//                                                    // Get all remaining kingdoms of the player and choose one randomly, set it as new mainkingdom if the attacked kingdom was the mainkingdom
//                                                    if ($target_id == $enemy_user->get_main_kingdom()) {
//                                                        $result = $this->mysqli->execute_query("SELECT id FROM kingdoms WHERE userid = ? AND id != ?",
//                                                            [$enemy_user_id, $target_id]);
//                                                        $kingdom_ids = [];
//                                                        while ($row = $result->fetch_assoc()) {
//                                                            $kingdom_ids[] = $row["id"];
//                                                        }
//
//                                                        // Pick one random ID
//                                                        if (!empty($kingdom_ids)) {
//                                                            $new_main_kingdom = $kingdom_ids[array_rand($kingdom_ids)];
//                                                            $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$new_main_kingdom, $enemy_user_id]);
//                                                        }
//                                                    }
//                                                } else {
//                                                    // Set players score back to 2 and give him a new kingdom [check if free field?]
//                                                    // If there are still events for that user, delete them
//                                                    $this->mysqli->execute_query("UPDATE users SET score = 2 WHERE id = ?", [$enemy_user_id]);
//                                                    $this->mysqli->execute_query("DELETE FROM events WHERE userid = ?", [$enemy_user_id]);
//
//                                                    $kingdom_helper = new Kingdom($this->mysqli);
//                                                    $main_kingdom = $kingdom_helper->create_kingdom($enemy_user_id, $enemy_user_name);
//
//                                                    if ($main_kingdom) {
//                                                        // Update mainkingdom in user table
//                                                        $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?",
//                                                            [$main_kingdom, $enemy_user_id]);
//                                                    } else {
//                                                        // TODO: What if there are no free map spots available?
//                                                    }
//                                                }
//
//                                                // Update kingdom to new owner
//                                                $this->mysqli->execute_query("UPDATE kingdoms SET userid = ?, username = ? WHERE id = ?",
//                                                    [$this->get_user_id(), $this->get_user_name(), $enemy_kingdom_id]);
//
//                                                $message .= "Für die Eroberung hat sich ein Eroberer geopfert.<br>";
//                                                $enemy_message .= "Unser Königreich wurde vom Gegner eingenommen...";
//                                            } else {
//                                                $message .= "Die Eroberung ist gescheitert...<br>";
//                                            }
//                                        } else {
//                                            // TODO: Implement logic for other soldier types (e.g. thief = stealing stuff)
//                                            $message .= "Kein Eroberer dabei.<br>";
//                                        }
//                                    }
//
//                                    // Update player score based on units lost
//                                    $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?",
//                                        [$my_score_loss, $this->get_user_id()]);
//
//                                    // Update enemy score based on units lost
//                                    $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?",
//                                        [$enemy_score_loss, $enemy_user_id]);
//
//                                    // Update Wall HP for kingdom
//                                    $this->mysqli->execute_query("UPDATE kingdoms SET wallhp = ? WHERE id = ?",
//                                        [$conquest->calculate_wall_damage(), $enemy_kingdom_id]);
//
//                                    // Send troops back to users kingdom if there are still any left
//                                    if ($initial_soldier_count == $my_loss_count) {
//                                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
//                                    } else {
//                                        $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ?  WHERE eventid = ?",
//                                            [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $event_id]);
//
//                                        $message .= "Die verbleibenden Truppen machen sich auf den Heimweg.";
//                                    }
//                                }
//
//                                // Send server message to the enemy
//                                send_server_message($enemy_user_id, $enemy_user_name, $enemy_message, MessageCategories::CATEGORY_WAR);
//                            }
//                        }
//
//                        // Send server message to the player
//                        send_server_message($this->get_user_id(), $this->get_user_name(), $message, MessageCategories::CATEGORY_WAR);
//                    }
//                    break;
//                case ActionTypes::ACTION_RETURN_TROOPS:
//                    if ($arrival_time < time()) {
//                        $enemy_kingdom = new Kingdom($this->mysqli, $target_id);
//
//                        if ($target_id == -1) {
//                            $field_query = "SELECT ft.fieldname
//                                            FROM map m
//                                            JOIN fieldtypes ft ON m.fieldtype = ft.fieldid
//                                            WHERE m.mapx = ? AND m.mapy = ?
//                                            LIMIT 1";
//                            $field_result = $this->mysqli->execute_query($field_query, [$target_x, $target_y]);
//
//                            $field_name = $field_result->fetch_assoc()["fieldname"];
//                        } else {
//                            $field_name = " {$enemy_kingdom->get_kingdom_owner_name()} ({$enemy_kingdom->get_kingdom_name()})";
//                        }
//
//                        $message = "Deine Truppen sind vom Feldzug zu $field_name ($target_x:$target_y) zurückgekehrt!";
//
//                        // Send server message to the player
//                        send_server_message($this->get_user_id(), $this->get_user_name(), $message, MessageCategories::CATEGORY_WAR);
//
//                        $result = $this->mysqli->execute_query("SELECT soldierid, soldiercount FROM senttroops WHERE eventid = ?", [$event_id]);
//
//                        $soldiers = [];
//                        foreach ($result as $row2) {
//                            $soldier_id = $row2["soldierid"];
//                            $soldiers[$soldier_id]["soldierid"] = $soldier_id;
//                            $soldiers[$soldier_id]["soldiercount"] = $row2["soldiercount"];
//                        }
//
//                        foreach ($soldiers as $soldier) {
//                            $query = "UPDATE soldiers SET soldiercount = soldiercount + ? WHERE kingdomid = ? AND soldierid = ?";
//                            $this->mysqli->execute_query($query, [$soldier["soldiercount"], $kingdom_id, $soldier["soldierid"]]);
//                        }
//
//                        // Delete the event and senttroops
//                        $this->mysqli->execute_query("DELETE FROM senttroops WHERE eventid = ?", [$event_id]);
//                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
//                    }
//                    break;
//                case ActionTypes::ACTION_RECEIVE_RESOURCES:
//                    if ($arrival_time < time()) {
//                        $target_kingdom = new Kingdom($this->mysqli, $kingdom_id);
//
//                        // buildingid = Resource type, buildinglevel = Resource amount
//                        $res_type = $building_id;
//                        $res_amount = $building_level;
//
//                        switch ($res_type) {
//                            case ResourceTypes::RESOURCE_TYPE_FOOD:
//                                $target_kingdom->give_kingdom_food($res_amount);
//                                break;
//                            case ResourceTypes::RESOURCE_TYPE_WOOD:
//                                $target_kingdom->give_kingdom_wood($res_amount);
//                                break;
//                            case ResourceTypes::RESOURCE_TYPE_STONE:
//                                $target_kingdom->give_kingdom_stone($res_amount);
//                                break;
//                            case ResourceTypes::RESOURCE_TYPE_GOLD:
//                                $target_kingdom->give_kingdom_gold($res_amount);
//                                break;
//                        }
//
//                        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$event_id]);
//
//                        $msg = "Eine Warenlieferung ist in deinem Königreich \"" . $target_kingdom->get_kingdom_name() . "\"
//                                ({$target_kingdom->get_kingdom_map_x()}:{$target_kingdom->get_kingdom_map_y()}) angekommen:<br><br>" .
//                            get_resource_icon($res_type) . " " . fnum($res_amount);
//
//                        send_server_message($this->user_id, $this->user_name, $msg, MessageCategories::CATEGORY_TRADE);
//                    }
//                    break;
//            }
//        }
    }

    public function check_session_id(): void
    {
        $result = $this->mysqli->execute_query("SELECT sessionid FROM users WHERE id = ?", [$this->get_user_id()]);

        if ($result->fetch_assoc()["sessionid"] !== session_id()) {
            session_destroy();
            change_location("index.php?logout");
            exit;
        }
    }

    public function get_user_id(): int
    {
        return $this->user_id;
    }

    public function set_user_id(int $user_id): void
    {
        $this->user_id = $user_id;
    }

    public function get_avatar(): string
    {
        $hashedName = hash('sha256', $this->user_id . AVATAR_SALT);
        $directory = __DIR__ . '/../' . UPLOADS_FILE_PATH;
        $files = glob($directory . $hashedName . ".*");

        if (!empty($files)) {
            $info = pathinfo($files[0]);
            return UPLOADS_FILE_PATH . $hashedName . "." . $info["extension"];
        }
        return DEFAULT_AVATAR;
    }

    public function get_user_database_id(string $activation_key)
    {
        $result = $this->mysqli->execute_query("SELECT id FROM users WHERE activationkey = ?", [$activation_key]);
        return $result->fetch_assoc()["id"] ?? -1;
    }

    public function is_logged_in(): bool
    {
        return isset($_SESSION["userid"]);
    }

    public function get_user_admin_level(): int
    {
        $result = $this->mysqli->execute_query("SELECT adminlevel FROM users WHERE id = ?", [$this->user_id]);
        return $result->fetch_assoc()["adminlevel"] ?? 0;
    }

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

    public function clear_last_researched_tech(int $kingdom_id): void
    {
        if (isset($_SESSION["last_researched_tech"][$kingdom_id])) {
            unset($_SESSION["last_researched_tech"][$kingdom_id]);
        }
    }

    public function get_last_researched_tech(int $kingdom_id): ?array
    {
        return $_SESSION["last_researched_tech"][$kingdom_id] ?? null;
    }

    public function set_last_researched_tech(int $kingdom_id, string $tech_name, int $tech_level): void
    {
        if (!isset($_SESSION["last_researched_tech"])) {
            $_SESSION["last_researched_tech"] = array();
        }
        $_SESSION["last_researched_tech"][$kingdom_id] = [
            "techname" => $tech_name,
            "techlevel" => $tech_level
        ];
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

    public function set_user_score(int $score): void
    {
        $this->mysqli->execute_query("UPDATE users SET score = ? WHERE id = ?", [$score, $this->get_user_id()]);
    }

    public function get_user_score(): int
    {
        $result = $this->mysqli->execute_query("SELECT score FROM users WHERE id = ?", [$this->user_id]);
        return $result->fetch_assoc()["score"];
    }

    public function get_current_kingdom(): int
    {
        return $this->current_kingdom;
    }

    public function set_current_kingdom(int $kingdom_id): void
    {
        $this->current_kingdom = $kingdom_id;
    }

    public function get_user_name(): string
    {
        return $this->user_name;
    }

    public function set_user_name(string $user_name): void
    {
        $this->user_name = $user_name;
    }

    public function get_main_kingdom(): int
    {
        $result = $this->mysqli->execute_query("SELECT mainkingdom FROM users WHERE id = ?", [$this->user_id]);
        return $result->fetch_assoc()["mainkingdom"];
    }

    public function give_user_coins(int $coins): void
    {
        $this->mysqli->execute_query("UPDATE users SET coins = coins + ? WHERE id = ?", [$coins, $this->user_id]);
    }

    public function get_user_coins(): int
    {
        $result = $this->mysqli->execute_query("SELECT coins FROM users WHERE id = ?", [$this->user_id]);
        return $result->fetch_assoc()["coins"];
    }

    public function get_reg_status(): string
    {
        return $this->reg_status;
    }
}
<?php

class EventManager
{
    private mysqli $mysqli;
    private User $user;

    public function __construct(User $user)
    {
        $this->mysqli = Database::get_instance()->get_connection();
        $this->user = $user;
    }

    public function process_all(): void
    {
        $this->cleanup_marketplace();

        $result = $this->mysqli->execute_query("SELECT * FROM events WHERE userid = ?", [$this->user->get_user_id()]);

        foreach ($result as $row) {
            $this->handle_event($row);
        }
    }

    private function handle_event(array $row): void
    {
        switch ($row["actionid"]) {
            case ActionTypes::ACTION_RESEARCH_TECH:
                $this->handle_research($row);
                break;
            case ActionTypes::ACTION_BUILD_BUILDING:
                $this->handle_building($row);
                break;
            case ActionTypes::ACTION_BUILD_TROOPS:
                $this->handle_recruitment($row);
                break;
            case ActionTypes::ACTION_SEND_TROOPS:
                $this->handle_combat($row);
                break;
            case ActionTypes::ACTION_RETURN_TROOPS:
                $this->handle_troop_return($row);
                break;
            case ActionTypes::ACTION_RECEIVE_RESOURCES:
                $this->handle_resource_transfer($row);
                break;
        }
    }

    private function handle_research(array $row): void
    {
        if ($row["buildingtime"] >= time()) return;

        $kingdom = new Kingdom($this->mysqli, $row["kingdomid"]);
        $tech_id = $row["buildingid"];

        // Apply resource effects
        switch ($tech_id) {
            case TechTypes::TECH_TYPE_WOOD_INC:
                $kingdom->set_kingdom_wood_per_hour($kingdom->get_kingdom_wood_per_hour() + RESEARCH_WOOD_INC);
                break;
            case TechTypes::TECH_TYPE_FOOD_INC:
                $kingdom->set_kingdom_food_per_hour($kingdom->get_kingdom_food_per_hour() + RESEARCH_FOOD_INC);
                break;
            case TechTypes::TECH_TYPE_STONE_INC:
                $kingdom->set_kingdom_stone_per_hour($kingdom->get_kingdom_stone_per_hour() + RESEARCH_STONE_INC);
                break;
            case TechTypes::TECH_TYPE_GOLD_INC:
                $kingdom->set_kingdom_gold_per_hour($kingdom->get_kingdom_gold_per_hour() + RESEARCH_GOLD_INC);
                break;
            case TechTypes::TECH_TYPE_STORAGE_INC:
                $kingdom->set_kingdom_max_food($kingdom->get_kingdom_max_food() + RESEARCH_STORAGE_INC);
                $kingdom->set_kingdom_max_wood($kingdom->get_kingdom_max_wood() + RESEARCH_STORAGE_INC);
                $kingdom->set_kingdom_max_stone($kingdom->get_kingdom_max_stone() + RESEARCH_STORAGE_INC);
                $kingdom->set_kingdom_max_gold($kingdom->get_kingdom_max_gold() + RESEARCH_STORAGE_INC);
                break;
            case TechTypes::TECH_TYPE_WALL_HP_INC:
                if ($kingdom->get_wall_hp() == $kingdom->get_wall_max_hp()) {
                    $kingdom->set_wall_hp($kingdom->get_wall_hp() + RESEARCH_WALL_HP_INC);
                }
                break;
        }

        // Calculate score
        $res = $this->mysqli->execute_query("SELECT techscore FROM techlist WHERE id = ?", [$tech_id]);
        $score_gain = $res->fetch_assoc()["techscore"] * $row["buildinglevel"] + 1;

        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

        if ($row["buildinglevel"] == 0) {
            $this->mysqli->execute_query("INSERT INTO techs (kingdomid, techid, techname, techlevel) VALUES (?, ?, ?, ?)",
                [$row["kingdomid"], $tech_id, $row["buildingname"], 1]);
        } else {
            $this->mysqli->execute_query("UPDATE techs SET techlevel = techlevel + 1 WHERE kingdomid = ? AND techid = ?",
                [$row["kingdomid"], $tech_id]);
        }

        $this->user->set_last_researched_tech($row["kingdomid"], $row["buildingname"], $row["buildinglevel"]);
        $this->update_user_score($score_gain);
    }

    private function handle_building(array $row): void
    {
        if ($row["buildingtime"] >= time()) return;

        $res = $this->mysqli->execute_query("SELECT buildingscore FROM buildinglist WHERE id = ?", [$row["buildingid"]]);
        $score_gain = $res->fetch_assoc()["buildingscore"] * $row["buildinglevel"] + 1;

        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

        if ($row["buildinglevel"] == 0) {
            $this->mysqli->execute_query("INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel) VALUES (?, ?, ?, ?)",
                [$row["kingdomid"], $row["buildingid"], $row["buildingname"], 1]);
        } else {
            $this->mysqli->execute_query("UPDATE buildings SET buildinglevel = buildinglevel + 1 WHERE kingdomid = ? AND buildingid = ?",
                [$row["kingdomid"], $row["buildingid"]]);
        }

        $this->user->set_last_built_building($row["kingdomid"], $row["buildingname"], $row["buildinglevel"]);
        $this->update_user_score($score_gain);

        // Special effects for a building after construction
        $this->apply_building_effects($row["buildingid"], $row["buildinglevel"], $row["kingdomid"]);
    }

    private function handle_recruitment(array $row): void
    {
        $soldiers = $this->load_soldier_data();
        $s_id = $row["soldierid"];
        $soldier_time = $soldiers[$s_id]->get_soldier_time();

        $total_difference = $row["recruittime"] - time();
        $number_left_to_recruit = max(0, ceil($total_difference / $soldier_time));
        $soldier_difference = $row["soldiergoal"] - $number_left_to_recruit;

        if ($soldier_difference != 0) {
            $this->mysqli->execute_query("UPDATE events SET soldiergoal = soldiergoal - ? WHERE kingdomid = ? AND soldierid = ?",
                [$soldier_difference, $row["kingdomid"], $s_id]);

            $soldier_name = $soldiers[$s_id]->get_soldier_name();
            $this->mysqli->execute_query("INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
                                                VALUES (?, ?, ?, ?) 
                                                ON DUPLICATE KEY UPDATE soldiercount = soldiercount + ?",
                [$row["kingdomid"], $s_id, $soldier_name, $soldier_difference, $soldier_difference]);

            $this->user->set_last_recruited_soldier($row["kingdomid"], $soldier_name, $soldier_difference);

            $vill_cost = $soldier_difference * $soldiers[$s_id]->get_soldier_villager_cost();
            $this->mysqli->execute_query("UPDATE kingdoms SET villager = villager - ? WHERE id = ?", [$vill_cost, $row["kingdomid"]]);
            // apply_villager_cap ?

            $score_gain = $soldier_difference * $soldiers[$s_id]->get_soldier_score_gain();
            $this->update_user_score($score_gain);
        }

        if ($number_left_to_recruit == 0) {
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
        }
    }

    private function handle_combat(array $row): void
    {
        if ($row["arrivaltime"] >= time()) return;

        $map = new Map($this->mysqli, $this->user);
        $home_kingdom = new Kingdom($this->mysqli, $row["kingdomid"]);
        $my_x = $home_kingdom->get_kingdom_map_x();
        $my_y = $home_kingdom->get_kingdom_map_y();

        $message = "";
        $enemy_message = "";
        $return_time = $map->get_arrival_time($row["targetx"], $row["targety"], $my_x, $my_y);

        $conquest = new Conquest($this->mysqli);
        $conquest->set_event_id($row["eventid"]);
        $conquest->fetch_sent_troops();

        // Target is a free field
        if ($row["targetid"] == -1) {
            $this->process_empty_field_conquest($row, $conquest, $message);

            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ? WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);
        } // Target is a kingdom
        else {
            $enemy_kingdom = new Kingdom($this->mysqli, $row["targetid"]);
            $enemy_uid = $enemy_kingdom->get_kingdom_owner_id();

            // Own kingdom (park troops)
            if ($this->user->get_user_id() == $enemy_uid) {
                $message .= "Deine Truppen sind erfolgreich bei deinem Königreich {$enemy_kingdom->get_kingdom_name()} ({$row["targetx"]}:{$row["targety"]}) angekommen.<br><br>";
                $conquest->set_target_id($row["targetid"]);
                $conquest->deploy_soldiers_to_kingdom();
                $message .= $conquest->get_my_message();
            } else { // Enemy kingdom (battle)
                $this->process_battle($row, $conquest, $home_kingdom, $enemy_kingdom, $message, $enemy_message, $return_time);
            }
        }

        send_server_message($this->user->get_user_id(), $this->user->get_user_name(), $message, MessageCategories::CATEGORY_WAR);
    }

    private function handle_troop_return(array $row): void
    {
        if ($row["arrivaltime"] >= time()) return;

        $target_x = $row["targetx"];
        $target_y = $row["targety"];

        if ($row["targetid"] == -1) {
            $res = $this->mysqli->execute_query("SELECT ft.fieldname FROM map m JOIN fieldtypes ft ON m.fieldtype = ft.fieldid WHERE m.mapx = ? AND m.mapy = ?",
                [$target_x, $target_y]);

            $field_name = $res->fetch_assoc()["fieldname"] ?? "Unbekannt";
        } else {
            $enemy_k = new Kingdom($this->mysqli, $row["targetid"]);

            $field_name = " {$enemy_k->get_kingdom_owner_name()} ({$enemy_k->get_kingdom_name()})";
        }

        $msg = "Deine Truppen sind vom Feldzug zu $field_name ($target_x:$target_y) zurückgekehrt!";
        send_server_message($this->user->get_user_id(), $this->user->get_user_name(), $msg, MessageCategories::CATEGORY_WAR);

        $res = $this->mysqli->execute_query("SELECT soldierid, soldiercount FROM senttroops WHERE eventid = ?", [$row["eventid"]]);

        while ($sol = $res->fetch_assoc()) {
            $this->mysqli->execute_query("UPDATE soldiers SET soldiercount = soldiercount + ? WHERE kingdomid = ? AND soldierid = ?",
                [$sol["soldiercount"], $row["kingdomid"], $sol["soldierid"]]);
        }

        // Delete the event and senttroops
        $this->mysqli->execute_query("DELETE FROM senttroops WHERE eventid = ?", [$row["eventid"]]);
        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
    }

    private function handle_resource_transfer(array $row): void
    {
        if ($row["arrivaltime"] >= time()) return;

        // buildingid = Resource type, buildinglevel = Resource amount
        $target_k = new Kingdom($this->mysqli, $row["kingdomid"]);
        $res_type = $row["buildingid"];
        $amount = $row["buildinglevel"];

        switch ($res_type) {
            case ResourceTypes::RESOURCE_TYPE_FOOD:
                $target_k->give_kingdom_food($amount);
                break;
            case ResourceTypes::RESOURCE_TYPE_WOOD:
                $target_k->give_kingdom_wood($amount);
                break;
            case ResourceTypes::RESOURCE_TYPE_STONE:
                $target_k->give_kingdom_stone($amount);
                break;
            case ResourceTypes::RESOURCE_TYPE_GOLD:
                $target_k->give_kingdom_gold($amount);
                break;
        }

        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);

        $msg = "Eine Warenlieferung ist in deinem Königreich \"{$target_k->get_kingdom_name()}\" 
                ({$target_k->get_kingdom_map_x()}:{$target_k->get_kingdom_map_y()}) angekommen:<br><br>" . get_resource_icon($res_type) . " " . fnum($amount);
        send_server_message($this->user->get_user_id(), $this->user->get_user_name(), $msg, MessageCategories::CATEGORY_TRADE);
    }

    // Helper functions

    private function apply_building_effects(int $bid, int $lvl, int $kid): void
    {
        switch ($bid) {
            case BuildingTypes::BUILDING_WALL:
                $hp = ($lvl + 1) * DEFAULT_WALL_HP;

                $this->mysqli->execute_query("UPDATE kingdoms SET wallhp = ? WHERE id = ?", [$hp, $kid]);
                break;
            case BuildingTypes::BUILDING_STORAGE:
                $bonus = floor((round(STORAGE_STARTING_VALUE * pow(STORAGE_INC_FACTOR, $lvl))) / 100) * 100;

                $this->mysqli->execute_query("UPDATE kingdoms SET maxfood = maxfood + ?, maxwood = maxwood + ?, maxstone = maxstone + ?, maxgold = maxgold + ? WHERE id = ?",
                    [$bonus, $bonus, $bonus, $bonus, $kid]);
                break;
            case BuildingTypes::BUILDING_MILL:
                $this->update_production($kid, "foodrate", BASE_FOOD_GAIN, "foodperhour");
                break;
            case BuildingTypes::BUILDING_SAWMILL:
                $this->update_production($kid, "woodrate", BASE_WOOD_GAIN, "woodperhour");
                break;
            case BuildingTypes::BUILDING_STONEMINE:
                $this->update_production($kid, "stonerate", BASE_STONE_GAIN, "stoneperhour");
                break;
            case BuildingTypes::BUILDING_GOLDMINE:
                $this->update_production($kid, "goldrate", BASE_GOLD_GAIN, "goldperhour");
                break;
        }
    }

    private function update_production(int $kid, string $rate_field, int $base, string $target_field): void
    {
        $res = $this->mysqli->execute_query("SELECT ft.$rate_field FROM map m JOIN fieldtypes ft ON m.fieldtype = ft.fieldid WHERE m.kingdomid = ?", [$kid]);
        $rate = $res->fetch_assoc()[$rate_field];
        $this->mysqli->execute_query("UPDATE kingdoms SET $target_field = $target_field + ? WHERE id = ?", [$base * $rate, $kid]);
    }

    private function process_empty_field_conquest(array $row, Conquest $conquest, string &$message): void
    {
        $res = $this->mysqli->execute_query("SELECT ft.fieldname FROM map m JOIN fieldtypes ft ON m.fieldtype = ft.fieldid WHERE m.mapx = ? AND m.mapy = ?",
            [$row["targetx"], $row["targety"]]);

        $message .= "Truppen sind angekommen bei {$res->fetch_assoc()["fieldname"]} ({$row["targetx"]}:{$row["targety"]}).<br><br>";

        if ($conquest->has_conquerer()) {
            $rate = $conquest->get_conquering_rate($conquest->get_conquerer_count());
            $message .= "Es wird versucht, das Gebiet zu erobern... (Chance: $rate %)<br>";

            if ($conquest->is_conquered($rate)) {
                $conquerer_id = $conquest->fetch_conquerer_id();

                $soldier_types = $conquest->get_soldier_types();
                $score_loss = $soldier_types[$conquerer_id]["score"];
                $this->update_user_score(-$score_loss);

                $this->mysqli->execute_query($conquest->get_conquerer_count() <= 1 ? "DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?"
                    : "UPDATE senttroops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?", [$row["eventid"], $conquerer_id]);
                $new_kingdom = (new Kingdom($this->mysqli))->create_kingdom($this->user->get_user_id(), $this->user->get_user_name(), true, $row["targetx"], $row["targety"]);

                $message .= "Die Eroberung war erfolgreich!<br>Für die Eroberung hat sich ein Eroberer geopfert.
                                <br>Ein neues Königreich ist entstanden: $new_kingdom
                                <br>Die restlichen Truppen machen sich auf den Heimweg.";
            } else {
                $message .= "Die Eroberung ist gescheitert...<br>";
            }
        } else {
            // TODO: Implement logic for other soldier types (e.g. thief = stealing stuff)
            $message .= "Kein Eroberer dabei. Die Truppen machen sich auf den Heimweg.<br>";
        }
    }

    private function process_battle(array $row, Conquest $conquest, Kingdom $home_kingdom, Kingdom $enemy_kingdom, string &$message, string &$enemy_msg, int $return_time): void
    {
        $enemy_user = new User($enemy_kingdom->get_kingdom_owner_id(), $enemy_kingdom->get_kingdom_owner_name());

        if ($conquest->has_noob_protection($this->user->get_user_score(), $enemy_user->get_user_score())) {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ? WHERE eventid = ?", [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);
            $message .= "Der Gegner steht unter Noob-Schutz! Die Truppen machen sich auf den Heimweg.";
            return;
        }

        $message .= "Es hat ein Kampf stattgefunden mit Spieler {$enemy_user->get_user_name()} ({$enemy_kingdom->get_kingdom_name()})!<br>Kampfergebnis:<br><br>";
        $enemy_msg .= "Du wurdest vom Spieler {$this->user->get_user_name()} ({$home_kingdom->get_kingdom_name()} {$home_kingdom->get_kingdom_map_x()}:{$home_kingdom->get_kingdom_map_y()}) angegriffen!<br>Kampfergebnis:<br><br>";

        $conquest->set_target_id($row["targetid"]);
        $conquest->set_enemy_kingdom($enemy_kingdom);
        $conquest->initialize_soldier_types();
        $conquest->initialize_soldier_values();
        $conquest->get_enemy_soldiers();
        $conquest->set_initial_soldiers();
        $conquest->calculate_wall_bonus();
        $conquest->set_soldier_stats();
        $conquest->calculate_battle_outcome();
        $conquest->calculate_wall_damage();
        $conquest->calculate_loss_counts();

        // Kampfbericht-Tabellen
        $table = "<table class='table' style='width: 100%;'>
                    <tr>
                        <td class='td-center td-gradient'>Einheit</td>
                        <td class='td-center td-gradient'>Eigene Truppen</td>
                        <td class='td-center td-gradient'>Eigene Verluste</td>
                        <td class='td-center td-gradient'>Gegn. Truppen</td>
                        <td class='td-center td-gradient'>Gegn. Verluste</td>
                    </tr>";
        $summary = "<tr>
                        <td class='td-center'><b>Summe</b></td>
                        <td class='td-center'>{$conquest->get_initial_soldier_count()}</td>
                        <td class='td-center'>{$conquest->get_my_loss_count()}</td>
                        <td class='td-center'>{$conquest->get_initial_enemy_count()}</td>
                        <td class='td-center'>{$conquest->get_enemy_loss_count()}</td>
                    </tr></table><br>";

        $message .= $table . $conquest->get_my_message() . $summary . $conquest->append_my_after_battle_message();
        $message .= "<br><b>Punkteverlust durch Truppen:</b> -" . fnum($conquest->get_my_score_loss()) . " Punkte.<br>";

        $enemy_msg .= $table . $conquest->get_enemy_message() . "<tr>
                                                                    <td class='td-center'><b>Summe</b></td>
                                                                    <td class='td-center'>{$conquest->get_initial_enemy_count()}</td>
                                                                    <td class='td-center'>{$conquest->get_enemy_loss_count()}</td>
                                                                    <td class='td-center'>{$conquest->get_initial_soldier_count()}</td>
                                                                    <td class='td-center'>{$conquest->get_my_loss_count()}</td>
                                                                </tr></table><br>" . $conquest->append_enemy_after_battle_message();
        $enemy_msg .= "<br><b>Punkteverlust durch Truppen:</b> -" . fnum($conquest->get_enemy_score_loss()) . " Punkte.<br>";

        // Eroberungs-Check
        if ($conquest->get_enemy_loss_count() == $conquest->get_initial_enemy_count()) {
            if ($conquest->has_conquerer()) {
                $this->handle_post_battle_conquest($row, $conquest, $enemy_kingdom, $enemy_user, $message, $enemy_msg);
            } else {
                // TODO: Implement logic for other soldier types (e.g. thief = stealing stuff)
                $message .= "Kein Eroberer dabei.<br>";
            }
        }

        // Score & Wall-Updates
        $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$conquest->get_my_score_loss(), $this->user->get_user_id()]);
        $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$conquest->get_enemy_score_loss(), $enemy_user->get_user_id()]);
        $this->mysqli->execute_query("UPDATE kingdoms SET wallhp = ? WHERE id = ?", [$conquest->calculate_wall_damage(), $enemy_kingdom->get_kingdom_id()]);

        // Send troops back to users kingdom if there are still any left
        if ($conquest->get_initial_soldier_count() == $conquest->get_my_loss_count()) {
            $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$row["eventid"]]);
        } else {
            $this->mysqli->execute_query("UPDATE events SET actionid = ?, arrivaltime = ? WHERE eventid = ?",
                [ActionTypes::ACTION_RETURN_TROOPS, time() + $return_time, $row["eventid"]]);

            $message .= "Die verbleibenden Truppen machen sich auf den Heimweg.";
        }

        // Send server message to the enemy
        send_server_message($enemy_user->get_user_id(), $enemy_user->get_user_name(), $enemy_msg, MessageCategories::CATEGORY_WAR);
    }

    private function handle_post_battle_conquest(array $row, Conquest $conquest, Kingdom $enemy_kingdom, User $enemy_user, string &$message, string &$enemy_msg): void
    {
        $rate = $conquest->get_conquering_rate($conquest->get_conquerer_count());
        $message .= "Es wird versucht, das Königreich zu erobern... (Chance: $rate %)<br>";

        if ($conquest->is_conquered($rate)) {
            $c_id = $conquest->fetch_conquerer_id();

            $soldier_types = $conquest->get_soldier_types();
            $score_loss = $soldier_types[$c_id]["score"];
            $this->update_user_score(-$score_loss);

            $this->mysqli->execute_query($conquest->get_conquerer_count() <= 1 ? "DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?"
                : "UPDATE senttroops SET soldiercount = soldiercount - 1 WHERE eventid = ? AND soldierid = ?", [$row["eventid"], $c_id]);

            // Check if it's the last kingdom of the enemy
            $k_count_res = $this->mysqli->execute_query("SELECT COUNT(*) FROM kingdoms WHERE userid = ?", [$enemy_user->get_user_id()]);

            $loss_res = $this->mysqli->execute_query("SELECT SUM((b.buildinglevel * (b.buildinglevel + 1) / 2) * bl.buildingscore) AS loss 
                                                FROM buildings b 
                                                JOIN buildinglist bl ON b.buildingid = bl.id 
                                                WHERE b.kingdomid = ?",
                [$enemy_kingdom->get_kingdom_id()]);
            $total_building_score_loss = (int)($loss_res->fetch_assoc()["loss"] ?? 0);

            // Does the enemy still have a kingdom?
            if ($k_count_res->fetch_column() > 1) {
                // Set new main kingdom for the enemy
                // If there are still events for that kingdom, delete them
                $this->mysqli->execute_query("DELETE FROM events WHERE kingdomid = ? AND userid = ?", [$enemy_kingdom->get_kingdom_id(), $enemy_user->get_user_id()]);

//                $loss_res = $this->mysqli->execute_query("SELECT SUM((b.buildinglevel * (b.buildinglevel + 1) / 2) * bl.buildingscore) AS loss
//                                                                FROM buildings b
//                                                                JOIN buildinglist bl ON b.buildingid = bl.id
//                                                                WHERE b.kingdomid = ?",
//                    [$enemy_kingdom->get_kingdom_id()]);

                $this->mysqli->execute_query("UPDATE users SET score = score - ? WHERE id = ?", [$total_building_score_loss, $enemy_user->get_user_id()]);

                // Get all remaining kingdoms of the player and choose one and set it as new mainkingdom if the attacked kingdom was the mainkingdom
                if ($enemy_kingdom->get_kingdom_id() == $enemy_user->get_main_kingdom()) {
                    $new_mainkingdom = $this->mysqli->execute_query("SELECT id FROM kingdoms WHERE userid = ? AND id != ? LIMIT 1",
                        [$enemy_user->get_user_id(), $enemy_kingdom->get_kingdom_id()])->fetch_column();

                    if ($new_mainkingdom) $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$new_mainkingdom, $enemy_user->get_user_id()]);
                }
            } else {
                // Set players score back to 2 and give him a new kingdom [check if free field?]
                // If there are still events for that user, delete them
                $this->mysqli->execute_query("UPDATE users SET score = 2 WHERE id = ?", [$enemy_user->get_user_id()]);
                $this->mysqli->execute_query("DELETE FROM events WHERE userid = ?", [$enemy_user->get_user_id()]);
                $main_kingdom = (new Kingdom($this->mysqli))->create_kingdom($enemy_user->get_user_id(), $enemy_user->get_user_name());

                if ($main_kingdom) {
                    $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?",
                        [$main_kingdom, $enemy_user->get_user_id()]);
                } else {
                    // TODO: What if there are no free map spots available?
                    echo "no free map spots available anymore!";
                }
            }

            // Update kingdom to new owner
            $this->mysqli->execute_query("UPDATE kingdoms SET userid = ?, username = ? WHERE id = ?",
                [$this->user->get_user_id(), $this->user->get_user_name(), $enemy_kingdom->get_kingdom_id()]);

            $message .= "Die Eroberung war erfolgreich!<br>Für die Eroberung hat sich ein Eroberer geopfert.<br>Das Königreich gehört nun dir.<br>";
            $message .= "Der Gegner hat <b>" . fnum($total_building_score_loss) . " Punkte</b> durch den Verlust der Gebäude verloren.<br>";
            $enemy_msg .= "Unser Königreich wurde vom Gegner eingenommen...";
            $enemy_msg .= "Du hast <b>" . fnum($total_building_score_loss) . " Punkte</b> durch den Verlust deiner Gebäude verloren.<br>";
        } else {
            $message .= "Die Eroberung ist gescheitert...<br>";
        }
    }

    public function cleanup_marketplace(): void
    {
        $now = time();
        // Find all expired offers
        $result = $this->mysqli->execute_query("SELECT * FROM marketplace WHERE expires_at <= ?", [$now]);

        while ($row = $result->fetch_assoc()) {
            $offer_id = $row["offerid"];
            $k_id = $row["kingdomid"];
            $res_type = $row["supply"];
            $amount = $row["supplyvalue"];
            $u_id = $row["userid"];
            $u_name = $row["username"];

            // Give the resources back to the original kingdom
            $res_field = match ($res_type) {
                ResourceTypes::RESOURCE_TYPE_FOOD => "food",
                ResourceTypes::RESOURCE_TYPE_WOOD => "wood",
                ResourceTypes::RESOURCE_TYPE_STONE => "stone",
                ResourceTypes::RESOURCE_TYPE_GOLD => "gold",
                default => null
            };

            if ($res_field) {
                $this->mysqli->execute_query("UPDATE kingdoms SET $res_field = $res_field + ? WHERE id = ?",
                    [$amount, $k_id]
                );
            }

            $msg = "Ein Handelsangebot von dir ist abgelaufen. Die Ressourcen wurden an dein Königreich zurückerstattet.";
            send_server_message($u_id, $u_name, $msg, MessageCategories::CATEGORY_TRADE);

            // Delete offer
            $this->mysqli->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$offer_id]);
        }
    }

    private function update_user_score(int $add): void
    {
        $this->mysqli->execute_query("UPDATE users SET score = score + ? WHERE id = ?", [$add, $this->user->get_user_id()]);
        $this->user->set_user_score($this->user->get_user_score() + $add);
    }

    private function load_soldier_data(): array
    {
        $soldiers = [];
        $res = $this->mysqli->execute_query("SELECT * FROM soldierlist");

        foreach ($res as $row) {
            $s = new Soldier();
            $s->set_soldier_id($row["id"]);
            $s->set_soldier_name($row["soldiername"]);
            $s->set_soldier_villager_cost($row["villager"]);
            $s->set_soldier_time($row["requiredtime"]);
            $s->set_soldier_score_gain($row["scoregain"]);
            $soldiers[$row["id"]] = $s;
        }

        return $soldiers;
    }
}
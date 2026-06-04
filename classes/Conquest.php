<?php

class Conquest
{
    private object $mysqli;
    private array $soldiers = [];
    private array $enemy_soldiers = [];
    private array $my_total_atk = [];
    private array $enemy_total_def = [];
    private array $soldier_type_atk = [];
    private array $soldier_type_def = [];
    private array $soldier_types = [];
    private array $initial_soldiers = [];
    private int $initial_soldier_count = 0;
    private int $initial_enemy_count = 0;
    private int $my_loss_count = 0;
    private int $my_score_loss = 0;
    private int $enemy_loss_count = 0;
    private int $enemy_score_loss = 0;
    private int $target_id;
    private int $event_id;
    private int $conquerer_count = 0;
    private int $accumulated_damage = 0;
    private Kingdom $enemy_kingdom;
    private string $my_message = "";
    private string $enemy_message = "";

    public function __construct(object $db_conn)
    {
        $this->mysqli = $db_conn;
    }

    public function set_target_id(int $target_id): void
    {
        $this->target_id = $target_id;
    }

    public function set_event_id(int $event_id): void
    {
        $this->event_id = $event_id;
    }

    public function set_enemy_kingdom(Kingdom $enemy_kingdom): void
    {
        $this->enemy_kingdom = $enemy_kingdom;
    }

    public function fetch_sent_troops(): void
    {
        $query = "
                    SELECT s.id, s.soldiername, st.soldiercount 
                    FROM senttroops st
                    JOIN soldierlist s ON st.soldierid = s.id
                    WHERE st.eventid = ?
                ";
        $result = $this->mysqli->execute_query($query, [$this->event_id]);

        foreach ($result as $row) {
            $soldier_id = $row["id"];
            $soldier_name = $row["soldiername"];
            $soldier_count = $row["soldiercount"];

            $this->soldiers[$soldier_id] = [
                "name" => $soldier_name,
                "count" => $soldier_count
            ];

            // Check if there is a conqueror and count them
            if ($soldier_name === "Eroberer") {
                $this->conquerer_count = $soldier_count;
            }
        }
    }

    public function has_conquerer(): bool
    {
        return $this->conquerer_count > 0;
    }

    public function get_conquerer_count(): int
    {
        return $this->conquerer_count;
    }

    public function fetch_conquerer_id(): int
    {
        $conquerer_id = null;

        foreach ($this->soldiers as $soldier_id => $soldier_data) {
            if ($soldier_data["name"] === "Eroberer") {
                $conquerer_id = $soldier_id;
                break;
            }
        }

        return $conquerer_id;
    }

    public function calculate_wall_damage(): int
    {
        $current_wall_hp = $this->enemy_kingdom->get_wall_hp();
        $enemy_defense_without_wall = 0;

        foreach ($this->soldier_types as $id => $soldier) {
            $enemy_defense_without_wall += $this->enemy_soldiers[$id] * $soldier["defense"];
        }

        $damage_diff = $this->accumulated_damage - $enemy_defense_without_wall;
        $damage_to_wall = $damage_diff > 0
            ? (int)round(min($current_wall_hp, $damage_diff * 0.4))
            : (int)max(1, min($current_wall_hp, $this->accumulated_damage * 0.05)); // 5% of accumulated damage minimum

        return max(0, $current_wall_hp - $damage_to_wall);
    }

    public function get_enemy_soldiers(): void
    {
        $enemy_soldiers_result = $this->mysqli->execute_query("SELECT * FROM soldiers WHERE kingdomid = ?", [$this->target_id]);
        foreach ($enemy_soldiers_result as $row) {
            $this->enemy_soldiers[$row["soldierid"]] = $row["soldiercount"];
        }
    }

    public function initialize_soldier_types(): void
    {
        $result = $this->mysqli->execute_query("SELECT * FROM soldierlist");
        foreach ($result as $row) {
            $this->soldier_types[$row["id"]] = [
                "soldierid" => $row["id"],
                "soldiername" => $row["soldiername"],
                "attack" => $row["attack"],
                "defense" => $row["defense"],
                "score" => $row["scoregain"]
            ];
        }
    }

    public function initialize_soldier_values(): void
    {
        foreach ($this->soldier_types as $id => $soldier) {
            if (!isset($this->soldiers[$id])) {
                $this->soldiers[$id]["count"] = 0;
            }

            $this->my_total_atk[$id] = 0;
            $this->enemy_total_def[$id] = 0;
            $this->soldier_type_atk[$id] = 0;
            $this->soldier_type_def[$id] = 0;
        }
    }

    public function set_initial_soldiers(): void
    {
        foreach ($this->soldier_types as $id => $soldier) {
            $this->initial_soldiers[$id] = [
                "initial_my_soldiers" => $this->soldiers[$id]["count"] ?? 0,
                "initial_enemy_soldiers" => $this->enemy_soldiers[$id] ?? 0,
                "my_losses" => 0,
                "enemy_losses" => 0
            ];
        }
    }

    public function set_soldier_stats(): void
    {
        $bonus_defense = $this->calculate_wall_bonus();

        foreach ($this->soldier_types as $id => $soldier) {
            $my_soldier_count = $this->initial_soldiers[$id]["initial_my_soldiers"];
            $enemy_soldier_count = $this->initial_soldiers[$id]["initial_enemy_soldiers"];
            $soldier_atk = $soldier["attack"];
            $soldier_def = $soldier["defense"] + $bonus_defense;

            $this->enemy_soldiers[$id] = $enemy_soldier_count;
            $this->initial_soldier_count += $my_soldier_count;
            $this->initial_enemy_count += $enemy_soldier_count;

            $this->my_total_atk[$id] += $my_soldier_count * $soldier_atk;
            $this->enemy_total_def[$id] += $enemy_soldier_count * $soldier_def;

            $this->soldier_type_atk[$id] = $soldier_atk;
            $this->soldier_type_def[$id] = $soldier_def;
        }
    }

    public function calculate_wall_bonus(): int
    {
        $wall = (new Kingdom($this->mysqli))->fetch_kingdom_building($this->enemy_kingdom->get_kingdom_id(), BuildingTypes::BUILDING_WALL);

        return $this->enemy_kingdom->calculate_wall_defense($this->enemy_kingdom->get_wall_hp(),
            $wall->get_building_level());
    }

    public function calculate_battle_outcome(): void
    {
        foreach ($this->soldier_types as $attacker_id => $attacker_soldier) {
            if ($this->soldiers[$attacker_id]["count"] > 0) {
                foreach ($this->soldier_types as $defender_id => $defender_soldier) {
                    if ($this->enemy_soldiers[$defender_id] > 0) {
                        // Calculate damage done (for wall hp)
                        $damage_done = min($this->my_total_atk[$attacker_id], $this->enemy_total_def[$defender_id]);
                        $this->accumulated_damage += $damage_done;

                        $outcome_for_me = ceil(
                            max($this->my_total_atk[$attacker_id] - $this->enemy_total_def[$defender_id], 0) / $this->soldier_type_atk[$attacker_id]
                        );
                        $outcome_for_enemy = ceil(
                            max($this->enemy_total_def[$defender_id] - $this->my_total_atk[$attacker_id], 0) / $this->soldier_type_def[$defender_id]
                        );

                        $this->soldiers[$attacker_id]["count"] = $outcome_for_me;
                        $this->enemy_soldiers[$defender_id] = $outcome_for_enemy;

                        // Calculate unit loss
                        $this->initial_soldiers[$attacker_id]["my_losses"] = $this->initial_soldiers[$attacker_id]["initial_my_soldiers"] - $this->soldiers[$attacker_id]["count"];
                        $this->initial_soldiers[$defender_id]["enemy_losses"] = $this->initial_soldiers[$defender_id]["initial_enemy_soldiers"] - $this->enemy_soldiers[$defender_id];

                        // Recalculate total ATK for type and DEF for enemy type
                        $this->my_total_atk[$attacker_id] = $this->soldiers[$attacker_id]["count"] * $this->soldier_type_atk[$attacker_id];
                        $this->enemy_total_def[$defender_id] = $this->enemy_soldiers[$defender_id] * $this->soldier_type_def[$defender_id];
                    }
                }
            }
        }
    }

    public function calculate_loss_counts(): void
    {
        foreach ($this->soldier_types as $id => $soldier) {
            if ($this->initial_soldiers[$id]["initial_enemy_soldiers"] == 0 && $this->initial_soldiers[$id]["initial_my_soldiers"] == 0) {
                continue;
            }

            $enemy_count = $this->initial_soldiers[$id]["initial_enemy_soldiers"] == 0 ? "?" : $this->initial_soldiers[$id]["initial_enemy_soldiers"];
            $enemy_loss = $this->initial_soldiers[$id]["initial_enemy_soldiers"] == 0 ? "?" : $this->initial_soldiers[$id]["enemy_losses"];
            $this->my_message .= "<tr>
                                                <td class='td-center'>{$soldier["soldiername"]}</td>
                                                <td class='td-center'>{$this->initial_soldiers[$id]["initial_my_soldiers"]}</td>
                                                <td class='td-center'>{$this->initial_soldiers[$id]["my_losses"]}</td>
                                                <td class='td-center'>$enemy_count</td>
                                                <td class='td-center'>$enemy_loss</td>
                                              </tr>";
            $this->enemy_message .= "<tr>
                                                <td class='td-center'>{$soldier["soldiername"]}</td>
                                                <td class='td-center'>{$this->initial_soldiers[$id]["initial_enemy_soldiers"]}</td>
                                                <td class='td-center'>{$this->initial_soldiers[$id]["enemy_losses"]}</td>
                                                <td class='td-center'>{$this->initial_soldiers[$id]["initial_my_soldiers"]}</td>
                                                <td class='td-center'>{$this->initial_soldiers[$id]["my_losses"]}</td>
                                              </tr>";

            if ($this->initial_soldiers[$id]["initial_my_soldiers"] > 0) {
                if ($this->initial_soldiers[$id]["my_losses"] >= $this->initial_soldiers[$id]["initial_my_soldiers"]) {
                    $this->mysqli->execute_query("DELETE FROM senttroops WHERE eventid = ? AND soldierid = ?", [$this->event_id, $id]);
                } else {
                    $my_survivors = $this->initial_soldiers[$id]["initial_my_soldiers"] - $this->initial_soldiers[$id]["my_losses"];

                    if ($my_survivors != $this->initial_soldiers[$id]["initial_my_soldiers"]) {
                        $this->mysqli->execute_query("UPDATE senttroops SET soldiercount = ? WHERE eventid = ? AND soldierid = ?",
                            [$my_survivors, $this->event_id, $id]);
                    }
                }

                if ($soldier["soldiername"] == "Eroberer") {
                    $this->conquerer_count -= $this->initial_soldiers[$id]["my_losses"];
                }

                $this->my_score_loss += $this->initial_soldiers[$id]["my_losses"] * $soldier["score"];
            }

            if ($this->initial_soldiers[$id]["initial_enemy_soldiers"] > 0) {
                if ($this->initial_soldiers[$id]["enemy_losses"] >= $this->initial_soldiers[$id]["initial_enemy_soldiers"]) {
                    $this->mysqli->execute_query("DELETE FROM soldiers WHERE kingdomid = ? AND soldierid = ?", [$this->enemy_kingdom->get_kingdom_id(), $id]);
                } else {
                    $enemy_survivors = $this->initial_soldiers[$id]["initial_enemy_soldiers"] - $this->initial_soldiers[$id]["enemy_losses"];

                    if ($enemy_survivors != $this->initial_soldiers[$id]["initial_enemy_soldiers"]) {
                        $this->mysqli->execute_query("UPDATE soldiers SET soldiercount = ? WHERE kingdomid = ? AND soldierid = ?",
                            [$enemy_survivors, $this->enemy_kingdom->get_kingdom_id(), $id]);
                    }
                }

                $this->enemy_score_loss += $this->initial_soldiers[$id]["enemy_losses"] * $soldier["score"];
            }

            $this->my_loss_count += $this->initial_soldiers[$id]["my_losses"];
            $this->enemy_loss_count += $this->initial_soldiers[$id]["enemy_losses"];
        }
    }

    public function deploy_soldiers_to_kingdom(): void
    {
        $this->my_message .= "<table class='table'>
                                            <tr>
                                                <td class='td-center td-gradient'>Einheit</td>
                                                <td class='td-center td-gradient'>Anzahl</td>
                                            </tr>";

        // Update/Insert troops to new kingdom
        foreach ($this->soldiers as $soldier_id => $soldier_data) {
            $this->my_message .= "<tr>
                                                <td class='td-center'>{$soldier_data["name"]}</td>
                                                <td class='td-center'>{$soldier_data["count"]}</td>
                                              </tr>";

            $query = "
                    INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE soldiercount = soldiercount + VALUES(soldiercount);
            ";
            $this->mysqli->execute_query($query, [$this->target_id, $soldier_id, $soldier_data["name"], $soldier_data["count"]]);
        }
        $this->my_message .= "</table>";

        // Delete the event and senttroops
        $this->mysqli->execute_query("DELETE FROM senttroops WHERE eventid = ?", [$this->event_id]);
        $this->mysqli->execute_query("DELETE FROM events WHERE eventid = ?", [$this->event_id]);
    }

    public function get_soldier_types(): array
    {
        return $this->soldier_types;
    }

    public function get_my_loss_count(): int
    {
        return $this->my_loss_count;
    }

    public function get_my_score_loss(): int
    {
        return $this->my_score_loss;
    }

    public function get_enemy_loss_count(): int
    {
        return $this->enemy_loss_count;
    }

    public function get_enemy_score_loss(): int
    {
        return $this->enemy_score_loss;
    }

    public function get_my_message(): string
    {
        return $this->my_message;
    }

    public function get_enemy_message(): string
    {
        return $this->enemy_message;
    }

    public function get_initial_soldier_count(): int
    {
        return $this->initial_soldier_count;
    }

    public function get_initial_enemy_count(): int
    {
        return $this->initial_enemy_count;
    }

    public function get_conquering_rate(int $conquerer_count): float
    {
        return min(BASE_CONQUEST_CHANCE + ($conquerer_count * MIN_CONQUEST_CHANCE), MAX_CONQUEST_CHANCE) * 100;
    }

    public function is_conquered(int $success_rate): bool
    {
        return mt_rand(0, 100) < $success_rate;
    }

    public function has_noob_protection(int $attacker_score, int $defender_score): bool
    {
        $noob_mult = NOOB_PROTECTION_MULT;
        $min_score = $attacker_score * $noob_mult;
        $max_score = $attacker_score / $noob_mult;

        return $defender_score < $min_score || $defender_score > $max_score;
    }

    public function append_my_after_battle_message(): string
    {
        if ($this->my_loss_count == 0) {
            $this->my_message = "Wir haben den Kampf unbeschadet überstanden!<br>";
        } else if (($this->my_loss_count / $this->initial_soldier_count) >= 0.5 && ($this->my_loss_count / $this->initial_soldier_count) < 1) {
            $this->my_message = "Wir haben mehr als die Hälfte unserer Truppen verloren...<br>";
        } else if (($this->my_loss_count / $this->initial_soldier_count) > 0 && ($this->my_loss_count / $this->initial_soldier_count) < 0.5) {
            $this->my_message = "Wir haben ein paar unserer Truppen verloren.<br>";
        } else {
            $this->my_message = "Wir wurden komplett vom Gegner aufgerieben...<br>";
        }

        return $this->my_message;
    }

    public function append_enemy_after_battle_message(): string
    {
        if ($this->enemy_loss_count == 0) {
            $this->enemy_message = "Wir haben den Kampf unbeschadet überstanden!<br>";
        } else if (($this->enemy_loss_count / $this->initial_enemy_count) >= 0.5 && ($this->enemy_loss_count / $this->initial_enemy_count) < 1) {
            $this->enemy_message = "Wir haben mehr als die Hälfte unserer Truppen verloren...<br>";
        } else if (($this->enemy_loss_count / $this->initial_enemy_count) > 0 && ($this->enemy_loss_count / $this->initial_enemy_count) < 0.5) {
            $this->enemy_message = "Wir haben ein paar unserer Truppen verloren.<br>";
        } else {
            $this->enemy_message = "Wir wurden komplett vom Gegner aufgerieben...<br>";
        }

        return $this->enemy_message;
    }

    public function get_initial_soldiers_detailed(): array
    {
        $details = [];
        foreach ($this->initial_soldiers as $id => $data) {
            if ($data["initial_my_soldiers"] > 0) {
                $name = $this->soldier_types[$id]["soldiername"];
                $details[$name] = (int)$data["initial_my_soldiers"];
            }
        }
        return $details;
    }

    public function get_initial_enemy_detailed(): array
    {
        $details = [];
        foreach ($this->initial_soldiers as $id => $data) {
            if ($data["initial_enemy_soldiers"] > 0) {
                $name = $this->soldier_types[$id]["soldiername"];
                $details[$name] = (int)$data["initial_enemy_soldiers"];
            }
        }
        return $details;
    }

    public function get_attacker_losses_detailed(): array
    {
        $details = [];
        foreach ($this->initial_soldiers as $id => $data) {
            if ($data["my_losses"] > 0) {
                $name = $this->soldier_types[$id]["soldiername"];
                $details[$name] = (int)$data["my_losses"];
            }
        }
        return $details;
    }

    public function get_defender_losses_detailed(): array
    {
        $details = [];
        foreach ($this->initial_soldiers as $id => $data) {
            if ($data["enemy_losses"] > 0) {
                $name = $this->soldier_types[$id]["soldiername"];
                $details[$name] = (int)$data["enemy_losses"];
            }
        }
        return $details;
    }
}

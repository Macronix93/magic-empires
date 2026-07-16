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
                    FROM sent_troops st
                    JOIN soldier_list s ON st.soldierid = s.id
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
        $wall_level = $this->enemy_kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_WALL);

        // 1. Mauer-Eigenschutz (Soak)
        // Jede Mauerstufe schluckt einen festen Betrag an Schaden, bevor die HP sinken.
        $wall_absorption = $wall_level * 100;

        $enemy_defense_without_wall = 0;
        foreach ($this->soldier_types as $id => $soldier) {
            $enemy_defense_without_wall += $this->enemy_soldiers[$id] * $soldier["defense"];
        }

        // Die Differenz zwischen Angriffs-Pool und Truppen-Verteidigung
        $damage_diff = $this->accumulated_damage - $enemy_defense_without_wall;

        if ($damage_diff > 0) {
            // Angreifer hat gewonnen:
            // Wir ziehen erst den Eigenschutz der Mauer ab
            $effective_damage = max(0, $damage_diff - $wall_absorption);

            // 2. Faktor massiv senken (von 40% auf z.B. 3%)
            // Ohne Belagerungswaffen sollten Soldaten kaum eine Steinmauer einreißen.
            $damage_to_wall = $effective_damage * 0.03;
        } else {
            // Angreifer hat verloren oder Gleichstand:
            // Nur minimaler Abnutzungsschaden (0,1% statt 5%)
            $damage_to_wall = $this->accumulated_damage * 0.001;
        }

        // 3. Belagerungs-Bonus (Schmiede/Technik) einrechnen
        $res_atk = $this->mysqli->execute_query("SELECT kingdomid FROM events WHERE eventid = ?", [$this->event_id]);
        $attacker_kingdom_id = $res_atk->fetch_column();

        $res_siege = $this->mysqli->execute_query("SELECT techlevel FROM techs WHERE kingdomid = ? AND techid = ?",
            [$attacker_kingdom_id, TechTypes::TECH_TYPE_SIEGE]);
        $siege_lvl = ($res_siege->num_rows > 0) ? $res_siege->fetch_column() : 0;

        // Belagerungstechnik erhöht den Schaden an der Mauer
        $multiplier = 1 + ($siege_lvl * SMITHY_SIEGE_BONUS);
        $final_damage = (int)round($damage_to_wall * $multiplier);

        // Sicherheitscheck: Mindestens 0, maximal aktuelle HP
        $final_damage = max(0, min($current_wall_hp, $final_damage));

        return $current_wall_hp - $final_damage;
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
        $result = $this->mysqli->execute_query("SELECT id, soldiername, category, attack, defense, scoregain FROM soldier_list");

        foreach ($result as $row) {
            $this->soldier_types[$row["id"]] = [
                "soldierid" => $row["id"],
                "soldiername" => $row["soldiername"],
                "category" => $row["category"],
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

    public function set_soldier_stats(Kingdom $home_kingdom): void
    {
        $bonus_defense = $this->calculate_wall_bonus();

        // Attacker Bonus (War God)
        $atk_multiplier = 1.0;

        if ($home_kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
            $atk_multiplier += $home_kingdom->get_shrine_modifier();
        }

        $inf_atk = $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES) * SMITHY_INF_ATK_BONUS;
        $inf_def = $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL) * SMITHY_INF_DEF_BONUS;
        $cav_atk = $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING) * SMITHY_CAV_ATK_BONUS;
        $cav_def = $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS) * SMITHY_CAV_DEF_BONUS;
        $arc_atk = $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS) * SMITHY_ARC_ATK_BONUS;
        $arc_def = $home_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET) * SMITHY_ARC_DEF_BONUS;

        foreach ($this->soldier_types as $id => $soldier) {
            $my_soldier_count = $this->initial_soldiers[$id]["initial_my_soldiers"];
            $enemy_soldier_count = $this->initial_soldiers[$id]["initial_enemy_soldiers"];

            $t_atk = 0;
            $t_def = 0;
            if ($soldier["category"] == SoldierTypes::SOLDIER_TYPE_INFANTRY) {
                $t_atk = $inf_atk;
                $t_def = $inf_def;
            } else if ($soldier["category"] == SoldierTypes::SOLDIER_TYPE_CAVALRY) {
                $t_atk = $cav_atk;
                $t_def = $cav_def;
            } else if ($soldier["category"] == SoldierTypes::SOLDIER_TYPE_ARCHERS) {
                $t_atk = $arc_atk;
                $t_def = $arc_def;
            }

            $soldier_atk = (int)(($soldier["attack"] + $t_atk) * $atk_multiplier);
            $soldier_def = $soldier["defense"] + $t_def + $bonus_defense;

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
        $wall = new Kingdom($this->mysqli)->fetch_kingdom_building($this->enemy_kingdom->get_kingdom_id(), BuildingTypes::BUILDING_WALL);

        if (!$wall) {
            return 0;
        }

        return $this->enemy_kingdom->calculate_wall_defense($this->enemy_kingdom->get_wall_hp(),
            $wall->get_building_level());
    }

    public function calculate_battle_outcome(): void
    {
        $player_atk_pool = 0;
        $player_def_pool = 0;
        $enemy_atk_pool = 0;
        $enemy_def_pool = 0;

        $total_own_units = array_sum(array_column($this->initial_soldiers, "initial_my_soldiers"));
        $total_enemy_units = array_sum(array_column($this->initial_soldiers, "initial_enemy_soldiers"));

        if ($total_own_units <= 0 || $total_enemy_units <= 0) return;

        foreach ($this->soldier_types as $id => $unit) {
            $ownCount = $this->initial_soldiers[$id]["initial_my_soldiers"];
            $enemyCount = $this->initial_soldiers[$id]["initial_enemy_soldiers"];

            if ($ownCount > 0) {
                $bonus = 1.0;

                foreach ($this->soldier_types as $id_target => $unit_target) {
                    if ($this->initial_soldiers[$id_target]["initial_enemy_soldiers"] > 0) {
                        $share = $this->initial_soldiers[$id_target]["initial_enemy_soldiers"] / $total_enemy_units;

                        if (($unit["category"] == 0 && $unit_target["category"] == 1) ||
                            ($unit["category"] == 1 && $unit_target["category"] == 2) ||
                            ($unit["category"] == 2 && $unit_target["category"] == 0)) {
                            $bonus += (0.5 * $share);
                        }
                    }
                }
                $player_atk_pool += ($ownCount * $this->soldier_type_atk[$id] * $bonus);
            }

            if ($enemyCount > 0) {
                $bonus = 1.0;

                foreach ($this->soldier_types as $id_target => $unit_target) {
                    if ($this->initial_soldiers[$id_target]["initial_my_soldiers"] > 0) {
                        $share = $this->initial_soldiers[$id_target]["initial_my_soldiers"] / $total_enemy_units;

                        if (($unit["category"] == 0 && $unit_target["category"] == 1) ||
                            ($unit["category"] == 1 && $unit_target["category"] == 2) ||
                            ($unit["category"] == 2 && $unit_target["category"] == 0)) {
                            $bonus += (0.5 * $share);
                        }
                    }
                }

                $enemy_atk_pool += ($enemyCount * $unit["attack"] * $bonus);
            }

            $player_def_pool += ($ownCount * $this->soldier_type_def[$id]);
            $enemy_def_pool += ($enemyCount * $this->soldier_type_def[$id]);
        }

        $player_loss_ratio = ($player_def_pool > 0) ? min(1.0, $enemy_atk_pool / $player_def_pool) : 1.0;
        $enemy_loss_ratio = ($enemy_def_pool > 0) ? min(1.0, $player_atk_pool / $enemy_def_pool) : 1.0;
        $player_loss_ratio = round($player_loss_ratio, 6);
        $enemy_loss_ratio = round($enemy_loss_ratio, 6);

        foreach ($this->soldier_types as $id => $unit) {
            $my_losses = round($this->initial_soldiers[$id]["initial_my_soldiers"] * $player_loss_ratio);
            $en_losses = round($this->initial_soldiers[$id]["initial_enemy_soldiers"] * $enemy_loss_ratio);

            $this->initial_soldiers[$id]["my_losses"] = (int)$my_losses;
            $this->initial_soldiers[$id]["enemy_losses"] = (int)$en_losses;

            $this->soldiers[$id]["count"] = $this->initial_soldiers[$id]["initial_my_soldiers"] - (int)$my_losses;
            $this->enemy_soldiers[$id] = $this->initial_soldiers[$id]["initial_enemy_soldiers"] - (int)$en_losses;
        }

        $this->accumulated_damage = $player_atk_pool;
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
                    $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ? AND soldierid = ?", [$this->event_id, $id]);
                } else {
                    $my_survivors = $this->initial_soldiers[$id]["initial_my_soldiers"] - $this->initial_soldiers[$id]["my_losses"];

                    if ($my_survivors != $this->initial_soldiers[$id]["initial_my_soldiers"]) {
                        $this->mysqli->execute_query("UPDATE sent_troops SET soldiercount = ? WHERE eventid = ? AND soldierid = ?",
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
        if (empty($this->soldiers)) {
            return;
        }

        $values_parts = [];
        $params = [];

        foreach ($this->soldiers as $soldier_id => $soldier_data) {
            if ($soldier_data["count"] > 0) {
                $values_parts[] = "(?, ?, ?, ?)";
                $params[] = $this->target_id;
                $params[] = $soldier_id;
                $params[] = $soldier_data["name"];
                $params[] = $soldier_data["count"];
            }
        }

        if (!empty($values_parts)) {
            $query = "
            INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
            VALUES " . implode(', ', $values_parts) . "
            ON DUPLICATE KEY UPDATE soldiercount = soldiers.soldiercount + VALUES(soldiercount);
        ";

            $this->mysqli->execute_query($query, $params);
        }

        // Cleanup
        $this->mysqli->execute_query("DELETE FROM sent_troops WHERE eventid = ?", [$this->event_id]);
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

    public function is_conquered(float $success_rate): bool
    {
        return mt_rand(0, 100) <= $success_rate;
    }

    public function has_noob_protection(int $attacker_score, int $defender_score): bool
    {
        $noob_mult = NOOB_PROTECTION_MULT;
        $min_score = $attacker_score * $noob_mult;
        $max_score = $attacker_score / $noob_mult;

        return $defender_score < $min_score || $defender_score > $max_score;
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

    public function calculate_loot_capacity(int $base_capacity, Kingdom $attacker_kingdom): int
    {
        $plunder_lvl = $attacker_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_PLUNDER);
        return (int)($base_capacity * (1 + ($plunder_lvl * PLUNDER_CAPACITY_BONUS)));
    }

    public function get_surviving_count(int $soldier_id): int
    {
        return (int)($this->soldiers[$soldier_id]["count"] ?? 0);
    }

    public function get_battle_result_data(bool $for_attacker): array
    {
        $data = [];

        foreach ($this->soldier_types as $id => $soldier) {
            $initial = $for_attacker
                ? ($this->initial_soldiers[$id]["initial_my_soldiers"] ?? 0)
                : ($this->initial_soldiers[$id]["initial_enemy_soldiers"] ?? 0);

            $losses = $for_attacker
                ? ($this->initial_soldiers[$id]["my_losses"] ?? 0)
                : ($this->initial_soldiers[$id]["enemy_losses"] ?? 0);

            if ($initial === 0 && $for_attacker && isset($this->soldiers[$id]["count"])) {
                $initial = $this->soldiers[$id]["count"];
            }

            if ($initial > 0) {
                $res = $this->mysqli->execute_query("SELECT icon FROM soldier_list WHERE id = ?", [$id]);
                $icon = $res->fetch_column() ?: "icon_error";

                $data[] = [
                    "id" => $id,
                    "name" => $soldier["soldiername"],
                    "initial" => (int)$initial,
                    "losses" => (int)$losses,
                    "icon" => $icon,
                    "atk" => $soldier["attack"],
                    "def" => $soldier["defense"]
                ];
            }
        }
        return $data;
    }

    public function get_initial_count_by_id(int $soldierId, bool $is_attacker): int
    {
        return (int)($is_attacker
            ? ($this->initial_soldiers[$soldierId]["initial_my_soldiers"] ?? 0)
            : ($this->initial_soldiers[$soldierId]["initial_enemy_soldiers"] ?? 0));
    }
}

<?php

class Conquest
{
    private object $mysqli;
    private array $soldiers = [];
    private array $enemy_soldiers = [];
    private int $enemy_def_without_wall = 0;
    private array $enemy_soldier_type_atk = [];
    private array $enemy_soldier_type_def = [];
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
    private int $enemy_garrison_loss_count = 0;
    private Kingdom $enemy_kingdom;

    public function __construct()
    {
        $this->mysqli = Database::get_instance()->get_connection();
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
                    SELECT s.id, s.soldiername, st.soldiercount, st.initial_count 
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
                "count" => $soldier_count,
                "initial" => (int)$row["initial_count"]
            ];

            // Check if there is a conqueror and count them
            if ($soldier_id === Soldiers::SOLDIER_CONQUEROR) {
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
        $conquerer_id = Soldiers::SOLDIER_CONQUEROR;

        if (isset($this->soldiers[$conquerer_id]) && $this->soldiers[$conquerer_id]["count"] > 0) {
            return $conquerer_id;
        }

        return 0;
    }

    public function calculate_wall_damage(): int
    {
        $current_wall_hp = $this->enemy_kingdom->get_wall_hp();
        $wall_level = $this->enemy_kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_WALL);
        $max_wall_hp = $this->enemy_kingdom->get_wall_max_hp();

        $wall_absorption = $wall_level * (WALL_ABSORPTION_PER_LEVEL * WALL_ABSORPTION_MULTIPLIER);
        $damage_diff = $this->accumulated_damage - $this->enemy_def_without_wall;

        $effective_damage = max(0, $damage_diff - $wall_absorption);

        $normal_troop_wall_dmg = $effective_damage * (WALL_EFFECTIVE_DMG_FACTOR * WALL_NORMAL_TROOP_DAMAGE_FACTOR);

        $max_normal_dmg_cap = $max_wall_hp * WALL_MAX_NORMAL_DAMAGE_PERCENT;
        $normal_troop_wall_dmg = min($normal_troop_wall_dmg, $max_normal_dmg_cap);

        $ram_count = (int)($this->soldiers[Soldiers::SOLDIER_RAM]["initial"] ?? 0);
        $ram_damage = ($ram_count * RAM_FLAT_DAMAGE);
        $ram_bonus = min(RAM_WALL_DAMAGE_LIMIT, $ram_count * RAM_WALL_DAMAGE_FACTOR);

        $res_atk = $this->mysqli->execute_query("SELECT kingdomid FROM events WHERE eventid = ?", [$this->event_id]);
        $attacker_kingdom_id = $res_atk->fetch_column();

        $res_siege = $this->mysqli->execute_query("SELECT techlevel FROM techs WHERE kingdomid = ? AND techid = ?",
            [$attacker_kingdom_id, TechTypes::TECH_TYPE_SIEGE]);
        $siege_lvl = ($res_siege->num_rows > 0) ? $res_siege->fetch_column() : 0;

        $multiplier = 1 + ($siege_lvl * SMITHY_SIEGE_BONUS) + $ram_bonus;

        $total_wall_dmg = ($normal_troop_wall_dmg + $ram_damage) * $multiplier;
        $final_damage = (int)round($total_wall_dmg);

        return max(0, $current_wall_hp - $final_damage);
    }

    public function get_enemy_soldiers(): void
    {
        $enemy_soldiers_result = $this->mysqli->execute_query("SELECT * FROM soldiers WHERE kingdomid = ?", [$this->target_id]);
        foreach ($enemy_soldiers_result as $row) {
            $this->enemy_soldiers[$row["soldierid"]] = $row["soldiercount"];
        }

        $res_stat = $this->mysqli->execute_query("
            SELECT soldier_id, SUM(soldiercount) as total 
            FROM stationed_troops 
            WHERE target_kingdom_id = ? 
            GROUP BY soldier_id",
            [$this->target_id]);

        foreach ($res_stat as $row) {
            $sid = $row["soldier_id"];

            if (!isset($this->enemy_soldiers[$sid])) $this->enemy_soldiers[$sid] = 0;

            $this->enemy_soldiers[$sid] += (int)$row["total"];
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

            $this->soldier_type_atk[$id] = 0;
            $this->soldier_type_def[$id] = 0;
            $this->enemy_soldier_type_atk[$id] = 0;
            $this->enemy_soldier_type_def[$id] = 0;
        }
    }

    public function set_initial_soldiers(): void
    {
        $this->initial_soldier_count = 0;
        $this->initial_enemy_count = 0;

        foreach ($this->soldier_types as $id => $soldier) {
            $own = isset($this->soldiers[$id]["initial"]) ? (int)$this->soldiers[$id]["initial"] : 0;

            $enemy_raw = $this->enemy_soldiers[$id] ?? 0;
            $enemy = is_array($enemy_raw) ? (int)($enemy_raw["count"] ?? 0) : (int)$enemy_raw;

            $this->initial_soldiers[$id] = [
                "initial_my_soldiers" => $own,
                "initial_enemy_soldiers" => $enemy,
                "my_losses" => 0,
                "enemy_losses" => 0
            ];

            $this->initial_soldier_count += $own;
            $this->initial_enemy_count += $enemy;
        }
    }

    public function set_soldier_stats(Kingdom $attacker_kingdom, Kingdom $defender_kingdom): void
    {
        // TECHS ATTACKER
        $atk_techs = [
            "inf_a" => $attacker_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES) * SMITHY_INF_ATK_BONUS,
            "inf_d" => $attacker_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL) * SMITHY_INF_DEF_BONUS,
            "cav_a" => $attacker_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING) * SMITHY_CAV_ATK_BONUS,
            "cav_d" => $attacker_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS) * SMITHY_CAV_DEF_BONUS,
            "arc_a" => $attacker_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS) * SMITHY_ARC_ATK_BONUS,
            "arc_d" => $attacker_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET) * SMITHY_ARC_DEF_BONUS
        ];

        // TECHS DEFENDER
        $def_techs = [
            "inf_a" => $defender_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES) * SMITHY_INF_ATK_BONUS,
            "inf_d" => $defender_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL) * SMITHY_INF_DEF_BONUS,
            "cav_a" => $defender_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING) * SMITHY_CAV_ATK_BONUS,
            "cav_d" => $defender_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS) * SMITHY_CAV_DEF_BONUS,
            "arc_a" => $defender_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS) * SMITHY_ARC_ATK_BONUS,
            "arc_d" => $defender_kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET) * SMITHY_ARC_DEF_BONUS
        ];

        // Shrine Boni
        $atk_shrine = 1.0;
        if ($attacker_kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
            $atk_shrine += $attacker_kingdom->calculate_shrine_bonus($attacker_kingdom->get_shrine_modifier());
        }

        $def_atk_shrine = 1.0;
        if ($defender_kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
            $def_atk_shrine += $defender_kingdom->calculate_shrine_bonus($defender_kingdom->get_shrine_modifier());
        }

        foreach ($this->soldier_types as $id => $soldier) {
            $cat = $soldier["category"];

            $prefix = match ($cat) {
                0 => "inf_",
                1 => "cav_",
                2 => "arc_",
                default => null
            };

            if ($prefix) {
                // Stats Attacker Troops
                $this->soldier_type_atk[$id] = (int)round(($soldier["attack"] * $atk_shrine) + $atk_techs[$prefix . 'a']);
                $this->soldier_type_def[$id] = (int)($soldier["defense"] + $atk_techs[$prefix . 'd']);
                // Stats Defender Troops
                $this->enemy_soldier_type_atk[$id] = (int)round(($soldier["attack"] * $def_atk_shrine) + $def_techs[$prefix . 'a']);
                $this->enemy_soldier_type_def[$id] = (int)($soldier["defense"] + $def_techs[$prefix . 'd']);
            } else {
                // Special Units
                $this->soldier_type_atk[$id] = (int)$soldier["attack"];
                $this->soldier_type_def[$id] = (int)$soldier["defense"];
                $this->enemy_soldier_type_atk[$id] = (int)$soldier["attack"];
                $this->enemy_soldier_type_def[$id] = (int)$soldier["defense"];
            }
        }
    }

    public function calculate_wall_bonus(): int
    {
        $wall_level = $this->enemy_kingdom->get_kingdom_building_level(BuildingTypes::BUILDING_WALL);

        if ($wall_level <= 0) {
            return 0;
        }

        return $this->enemy_kingdom->calculate_wall_defense($this->enemy_kingdom->get_wall_hp(), $wall_level);
    }

    public function calculate_battle_outcome(): void
    {
        $total_attacker_units = array_sum(array_column($this->initial_soldiers, "initial_my_soldiers"));
        $total_defender_units = array_sum(array_column($this->initial_soldiers, "initial_enemy_soldiers"));

        if ($total_attacker_units <= 0) return;

        if ($total_defender_units <= 0) {
            $attacker_atk_pool = 0;
            foreach ($this->soldier_types as $id => $unit) {
                $count_own = $this->initial_soldiers[$id]["initial_my_soldiers"];
                if ($count_own > 0) {
                    $attacker_atk_pool += ($count_own * $this->soldier_type_atk[$id]);
                }
            }
            $this->accumulated_damage = (int)round($attacker_atk_pool);
            $this->enemy_def_without_wall = 0;
            return;
        }

        $total_attacker_def = 0;
        $total_defender_def = 0;
        $defender_def_no_wall = 0;

        $own_has_cat = [];
        $enemy_has_cat = [];

        foreach ($this->soldier_types as $id => $unit) {
            $cat = (int)$unit["category"];
            $count_own = (int)$this->initial_soldiers[$id]["initial_my_soldiers"];
            $count_enemy = (int)$this->initial_soldiers[$id]["initial_enemy_soldiers"];

            if ($count_own > 0) $own_has_cat[$cat] = true;
            if ($count_enemy > 0) $enemy_has_cat[$cat] = true;

            $total_attacker_def += ($count_own * $this->soldier_type_def[$id]);
            $current_unit_def_enemy = ($count_enemy * $this->enemy_soldier_type_def[$id]);
            $total_defender_def += $current_unit_def_enemy;
            $defender_def_no_wall += $current_unit_def_enemy;
        }

        $get_preferred_target_cat = function (int $cat): int {
            return match ($cat) {
                SoldierTypes::SOLDIER_TYPE_INFANTRY => SoldierTypes::SOLDIER_TYPE_CAVALRY,  // Inf (0) -> Cav (1)
                SoldierTypes::SOLDIER_TYPE_CAVALRY => SoldierTypes::SOLDIER_TYPE_ARCHERS,   // Cav (1) -> Arch (2)
                SoldierTypes::SOLDIER_TYPE_ARCHERS => SoldierTypes::SOLDIER_TYPE_INFANTRY,  // Arch (2) -> Inf (0)
                default => -1
            };
        };

        $get_category_def_pool = function (string $key, array $def_stats): array {
            $pools = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
            foreach ($this->soldier_types as $id => $unit) {
                $cat = (int)$unit["category"];
                $cnt = (int)($this->initial_soldiers[$id][$key] ?? 0);
                $pools[$cat] = ($pools[$cat] ?? 0) + ($cnt * $def_stats[$id]);
            }
            return $pools;
        };

        $own_def_pools = $get_category_def_pool("initial_my_soldiers", $this->soldier_type_def);
        $enemy_def_pools = $get_category_def_pool("initial_enemy_soldiers", $this->enemy_soldier_type_def);

        $distribute_damage = function (
            string $attacker_key,
            array  $defender_has_cat,
            array  $atk_stats,
            array  $defender_def_pools,
            int    $total_def
        ) use ($get_preferred_target_cat): array {
            $targeted_dmg = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
            $shared_dmg = 0;
            $total_raw_atk = 0;

            $focus_share = defined('RPS_TARGET_FOCUS') ? RPS_TARGET_FOCUS : 0.70;
            $default_share = 1.0 - $focus_share;

            foreach ($this->soldier_types as $id => $unit) {
                $cnt = (int)($this->initial_soldiers[$id][$attacker_key] ?? 0);
                if ($cnt <= 0) continue;

                $unit_atk_sum = $cnt * $atk_stats[$id];
                $total_raw_atk += $unit_atk_sum;

                $target_cat = $get_preferred_target_cat((int)$unit["category"]);
                if ($target_cat !== -1 && !empty($defender_has_cat[$target_cat])) {
                    $target_damage_with_bonus = $unit_atk_sum * $focus_share * (1.0 + RPS_BONUS);

                    $max_def_capacity = ($defender_def_pools[$target_cat] ?? 0) * LETHALITY_PVP;
                    if ($target_damage_with_bonus > $max_def_capacity && $max_def_capacity > 0) {
                        $excess = $target_damage_with_bonus - $max_def_capacity;
                        $targeted_dmg[$target_cat] += $max_def_capacity;
                        $shared_dmg += ($excess / (1.0 + RPS_BONUS)) + ($unit_atk_sum * $default_share);
                    } else {
                        $targeted_dmg[$target_cat] += $target_damage_with_bonus;
                        $shared_dmg += ($unit_atk_sum * $default_share);
                    }
                } else {
                    $shared_dmg += $unit_atk_sum;
                }
            }

            $final_incoming_dmg = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
            foreach ($defender_def_pools as $cat => $cat_def) {
                $final_incoming_dmg[$cat] = $targeted_dmg[$cat];
                if ($total_def > 0 && $cat_def > 0) {
                    $final_incoming_dmg[$cat] += $shared_dmg * ($cat_def / $total_def);
                }
            }

            return [
                "final_incoming_dmg" => $final_incoming_dmg,
                "total_raw_atk" => $total_raw_atk
            ];
        };

        $own_offense = $distribute_damage(
            "initial_my_soldiers",
            $enemy_has_cat,
            $this->soldier_type_atk,
            $enemy_def_pools,
            $total_defender_def
        );

        $enemy_offense = $distribute_damage(
            "initial_enemy_soldiers",
            $own_has_cat,
            $this->enemy_soldier_type_atk,
            $own_def_pools,
            $total_attacker_def
        );

        $wall_bonus = $this->calculate_wall_bonus();
        $wall_counter_damage = 0;

        if ($wall_bonus > 0) {
            $wall_counter_damage = $wall_bonus * WALL_COUNTER_DAMAGE_FACTOR;
            foreach ($own_def_pools as $cat => $cat_def) {
                if ($total_attacker_def > 0 && $cat_def > 0) {
                    $enemy_offense["final_incoming_dmg"][$cat] += $wall_counter_damage * ($cat_def / $total_attacker_def);
                }
            }
        }

        $effective_enemy_counter_dmg = $enemy_offense["total_raw_atk"] + $wall_counter_damage;

        $global_atk_loss_damping = 1.0;
        $global_def_loss_damping = 1.0;

        if ($own_offense["total_raw_atk"] > 0 && $effective_enemy_counter_dmg > 0) {
            $range = max(0.01, PVP_DAMPING_MAX_RATIO - PVP_DAMPING_THRESHOLD);
            $ratio_def = $effective_enemy_counter_dmg / $own_offense["total_raw_atk"];
            if ($ratio_def > PVP_DAMPING_THRESHOLD) {
                $clamped = max(0.0, min(1.0, ($ratio_def - PVP_DAMPING_THRESHOLD) / $range));
                $global_def_loss_damping *= pow(1.0 - $clamped, PVP_DAMPING_EXPONENT);
            }
            $ratio_atk = $own_offense["total_raw_atk"] / $effective_enemy_counter_dmg;
            if ($ratio_atk > PVP_DAMPING_THRESHOLD) {
                $clamped = max(0.0, min(1.0, ($ratio_atk - PVP_DAMPING_THRESHOLD) / $range));
                $global_atk_loss_damping *= pow(1.0 - $clamped, PVP_DAMPING_EXPONENT);
            }
        }

        $get_category_loss_ratio = function (float $incoming_dmg, int $cat_def, bool $is_defender) use (
            $wall_bonus,
            $total_defender_def,
            $global_def_loss_damping,
            $global_atk_loss_damping
        ): float {
            if ($cat_def <= 0) return 1.0;
            $effective_def = (float)$cat_def;
            if ($is_defender && $total_defender_def > 0) {
                $effective_def += $wall_bonus * ($cat_def / $total_defender_def);
            }
            $damping = $is_defender ? $global_def_loss_damping : $global_atk_loss_damping;
            $raw_ratio = $incoming_dmg / ($effective_def * LETHALITY_PVP);
            return $raw_ratio * $damping;
        };

        $own_cat_loss_ratios = [];
        foreach ($own_def_pools as $c => $cat_def) {
            $own_cat_loss_ratios[$c] = $get_category_loss_ratio(
                (float)($enemy_offense["final_incoming_dmg"][$c] ?? 0),
                $cat_def,
                false
            );
        }

        $enemy_cat_loss_ratios = [];
        foreach ($enemy_def_pools as $c => $cat_def) {
            $enemy_cat_loss_ratios[$c] = $get_category_loss_ratio(
                (float)($own_offense["final_incoming_dmg"][$c] ?? 0),
                $cat_def,
                true
            );
        }

        $avg_own_def = $total_attacker_def / $total_attacker_units;
        $avg_enemy_def = $total_defender_def / $total_defender_units;
        $exp = defined('ARMOR_WEIGHT_EXPONENT') ? ARMOR_WEIGHT_EXPONENT : 0.50;

        foreach ($this->soldier_types as $id => $unit) {
            $cat = (int)$unit["category"];

            $initial_own = (int)$this->initial_soldiers[$id]["initial_my_soldiers"];
            if ($initial_own > 0) {
                $base_ratio = $own_cat_loss_ratios[$cat] ?? 0.0;
                $unit_def = max(1, $this->soldier_type_def[$id]);
                $armor_modifier = pow($avg_own_def / $unit_def, $exp);
                $unit_loss_ratio = max(0.0, min(1.0, $base_ratio * $armor_modifier));

                $attacker_losses = (int)round($initial_own * $unit_loss_ratio);
                $this->initial_soldiers[$id]["my_losses"] = $attacker_losses;
                $this->soldiers[$id]["count"] = $initial_own - $attacker_losses;
            }

            $initial_enemy = (int)$this->initial_soldiers[$id]["initial_enemy_soldiers"];
            if ($initial_enemy > 0) {
                $base_ratio = $enemy_cat_loss_ratios[$cat] ?? 0.0;
                $unit_def = max(1, $this->enemy_soldier_type_def[$id]);
                $armor_modifier = pow($avg_enemy_def / $unit_def, $exp);
                $unit_loss_ratio = max(0.0, min(1.0, $base_ratio * $armor_modifier));

                $defender_losses = (int)round($initial_enemy * $unit_loss_ratio);
                $this->initial_soldiers[$id]["enemy_losses"] = $defender_losses;
                $this->enemy_soldiers[$id] = $initial_enemy - $defender_losses;
            }
        }

        $this->accumulated_damage = (int)round($own_offense["total_raw_atk"]);
        $this->enemy_def_without_wall = (int)round($defender_def_no_wall);
    }

    public function calculate_loss_counts(): void
    {
        $this->enemy_garrison_loss_count = 0;

        foreach ($this->soldier_types as $id => $soldier) {
            $init_my = (int)$this->initial_soldiers[$id]["initial_my_soldiers"];
            $init_enemy = (int)$this->initial_soldiers[$id]["initial_enemy_soldiers"];

            if ($init_enemy === 0 && $init_my === 0) {
                continue;
            }

            if ($init_my > 0) {
                $my_loss = (int)$this->initial_soldiers[$id]["my_losses"];
                if ($my_loss >= $init_my) {
                    $this->mysqli->execute_query(
                        "DELETE FROM sent_troops WHERE eventid = ? AND soldierid = ?",
                        [$this->event_id, $id]
                    );
                } else if ($my_loss > 0) {
                    $my_survivors = $init_my - $my_loss;
                    $this->mysqli->execute_query(
                        "UPDATE sent_troops SET soldiercount = ? WHERE eventid = ? AND soldierid = ?",
                        [$my_survivors, $this->event_id, $id]
                    );
                }

                if ($soldier["soldiername"] === "Eroberer") {
                    $this->conquerer_count -= $my_loss;
                }
                $this->my_score_loss += $my_loss * $soldier["score"];
                $this->my_loss_count += $my_loss;
            }

            if ($init_enemy > 0) {
                $total_unit_losses = (int)$this->initial_soldiers[$id]["enemy_losses"];

                $res_own_garrison = (int)($this->mysqli->execute_query(
                    "SELECT soldiercount FROM soldiers WHERE kingdomid = ? AND soldierid = ?",
                    [$this->enemy_kingdom->get_kingdom_id(), $id]
                )->fetch_column() ?: 0);

                if ($total_unit_losses > 0 && $res_own_garrison > 0) {
                    $garrison_share = $res_own_garrison / $init_enemy;
                    $own_losses = (int)floor($total_unit_losses * $garrison_share);

                    if ($own_losses === 0 && $total_unit_losses >= $init_enemy) {
                        $own_losses = $res_own_garrison;
                    }
                    $own_losses = min($res_own_garrison, $own_losses);

                    if ($own_losses >= $res_own_garrison) {
                        $this->mysqli->execute_query(
                            "DELETE FROM soldiers WHERE kingdomid = ? AND soldierid = ?",
                            [$this->enemy_kingdom->get_kingdom_id(), $id]
                        );
                    } else if ($own_losses > 0) {
                        $this->mysqli->execute_query(
                            "UPDATE soldiers SET soldiercount = soldiercount - ? WHERE kingdomid = ? AND soldierid = ?",
                            [$own_losses, $this->enemy_kingdom->get_kingdom_id(), $id]
                        );
                    }

                    $this->enemy_score_loss += $own_losses * $soldier["score"];
                    $this->enemy_garrison_loss_count += $own_losses;
                }

                $this->enemy_loss_count += $total_unit_losses;
            }
        }
    }

    public function get_enemy_garrison_loss_count(): int
    {
        return $this->enemy_garrison_loss_count;
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
        if (NOOB_PROTECTION_MULT <= 0) {
            return false;
        }

        $min_score = $attacker_score * NOOB_PROTECTION_MULT;
        $max_score = $attacker_score / NOOB_PROTECTION_MULT;

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

    public function get_surviving_count(int $soldier_id): int
    {
        return (int)($this->soldiers[$soldier_id]["count"] ?? 0);
    }

    public function get_battle_result_data(bool $for_attacker, bool $is_stationing = false): array
    {
        $data = [];

        foreach ($this->initial_soldiers as $id => $stats) {
            $initial = $for_attacker
                ? ($stats["initial_my_soldiers"] ?? 0)
                : ($stats["initial_enemy_soldiers"] ?? 0);

            $losses = $for_attacker
                ? ($stats["my_losses"] ?? 0)
                : ($stats["enemy_losses"] ?? 0);

            if ($initial === 0 && $for_attacker && isset($this->soldiers[$id]["count"])) {
                $initial = $this->soldiers[$id]["count"];
            }

            if ($initial > 0) {
                $display_atk = 0;
                $display_def = 0;
                if (!$is_stationing && !is_string($id)) {
                    $display_atk = $for_attacker ? $this->soldier_type_atk[$id] : $this->enemy_soldier_type_atk[$id];
                    $display_def = $for_attacker ? $this->soldier_type_def[$id] : $this->enemy_soldier_type_def[$id];
                }

                $data[] = [
                    "id" => $id,
                    "initial" => (int)$initial,
                    "losses" => (int)$losses,
                    "atk" => $display_atk,
                    "def" => $display_def
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

    public function get_monster_defenders(int $x, int $y): void
    {
        $res = $this->mysqli->execute_query("
        SELECT mcu.count, ml.id as monster_id, ml.monster_name, ml.attack, ml.defense, ml.icon
        FROM monster_camp_units mcu
        JOIN monster_list ml ON mcu.monster_id = ml.id
        WHERE mcu.mapx = ? AND mcu.mapy = ?", [$x, $y]);

        foreach ($res as $row) {
            $this->enemy_soldiers[$row["monster_id"]] = [
                "count" => (int)$row["count"],
                "name" => $row["monster_name"],
                "atk" => (int)$row["attack"],
                "def" => (int)$row["defense"],
                "icon" => $row["icon"]
            ];
        }
    }

    public function set_initial_monster_battle(): void
    {
        $this->initial_soldier_count = 0;
        $this->initial_enemy_count = 0;
        $this->initial_soldiers = [];

        // Attacker troops
        foreach ($this->soldier_types as $id => $soldier) {
            $own = isset($this->soldiers[$id]["count"]) ? (int)$this->soldiers[$id]["count"] : 0;

            $this->initial_soldiers[$id] = [
                "initial_my_soldiers" => $own,
                "initial_enemy_soldiers" => 0,
                "my_losses" => 0,
                "enemy_losses" => 0
            ];
            $this->initial_soldier_count += $own;
        }

        // Defender troops (monsters)
        foreach ($this->enemy_soldiers as $m_id => $m_data) {
            $key = "m" . $m_id;

            $this->initial_soldiers[$key] = [
                "initial_my_soldiers" => 0,
                "initial_enemy_soldiers" => (int)$m_data["count"],
                "my_losses" => 0,
                "enemy_losses" => 0
            ];
            $this->initial_enemy_count += $m_data["count"];
        }
    }

    public function get_monster_enemy_data(): array
    {
        return $this->enemy_soldiers;
    }

    public function apply_losses_to_stationed_troops(): void
    {
        $query = "SELECT st.*, sl.scoregain, u.username AS owner_name 
              FROM stationed_troops st 
              JOIN soldier_list sl ON st.soldier_id = sl.id 
              JOIN users u ON st.owner_id = u.id 
              WHERE st.target_kingdom_id = ?
              ORDER BY st.soldier_id, st.id";
        $res = $this->mysqli->execute_query($query, [$this->target_id]);

        $attacker_name = $this->mysqli->execute_query(
            "SELECT u.username FROM events e JOIN users u ON e.userid = u.id WHERE e.eventid = ?",
            [$this->event_id]
        )->fetch_column() ?: "Unbekannt";

        $reports_to_send = [];
        $usernames = [];

        $remaining_losses_per_type = [];
        foreach ($this->soldier_types as $id => $s) {
            $tot_loss = (int)($this->initial_soldiers[$id]["enemy_losses"] ?? 0);

            $res_own_garrison = (int)($this->mysqli->execute_query(
                "SELECT soldiercount FROM soldiers WHERE kingdomid = ? AND soldierid = ?",
                [$this->enemy_kingdom->get_kingdom_id(), $id]
            )->fetch_column() ?: 0);

            $tot_init = (int)($this->initial_soldiers[$id]["initial_enemy_soldiers"] ?? 0);
            $garrison_share = ($tot_init > 0) ? ($res_own_garrison / $tot_init) : 0;
            $garrison_loss = min($res_own_garrison, (int)floor($tot_loss * $garrison_share));

            $remaining_losses_per_type[$id] = max(0, $tot_loss - $garrison_loss);
        }

        while ($row = $res->fetch_assoc()) {
            $uid = (int)$row["owner_id"];
            $sid = (int)$row["soldier_id"];
            $usernames[$uid] = $row["owner_name"];
            $initial = (int)$row["soldiercount"];

            $tot_init_type = (int)($this->initial_soldiers[$sid]["initial_enemy_soldiers"] ?? 0);
            $tot_loss_type = (int)($this->initial_soldiers[$sid]["enemy_losses"] ?? 0);

            $loss = 0;
            if ($tot_init_type > 0 && $tot_loss_type > 0) {
                $unit_loss_ratio = $tot_loss_type / $tot_init_type;
                $calculated_loss = (int)round($initial * $unit_loss_ratio);

                $loss = min($initial, $calculated_loss, $remaining_losses_per_type[$sid]);
                $remaining_losses_per_type[$sid] = max(0, $remaining_losses_per_type[$sid] - $loss);
            }

            if ($loss > 0) {
                $score_loss = $loss * (int)$row["scoregain"];
                if ($score_loss > 0) {
                    $this->mysqli->execute_query(
                        "UPDATE users SET score = GREATEST(0, score - ?) WHERE id = ?",
                        [$score_loss, $uid]
                    );
                }

                if ($loss >= $initial) {
                    $this->mysqli->execute_query("DELETE FROM stationed_troops WHERE id = ?", [$row["id"]]);
                } else {
                    $this->mysqli->execute_query(
                        "UPDATE stationed_troops SET soldiercount = soldiercount - ? WHERE id = ?",
                        [$loss, $row["id"]]
                    );
                }

                Stats::update_player_stat($uid, "units_fallen_pvp", $loss);
            }

            if (!isset($reports_to_send[$uid])) {
                $reports_to_send[$uid] = [];
            }
            $reports_to_send[$uid][] = [
                "sid" => $sid,
                "initial" => $initial,
                "loss" => $loss
            ];
        }

        foreach ($reports_to_send as $uid => $troop_results) {
            $this->send_combined_support_report($uid, $usernames[$uid] ?? "Spieler", $troop_results, $attacker_name);
        }
    }

    private function send_combined_support_report(int $uid, string $u_name, array $troop_results, string $attacker_name): void
    {
        $target_k = new Kingdom($this->target_id);
        $tx = $target_k->get_kingdom_map_x();
        $ty = $target_k->get_kingdom_map_y();

        $res_atk_k = $this->mysqli->execute_query("
            SELECT kingdomname, mapx, mapy FROM kingdoms 
            WHERE id = (SELECT kingdomid FROM events WHERE eventid = ?)
        ", [$this->event_id]);
        $atk_k_data = $res_atk_k->fetch_assoc();

        $units_data = [];
        $total_loss = 0;
        foreach ($troop_results as $res) {
            $units_data[] = [
                "id" => (int)$res["sid"],
                "initial" => (int)$res["initial"],
                "losses" => (int)$res["loss"]
            ];
            $total_loss += (int)$res["loss"];
        }

        $support_combat_json = [
            "template" => "support_combat",
            "target_name" => $target_k->get_kingdom_name(),
            "target_x" => $tx,
            "target_y" => $ty,
            "attacker_name" => $attacker_name,
            "atk_kname" => $atk_k_data['kingdomname'] ?? 'Unbekannt',
            "atk_x" => (int)($atk_k_data['mapx'] ?? 0),
            "atk_y" => (int)($atk_k_data['mapy'] ?? 0),
            "total_loss" => $total_loss,
            "units" => $units_data
        ];

        Messages::send_server_message($uid, $u_name, MessageCategories::CATEGORY_WAR, $support_combat_json);
    }
}

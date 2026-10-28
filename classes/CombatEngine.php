<?php

class CombatEngine
{
    public static function calculate_pvp(array $attackers, array $defenders, int $wall_bonus = 0): array
    {
        $total_atk_units = 0;
        $total_def_units = 0;
        $total_atk_def = 0;
        $total_def_def = 0;
        $atk_def_pools = [
            SoldierTypes::SOLDIER_TYPE_INFANTRY => 0,
            SoldierTypes::SOLDIER_TYPE_CAVALRY => 0,
            SoldierTypes::SOLDIER_TYPE_ARCHERS => 0,
            SoldierTypes::SOLDIER_TYPE_SPECIAL => 0
        ];
        $def_def_pools = [
            SoldierTypes::SOLDIER_TYPE_INFANTRY => 0,
            SoldierTypes::SOLDIER_TYPE_CAVALRY => 0,
            SoldierTypes::SOLDIER_TYPE_ARCHERS => 0,
            SoldierTypes::SOLDIER_TYPE_SPECIAL => 0
        ];
        $atk_has_cat = [];
        $def_has_cat = [];

        foreach ($attackers as $u) {
            $cnt = (int)$u["count"];
            if ($cnt <= 0) continue;

            $cat = (int)$u["category"];
            $total_atk_units += $cnt;
            $u_def = $cnt * (int)$u['def'];
            $total_atk_def += $u_def;
            $atk_def_pools[$cat] = ($atk_def_pools[$cat] ?? 0) + $u_def;
            $atk_has_cat[$cat] = true;
        }

        foreach ($defenders as $u) {
            $cnt = (int)$u["count"];
            if ($cnt <= 0) continue;

            $cat = (int)$u["category"];
            $total_def_units += $cnt;
            $u_def = $cnt * (int)$u["def"];
            $total_def_def += $u_def;
            $def_def_pools[$cat] = ($def_def_pools[$cat] ?? 0) + $u_def;
            $def_has_cat[$cat] = true;
        }

        if ($total_atk_units <= 0 || $total_def_units <= 0) {
            return self::empty_result($attackers, $defenders, $total_atk_units, $total_def_units);
        }

        // RPS Logic
        $get_target_cat = fn(int $cat): int => match ($cat) {
            SoldierTypes::SOLDIER_TYPE_INFANTRY => SoldierTypes::SOLDIER_TYPE_CAVALRY,
            SoldierTypes::SOLDIER_TYPE_CAVALRY => SoldierTypes::SOLDIER_TYPE_ARCHERS,
            SoldierTypes::SOLDIER_TYPE_ARCHERS => SoldierTypes::SOLDIER_TYPE_INFANTRY,
            default => -1
        };

        // Damage Distribution
        $distribute = function (array $units, array $enemy_has_cat, array $enemy_def_pools, int $enemy_total_def) use ($get_target_cat) {
            $targeted_dmg = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
            $shared_dmg = 0;
            $total_raw_atk = 0;
            $global_focus_share = RPS_TARGET_FOCUS;

            foreach ($units as $u) {
                $cnt = (int)$u["count"];
                if ($cnt <= 0) continue;
                $u_atk_sum = $cnt * (int)$u["atk"];
                $total_raw_atk += $u_atk_sum;

                $target_cat = isset($u["target_cat"]) ? (int)$u["target_cat"] : $get_target_cat((int)$u["category"]);
                $unit_rps_bonus = isset($u["rps_bonus"]) ? (float)$u["rps_bonus"] : RPS_BONUS;
                $focus_share = isset($u["target_focus"]) ? (float)$u["target_focus"] : $global_focus_share;
                $default_share = 1.0 - $focus_share;

                if ($target_cat !== -1 && !empty($enemy_has_cat[$target_cat])) {
                    $target_dmg = $u_atk_sum * $focus_share * (1.0 + $unit_rps_bonus);
                    $max_capacity = ($enemy_def_pools[$target_cat] ?? 0) * LETHALITY_PVP;

                    if ($target_dmg > $max_capacity && $max_capacity > 0) {
                        $excess = $target_dmg - $max_capacity;
                        $targeted_dmg[$target_cat] += $max_capacity;
                        $shared_dmg += ($excess / (1.0 + $unit_rps_bonus)) + ($u_atk_sum * $default_share);
                    } else {
                        $targeted_dmg[$target_cat] += $target_dmg;
                        $shared_dmg += ($u_atk_sum * $default_share);
                    }
                } else {
                    $shared_dmg += $u_atk_sum;
                }
            }

            $final_incoming = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
            foreach ($enemy_def_pools as $cat => $cat_def) {
                $final_incoming[$cat] = $targeted_dmg[$cat];

                if ($enemy_total_def > 0 && $cat_def > 0) {
                    $final_incoming[$cat] += $shared_dmg * ($cat_def / $enemy_total_def);
                }
            }

            return ["incoming" => $final_incoming, "raw_atk" => $total_raw_atk];
        };

        $own_offense = $distribute($attackers, $def_has_cat, $def_def_pools, $total_def_def);
        $enemy_offense = $distribute($defenders, $atk_has_cat, $atk_def_pools, $total_atk_def);

        // Wall strikes back
        $wall_counter = 0;
        if ($wall_bonus > 0) {
            $wall_counter = $wall_bonus * WALL_COUNTER_DAMAGE_FACTOR;
            foreach ($atk_def_pools as $cat => $cat_def) {
                if ($total_atk_def > 0 && $cat_def > 0) {
                    $enemy_offense['incoming'][$cat] += $wall_counter * ($cat_def / $total_atk_def);
                }
            }
        }

        $effective_counter = $enemy_offense['raw_atk'] + $wall_counter;

        // Damping
        $damping_atk = 1.0;
        $damping_def = 1.0;
        if ($own_offense["raw_atk"] > 0 && $effective_counter > 0) {
            $range = max(0.01, PVP_DAMPING_MAX_RATIO - PVP_DAMPING_THRESHOLD);

            $ratio_def = $effective_counter / $own_offense["raw_atk"];
            if ($ratio_def > PVP_DAMPING_THRESHOLD) {
                $clamped = max(0.0, min(1.0, ($ratio_def - PVP_DAMPING_THRESHOLD) / $range));
                $damping_def *= pow(1.0 - $clamped, PVP_DAMPING_EXPONENT);
            }

            $ratio_atk = $own_offense["raw_atk"] / $effective_counter;
            if ($ratio_atk > PVP_DAMPING_THRESHOLD) {
                $clamped = max(0.0, min(1.0, ($ratio_atk - PVP_DAMPING_THRESHOLD) / $range));
                $damping_atk *= pow(1.0 - $clamped, PVP_DAMPING_EXPONENT);
            }
        }

        // Base Loss Ratios
        $get_loss_ratio = function (float $incoming, int $cat_def, bool $is_def) use ($wall_bonus, $total_def_def, $damping_def, $damping_atk): float {
            if ($cat_def <= 0) return 1.0;

            $eff_def = (float)$cat_def;
            if ($is_def && $total_def_def > 0) {
                $eff_def += $wall_bonus * ($cat_def / $total_def_def);
            }

            $damping = $is_def ? $damping_def : $damping_atk;

            return ($incoming / ($eff_def * LETHALITY_PVP)) * $damping;
        };

        $atk_cat_ratios = [];
        foreach ($atk_def_pools as $c => $c_def) {
            $atk_cat_ratios[$c] = $get_loss_ratio((float)($enemy_offense["incoming"][$c] ?? 0), $c_def, false);
        }

        $def_cat_ratios = [];
        foreach ($def_def_pools as $c => $c_def) {
            $def_cat_ratios[$c] = $get_loss_ratio((float)($own_offense["incoming"][$c] ?? 0), $c_def, true);
        }

        // Armor Modificator
        $avg_atk_def = $total_atk_def / $total_atk_units;
        $avg_def_def = $total_def_def / $total_def_units;
        $exp = defined("ARMOR_WEIGHT_EXPONENT") ? ARMOR_WEIGHT_EXPONENT : 0.50;

        [$atk_losses, $total_atk_losses] = self::calculate_unit_losses($attackers, $atk_cat_ratios, $avg_atk_def, $exp);
        [$def_losses, $total_def_losses] = self::calculate_unit_losses($defenders, $def_cat_ratios, $avg_def_def, $exp);

        // Calculate winner
        $atk_survivors = max(0, $total_atk_units - $total_atk_losses);
        $def_survivors = max(0, $total_def_units - $total_def_losses);
        $atk_survivor_ratio = $atk_survivors / $total_atk_units;
        $def_survivor_ratio = $def_survivors / $total_def_units;

        $attacker_won = ($atk_survivors > 0) && (
                ($total_def_losses >= $total_def_units) ||
                ($atk_survivor_ratio > $def_survivor_ratio)
            );

        $defender_won = ($def_survivors > 0) && (
                ($total_atk_losses >= $total_atk_units) ||
                ($def_survivor_ratio > $atk_survivor_ratio)
            );

        return [
            "attacker_losses" => $atk_losses,
            "defender_losses" => $def_losses,
            "total_atk_losses" => $total_atk_losses,
            "total_def_losses" => $total_def_losses,
            "total_atk_units" => $total_atk_units,
            "total_def_units" => $total_def_units,
            "attacker_raw_atk" => (int)round($own_offense["raw_atk"]),
            "defender_def_no_wall" => (int)round($total_def_def),
            "attacker_won" => $attacker_won,
            "defender_won" => $defender_won
        ];
    }

    private static function empty_result(array $attackers, array $defenders, int $total_atk, int $total_def): array
    {
        $atk_losses = [];
        $raw_atk = 0;
        foreach ($attackers as $u) {
            $key = $u["key"] ?? $u["id"];
            $atk_losses[$key] = 0;

            if ($total_atk > 0 && isset($u["count"], $u["atk"])) {
                $raw_atk += ((int)$u["count"] * (int)$u["atk"]);
            }
        }

        $def_losses = [];
        foreach ($defenders as $u) {
            $key = $u["key"] ?? $u["id"];
            $def_losses[$key] = 0;
        }

        return [
            "attacker_losses" => $atk_losses,
            "defender_losses" => $def_losses,
            "total_atk_losses" => 0,
            "total_def_losses" => 0,
            "total_atk_units" => $total_atk,
            "total_def_units" => $total_def,
            "attacker_raw_atk" => $raw_atk,
            "defender_def_no_wall" => 0,
            "attacker_won" => ($total_atk > 0 && $total_def === 0)
        ];
    }

    private static function calculate_unit_losses(array $units, array $cat_ratios, float $avg_def, float $exp): array
    {
        $losses = [];
        $total_losses = 0;

        foreach ($units as $u) {
            $key = $u["key"] ?? $u["id"];
            $init = (int)$u["count"];
            if ($init <= 0) {
                $losses[$key] = 0;
                continue;
            }

            $base = $cat_ratios[$u["category"]] ?? 0.0;
            $armor = pow($avg_def / max(1, (int)$u["def"]), $exp);
            $ratio = max(0.0, min(1.0, $base * $armor));
            $loss = (int)round($init * $ratio);

            $losses[$key] = $loss;
            $total_losses += $loss;
        }

        return [$losses, $total_losses];
    }
}
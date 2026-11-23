<?php

class Alchemy
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = Database::get_instance()->get_connection();
    }

    public static function process_all(): void
    {
        $db = Database::get_instance()->get_connection();

        $active = $db->query("
            SELECT a.kingdom_id, b.buildinglevel 
            FROM kingdom_alchemy a
            JOIN buildings b ON a.kingdom_id = b.kingdomid AND b.buildingid = " . BuildingTypes::BUILDING_ALCHEMY_LAB . "
            WHERE a.input_amount > 0 AND a.input_resource != -1
        ");

        $alchemy = new self();
        while ($row = $active->fetch_assoc()) {
            $alchemy->process_kingdom((int)$row["kingdom_id"], (int)$row["buildinglevel"]);
        }
    }

    public function process_kingdom(int $kingdom_id, int $lab_level): void
    {
        $this->db->begin_transaction();
        try {
            $res = $this->db->execute_query("SELECT * FROM kingdom_alchemy WHERE kingdom_id = ? FOR UPDATE", [$kingdom_id]);
            $row = $res->fetch_assoc();
            $now = microtime(true);

            if (!$row || (int)$row["input_resource"] === -1 || (int)$row["input_amount"] <= 0) {
                if ($row && (int)$row["input_amount"] <= 0) {
                    $this->db->execute_query("UPDATE kingdom_alchemy SET last_update = ? WHERE kingdom_id = ?", [sprintf("%.4F", $now), $kingdom_id]);
                }
                $this->db->commit();
                return;
            }

            $in_res = (int)$row["input_resource"];
            $out_res = (int)$row["target_resource"];
            $step = self::get_step_size($in_res, $out_res);
            $rate = self::get_conversion_rate($in_res, $out_res);
            $output_per_step = (int)round($step * $rate);

            if ($output_per_step <= 0) {
                $this->db->commit();
                return;
            }

            $max_output = self::get_max_output_buffer($in_res, $out_res, $lab_level);
            $current_output = (int)round((float)$row["output_amount"]);
            $output_room = max(0, $max_output - $current_output);

            if ($output_room < $output_per_step) {
                $this->db->execute_query("UPDATE kingdom_alchemy SET last_update = ? WHERE kingdom_id = ?", [sprintf("%.4F", $now), $kingdom_id]);
                $this->db->commit();
                return;
            }

            $elapsed = max(0.0, $now - (float)$row["last_update"]);
            if ($elapsed <= 0) {
                $this->db->commit();
                return;
            }

            $speed_per_hour = self::get_speed_per_hour($lab_level, $in_res);
            if ($speed_per_hour <= 0) {
                $this->db->commit();
                return;
            }

            $seconds_per_step = ($step * 3600.0) / $speed_per_hour;
            if ($seconds_per_step <= 0) {
                $this->db->commit();
                return;
            }

            $possible_steps_by_input = (int)floor((int)$row["input_amount"] / $step);
            $possible_steps_by_room = (int)floor($output_room / $output_per_step);
            $max_possible_steps = min($possible_steps_by_input, $possible_steps_by_room);

            if ($max_possible_steps <= 0) {
                $this->db->commit();
                return;
            }

            $remaining_seconds_for_all = $max_possible_steps * $seconds_per_step;
            $grace_tolerance = min(1.0, $seconds_per_step);

            if ($elapsed >= ($remaining_seconds_for_all - $grace_tolerance)) {
                $steps_to_run = $max_possible_steps;
            } else {
                $steps_to_run = (int)floor(($elapsed * $speed_per_hour + 1e-6) / ($step * 3600.0));
                $steps_to_run = min($steps_to_run, $max_possible_steps);
            }

            if ($steps_to_run > 0) {
                $input_consumed = $steps_to_run * $step;
                $output_generated = $steps_to_run * $output_per_step;
                $new_input = max(0, (int)$row["input_amount"] - $input_consumed);
                $new_output = $current_output + $output_generated;
                $time_used = ($steps_to_run * $step * 3600.0) / $speed_per_hour;
                $sub_second_remainder = max(0.0, $elapsed - $time_used);
                $can_run_more = ($new_input >= $step && ($output_room - $output_generated) >= $output_per_step);
                $new_last_update = $can_run_more ? ($now - $sub_second_remainder) : $now;

                $this->db->execute_query(
                    "UPDATE kingdom_alchemy SET input_amount = ?, output_amount = ?, last_update = ? WHERE kingdom_id = ?",
                    [$new_input, $new_output, sprintf("%.4F", $new_last_update), $kingdom_id]
                );
            }

            $this->db->commit();
        } catch (Throwable) {
            $this->db->rollback();
        }
    }

    public static function get_step_size(int $from_res, int $to_res): int
    {
        $weights = ALCHEMY_RESOURCE_WEIGHTS;
        if (!isset($weights[$from_res], $weights[$to_res])) return 1;

        $w_from = (int)round($weights[$from_res] * 1000);
        $w_to = (int)round($weights[$to_res] * 1000);

        $divisor = self::gcd($w_from, $w_to);
        return max(1, (int)($w_from / $divisor));
    }

    private static function gcd(int $a, int $b): int
    {
        return ($b === 0) ? $a : self::gcd($b, $a % $b);
    }

    public static function get_conversion_rate(int $from_res, int $to_res): float
    {
        $weights = ALCHEMY_RESOURCE_WEIGHTS;
        if (!isset($weights[$from_res], $weights[$to_res]) || $weights[$from_res] <= 0) {
            return 0.0;
        }

        return (float)($weights[$to_res] / $weights[$from_res]);
    }

    public static function get_max_output_buffer(int $from_res, int $to_res, int $lab_level): int
    {
        $cap = self::get_capacity($lab_level);
        $rate = self::get_conversion_rate($from_res, $to_res);
        return (int)round($cap * $rate * ALCHEMY_OUTPUT_BUFFER_MULTIPLIER);
    }

    public static function get_capacity(int $level): int
    {
        if ($level <= 0) {
            return 0;
        }

        $lvl = max(1, min($level, ALCHEMY_MAX_LEVEL));
        return ALCHEMY_CAPACITY_BY_LEVEL[$lvl] ?? 50000;
    }

    public static function get_speed_per_hour(int $level, ?int $from_res = null): int
    {
        if ($level <= 0) return 0;

        $lvl = max(1, min($level, ALCHEMY_MAX_LEVEL));
        $base_speed = ALCHEMY_SPEED_BY_LEVEL[$lvl] ?? 8000;

        if ($from_res !== null && isset(ALCHEMY_RESOURCE_WEIGHTS[$from_res])) {
            return (int)round($base_speed * ALCHEMY_RESOURCE_WEIGHTS[$from_res]);
        }

        return $base_speed;
    }

    public function start_transmutation(int $kingdom_id, int $from_res, int $to_res, int $amount, int $lab_level, Kingdom $k): ?string
    {
        if ($from_res === $to_res) {
            return "Start- und Zielressource dürfen nicht identisch sein.";
        }

        $cost_key = self::resource_to_cost_key($from_res);
        if ($cost_key === null || self::resource_to_cost_key($to_res) === null) {
            return "Ungültige Ressourcen gewählt.";
        }

        $step = self::get_step_size($from_res, $to_res);
        $amount = $amount - ($amount % $step);
        if ($amount < $step) {
            return "Die Menge muss mindestens $step " . ($step === 1 ? "Einheit" : "Einheiten") . " betragen.";
        }

        $maxCap = self::get_capacity($lab_level);
        if ($amount > $maxCap) {
            return "Die maximale Kessel-Kapazität beträgt " . fnum($maxCap) . ".";
        }

        $this->process_kingdom($kingdom_id, $lab_level);

        $this->db->begin_transaction();
        try {
            $this->db->execute_query("SELECT id FROM kingdoms WHERE id = ? FOR UPDATE", [$kingdom_id]);

            $state = $this->db->execute_query(
                "SELECT input_amount, output_amount FROM kingdom_alchemy WHERE kingdom_id = ? FOR UPDATE",
                [$kingdom_id]
            )->fetch_assoc();

            if ($state && ((int)$state["input_amount"] > 0 || (float)$state["output_amount"] > 0)) {
                throw new RuntimeException("Es läuft bereits eine Umwandlung oder es liegen noch fertige Ressourcen zur Abholung bereit!");
            }

            if (!$k->pay_costs([$cost_key => $amount])) {
                throw new RuntimeException("Du hast nicht genügend Ressourcen auf Lager!");
            }

            $this->db->execute_query("
                INSERT INTO kingdom_alchemy (kingdom_id, input_resource, input_amount, target_resource, output_amount, last_update)
                VALUES (?, ?, ?, ?, 0, ?)
                ON DUPLICATE KEY UPDATE
                    input_resource = VALUES(input_resource),
                    input_amount = VALUES(input_amount),
                    target_resource = VALUES(target_resource),
                    output_amount = 0,
                    last_update = VALUES(last_update)
            ", [$kingdom_id, $from_res, $amount, $to_res, sprintf("%.4F", microtime(true))]);

            $this->db->commit();
            return null;
        } catch (RuntimeException $e) {
            $this->db->rollback();
            return $e->getMessage();
        } catch (Throwable $e) {
            $this->db->rollback();
            Logger::get_instance()->error("Alchemy start_transmutation error: " . $e->getMessage());
            return "Fehler beim Starten der Umwandlung.";
        }
    }

    public function get_state(int $kingdom_id, int $lab_level): array
    {
        $this->process_kingdom($kingdom_id, $lab_level);

        $res = $this->db->execute_query("SELECT * FROM kingdom_alchemy WHERE kingdom_id = ?", [$kingdom_id]);
        $row = $res->fetch_assoc();

        if (!$row) {
            return [
                "kingdom_id" => $kingdom_id,
                "input_resource" => -1,
                "input_amount" => 0,
                "target_resource" => -1,
                "output_amount" => 0,
                "last_update" => microtime(true)
            ];
        }

        return $row;
    }

    public function top_up(int $kingdom_id, int $amount, int $lab_level, Kingdom $k): ?string
    {
        if ($amount <= 0) {
            return "Bitte gib eine Menge größer als 0 an.";
        }

        $this->process_kingdom($kingdom_id, $lab_level);

        $this->db->begin_transaction();
        try {
            $this->db->execute_query("SELECT id FROM kingdoms WHERE id = ? FOR UPDATE", [$kingdom_id]);

            $state = $this->db->execute_query(
                "SELECT * FROM kingdom_alchemy WHERE kingdom_id = ? FOR UPDATE",
                [$kingdom_id]
            )->fetch_assoc();

            if (!$state || (int)$state["input_resource"] === -1) {
                throw new RuntimeException("Keine Umwandlung aktiv.");
            }

            $in_res = (int)$state["input_resource"];
            $out_res = (int)$state["target_resource"];
            $cur_in = (int)$state["input_amount"];

            $cost_key = self::resource_to_cost_key($in_res);
            if ($cost_key === null) {
                throw new RuntimeException("Ungültige Ressource im Kessel.");
            }

            $step = self::get_step_size($in_res, $out_res);
            $amount = $amount - ($amount % $step);
            if ($amount <= 0) {
                throw new RuntimeException("Bitte gib mindestens $step Einheiten zum Nachfüllen an.");
            }

            $max_cap = self::get_capacity($lab_level);
            $free_space = max(0, $max_cap - $cur_in);
            if ($amount > $free_space) {
                throw new RuntimeException("Der Kessel kann maximal noch " . fnum($free_space) . " Ressourcen aufnehmen.");
            }

            if (!$k->pay_costs([$cost_key => $amount])) {
                throw new RuntimeException("Nicht genügend Rohstoffe im Lager.");
            }

            if ($cur_in < $step) {
                $this->db->execute_query(
                    "UPDATE kingdom_alchemy SET input_amount = input_amount + ?, last_update = ? WHERE kingdom_id = ?",
                    [$amount, sprintf("%.4F", microtime(true)), $kingdom_id]
                );
            } else {
                $this->db->execute_query(
                    "UPDATE kingdom_alchemy SET input_amount = input_amount + ? WHERE kingdom_id = ?",
                    [$amount, $kingdom_id]
                );
            }

            $this->db->commit();
            return null;
        } catch (RuntimeException $e) {
            $this->db->rollback();
            return $e->getMessage();
        } catch (Throwable $e) {
            $this->db->rollback();
            Logger::get_instance()->error("Alchemy top_up error: " . $e->getMessage());
            return "Fehler beim Nachfüllen.";
        }
    }

    public function claim(int $kingdom_id, int $lab_level, Kingdom $k): ?string
    {
        $this->process_kingdom($kingdom_id, $lab_level);

        $this->db->begin_transaction();
        try {
            $state = $this->db->execute_query(
                "SELECT * FROM kingdom_alchemy WHERE kingdom_id = ? FOR UPDATE",
                [$kingdom_id]
            )->fetch_assoc();

            $claimable = $state ? (int)round((float)$state["output_amount"]) : 0;
            if ($claimable <= 0) {
                $this->db->rollback();
                return "Keine fertigen Ressourcen zum Einsammeln bereit.";
            }

            $target_res = (int)$state["target_resource"];
            $k->modify_resource($target_res, $claimable);

            if ((int)$state["input_amount"] <= 0) {
                $this->db->execute_query("DELETE FROM kingdom_alchemy WHERE kingdom_id = ?", [$kingdom_id]);
            } else {
                $this->db->execute_query("UPDATE kingdom_alchemy SET output_amount = 0 WHERE kingdom_id = ?", [$kingdom_id]);
            }

            $this->db->commit();

            Stats::update_player_stat($k->get_kingdom_owner_id(), "alchemy_claimed", $claimable);
            return null;
        } catch (Throwable $e) {
            $this->db->rollback();
            Logger::get_instance()->error("Alchemy claim error: " . $e->getMessage());
            return "Fehler beim Abholen.";
        }
    }

    public function cancel(int $kingdom_id, int $lab_level, Kingdom $k): ?string
    {
        $this->process_kingdom($kingdom_id, $lab_level);

        $this->db->begin_transaction();
        try {
            $state = $this->db->execute_query(
                "SELECT * FROM kingdom_alchemy WHERE kingdom_id = ? FOR UPDATE",
                [$kingdom_id]
            )->fetch_assoc();

            if (!$state || ((int)$state["input_resource"] === -1 && (float)$state["output_amount"] <= 0)) {
                $this->db->rollback();
                return "Keine Umwandlung aktiv.";
            }

            $unconverted = (int)$state["input_amount"];
            if ($unconverted > 0 && (int)$state["input_resource"] !== -1) {
                $k->modify_resource((int)$state["input_resource"], $unconverted);
            }

            $has_claimable = ((int)round((float)$state["output_amount"]) > 0);
            if ($has_claimable) {
                $this->db->execute_query(
                    "UPDATE kingdom_alchemy SET input_resource = -1, input_amount = 0, last_update = ? WHERE kingdom_id = ?",
                    [sprintf("%.4F", microtime(true)), $kingdom_id]
                );
            } else {
                $this->db->execute_query("DELETE FROM kingdom_alchemy WHERE kingdom_id = ?", [$kingdom_id]);
            }

            $this->db->commit();
            return null;
        } catch (Throwable $e) {
            $this->db->rollback();
            Logger::get_instance()->error("Alchemy cancel error: " . $e->getMessage());
            return "Fehler beim Abbrechen.";
        }
    }

    private static function resource_to_cost_key(int $res_type): ?string
    {
        return match ($res_type) {
            ResourceTypes::RESOURCE_TYPE_FOOD => "cost_food",
            ResourceTypes::RESOURCE_TYPE_WOOD => "cost_wood",
            ResourceTypes::RESOURCE_TYPE_STONE => "cost_stone",
            ResourceTypes::RESOURCE_TYPE_GOLD => "cost_gold",
            default => null
        };
    }

}
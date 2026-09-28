<?php

class Alchemy
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = Database::get_instance()->get_connection();
    }

    private static function gcd(int $a, int $b): int
    {
        return ($b === 0) ? $a : self::gcd($b, $a % $b);
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
        return ALCHEMY_BASE_CAPACITY + (($level - 1) * ALCHEMY_CAPACITY_PER_LEVEL);
    }

    public static function get_speed_per_hour(int $level, ?int $from_res = null): int
    {
        if ($level <= 0) return 0;
        $base_speed = ALCHEMY_BASE_SPEED + (($level - 1) * ALCHEMY_SPEED_PER_LEVEL);

        if ($from_res !== null && isset(ALCHEMY_RESOURCE_WEIGHTS[$from_res])) {
            return (int)round($base_speed * ALCHEMY_RESOURCE_WEIGHTS[$from_res]);
        }
        return $base_speed;
    }

    public static function get_conversion_rate(int $from_res, int $to_res): float
    {
        $weights = ALCHEMY_RESOURCE_WEIGHTS;
        if (!isset($weights[$from_res], $weights[$to_res]) || $weights[$from_res] <= 0) {
            return 0.0;
        }

        return (float)($weights[$to_res] / $weights[$from_res]);
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
                "last_update" => time()
            ];
        }

        return $row;
    }

    public function process_kingdom(int $kingdom_id, int $lab_level): void
    {
        $this->db->begin_transaction();
        try {
            $res = $this->db->execute_query("SELECT * FROM kingdom_alchemy WHERE kingdom_id = ? FOR UPDATE", [$kingdom_id]);
            $row = $res->fetch_assoc();

            $now = time();

            if (!$row || (int)$row["input_resource"] === -1 || (int)$row["input_amount"] <= 0) {
                if ($row && (int)$row["input_amount"] <= 0) {
                    $this->db->execute_query("UPDATE kingdom_alchemy SET last_update = ? WHERE kingdom_id = ?", [$now, $kingdom_id]);
                }

                $this->db->commit();
                return;
            }

            $elapsed = max(0, $now - (int)$row["last_update"]);
            if ($elapsed <= 0) {
                $this->db->commit();
                return;
            }

            $in_res = (int)$row["input_resource"];
            $out_res = (int)$row["target_resource"];
            $rate = self::get_conversion_rate($in_res, $out_res);

            $max_output = self::get_max_output_buffer($in_res, $out_res, $lab_level);
            $current_output = (float)$row["output_amount"];
            $output_room = max(0.0, $max_output - $current_output);

            if ($output_room <= 0.0) {
                $this->db->execute_query("UPDATE kingdom_alchemy SET last_update = ? WHERE kingdom_id = ?", [$now, $kingdom_id]);
                $this->db->commit();
                return;
            }

            $speed_sec = self::get_speed_per_hour($lab_level, $in_res) / 3600;
            $max_input_by_room = $output_room / $rate;

            $step = self::get_step_size($in_res, $out_res);
            if ((int)$row["input_amount"] < $step) {
                $this->db->commit();
                return;
            }

            $convertible_input = min((float)$row["input_amount"], $speed_sec * $elapsed, $max_input_by_room);

            if ($convertible_input > 0) {
                $generated_output = $convertible_input * $rate;

                $new_input = max(0, (int)round($row["input_amount"] - $convertible_input));
                $new_output = round($current_output + $generated_output, 4);

                $this->db->execute_query("
                    UPDATE kingdom_alchemy 
                    SET input_amount = ?, output_amount = ?, last_update = ?
                    WHERE kingdom_id = ?
                ", [$new_input, $new_output, $now, $kingdom_id]);
            } else {
                $this->db->execute_query("UPDATE kingdom_alchemy SET last_update = ? WHERE kingdom_id = ?", [$now, $kingdom_id]);
            }

            $this->db->commit();
        } catch (Throwable) {
            $this->db->rollback();
        }
    }

    public function start_transmutation(int $kingdom_id, int $from_res, int $to_res, int $amount, int $lab_level, Kingdom $k): ?string
    {
        if ($from_res === $to_res) {
            return "Start- und Zielressource dürfen nicht identisch sein.";
        }

        $res_array = [
            ResourceTypes::RESOURCE_TYPE_FOOD,
            ResourceTypes::RESOURCE_TYPE_WOOD,
            ResourceTypes::RESOURCE_TYPE_STONE,
            ResourceTypes::RESOURCE_TYPE_GOLD
        ];

        if (!in_array($from_res, $res_array) || !in_array($to_res, $res_array)) {
            return "Ungültige Ressourcen gewählt.";
        }

        $step = self::get_step_size($from_res, $to_res);
        $amount = $amount - ($amount % $step);

        if ($amount <= 0) {
            return "Die Menge muss mindestens $step Einheiten betragen.";
        }

        $maxCap = self::get_capacity($lab_level);
        if ($amount > $maxCap) {
            return "Die maximale Kessel-Kapazität beträgt " . fnum($maxCap) . ".";
        }

        $stock = match ($from_res) {
            ResourceTypes::RESOURCE_TYPE_FOOD => $k->get_kingdom_food(),
            ResourceTypes::RESOURCE_TYPE_WOOD => $k->get_kingdom_wood(),
            ResourceTypes::RESOURCE_TYPE_STONE => $k->get_kingdom_stone(),
            ResourceTypes::RESOURCE_TYPE_GOLD => $k->get_kingdom_gold(),
            default => 0
        };

        if ($amount > $stock) {
            return "Du hast nicht genügend Ressourcen auf Lager!";
        }

        $state = $this->get_state($kingdom_id, $lab_level);
        if ($state["input_amount"] > 0) {
            return "Es läuft bereits eine Umwandlung!";
        }

        $this->db->begin_transaction();
        try {
            $k->modify_resource($from_res, -$amount);

            $this->db->execute_query("
                INSERT INTO kingdom_alchemy (kingdom_id, input_resource, input_amount, target_resource, output_amount, last_update)
                VALUES (?, ?, ?, ?, 0, ?)
                ON DUPLICATE KEY UPDATE 
                    input_resource = VALUES(input_resource),
                    input_amount = VALUES(input_amount),
                    target_resource = VALUES(target_resource),
                    output_amount = 0,
                    last_update = VALUES(last_update)
            ", [$kingdom_id, $from_res, $amount, $to_res, time()]);

            $this->db->commit();
            return null;
        } catch (Exception $e) {
            $this->db->rollback();
            return "Fehler beim Starten der Transmutation: " . $e->getMessage();
        }
    }

    public function top_up(int $kingdom_id, int $amount, int $lab_level, Kingdom $k): ?string
    {
        $state = $this->get_state($kingdom_id, $lab_level);
        if ($state["input_resource"] === -1) {
            return "Keine Umwandlung aktiv.";
        }

        $step = self::get_step_size($state["input_resource"], $state["target_resource"]);
        $amount = $amount - ($amount % $step);

        if ($amount <= 0) {
            return "Die Nachfüllmenge muss mindestens $step Einheiten betragen.";
        }

        $max_cap = self::get_capacity($lab_level);
        $free_space = max(0, $max_cap - $state["input_amount"]);

        if ($amount > $free_space) {
            return "Der Kessel kann maximal noch " . fnum($free_space) . " Ressourcen aufnehmen.";
        }

        $stock = match ($state["input_resource"]) {
            ResourceTypes::RESOURCE_TYPE_FOOD => $k->get_kingdom_food(),
            ResourceTypes::RESOURCE_TYPE_WOOD => $k->get_kingdom_wood(),
            ResourceTypes::RESOURCE_TYPE_STONE => $k->get_kingdom_stone(),
            ResourceTypes::RESOURCE_TYPE_GOLD => $k->get_kingdom_gold(),
            default => 0
        };

        if ($amount > $stock) {
            return "Nicht genügend Rohstoffe im Lager.";
        }

        $this->db->begin_transaction();
        try {
            $k->modify_resource($state["input_resource"], -$amount);

            $this->db->execute_query("UPDATE kingdom_alchemy SET input_amount = input_amount + ?, last_update = ? WHERE kingdom_id = ?", [$amount, time(), $kingdom_id]);
            $this->db->commit();
            return null;
        } catch (Exception $e) {
            $this->db->rollback();
            return "Fehler beim Nachfüllen: " . $e->getMessage();
        }
    }

    public function claim(int $kingdom_id, int $lab_level, Kingdom $k): ?string
    {
        $state = $this->get_state($kingdom_id, $lab_level);
        $claimable = (int)floor(round($state["output_amount"], 2));

        if ($claimable <= 0) {
            return "Keine fertigen Ressourcen zum Einsammeln bereit.";
        }

        $target_res = $state["target_resource"];

        $this->db->begin_transaction();
        try {
            $k->modify_resource($target_res, $claimable);

            $remaining_float = max(0.0, (float)$state["output_amount"] - $claimable);

            if ($state["input_amount"] <= 0) {
                $this->db->execute_query("DELETE FROM kingdom_alchemy WHERE kingdom_id = ?", [$kingdom_id]);
            } else {
                $this->db->execute_query("
                    UPDATE kingdom_alchemy 
                    SET output_amount = ?, last_update = ?
                    WHERE kingdom_id = ?
                ", [$remaining_float, time(), $kingdom_id]);
            }

            $this->db->commit();
            return null;
        } catch (Exception $e) {
            $this->db->rollback();
            return "Fehler beim Abholen: " . $e->getMessage();
        }
    }

    public function cancel(int $kingdom_id, int $lab_level, Kingdom $k): ?string
    {
        $state = $this->get_state($kingdom_id, $lab_level);
        if ($state["input_resource"] === -1 && (float)$state["output_amount"] <= 0) {
            return "Keine Umwandlung aktiv.";
        }

        $this->db->begin_transaction();
        try {
            $unconverted = $state["input_amount"];
            if ($unconverted > 0 && $state["input_resource"] !== -1) {
                $k->modify_resource($state["input_resource"], $unconverted);
            }

            $has_claimable = ((int)floor($state["output_amount"]) > 0);
            if ($has_claimable) {
                $this->db->execute_query("
                    UPDATE kingdom_alchemy 
                    SET input_resource = -1, input_amount = 0, last_update = ?
                    WHERE kingdom_id = ?
                ", [time(), $kingdom_id]);
            } else {
                $this->db->execute_query("DELETE FROM kingdom_alchemy WHERE kingdom_id = ?", [$kingdom_id]);
            }

            $this->db->commit();
            return null;
        } catch (Exception $e) {
            $this->db->rollback();
            return "Fehler beim Abbrechen: " . $e->getMessage();
        }
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
}
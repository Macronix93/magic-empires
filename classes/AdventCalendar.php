<?php

class AdventCalendar
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = Database::get_instance()->get_connection();
    }

    public static function is_active(): bool
    {
        $db = Database::get_instance()->get_connection();
        $val = $db->execute_query("SELECT value FROM system_settings WHERE name = 'advent_calendar_active'")->fetch_column();
        return ($val === '1');
    }

    public static function get_start_timestamp(): int
    {
        $db = Database::get_instance()->get_connection();
        $val = $db->execute_query("SELECT value FROM system_settings WHERE name = 'advent_calendar_start'")->fetch_column();
        return (int)($val ?? 0);
    }

    public static function get_current_day(): int
    {
        $start = self::get_start_timestamp();
        if ($start <= 0) return 1;

        $diff = time() - $start;
        $day = (int)floor($diff / 86400) + 1;
        return max(1, min(ADVENT_CALENDAR_TOTAL_DAYS, $day));
    }

    public function start_for_all_users(): int
    {
        $now = time();
        $this->db->execute_query("INSERT INTO system_settings (name, value) VALUES ('advent_calendar_active', '1') ON DUPLICATE KEY UPDATE value = '1'");
        $this->db->execute_query("INSERT INTO system_settings (name, value) VALUES ('advent_calendar_start', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [(string)$now]);

        // Reset gifts
        $this->db->query("TRUNCATE TABLE advent_calendar_gifts");

        // Activate modal for users
        $this->db->query("UPDATE users SET advent_intro_seen = 0 WHERE advent_intro_seen != 0");

        return $this->spawn_day_for_all(1);
    }

    public function stop(): void
    {
        $this->db->execute_query("UPDATE system_settings SET value = '0' WHERE name = 'advent_calendar_active'");
        $this->db->query("TRUNCATE TABLE advent_calendar_gifts");
    }

    public function spawn_all_test_gifts(int $user_id): int
    {
        $this->db->execute_query("DELETE FROM advent_calendar_gifts WHERE user_id = ?", [$user_id]);

        $fields = $this->find_free_coordinates($user_id, ADVENT_CALENDAR_TOTAL_DAYS);
        if (count($fields) < ADVENT_CALENDAR_TOTAL_DAYS) return 0;

        $entries = [];
        foreach ($fields as $index => $coord) {
            $entries[] = [
                "user_id" => $user_id,
                "day" => $index + 1,
                "x" => $coord["x"],
                "y" => $coord["y"]
            ];
        }

        return $this->insert_gifts_batch($entries);
    }

    public function spawn_day_for_all(int $day): int
    {
        $res = $this->db->query("SELECT id FROM users WHERE status = 1 AND is_banned = 0");
        $users = $res->fetch_all(MYSQLI_ASSOC);
        if (empty($users)) return 0;

        $existing_res = $this->db->execute_query("SELECT user_id FROM advent_calendar_gifts WHERE day = ?", [$day]);
        $existing_uids = array_column($existing_res->fetch_all(MYSQLI_ASSOC), "user_id");
        $existing_map = array_flip($existing_uids);

        $entries = [];
        foreach ($users as $u) {
            $uid = (int)$u["id"];
            if (isset($existing_map[$uid])) continue;

            $coords = $this->find_free_coordinates($uid);
            if (!empty($coords)) {
                $entries[] = [
                    "user_id" => $uid,
                    "day" => $day,
                    "x" => $coords[0]["x"],
                    "y" => $coords[0]["y"]
                ];
            }
        }

        return $this->insert_gifts_batch($entries);
    }

    public static function ensure_user_gifts(int $user_id): int
    {
        if (!self::is_active() || $user_id <= 0) return 0;

        $db = Database::get_instance()->get_connection();
        $curr_day = self::get_current_day();

        $res = $db->execute_query("SELECT day FROM advent_calendar_gifts WHERE user_id = ?", [$user_id]);
        $spawned_days = array_column($res->fetch_all(MYSQLI_ASSOC), "day");

        $missing_days = [];
        for ($d = 1; $d <= $curr_day; $d++) {
            if (!in_array($d, $spawned_days, true)) {
                $missing_days[] = $d;
            }
        }

        if (empty($missing_days)) return 0;

        $cal = new self();
        $coords = $cal->find_free_coordinates($user_id, count($missing_days));
        if (empty($coords)) return 0;

        $entries = [];
        foreach ($missing_days as $idx => $day) {
            if (!isset($coords[$idx])) break;

            $entries[] = [
                "user_id" => $user_id,
                "day" => $day,
                "x" => $coords[$idx]["x"],
                "y" => $coords[$idx]["y"]
            ];
        }

        return $cal->insert_gifts_batch($entries);
    }

    public function insert_gifts_batch(array $entries): int
    {
        if (empty($entries)) return 0;

        $now = time();
        $values = [];
        $params = [];
        $types = "";

        foreach ($entries as $e) {
            $values[] = "(?, ?, ?, ?, 0, ?)";
            $params[] = (int)$e['user_id'];
            $params[] = (int)$e['day'];
            $params[] = (int)$e['x'];
            $params[] = (int)$e['y'];
            $params[] = $now;
            $types .= "iiiii";
        }

        $sql = "INSERT INTO advent_calendar_gifts (user_id, day, mapx, mapy, is_collected, spawned_at) VALUES " . implode(', ', $values);
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        return count($entries);
    }

    public function find_free_coordinates(int $user_id, int $limit = 1): array
    {
        $query = "SELECT m.mapx, m.mapy FROM map m 
                  WHERE m.kingdomid = -1 
                  AND NOT (m.mapx BETWEEN 49 AND 51 AND m.mapy BETWEEN 49 AND 51)
                  AND NOT EXISTS (
                      SELECT 1 FROM advent_calendar_gifts ag 
                      WHERE ag.user_id = ? AND ag.mapx = m.mapx AND ag.mapy = m.mapy AND ag.is_collected = 0
                  )
                  ORDER BY RAND() LIMIT ?";
        $res = $this->db->execute_query($query, [$user_id, $limit]);

        $coords = [];
        while ($row = $res->fetch_assoc()) {
            $coords[] = ['x' => (int)$row['mapx'], 'y' => (int)$row['mapy']];
        }

        return $coords;
    }

    public function generate_gift_loot(int $user_id, int $day): array
    {
        $is_day_24 = ($day === ADVENT_CALENDAR_TOTAL_DAYS);

        $coins = $day;

        $avg_lvl = Building::get_user_max_building_avg($user_id);
        $building_factor = max(
            ADVENT_CALENDAR_MIN_BUILDING_FACTOR,
            sqrt(max(1.0, $avg_lvl)) * ADVENT_CALENDAR_BUILDING_FACTOR_COEFF
        );

        $day_progress = pow($day, ADVENT_CALENDAR_CURVE_EXPONENT);
        $base_res = (ADVENT_CALENDAR_BASE_RES_START + ($day * ADVENT_CALENDAR_DAY_RES_INCREMENT) + ($day_progress * ADVENT_CALENDAR_CURVE_MULTIPLIER)) * $building_factor;

        if ($is_day_24) {
            $base_res *= ADVENT_CALENDAR_DAY_24_MULTIPLIER;
        }

        $variance = fn($val) => (int)round($val * (mt_rand(ADVENT_CALENDAR_RES_MIN_VARIANCE, ADVENT_CALENDAR_RES_MAX_VARIANCE) / 100));
        $resources = [
            ResourceTypes::RESOURCE_TYPE_FOOD => $variance($base_res),
            ResourceTypes::RESOURCE_TYPE_WOOD => $variance($base_res),
            ResourceTypes::RESOURCE_TYPE_STONE => $variance($base_res),
            ResourceTypes::RESOURCE_TYPE_GOLD => $variance($base_res)
        ];

        $troop_chance = $is_day_24 ? ADVENT_CALENDAR_TROOP_CHANCE_DAY_24 : min(85, ADVENT_CALENDAR_BASE_TROOP_CHANCE + ($day * ADVENT_CALENDAR_TROOP_CHANCE_INCREMENT));
        $bonus_troops = [];

        if (mt_rand(1, 100) <= $troop_chance) {
            $pool = [
                Soldiers::SOLDIER_SWORDSMAN,
                Soldiers::SOLDIER_HALBERDIER,
                Soldiers::SOLDIER_LONGBOWMAN,
                Soldiers::SOLDIER_ELVENARCHER,
                Soldiers::SOLDIER_PALADIN,
                Soldiers::SOLDIER_CUIRASSIER
            ];
            if ($is_day_24) {
                $pool[] = Soldiers::SOLDIER_RAM;
                $pool[] = Soldiers::SOLDIER_CONQUEROR;
            }

            $chosen_id = $pool[array_rand($pool)];

            if ($chosen_id === Soldiers::SOLDIER_CONQUEROR) {
                $unit_count = ($day >= 18) ? mt_rand(1, 2) : 1;
            } else if ($chosen_id === Soldiers::SOLDIER_RAM) {
                $unit_count = mt_rand(1, min(3, (int)ceil($day / 8)));
            } else {
                $min_u = max(1, (int)round(2 + ($day * 0.3)));
                $max_u = (int)round(4 + ($day * 0.7));
                $unit_count = mt_rand($min_u, $max_u);
            }

            $bonus_troops[] = [
                "id" => $chosen_id,
                "count" => $unit_count
            ];
        }

        return [
            "coins" => $coins,
            "resources" => $resources,
            "troops" => $bonus_troops
        ];
    }

    public static function should_show_intro(int $user_id): bool
    {
        if ($user_id <= 0) return false;

        if (isset($_SESSION["tutorial_done"]) && (int)$_SESSION["tutorial_done"] === 0) {
            return false;
        }

        $db = Database::get_instance()->get_connection();

        $has_gifts = (bool)$db->execute_query("SELECT 1 FROM advent_calendar_gifts WHERE user_id = ? LIMIT 1", [$user_id])->fetch_column();
        if (!self::is_active() && !$has_gifts) return false;

        $seen = (int)($db->execute_query("SELECT advent_intro_seen FROM users WHERE id = ?", [$user_id])->fetch_column() ?? 1);
        return ($seen === 0);
    }

    public static function render_intro_modal(): string
    {
        $day = self::get_current_day();
        return "
        <div id='advent-overlay' class='info-box-bg' style='display:flex;'>
            <div class='big-box-container tutorial-box' style='max-width: 580px; margin: auto;'>
                <div class='big-box-header' style='color: var(--link-color);'>" . Messages::wrap_emojis("🎄 Das Fest der Reiche") . "</div>
                <div class='big-box-content' style='text-align: left; line-height: 1.6;'>
                    <div style='text-align: center; margin-bottom: 15px;'>
                        <img src='images/icons/icon_gift.png' class='advent-calendar-gift' alt='Geschenk'>
                    </div>
                    <p style='margin-top: 0; text-align: center;'><b>Willkommen zum Adventskalender!</b></p>
                    <p>In den nächsten 24 Tagen verbirgt sich jeden Tag ein geheimnisvolles Geschenk auf der Weltkarte – exklusiv für dein Königreich!</p>
                    <ul style='padding-left: 20px;'>
                        <li><b>Tägliche Münzen:</b> Du erhältst jeden Tag Münzen entsprechend des Tages (Tag 1 = 1 Münze ... Tag 24 = 24 Münzen!).</li>
                        <li><b>Ressourcen & Truppen:</b> Jedes Geschenk birgt Rohstoffe und eine Chance auf zusätzliche Krieger.</li>
                        <li><b>Eilmärsche:</b> Entsende <b>einen Boten</b> (max. 1 Einheit), um das Geschenk zu bergen.</li>
                    </ul>
                    <div class='tutorial-tip'>
                        💡 <b>Tipp:</b> Heute ist <b>Tag $day</b>. Öffne die 
                        <a href='map.php' style='color: var(--link-color); text-decoration: underline;' data-on-click='closeAdventModal'><b>Karte</b></a> und sichere dir dein Geschenk!
                    </div>
                    <div style='text-align: center; margin-top: 20px;'>
                        <button type='button' data-on-click='closeAdventModal' style='padding: 10px 40px; font-weight: bold;'>Auf die Suche gehen!</button>
                    </div>
                </div>
            </div>
        </div>";
    }
}
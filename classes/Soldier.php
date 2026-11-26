<?php

class Soldier
{
    private int $soldier_id;
    private string $s_name;
    private string $s_desc;
    private int $s_attack;
    private int $s_defense;
    private int $s_food;
    private int $s_gold;
    private int $s_stone;
    private int $s_wood;
    private int $s_villager;
    private int $s_req_level;
    private int $s_req_time;
    private int $s_score_gain;
    private int $s_soldier_category;
    private string $s_soldier_icon;
    public const array COMMAND_ACTION_TYPES = [
        ActionTypes::ACTION_SEND_TROOPS,
        ActionTypes::ACTION_RETURN_TROOPS,
        ActionTypes::ACTION_STATION_TROOPS,
        ActionTypes::ACTION_SUPPORT_RETURN,
    ];

    public function get_soldier_id(): int
    {
        return $this->soldier_id;
    }

    public function set_soldier_id($id): void
    {
        $this->soldier_id = $id;
    }

    public function fill_from_row(array $row): void
    {
        $this->set_soldier_id($row["id"]);
        $this->set_soldier_name($row["soldiername"] ?? "");
        $this->set_soldier_category($row["category"] ?? 0);
        $this->set_soldier_attack($row["attack"] ?? 0);
        $this->set_soldier_defense($row["defense"] ?? 0);
        $this->set_soldier_icon($row["icon"] ?? "");

        if (isset($row["description"])) $this->set_soldier_description($row["description"]);
        if (isset($row["food"])) $this->set_soldier_food_cost($row["food"]);
        if (isset($row["gold"])) $this->set_soldier_gold_cost($row["gold"]);
        if (isset($row["stone"])) $this->set_soldier_stone_cost($row["stone"]);
        if (isset($row["wood"])) $this->set_soldier_wood_cost($row["wood"]);
        if (isset($row["villager"])) $this->set_soldier_villager_cost($row["villager"]);
        if (isset($row["requiredlevel"])) $this->set_soldier_required_level($row["requiredlevel"]);
        if (isset($row["requiredtime"])) $this->set_soldier_time($row["requiredtime"]);
        if (isset($row["scoregain"])) $this->set_soldier_score_gain($row["scoregain"]);
    }

    public function get_soldier_icon(string $class = "buildable-icons"): string
    {
        $icon_path = "images/icons/" . ($this->s_soldier_icon ?? "icon_error") . ".png";
        $disk_path = __DIR__ . "/../" . $icon_path;

        if (isset($this->soldier_id) && file_exists($disk_path)) {
            return "<img src='$icon_path' class='$class' alt='$this->s_name' title='$this->s_name'/>";
        } else {
            return "<img src='images/icons/icon_error.png' class='buildable-icons' alt='Fehler' title='Icon nicht vorhanden'/>";
        }
    }

    public function get_soldier_icon_name(): string
    {
        return $this->s_soldier_icon ?? "icon_error";
    }

    public function set_soldier_icon(string $icon): void
    {
        $this->s_soldier_icon = $icon;
    }

    public function get_soldier_category(): int
    {
        return $this->s_soldier_category;
    }

    public function set_soldier_category($category): void
    {
        $this->s_soldier_category = $category;
    }

    public function get_soldier_required_level(): int
    {
        return $this->s_req_level;
    }

    public function set_soldier_required_level($level): void
    {
        $this->s_req_level = $level;
    }

    public function get_soldier_time(): int
    {
        return $this->s_req_time;
    }

    public function set_soldier_time($time): void
    {
        $this->s_req_time = $time;
    }

    public function get_soldier_name(): string
    {
        return $this->s_name;
    }

    public function set_soldier_name($name): void
    {
        $this->s_name = $name;
    }

    public function get_soldier_food_cost(): int
    {
        return $this->s_food;
    }

    public function set_soldier_food_cost($food): void
    {
        $this->s_food = $food;
    }

    public function get_soldier_gold_cost(): int
    {
        return $this->s_gold;
    }

    public function set_soldier_gold_cost($gold): void
    {
        $this->s_gold = $gold;
    }

    public function get_soldier_stone_cost(): int
    {
        return $this->s_stone;
    }

    public function set_soldier_stone_cost($stone): void
    {
        $this->s_stone = $stone;
    }

    public function get_soldier_wood_cost(): int
    {
        return $this->s_wood;
    }

    public function set_soldier_wood_cost($wood): void
    {
        $this->s_wood = $wood;
    }

    public function get_soldier_villager_cost(): int
    {
        return $this->s_villager;
    }

    public function set_soldier_villager_cost($villager): void
    {
        $this->s_villager = $villager;
    }

    public function get_soldier_attack(): int
    {
        return $this->s_attack;
    }

    public function set_soldier_attack($attack): void
    {
        $this->s_attack = $attack;
    }

    public function get_soldier_defense(): int
    {
        return $this->s_defense;
    }

    public function set_soldier_defense($defense): void
    {
        $this->s_defense = $defense;
    }

    public function get_soldier_score_gain(): int
    {
        return $this->s_score_gain;
    }

    public function set_soldier_score_gain($score): void
    {
        $this->s_score_gain = $score;
    }

    public function get_soldier_description(): string
    {
        return $this->s_desc;
    }

    public function set_soldier_description($description): void
    {
        $this->s_desc = $description;
    }

    public static function build_owned_units_sql(array $options = []): array
    {
        $kingdom_id = $options["kingdom_id"] ?? null;
        $kingdom_ids = $options["kingdom_ids"] ?? null;
        $user_id = $options["user_id"] ?? null;
        $include_garrison = $options["include_garrison"] ?? true;
        $include_sent = $options["include_sent"] ?? true;
        $include_stationed_out = $options["include_stationed_out"] ?? true;
        $include_mines = $options["include_mines"] ?? true;
        $include_upgrading = $options["include_upgrading"] ?? false;
        $include_queue = $options["include_queue"] ?? false;
        $include_incoming_station = $options["include_incoming_station"] ?? false;
        $soldier_ids = array_values(array_unique(array_map("intval", $options["soldier_ids"] ?? [])));
        $exclude_soldier_ids = array_values(array_unique(array_map("intval", $options["exclude_soldier_ids"] ?? [])));

        if ($kingdom_ids !== null) {
            $kingdom_ids = array_values(array_unique(array_map("intval", $kingdom_ids)));

            if (empty($kingdom_ids)) {
                return ["", []];
            }
        }

        $branches = [];
        $params = [];

        $apply_filters = static function (array &$where, array &$p, string $k_col, string $s_col, ?string $u_col = null) use ($kingdom_id, $kingdom_ids, $user_id, $soldier_ids, $exclude_soldier_ids): void {
            if ($kingdom_id !== null) {
                $where[] = "$k_col = ?";
                $p[] = (int)$kingdom_id;
            } else if ($kingdom_ids !== null) {
                $ph = implode(',', array_fill(0, count($kingdom_ids), '?'));
                $where[] = "$k_col IN ($ph)";
                array_push($p, ...$kingdom_ids);
            }

            if ($user_id !== null) {
                if ($u_col !== null) {
                    $where[] = "$u_col = ?";
                    $p[] = (int)$user_id;
                } else if ($kingdom_id === null && $kingdom_ids === null) {
                    $where[] = "$k_col IN (SELECT id FROM kingdoms WHERE userid = ?)";
                    $p[] = (int)$user_id;
                }
            }

            if (!empty($soldier_ids)) {
                $ph = implode(',', array_fill(0, count($soldier_ids), '?'));
                $where[] = "$s_col IN ($ph)";
                array_push($p, ...$soldier_ids);
            }

            if (!empty($exclude_soldier_ids)) {
                $ph = implode(',', array_fill(0, count($exclude_soldier_ids), '?'));
                $where[] = "$s_col NOT IN ($ph)";
                array_push($p, ...$exclude_soldier_ids);
            }
        };

        if ($include_garrison) {
            $where = [];
            $apply_filters($where, $params, "s.kingdomid", "s.soldierid");
            $w_sql = !empty($where) ? (" WHERE " . implode(" AND ", $where)) : "";
            $branches[] = "SELECT s.kingdomid AS kingdom_id, s.soldierid AS soldierid, s.soldiercount AS cnt, 'garrison' AS source_type FROM soldiers s$w_sql";
        }

        if ($include_sent) {
            $where = [];
            $apply_filters($where, $params, "st.source_kingdom_id", "st.soldierid", "e.userid");
            $w_sql = !empty($where) ? (" WHERE " . implode(" AND ", $where)) : "";
            $branches[] = "SELECT st.source_kingdom_id AS kingdom_id, st.soldierid AS soldierid, st.soldiercount AS cnt, 'away' AS source_type FROM sent_troops st JOIN events e ON st.eventid = e.eventid$w_sql";
        }

        if ($include_stationed_out) {
            $where = [];
            $apply_filters($where, $params, "st.source_kingdom_id", "st.soldier_id", "st.owner_id");
            $w_sql = !empty($where) ? (" WHERE " . implode(" AND ", $where)) : "";
            $branches[] = "SELECT st.source_kingdom_id AS kingdom_id, st.soldier_id AS soldierid, st.soldiercount AS cnt, 'away' AS source_type FROM stationed_troops st$w_sql";
        }

        if ($include_mines) {
            $where = [];
            $apply_filters($where, $params, "mst.kingdom_id", "mst.soldier_id", "mst.user_id");
            $w_sql = !empty($where) ? (" WHERE " . implode(" AND ", $where)) : "";
            $branches[] = "SELECT mst.kingdom_id AS kingdom_id, mst.soldier_id AS soldierid, mst.soldiercount AS cnt, 'away' AS source_type FROM mine_stationed_troops mst$w_sql";
        }

        if ($include_upgrading) {
            $where = ["e.actionid = " . ActionTypes::ACTION_UPGRADE_TROOPS];
            $apply_filters($where, $params, "e.kingdomid", "e.buildingid", "e.userid");
            $branches[] = "SELECT e.kingdomid AS kingdom_id, e.buildingid AS soldierid, e.soldiergoal AS cnt, 'garrison' AS source_type FROM events e WHERE " . implode(" AND ", $where);
        }

        if ($include_queue) {
            $where = ["e.actionid = " . ActionTypes::ACTION_BUILD_TROOPS];
            $apply_filters($where, $params, "e.kingdomid", "e.soldierid", "e.userid");
            $branches[] = "SELECT e.kingdomid AS kingdom_id, e.soldierid AS soldierid, e.soldiergoal AS cnt, 'recruiting' AS source_type FROM events e WHERE " . implode(" AND ", $where);
        }

        if ($include_incoming_station) {
            $where = [
                "e.actionid = " . ActionTypes::ACTION_SEND_TROOPS,
                "e.kingdomid != e.targetid",
                "e.userid = k.userid",
            ];
            $apply_filters($where, $params, "e.targetid", "st.soldierid", "e.userid");
            $branches[] = "SELECT e.targetid AS kingdom_id, st.soldierid AS soldierid, st.soldiercount AS cnt, 
                            'incoming_station' AS source_type 
                            FROM events e 
                                JOIN sent_troops st ON e.eventid = st.eventid 
                                JOIN kingdoms k ON e.targetid = k.id 
                            WHERE " . implode(" AND ", $where);
        }

        if (empty($branches)) {
            return ["", []];
        }

        return [implode(" UNION ALL ", $branches), $params];
    }

    public static function get_kingdom_unit_counts(int $kingdom_id, array $options = []): array
    {
        if ($kingdom_id <= 0) {
            return [];
        }

        $options["kingdom_id"] = $kingdom_id;
        [$sql, $params] = self::build_owned_units_sql($options);
        if ($sql === "") {
            return [];
        }

        $db = Database::get_instance()->get_connection();
        $res = $db->execute_query("SELECT soldierid, SUM(cnt) AS total_owned FROM ($sql) t GROUP BY soldierid", $params);

        $counts = [];
        while ($row = $res->fetch_assoc()) {
            $counts[(int)$row["soldierid"]] = (int)$row["total_owned"];
        }
        return $counts;
    }

    public static function get_kingdom_troop_sum(int $kingdom_id, array $options = []): int
    {
        if ($kingdom_id <= 0) {
            return 0;
        }

        $options["kingdom_id"] = $kingdom_id;
        [$sql, $params] = self::build_owned_units_sql($options);
        if ($sql === "") {
            return 0;
        }

        $db = Database::get_instance()->get_connection();
        return (int)$db->execute_query("SELECT IFNULL(SUM(cnt), 0) FROM ($sql) t", $params)->fetch_column();
    }

    public static function get_support_load_sql(string $kingdom_ref = "?"): string
    {
        $station_action = ActionTypes::ACTION_STATION_TROOPS;
        return "(
            IFNULL((SELECT SUM(soldiercount) FROM stationed_troops WHERE target_kingdom_id = $kingdom_ref), 0) +
            IFNULL((SELECT SUM(st.soldiercount) FROM sent_troops st JOIN events e ON st.eventid = e.eventid WHERE e.targetid = $kingdom_ref 
            AND e.actionid = $station_action), 0)
        )";
    }

    public static function get_target_support_count(int $kingdom_id, bool $include_incoming = true): int
    {
        $db = Database::get_instance()->get_connection();

        if ($include_incoming) {
            $sql = "SELECT " . self::get_support_load_sql() . " AS total";
            return (int)$db->execute_query($sql, [$kingdom_id, $kingdom_id])->fetch_column();
        }

        return (int)$db->execute_query(
            "SELECT IFNULL(SUM(soldiercount), 0) FROM stationed_troops WHERE target_kingdom_id = ?",
            [$kingdom_id]
        )->fetch_column();
    }

    public static function get_kingdom_command_count(int $kingdom_id): int
    {
        $db = Database::get_instance()->get_connection();
        $actions = implode(',', self::COMMAND_ACTION_TYPES);

        $row = $db->execute_query("
            SELECT
                (SELECT COUNT(*) FROM events WHERE kingdomid = ? AND actionid IN ($actions)) AS active_events,
                (SELECT COUNT(DISTINCT mine_id) FROM mine_stationed_troops WHERE kingdom_id = ?) AS active_mines
        ", [$kingdom_id, $kingdom_id])->fetch_assoc();

        return (int)($row["active_events"] ?? 0) + (int)($row["active_mines"] ?? 0);
    }

    public static function get_user_command_counts(int $user_id): array
    {
        $db = Database::get_instance()->get_connection();
        $actions = implode(',', self::COMMAND_ACTION_TYPES);

        $res = $db->execute_query("
            SELECT
                k.id AS kingdomid,
                (IFNULL(e.cnt, 0) + IFNULL(m.cnt, 0)) AS total_commands
            FROM kingdoms k
            LEFT JOIN (
                SELECT kingdomid, COUNT(*) AS cnt
                FROM events
                WHERE userid = ? AND actionid IN ($actions)
                GROUP BY kingdomid
            ) e ON k.id = e.kingdomid
            LEFT JOIN (
                SELECT kingdom_id, COUNT(DISTINCT mine_id) AS cnt
                FROM mine_stationed_troops
                WHERE user_id = ?
                GROUP BY kingdom_id
            ) m ON k.id = m.kingdom_id
            WHERE k.userid = ?
        ", [$user_id, $user_id, $user_id]);

        $counts = [];
        while ($row = $res->fetch_assoc()) {
            $counts[(int)$row["kingdomid"]] = (int)$row["total_commands"];
        }
        return $counts;
    }

    public static function get_user_deployment_flags(int $user_id): array
    {
        $db = Database::get_instance()->get_connection();
        $actions = implode(',', self::COMMAND_ACTION_TYPES);

        $row = $db->execute_query("
            SELECT
                (SELECT 1 FROM events WHERE userid = ? AND actionid IN ($actions) LIMIT 1) AS has_marching,
                (SELECT 1 FROM mine_stationed_troops WHERE user_id = ? LIMIT 1) AS has_mines,
                (SELECT 1 FROM stationed_troops WHERE owner_id = ? LIMIT 1) AS has_support
        ", [$user_id, $user_id, $user_id])->fetch_assoc();

        return [
            "marching" => !empty($row["has_marching"]),
            "mining" => !empty($row["has_mines"]),
            "supporting" => !empty($row["has_support"]),
        ];
    }
}
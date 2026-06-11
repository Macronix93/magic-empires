<?php

class Kingdom
{
    private object $mysqli;
    private int $kingdom_id;
    private int $food;
    private int $max_food;
    private int $food_per_hour;
    private int $wood;
    private int $max_wood;
    private int $wood_per_hour;
    private int $stone;
    private int $max_stone;
    private int $stone_per_hour;
    private int $gold;
    private int $max_gold;
    private int $gold_per_hour;
    private int $villager;
    private int $max_villager;
    private int $villager_per_hour;
    private int $base_food_rate;
    private int $base_stone_rate;
    private int $base_gold_rate;
    private int $base_wood_rate;
    private int $building_id;
    private int $recruiting_id;
    private int $tech_id;
    private int $map_x;
    private int $map_y;
    private string $kingdom_owner;
    private int $kingdom_owner_id;
    private string $kingdom_name;
    private int $wall_hp;
    private int $alignment;

    public function __construct(object $db_conn, int $kingdom_id = -1)
    {
        $this->mysqli = $db_conn;

        if ($kingdom_id != -1) {
            $result = $this->mysqli->execute_query("SELECT * FROM kingdoms WHERE id = ?", [$kingdom_id]);

            /*if (!$row) {
                // If kingdom is invalid, search for a valid kingdom from the player
                $result = $this->mysqli->execute_query("SELECT * FROM kingdoms WHERE userid = ? ORDER BY RAND() LIMIT 1", [User::get_instance()->get_user_id()]);
                $row = $result->fetch_assoc();
                $this->mysqli->execute_query("UPDATE users SET mainkingdom = ? WHERE id = ?", [$row["id"], User::get_instance()->get_user_id()]);

                $_SESSION["kingdomid"] = $row["id"];
            }*/
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $this->kingdom_id = $row["id"];
                $this->kingdom_owner_id = $row["userid"];
                $this->kingdom_owner = $row["username"];
                $this->kingdom_name = $row["kingdomname"];
                $this->map_y = $row["mapy"];
                $this->map_x = $row["mapx"];
                $this->food = $row["food"];
                $this->max_food = $row["maxfood"];
                $this->food_per_hour = $row["foodperhour"];
                $this->wood = $row["wood"];
                $this->max_wood = $row["maxwood"];
                $this->wood_per_hour = $row["woodperhour"];
                $this->stone = $row["stone"];
                $this->max_stone = $row["maxstone"];
                $this->stone_per_hour = $row["stoneperhour"];
                $this->gold = $row["gold"];
                $this->max_gold = $row["maxgold"];
                $this->gold_per_hour = $row["goldperhour"];
                $this->villager = $row["villager"];
                $this->max_villager = $row["maxvillager"];
                $this->villager_per_hour = $row["villagerperhour"];
                $this->wall_hp = $row["wallhp"];
                $this->alignment = $row["alignment"];

                $this->base_food_rate = $row["base_food_rate"];
                $this->base_gold_rate = $row["base_gold_rate"];
                $this->base_stone_rate = $row["base_stone_rate"];
                $this->base_wood_rate = $row["base_wood_rate"];
            }
        }
    }

    // Function to create a new kingdom
    public function create_kingdom(int $user_id, string $user_name, bool $is_conquest = false, int $map_x = -1, int $map_y = -1): false|int
    {
        //return (!$row) ? false : $this->found_free_field($row["fieldtype"], $row["mapx"], $row["mapy"], $user_id, $user_name);

        if ($is_conquest) {
            $result = $this->mysqli->execute_query("SELECT fieldtype FROM map WHERE mapx = ? AND mapy = ?", [$map_x, $map_y]);
            $row = $result->fetch_assoc();

            return $this->found_free_field($row["fieldtype"], $map_x, $map_y, $user_id, $user_name);
        } else {
            // Select a random map entry and deny registration, if no map entry was found
            $result = $this->mysqli->execute_query("SELECT mapx, mapy, fieldtype FROM map WHERE kingdomid = -1 ORDER BY RAND() LIMIT 1");
            $row = $result->fetch_assoc();

            if (!$row) {
                return false;
            } else {
                return $this->found_free_field($row["fieldtype"], $row["mapx"], $row["mapy"], $user_id, $user_name);
            }
        }
    }

    public function found_free_field(int $field_type, int $rand_x, int $rand_y, int $user_id, string $user_name): int
    {
        // Get resource gain rates based on fieldtype
        $result = $this->mysqli->execute_query("SELECT foodrate, woodrate, stonerate, goldrate FROM fieldtypes WHERE fieldid = ?", [$field_type]);
        $row = $result->fetch_object();

        $food_rate = BASE_FOOD_GAIN * $row->foodrate;
        $wood_rate = BASE_WOOD_GAIN * $row->woodrate;
        $stone_rate = BASE_STONE_GAIN * $row->stonerate;
        $gold_rate = BASE_GOLD_GAIN * $row->goldrate;

        // Insert kingdom
        $placeholder = "Königreich";
        $query = "
                    INSERT INTO kingdoms (kingdomname, userid, username, mapx, mapy, food, maxfood, wood, maxwood, stone, maxstone, gold, maxgold, foodperhour, 
                                          woodperhour, stoneperhour, goldperhour, wallhp, base_food_rate, base_gold_rate, base_stone_rate, base_wood_rate) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id;
        ";
        $result_kingdom = $this->mysqli->execute_query($query, [$placeholder, $user_id, $user_name, $rand_x, $rand_y, STARTING_FOOD, STARTING_FOOD,
            STARTING_WOOD, STARTING_WOOD, STARTING_STONE, STARTING_STONE, STARTING_GOLD, STARTING_GOLD, $food_rate, $wood_rate, $stone_rate, $gold_rate, DEFAULT_WALL_HP,
            $food_rate, $gold_rate, $stone_rate, $wood_rate]);
        $insert_id = $result_kingdom->fetch_assoc()["id"];
        $kingdom_name = $placeholder . $insert_id;

        // Update kingdom name with insert id
        $this->mysqli->execute_query("UPDATE kingdoms SET kingdomname = ? WHERE id = ?", [$kingdom_name, $insert_id]);

        // Update map properties of x and y
        $this->mysqli->execute_query("UPDATE map SET kingdomid = ? WHERE mapx = ? AND mapy = ?", [$insert_id, $rand_x, $rand_y]);

        // Insert standard buildings for this kingdom
        $query = "
                    INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel)
                    SELECT ?, '0', 'Dorfzentrum', '1'
                    UNION ALL
                    SELECT ?, '3', 'Mauer', '1'
                    UNION ALL
                    SELECT ?, '9', 'Lager', '1'
        ";
        $this->mysqli->execute_query($query, [$insert_id, $insert_id, $insert_id]);

        $new_k = new Kingdom($this->mysqli, $insert_id);
        $new_k->recalculate_production();

        return $insert_id;
    }

    public function get_kingdom_name(): string
    {
        return $this->kingdom_name;
    }

    public function get_kingdom_id(): int
    {
        return $this->kingdom_id;
    }

    public function get_kingdom_owner_id(): int
    {
        return $this->kingdom_owner_id;
    }

    public function get_kingdom_owner_name(): string
    {
        return $this->kingdom_owner;
    }

    public function get_kingdom_map_x(): int
    {
        return $this->map_x;
    }

    public function get_kingdom_map_y(): int
    {
        return $this->map_y;
    }

    public function get_kingdom_buildings(int $kingdom_id): array
    {
        $kingdom_buildings = [];
        $result = $this->mysqli->execute_query("SELECT buildingid, buildingname FROM buildings WHERE kingdomid = ?", [$kingdom_id]);

        if ($result->num_rows != 0) {
            foreach ($result as $row) {
                $building_id = $row["buildingid"];

                $kingdom_buildings[] = [
                    'buildingid' => $building_id,
                    'buildingname' => $row["buildingname"],
                    'buildingfile' => get_building_file($building_id),
                ];
            }
        }

        return $kingdom_buildings;
    }

    function fetch_kingdom_building(int $kingdom_id, int $building_id): ?Building
    {
        $result = $this->mysqli->execute_query("SELECT buildingname, buildinglevel FROM buildings WHERE kingdomid = ? AND buildingid = ?",
            [$kingdom_id, $building_id]);
        $row = $result->fetch_assoc();

        if (!$row) {
            return null;
        }

        $building = new Building();
        $building->set_building_name($row['buildingname']);
        $building->set_building_level($row['buildinglevel']);
        $building->set_building_id($building_id);
        $building->set_building_kingdom_id($kingdom_id);

        return $building;
    }

    function fetch_kingdom_tech(int $kingdom_id, int $tech_id): ?Tech
    {
        $result = $this->mysqli->execute_query("SELECT techname, techlevel FROM techs WHERE kingdomid = ? AND techid = ?",
            [$kingdom_id, $tech_id]);
        $row = $result->fetch_assoc();

        if (!$row) {
            return null;
        }

        $tech = new Tech($this->mysqli);
        $tech->set_tech_name($row['techname']);
        $tech->set_tech_level((int)$row['techlevel']);
        $tech->set_tech_id($tech_id);
        $tech->set_tech_kingdom_id($kingdom_id);

        return $tech;
    }

    public function is_kingdom_recruiting(int $kingdom_id): bool
    {
        $result = $this->mysqli->execute_query("SELECT soldierid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1",
            [$kingdom_id, ActionTypes::ACTION_BUILD_TROOPS]);
        $row = $result->fetch_assoc();
        if ($row) {
            $this->recruiting_id = $row["soldierid"];
        }
        return $result->num_rows == 1;
    }

    public function get_kingdom_recruiting_id(): int
    {
        return $this->recruiting_id;
    }

    public function is_kingdom_building(int $kingdom_id): bool
    {
        $result = $this->mysqli->execute_query("SELECT buildingid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1",
            [$kingdom_id, ActionTypes::ACTION_BUILD_BUILDING]);
        $row = $result->fetch_assoc();
        if ($row) {
            $this->building_id = $row["buildingid"];
        }
        return $result->num_rows == 1;
    }

    public function get_kingdom_building_id(): int
    {
        return $this->building_id;
    }

    public function get_kingdom_building_level(int $building_id): int
    {
        $result = $this->mysqli->execute_query("SELECT buildinglevel FROM buildings WHERE kingdomid = ? AND buildingid = ?",
            [$this->kingdom_id, $building_id]);
        $row = $result->fetch_assoc();
        return $row ? $row["buildinglevel"] : 0;
    }

    public function is_kingdom_researching(int $kingdom_id): bool
    {
        $result = $this->mysqli->execute_query("SELECT buildingid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1",
            [$kingdom_id, ActionTypes::ACTION_RESEARCH_TECH]);
        $row = $result->fetch_assoc();
        if ($row) {
            $this->tech_id = $row["buildingid"];
        }
        return $result->num_rows == 1;
    }

    public function get_shrine_modifier(): float
    {
        $tech_level = $this->get_kingdom_tech_level(TechTypes::TECH_TYPE_ANCESTRAL_RITES);
        return SHRINE_BONUS_BASE + ($tech_level * SHRINE_TECH_STEP);
    }

    public function get_kingdom_research_id(): int
    {
        return $this->tech_id;
    }

    public function give_kingdom_wood(int $amount): void
    {
        if ($this->wood + $amount > $this->get_kingdom_max_wood()) {
            $amount = $this->get_kingdom_max_wood() - $this->get_kingdom_wood();
        }
        $this->wood += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET wood = wood + ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function get_kingdom_max_wood(): int
    {
        return $this->max_wood;
    }

    public function set_kingdom_max_wood(int $amount): void
    {
        $this->mysqli->execute_query("UPDATE kingdoms SET maxwood = ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function get_kingdom_wood(): int
    {
        return $this->wood;
    }

    public function set_kingdom_wood(int $kingdom_id, int $amount): void
    {
        $this->wood = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET wood = ? WHERE id = ?", [$this->wood, $kingdom_id]);
    }

    public function set_kingdom_wood_per_hour(int $amount): void
    {
        $this->mysqli->execute_query("UPDATE kingdoms SET woodperhour = ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function give_kingdom_food(int $amount): void
    {
        if ($this->food + $amount > $this->get_kingdom_max_food()) {
            $amount = $this->get_kingdom_max_food() - $this->get_kingdom_food();
        }
        $this->food += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET food = food + ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function get_kingdom_max_food(): int
    {
        return $this->max_food;
    }

    public function set_kingdom_max_food(int $amount): void
    {
        $this->mysqli->execute_query("UPDATE kingdoms SET maxfood = ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function get_kingdom_food(): int
    {
        return $this->food;
    }

    public function set_kingdom_food(int $kingdom_id, int $amount): void
    {
        $this->food = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET food = ? WHERE id = ?", [$this->food, $kingdom_id]);
    }

    public function set_kingdom_food_per_hour(int $amount): void
    {
        $this->mysqli->execute_query("UPDATE kingdoms SET foodperhour = ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function give_kingdom_stone(int $amount): void
    {
        if ($this->stone + $amount > $this->get_kingdom_max_stone()) {
            $amount = $this->get_kingdom_max_stone() - $this->get_kingdom_stone();
        }
        $this->stone += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET stone = stone + ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function get_kingdom_max_stone(): int
    {
        return $this->max_stone;
    }

    public function set_kingdom_max_stone(int $amount): void
    {
        $this->mysqli->execute_query("UPDATE kingdoms SET maxstone = ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function get_kingdom_stone(): int
    {
        return $this->stone;
    }

    public function set_kingdom_stone(int $kingdom_id, int $amount): void
    {
        $this->stone = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET stone = ? WHERE id = ?", [$this->stone, $kingdom_id]);
    }

    public function set_kingdom_stone_per_hour(int $amount): void
    {
        $this->mysqli->execute_query("UPDATE kingdoms SET stoneperhour = ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function give_kingdom_gold(int $amount): void
    {
        if ($this->gold + $amount > $this->get_kingdom_max_gold()) {
            $amount = $this->get_kingdom_max_gold() - $this->get_kingdom_gold();
        }
        $this->gold += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET gold = gold + ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function get_kingdom_max_gold(): int
    {
        return $this->max_gold;
    }

    public function set_kingdom_max_gold(int $amount): void
    {
        $this->mysqli->execute_query("UPDATE kingdoms SET maxgold = ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function get_kingdom_gold(): int
    {
        return $this->gold;
    }

    public function set_kingdom_gold(int $kingdom_id, int $amount): void
    {
        $this->gold = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET gold = ? WHERE id = ?", [$this->gold, $kingdom_id]);
    }

    public function set_kingdom_gold_per_hour(int $amount): void
    {
        $this->mysqli->execute_query("UPDATE kingdoms SET goldperhour = ? WHERE id = ?", [$amount, $this->kingdom_id]);
    }

    public function get_kingdom_food_per_hour(): int
    {
        return $this->food_per_hour;
    }

    public function get_kingdom_wood_per_hour(): int
    {
        return $this->wood_per_hour;
    }

    public function get_kingdom_stone_per_hour(): int
    {
        return $this->stone_per_hour;
    }

    public function get_kingdom_gold_per_hour(): int
    {
        return $this->gold_per_hour;
    }

    public function get_kingdom_villager(): int
    {
        return $this->villager;
    }

    public function get_kingdom_max_villager(): int
    {
        return $this->max_villager;
    }

    public function get_kingdom_villager_per_hour(): int
    {
        return $this->villager_per_hour;
    }

    public function get_wall_hp(): int
    {
        return $this->wall_hp;
    }

    public function set_wall_hp(int $wall_hp): void
    {
        $this->wall_hp = $wall_hp;
        $this->mysqli->execute_query("UPDATE kingdoms SET wallhp = ? WHERE id = ?", [$wall_hp, $this->kingdom_id]);
    }

    public function get_wall_max_hp(): int
    {
        return DEFAULT_WALL_HP * $this->get_kingdom_building_level(BuildingTypes::BUILDING_WALL)
            + $this->get_kingdom_tech_level(TechTypes::TECH_TYPE_WALL_HP_INC) * RESEARCH_WALL_HP_INC;
    }

    public function calculate_wall_defense(int $wall_hp, int $wall_level): int
    {
        if ($wall_hp <= 0 || $wall_level <= 0) return 0;

        $max_hp = $wall_level * DEFAULT_WALL_HP;
        $level_scale = pow(($wall_level - 1), WALL_DEFENSE_FACTOR);
        $max_scale = pow(MAX_BUILDING_LEVEL - 1, WALL_DEFENSE_FACTOR);
        $scaled_max_defense = MIN_WALL_DEFENSE + (MAX_WALL_DEFENSE - MIN_WALL_DEFENSE) * ($level_scale / $max_scale);

        $defense = floor(($wall_hp / $max_hp) * $scaled_max_defense);

        if ($this->alignment == AlignmentTypes::ALIGN_TRADE) {
            $defense *= (1 - SHRINE_MALUS_BASE);
        }

        return max(MIN_WALL_DEFENSE, (int)$defense);
    }

    public function get_active_boosts(?int $res_type = null): array
    {
        $boosts = [];
        $query = "SELECT resource_type, boost_amount, expires_at 
              FROM kingdom_boosts 
              WHERE kingdomid = ? AND expires_at > ?";
        $params = [$this->kingdom_id, time()];

        if ($res_type !== null) {
            $query .= " AND resource_type = ?";
            $params[] = $res_type;
        }

        $res = $this->mysqli->execute_query($query, $params);

        while ($row = $res->fetch_assoc()) {
            $boosts[(int)$row["resource_type"]] = [
                "amount" => (int)$row["boost_amount"],
                "expiry" => (int)$row["expires_at"]
            ];
        }

        return $boosts;
    }

    public function get_boost_expiry(int $res_type): int
    {
        $res = $this->mysqli->execute_query(
            "SELECT expires_at FROM kingdom_boosts WHERE kingdomid = ? AND resource_type = ?",
            [$this->kingdom_id, $res_type]
        );
        $row = $res->fetch_assoc();

        return ($row && $row["expires_at"] > time()) ? $row["expires_at"] : 0;
    }

    public function recalculate_production(): void
    {
        $tech_mod = $this->get_shrine_modifier();

        $f_per_hour = $this->base_food_rate;
        $w_per_hour = $this->base_wood_rate;
        $s_per_hour = $this->base_stone_rate;
        $g_per_hour = $this->base_gold_rate;

        // Alignment buffs/nerfs
        if ($this->alignment == AlignmentTypes::ALIGN_NATURE) {
            $f_per_hour *= (1 + $tech_mod);
            $w_per_hour *= (1 + $tech_mod);
        }
        if ($this->alignment == AlignmentTypes::ALIGN_NATURE) {
            $s_per_hour *= (1 - SHRINE_MALUS_BASE);
        }
        if ($this->alignment == AlignmentTypes::ALIGN_TRADE) {
            $g_per_hour *= (1 + $tech_mod);
        } else if ($this->alignment == AlignmentTypes::ALIGN_WAR) {
            $g_per_hour *= (1 - SHRINE_MALUS_BASE);
        }

        $this->mysqli->execute_query("
        UPDATE kingdoms 
        SET foodperhour = ?, woodperhour = ?, stoneperhour = ?, goldperhour = ? 
        WHERE id = ?",
            [(int)round($f_per_hour), (int)round($w_per_hour), (int)round($s_per_hour), (int)round($g_per_hour), $this->kingdom_id]
        );

        $this->food_per_hour = (int)round($f_per_hour);
        $this->wood_per_hour = (int)round($w_per_hour);
        $this->stone_per_hour = (int)round($s_per_hour);
        $this->gold_per_hour = (int)round($g_per_hour);
    }

    function fetch_all_kingdom_buildings(): array
    {
        $buildings = [];

        // Query to fetch buildings and dependencies
        $query = "
            SELECT b.*, GROUP_CONCAT(d.dependencyid) AS dependency_ids, GROUP_CONCAT(d.dependencylevel) AS dependency_levels, bl.buildinglevel 
            FROM buildinglist b 
            LEFT JOIN buildingdeps d ON b.id = d.buildingid 
            LEFT JOIN buildings bl ON bl.buildingid = b.id AND bl.kingdomid = ?
            GROUP BY b.id
        ";
        $result = $this->mysqli->execute_query($query, [$this->kingdom_id]);

        // Process each building and its dependencies
        foreach ($result as $row) {
            $building_id = $row["id"];

            // Check if building object already exists
            if (!isset($buildings[$building_id])) {
                $building = new Building();
                $buildings = $building->create_building($building, $row, $buildings, $building_id);
            }

            // Process dependencies if any exist
            if ($row["dependency_ids"] !== null && $row["dependency_ids"] !== "") {
                $dependency_ids = explode(',', $row["dependency_ids"]);
                $dependency_levels = explode(',', $row["dependency_levels"]);

                foreach ($dependency_ids as $index => $dependency_id) {
                    $buildings[$building_id]->add_building_dependency($dependency_id, $dependency_levels[$index]);
                }
            }
        }

        return $buildings;
    }

    public function get_kingdom_tech_level(int $tech_id): int
    {
        $result = $this->mysqli->execute_query("SELECT techlevel FROM techs WHERE kingdomid = ? AND techid = ?",
            [$this->kingdom_id, $tech_id]);
        $row = $result->fetch_assoc();
        return $row ? $row["techlevel"] : 0;
    }

    public function fetch_all_kingdom_techs(): array
    {
        $techs = [];

        // All techs + current level
        $query_techs = "
            SELECT t.*,
                   tl.techlevel 
            FROM techlist t
            LEFT JOIN techs tl ON tl.techid = t.id AND tl.kingdomid = ?
        ";
        $result_techs = $this->mysqli->execute_query($query_techs, [$this->kingdom_id]);

        foreach ($result_techs as $row) {
            $tech = new Tech($this->mysqli);
            $techs[$row["id"]] = $tech->create_tech($row);
        }

        // Building dependencies
        $query_building_deps = "SELECT * FROM techbuildingdeps";
        $result_building_deps = $this->mysqli->execute_query($query_building_deps);

        foreach ($result_building_deps as $row) {
            $tech_id = $row["techid"];

            if (!isset($techs[$tech_id])) {
                continue;
            }

            $techs[$tech_id]->add_tech_dependency(
                $row["dependencyid"], $row["dependencylevel"], -1, 0
            );
        }

        // Tech dependencies
        $query_tech_deps = "SELECT * FROM techdeps";
        $result_tech_deps = $this->mysqli->execute_query($query_tech_deps);

        foreach ($result_tech_deps as $row) {
            $tech_id = $row["techid"];

            if (!isset($techs[$tech_id])) {
                continue;
            }

            $techs[$tech_id]->add_tech_dependency(
                -1, 0, $row["dependencyid"], $row["dependencylevel"]
            );
        }

        return $techs;
    }

    public function modify_resource(int $resource_type, int $amount): void
    {
        match ($resource_type) {
            ResourceTypes::RESOURCE_TYPE_FOOD => $this->give_kingdom_food($amount),
            ResourceTypes::RESOURCE_TYPE_WOOD => $this->give_kingdom_wood($amount),
            ResourceTypes::RESOURCE_TYPE_STONE => $this->give_kingdom_stone($amount),
            ResourceTypes::RESOURCE_TYPE_GOLD => $this->give_kingdom_gold($amount),
            default => null
        };
    }

    public function set_kingdom_alignment(int $alignment): void
    {
        $this->alignment = $alignment;
    }

    public function get_kingdom_alignment(): int
    {
        return $this->alignment;
    }

    public function get_march_speed_multiplier(): float
    {
        $level = $this->get_kingdom_tech_level(TechTypes::TECH_TYPE_CARTOGRAPHY);
        return 1 / (1 + ($level * CARTOGRAPHY_SPEED_BONUS));
    }

    public function get_construction_time_multiplier(): float
    {
        $level = $this->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARCHITECTURE);
        return max(0.5, 1 - ($level * ARCHITECTURE_TIME_REDUCTION)); // Max 50% reduction
    }

    public function get_repair_cost_multiplier(): float
    {
        $level = $this->get_kingdom_tech_level(TechTypes::TECH_TYPE_MAINTENANCE);
        return max(0.3, 1 - ($level * MAINTENANCE_REPAIR_REDUCTION));
    }
}
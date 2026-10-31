<?php

class Tech
{
    private int $tech_id; // ID of the tech
    private int $kingdom_id; // ID of the kingdom that the tech is connected to
    private int $t_level; // Current level of the tech for the kingdom
    private int $t_time; // Time to research
    private float $t_mult; // Multiplier for cost?
    private string $t_name; // Name of the tech
    private int $t_score; // The score that is gained when tech was researched/upgraded
    private int $t_foodcost;
    private int $t_woodcost;
    private int $t_stonecost;
    private int $t_goldcost;
    private int $t_maxlevel;
    private string $t_description;
    private array $t_dependencies = [];
    private int $origin_building = BuildingTypes::BUILDING_UNIVERSITY;
    public const array DISPLAY_ORDER = [
        // University
        TechTypes::TECH_TYPE_FOOD_INC,
        TechTypes::TECH_TYPE_WOOD_INC,
        TechTypes::TECH_TYPE_STONE_INC,
        TechTypes::TECH_TYPE_GOLD_INC,
        TechTypes::TECH_TYPE_STORAGE_INC,
        TechTypes::TECH_TYPE_ARCHITECTURE,
        TechTypes::TECH_TYPE_SCHOLARSHIP,
        TechTypes::TECH_TYPE_BARRACKS_EXPANSION,
        TechTypes::TECH_TYPE_PLUNDER,
        TechTypes::TECH_TYPE_CARTOGRAPHY,
        TechTypes::TECH_TYPE_ARCANE_INTEL,
        TechTypes::TECH_TYPE_WALL_HP_INC,
        TechTypes::TECH_TYPE_MAINTENANCE,
        TechTypes::TECH_TYPE_ANCESTRAL_RITES,
        TechTypes::TECH_TYPE_IMPERIAL,

        // Smithy
        TechTypes::TECH_TYPE_BLADES,
        TechTypes::TECH_TYPE_SHIELDWALL,
        TechTypes::TECH_TYPE_LANCE_RIDING,
        TechTypes::TECH_TYPE_CUIRASS,
        TechTypes::TECH_TYPE_ARROWHEADS,
        TechTypes::TECH_TYPE_DOUBLET,
        TechTypes::TECH_TYPE_WEIGHT,
        TechTypes::TECH_TYPE_METALLURGY,
        TechTypes::TECH_TYPE_SIEGE
    ];

    public function get_tech_kingdom_id(): int
    {
        return $this->kingdom_id;
    }

    public function get_tech_id(): int
    {
        return $this->tech_id;
    }

    public function set_tech_id(int $id): void
    {
        $this->tech_id = $id;
    }

    public function get_tech_time(): int
    {
        return $this->t_time;
    }

    public function get_tech_mult(): float
    {
        return $this->t_mult;
    }

    public function get_tech_name(): string
    {
        return $this->t_name;
    }

    public function get_tech_score(): int
    {
        return $this->t_score;
    }

    public function get_tech_level(): int
    {
        return $this->t_level;
    }

    public function get_tech_max_level(): int
    {
        if ($this->tech_id === TechTypes::TECH_TYPE_IMPERIAL) {
            return max(0, GLOBAL_SETTLEMENT_MAX - BASE_SETTLEMENT_LIMIT);
        }
        return $this->t_maxlevel;
    }

    public function add_tech_dependency(int $dependency_id,
                                        int $dependency_level,
                                        int $tech_dependency_id,
                                        int $tech_dependency_level): void
    {
        $this->t_dependencies[] = [
            "dependencyid" => $dependency_id,
            "techdepid" => $tech_dependency_id,
            "dependencylevel" => $dependency_level,
            "techdeplevel" => $tech_dependency_level
        ];
    }

    public function get_tech_dependencies(): array
    {
        return $this->t_dependencies;
    }

    public function get_tech_description(): string
    {
        return $this->t_description;
    }

    function calculate_tech_cost(): array
    {
        $level = $this->t_level;
        $factor = $this->t_mult;

        $calc = function ($base) use ($factor, $level) {
            if ($base <= 0) return 0;

            return (int)round($base * pow($factor, $level));
        };

        return [
            "cost_wood" => $calc($this->t_woodcost),
            "cost_food" => $calc($this->t_foodcost),
            "cost_stone" => $calc($this->t_stonecost),
            "cost_gold" => $calc($this->t_goldcost),
        ];
    }

    public function get_tech_icon(string $class = "buildable-icons"): string
    {
        $icon_path = "images/icons/icon_tech$this->tech_id.png";

        if (isset($this->tech_id) && file_exists($icon_path)) {
            return "<img src='$icon_path' class='$class' alt='$this->t_name' title='$this->t_name'/>";
        } else {
            return "<img src='images/icons/icon_error.png' class='buildable-icons' alt='Fehler' title='Icon nicht vorhanden'/>";
        }
    }

    public function create_tech(array $row): Tech
    {
        $this->set_tech_id($row["id"]);
        $this->set_tech_name($row["techname"]);
        $this->set_tech_score($row["techscore"]);
        $this->set_tech_description($row["description"]);
        $this->set_tech_max_level($row["maxlevel"]);
        $this->set_tech_wood_cost($row["woodcost"]);
        $this->set_tech_food_cost($row["foodcost"]);
        $this->set_tech_stone_cost($row["stonecost"]);
        $this->set_tech_gold_cost($row["goldcost"]);
        $this->set_tech_mult($row["multiplicator"]);
        $this->set_tech_time($row["timetoresearch"]);
        $this->set_tech_level($row["techlevel"] ?? 0);
        $this->set_origin_building((int)($row["origin_building"] ?? BuildingTypes::BUILDING_UNIVERSITY));

        return $this;
    }

    public function set_tech_kingdom_id(int $id): void
    {
        $this->kingdom_id = $id;
    }

    public function set_tech_name(string $name): void
    {
        $this->t_name = $name;
    }

    public function set_tech_score(int $score): void
    {
        $this->t_score = $score;
    }

    public function set_tech_wood_cost(int $cost): void
    {
        $this->t_woodcost = $cost;
    }

    public function set_tech_food_cost(int $cost): void
    {
        $this->t_foodcost = $cost;
    }

    public function set_tech_stone_cost(int $cost): void
    {
        $this->t_stonecost = $cost;
    }

    public function set_tech_gold_cost(int $cost): void
    {
        $this->t_goldcost = $cost;
    }

    public function set_tech_mult(float $mult): void
    {
        $this->t_mult = $mult;
    }

    public function set_tech_time(int $time): void
    {
        $this->t_time = $time;
    }

    public function set_tech_level(int $level): void
    {
        $this->t_level = $level;
    }

    public function set_tech_max_level(int $max_level): void
    {
        $this->t_maxlevel = $max_level;
    }

    public function set_tech_description(string $description): void
    {
        $this->t_description = $description;
    }

    public static function get_required_university_level(int $tech_id, int $target_level): int
    {
        if ($target_level <= 0) {
            return 1;
        }

        return match ($tech_id) {
            TechTypes::TECH_TYPE_FOOD_INC,
            TechTypes::TECH_TYPE_WOOD_INC => (int)ceil($target_level / 2),
            TechTypes::TECH_TYPE_STONE_INC => match (true) {
                $target_level <= 2 => 2,
                default => min(10, $target_level),
            },
            TechTypes::TECH_TYPE_GOLD_INC => min(10, $target_level + 1),
            TechTypes::TECH_TYPE_CARTOGRAPHY,
            TechTypes::TECH_TYPE_ARCHITECTURE => match (true) {
                $target_level <= 3 => 3,
                default => min(10, $target_level),
            },
            TechTypes::TECH_TYPE_MAINTENANCE => min(10, (int)ceil($target_level / 2) + 3),
            TechTypes::TECH_TYPE_IMPERIAL => min(10, $target_level + 3),
            TechTypes::TECH_TYPE_STORAGE_INC,
            TechTypes::TECH_TYPE_PLUNDER => match (true) {
                $target_level <= 3 => 5,
                $target_level <= 5 => 6,
                $target_level <= 7 => 7,
                $target_level <= 8 => 8,
                default => 10,
            },
            TechTypes::TECH_TYPE_ARCANE_INTEL => min(10, $target_level + 5),
            TechTypes::TECH_TYPE_WALL_HP_INC => match (true) {
                $target_level <= 2 => 7,
                $target_level <= 5 => 8,
                $target_level <= 8 => 9,
                default => 10,
            },
            TechTypes::TECH_TYPE_ANCESTRAL_RITES => match ($target_level) {
                1 => 8,
                2 => 9,
                default => 10,
            },
            TechTypes::TECH_TYPE_BARRACKS_EXPANSION => match (true) {
                $target_level <= 2 => 8,
                $target_level <= 4 => 9,
                default => 10,
            },
            TechTypes::TECH_TYPE_SCHOLARSHIP => min(10, $target_level + 2),
            default => min(10, $target_level),
        };
    }

    public static function get_required_smithy_level(int $tech_id, int $target_level): int
    {
        if ($target_level <= 0) {
            return 1;
        }

        return match ($tech_id) {
            TechTypes::TECH_TYPE_ARROWHEADS => min(10, $target_level + 1),
            TechTypes::TECH_TYPE_SHIELDWALL,
            TechTypes::TECH_TYPE_WEIGHT,
            TechTypes::TECH_TYPE_METALLURGY => min(10, $target_level + 2),
            TechTypes::TECH_TYPE_LANCE_RIDING => min(10, $target_level + 3),
            TechTypes::TECH_TYPE_CUIRASS => min(10, $target_level + 6),
            TechTypes::TECH_TYPE_SIEGE => match ($target_level) {
                1, 2 => 9,
                default => 10,
            },

            default => min(10, $target_level),
        };
    }

    public function get_origin_building(): int
    {
        return $this->origin_building;
    }

    public function set_origin_building(int $origin_building): void
    {
        $this->origin_building = $origin_building;
    }

    public static function sort_tech_array(array &$techs): void
    {
        uksort($techs, function ($a, $b) {
            $posA = array_search((int)$a, self::DISPLAY_ORDER, true);
            $posB = array_search((int)$b, self::DISPLAY_ORDER, true);
            $posA = ($posA === false) ? 9999 : $posA;
            $posB = ($posB === false) ? 9999 : $posB;

            if ($posA === $posB) {
                return $a <=> $b;
            }

            return $posA <=> $posB;
        });
    }
}

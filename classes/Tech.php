<?php

class Tech
{
    private object $mysqli;
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

    public function __construct(object $db_conn)
    {
        $this->mysqli = $db_conn;
    }

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

    public function get_tech_max_level(): int
    {
        return $this->t_maxlevel;
    }

    function calculate_tech_cost(): array
    {
        $mult = $this->t_mult;
        $level = $this->t_level;

        $cost_wood = round($this->get_tech_cost(ResourceTypes::RESOURCE_TYPE_WOOD) + $this->get_tech_cost(ResourceTypes::RESOURCE_TYPE_WOOD) * $mult * $level);
        $cost_food = round($this->get_tech_cost(ResourceTypes::RESOURCE_TYPE_FOOD) + $this->get_tech_cost(ResourceTypes::RESOURCE_TYPE_FOOD) * $mult * $level);
        $cost_stone = round($this->get_tech_cost(ResourceTypes::RESOURCE_TYPE_STONE) + $this->get_tech_cost(ResourceTypes::RESOURCE_TYPE_STONE) * $mult * $level);
        $cost_gold = round($this->get_tech_cost(ResourceTypes::RESOURCE_TYPE_GOLD) + $this->get_tech_cost(ResourceTypes::RESOURCE_TYPE_GOLD) * $mult * $level);

        return array(
            "cost_wood" => $cost_wood,
            "cost_food" => $cost_food,
            "cost_stone" => $cost_stone,
            "cost_gold" => $cost_gold,
        );
    }

    public function get_tech_cost(int $type): int
    {
        return match ($type) {
            ResourceTypes::RESOURCE_TYPE_WOOD => $this->t_woodcost,
            ResourceTypes::RESOURCE_TYPE_FOOD => $this->t_foodcost,
            ResourceTypes::RESOURCE_TYPE_STONE => $this->t_stonecost,
            ResourceTypes::RESOURCE_TYPE_GOLD => $this->t_goldcost,
            default => 0,
        };
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
    
    public function is_researched(): bool
    {
        $query = "SELECT * FROM techs WHERE kingdomid = ? AND techid = ?";
        $result = $this->mysqli->execute_query($query, [$this->kingdom_id, $this->tech_id]);

        if ($result->num_rows > 0) {
            return true;
        } else {
            return false;
        }
    }

    public function create_tech(array $row): Tech
    {
        $this->set_tech_id($row["id"]);
        $this->set_tech_kingdom_id($_SESSION["kingdomid"]);
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
}

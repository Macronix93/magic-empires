<?php

class Building
{
    private int $building_id; // ID of the building
    private int $kingdom_id; // ID of the kingdom that the building is connected to
    private int $b_level; // Current level of the building for the kingdom
    private int $b_time; // Time to build
    private float $b_mult; // Multiplier for cost?
    private string $b_name; // Name of the building
    private int $b_score; // The score that is gained when building was built/upgraded
    private int $b_foodcost;
    private int $b_woodcost;
    private int $b_stonecost;
    private int $b_goldcost;
    private string $b_description;
    private array $b_dependencies = [];

    public function get_building_kingdom_id(): int
    {
        return $this->kingdom_id;
    }

    public function get_building_id(): int
    {
        return $this->building_id;
    }

    public function set_building_id(int $id): void
    {
        $this->building_id = $id;
    }

    public function get_building_time(): int
    {
        return $this->b_time;
    }

    public function get_building_mult(): float
    {
        return $this->b_mult;
    }

    public function get_building_name(): string
    {
        return $this->b_name;
    }

    public function get_building_score(): int
    {
        return $this->b_score;
    }

    public function get_building_level(): int
    {
        return $this->b_level;
    }

    public function add_building_dependency(int $dependency_id, int $dependency_level): void
    {
        $this->b_dependencies[] = [
            "dependencyid" => $dependency_id,
            "dependencylevel" => $dependency_level
        ];
    }

    public function get_building_dependencies(): array
    {
        return $this->b_dependencies;
    }

    public function get_building_description(): string
    {
        return $this->b_description;
    }

    function calculate_building_cost(): array
    {
        $level = $this->b_level;
        $factor = $this->b_mult;

        $calc = function ($base) use ($factor, $level) {
            if ($base <= 0) return 0;

            return round($base * pow($factor, $level));
        };

        return [
            "cost_wood" => $calc($this->b_woodcost),
            "cost_food" => $calc($this->b_foodcost),
            "cost_stone" => $calc($this->b_stonecost),
            "cost_gold" => $calc($this->b_goldcost),
        ];
    }

    public function get_building_cost(int $type): int
    {
        return match ($type) {
            ResourceTypes::RESOURCE_TYPE_WOOD => $this->b_woodcost,
            ResourceTypes::RESOURCE_TYPE_FOOD => $this->b_foodcost,
            ResourceTypes::RESOURCE_TYPE_STONE => $this->b_stonecost,
            ResourceTypes::RESOURCE_TYPE_GOLD => $this->b_goldcost,
            default => 0,
        };
    }

    public function get_building_icon(string $class = "buildable-icons"): string
    {
        $icon_path = "images/icons/icon_building$this->building_id.png";

        if (isset($this->building_id) && file_exists($icon_path)) {
            return "<img src='$icon_path' class='$class' alt='$this->b_name' title='$this->b_name'/>";
        } else {
            return "<img src='images/icons/icon_error.png' class='buildable-icons' alt='Fehler' title='Icon nicht vorhanden'/>";
        }
    }

    public function create_building(Building $building, array $row, array $buildings, mixed $building_id): array
    {
        $building->set_building_id($building_id);
        $building->set_building_kingdom_id($_SESSION["kingdomid"]);
        $building->set_building_name($row["buildingname"]);
        $building->set_building_score($row["buildingscore"]);
        $building->set_building_wood_cost($row["woodcost"]);
        $building->set_building_food_cost($row["foodcost"]);
        $building->set_building_stone_cost($row["stonecost"]);
        $building->set_building_gold_cost($row["goldcost"]);
        $building->set_building_mult($row["multiplicator"]);
        $building->set_building_time($row["timetobuild"]);
        $building->set_building_level($row["buildinglevel"] ?? 0);
        $building->set_building_description($row["description"]);

        $buildings[$building_id] = $building;
        return $buildings;
    }

    public function set_building_kingdom_id(int $id): void
    {
        $this->kingdom_id = $id;
    }

    public function set_building_name(string $name): void
    {
        $this->b_name = $name;
    }

    public function set_building_score(int $score): void
    {
        $this->b_score = $score;
    }

    public function set_building_wood_cost(int $cost): void
    {
        $this->b_woodcost = $cost;
    }

    public function set_building_food_cost(int $cost): void
    {
        $this->b_foodcost = $cost;
    }

    public function set_building_stone_cost(int $cost): void
    {
        $this->b_stonecost = $cost;
    }

    public function set_building_gold_cost(int $cost): void
    {
        $this->b_goldcost = $cost;
    }

    public function set_building_mult(float $mult): void
    {
        $this->b_mult = $mult;
    }

    public function set_building_time(int $time): void
    {
        $this->b_time = $time;
    }

    public function set_building_level(int $level): void
    {
        $this->b_level = $level;
    }

    public function set_building_description(string $description): void
    {
        $this->b_description = $description;
    }
}
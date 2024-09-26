<?php

class Building {
    private $mysqli;
    private int $bid; // ID of the building
    private int $kid; // ID of the kingdom that the building is connected to
    private int $blevel; // Current level of the building for the kingdom
    private int $btime; // Time to build
    private float $bmult; // Multiplier for cost?
    private string $bname; // Name of the building
    private int $bscore; // The score that is gained when building was built/upgraded
    private int $bfoodcost;
    private int $bwoodcost;
    private int $bstonecost;
    private int $bgoldcost;
    private array $bdependencies = [];

    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
    }

    public function get_building_kingdom_id(): int {
        return $this->kid;
    }

    public function set_building_kingdom_id($id): void {
        $this->kid = $id;
    }

    public function get_building_id(): int {
        return $this->bid;
    }

    public function set_building_id($id): void {
        $this->bid = $id;
    }

    public function get_building_time(): int {
        return $this->btime;
    }

    public function set_building_time($time): void {
        $this->btime = $time;
    }

    public function get_building_mult(): float {
        return $this->bmult;
    }

    public function set_building_mult($mult): void {
        $this->bmult = $mult;
    }

    public function get_building_name(): string {
        return $this->bname;
    }

    public function set_building_name($name): void {
        $this->bname = $name;
    }

    public function get_building_score(): int {
        return $this->bscore;
    }

    public function set_building_score($score): void {
        $this->bscore = $score;
    }

    public function get_building_level(): int {
        return $this->blevel;
    }

    public function set_building_level($level): void {
        $this->blevel = $level;
    }

    public function get_building_cost($type): int {
        return match ($type) {
            1 => $this->bwoodcost,
            2 => $this->bfoodcost,
            3 => $this->bstonecost,
            4 => $this->bgoldcost,
            default => 0,
        };
    }

    public function set_building_food_cost($cost): void {
        $this->bfoodcost = $cost;
    }

    public function set_building_wood_cost($cost): void {
        $this->bwoodcost = $cost;
    }

    public function set_building_stone_cost($cost): void {
        $this->bstonecost = $cost;
    }

    public function set_building_gold_cost($cost): void {
        $this->bgoldcost = $cost;
    }

    public function add_building_dependency($dependencyid, $dependencylevel): void {
        $this->bdependencies[] = [
            "dependencyid" => $dependencyid,
            "dependencylevel" => $dependencylevel
        ];
    }

    public function get_building_dependencies(): array {
        return $this->bdependencies;
    }

    function calculate_building_cost(): array {
        $mult = $this->bmult;
        $level = $this->blevel;

        $costWood = round($this->get_building_cost(BUILDING_COST_WOOD) + $this->get_building_cost(BUILDING_COST_WOOD) * $mult * $level);
        $costFood = round($this->get_building_cost(BUILDING_COST_FOOD) + $this->get_building_cost(BUILDING_COST_WOOD) * $mult * $level);
        $costStone = round($this->get_building_cost(BUILDING_COST_STONE) + $this->get_building_cost(BUILDING_COST_WOOD) * $mult * $level);
        $costGold = round($this->get_building_cost(BUILDING_COST_GOLD) + $this->get_building_cost(BUILDING_COST_WOOD) * $mult * $level);

        return array(
            "costWood" => $costWood,
            "costFood" => $costFood,
            "costStone" => $costStone,
            "costGold" => $costGold,
        );
    }

    public function get_building_icon(): string {
        if (isset($this->bid)) {
            return "<img src='images/icons/icon_building$this->bid.png' class='item-icons' alt='$this->bname' title='$this->bname'/>";
        } else {
            return "ERROR: ICON NOT FOUND";
        }
    }

    public function get_resource_text($cost, $currentVal): string {
        return ($cost > $currentVal ? "<b class='error'>" . fnum($cost) . "</b>" : fnum($cost));
    }

    public function is_built(): bool {
        $query = "SELECT * FROM buildings WHERE kingdomid = ? AND buildingid = ?";
        $result = $this->mysqli->execute_query($query, [$this->kid, $this->bid]);

        if ($result->num_rows > 0) {
            return true;
        } else {
            return false;
        }
    }

    public function create_building(Building $building, array $row, array $buildings, mixed $buildingID): array {
        $building->set_building_id($buildingID);
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

        $buildings[$buildingID] = $building;
        return $buildings;
    }
}
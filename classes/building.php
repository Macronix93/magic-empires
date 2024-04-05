<?php

class Building {
    private $mysqli;
    private int $bid; // ID of the building
    private int $kid; // ID of the kingdom that the building is connected to
    private int $blevel; // Current level of the building for the kingdom
    private int $breqlevel; // Required (towncenter) level for building
    private int $btime; // Time to build
    private float $bmult; // Multiplier for cost?
    private string $bname; // Name of the building
    private int $bscore; // The score that is gained when building was built
    private int $bfoodcost;
    private int $bwoodcost;
    private int $bstonecost;
    private int $bgoldcost;

    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
    }

    public function getBuildingKingdomID(): int {
        return $this->kid;
    }

    public function setBuildingKingdomID($id): void {
        $this->kid = $id;
    }

    public function getBuildingID(): int {
        return $this->bid;
    }

    public function setBuildingID($id): void {
        $this->bid = $id;
    }

    public function getBuildingRequiredLevel(): int {
        return $this->breqlevel;
    }

    public function setBuildingRequiredLevel($reqlevel): void {
        $this->breqlevel = $reqlevel;
    }

    public function getBuildingTime(): int {
        return $this->btime;
    }

    public function setBuildingTime($time): void {
        $this->btime = $time;
    }

    public function getBuildingMult(): float {
        return $this->bmult;
    }

    public function setBuildingMult($mult): void {
        $this->bmult = $mult;
    }

    public function getBuildingName(): string {
        return $this->bname;
    }

    public function setBuildingName($name): void {
        $this->bname = $name;
    }

    public function getBuildingScore(): int {
        return $this->bscore;
    }

    public function setBuildingScore($score): void {
        $this->bscore = $score;
    }

    public function getBuildingLevel(): int {
        return $this->blevel;
    }

    public function setBuildingLevel(): void {
        $level = 0;
        $stmt = $this->mysqli->prepare("SELECT buildinglevel FROM buildings WHERE kingdomid = ? AND buildingid = ?");
        $stmt->bind_param('ii', $this->kid, $this->bid);
        $stmt->execute();
        $stmt->bind_result($level);
        $stmt->fetch();
        $stmt->close();
        $this->blevel = $level;
    }

    public function getBuildingCost($type): int {
        $cost = 0;

        switch ($type) {
            case 1:
            { // Wood
                $cost = $this->bwoodcost;
                break;
            }
            case 2:
            { // Food
                $cost = $this->bfoodcost;
                break;
            }
            case 3:
            { // Stone
                $cost = $this->bstonecost;
                break;
            }
            case 4:
            { // Gold
                $cost = $this->bgoldcost;
                break;
            }
        }

        return $cost;
    }

    public function setBuildingFoodCost($cost): void {
        $this->bfoodcost = $cost;
    }

    public function setBuildingWoodCost($cost): void {
        $this->bwoodcost = $cost;
    }

    public function setBuildingStoneCost($cost): void {
        $this->bstonecost = $cost;
    }

    public function setBuildingGoldCost($cost): void {
        $this->bgoldcost = $cost;
    }

    function calculateBuildingCost(): array {
        $mult = $this->bmult;
        $level = $this->blevel;

        $costWood = round($this->getBuildingCost(BUILDING_COST_WOOD) + $this->getBuildingCost(BUILDING_COST_WOOD) * $mult * $level);
        $costFood = round($this->getBuildingCost(BUILDING_COST_FOOD) + $this->getBuildingCost(BUILDING_COST_WOOD) * $mult * $level);
        $costStone = round($this->getBuildingCost(BUILDING_COST_STONE) + $this->getBuildingCost(BUILDING_COST_WOOD) * $mult * $level);
        $costGold = round($this->getBuildingCost(BUILDING_COST_GOLD) + $this->getBuildingCost(BUILDING_COST_WOOD) * $mult * $level);

        return array(
            "costWood" => $costWood,
            "costFood" => $costFood,
            "costStone" => $costStone,
            "costGold" => $costGold,
        );
    }

    public function getBuildingIcon(): string {
        if (isset($this->bid)) {
            return "<img src='images/icons/icon_building$this->bid.png' class='item-icons' alt='{$this->bname}'/>";
        } else {
            return "ICON NOT FOUND";
        }
    }

    public function getRessourceText($cost, $currentVal): string {
        return ($cost > $currentVal ? "<b class='error'>" . $cost . "</b>" : $cost);
    }

    public function isBuilt(): bool {
        $stmt = $this->mysqli->prepare("SELECT * FROM buildings WHERE kingdomid = ? AND buildingid = ?");
        $stmt->bind_param("ii", $this->kid, $this->bid);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->close();
            return true;
        } else {
            $stmt->close();
            return false;
        }
    }
}
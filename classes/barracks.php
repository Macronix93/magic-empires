<?php

class Barracks {
    private $mysqli;
    private $soldiers = array(array());

    // Constructor
    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
        $this->getSoldierList();
    }

    public function getSoldierList() {
        $result = $this->mysqli->query("SELECT * FROM soldierlist");
        $this->soldiers = [];

        while ($row = $result->fetch_assoc()) {
            $this->soldiers[] = [
                "id" => $row["id"],
                "soldiername" => $row["soldiername"],
                "description" => $row["description"],
                "attack" => $row["attack"],
                "defense" => $row["defense"],
                "food" => $row["food"],
                "gold" => $row["gold"],
                "villager" => $row["villager"],
                "requiredlevel" => $row["requiredlevel"],
                "requiredtime" => $row["requiredtime"],
                "scoregain" => $row["scoregain"],
            ];
        }

        $result->close();

        return $this->soldiers;
    }

    public function getSoldierRequiredLevel($sid) {
        return $this->soldiers[$sid]["requiredlevel"];
    }

    public function getSoldierTime($sid) {
        return $this->soldiers[$sid]["requiredtime"];
    }

    public function getSoldierName($sid) {
        return $this->soldiers[$sid]["soldiername"];
    }

    public function getSoldierFoodCost($sid) {
        return $this->soldiers[$sid]["food"];
    }

    public function getSoldierGoldCost($sid) {
        return $this->soldiers[$sid]["gold"];
    }

    public function getSoldierVillagerCost($sid) {
        return $this->soldiers[$sid]["villager"];
    }

    public function getSoldierAttack($sid) {
        return $this->soldiers[$sid]["attack"];
    }

    public function getSoldierDefense($sid) {
        return $this->soldiers[$sid]["defense"];
    }

    public function getSoldierScoreGain($sid) {
        return $this->soldiers[$sid]["scoregain"];
    }

    public function getSoldierIcon($sid) {
        switch ($sid) {
            case 0: // Milizsoldat
                return " <img src='images/icons/icon_militia.png' class='ressource-icons' alt='Milizsoldat'>";
            case 1: // Schwertkämpfer
                return " <img src='images/icons/icon_swordsman.png' class='ressource-icons' alt='Schwertkämpfer'>";
            case 2: // Dieb
                return " <img src='images/icons/icon_thief.png' class='ressource-icons' alt='Dieb'>";
            case 3: // Eroberer
                return " <img src='images/icons/icon_conqueror.png' class='ressource-icons' alt='Eroberer'>";
        }
        return "ICON NOT FOUND";
    }

    public function getSoldierDescription($sid) {
        return $this->soldiers[$sid]["description"];
    }

    public function getSoldierCount() {
        return count($this->soldiers);
    }
}
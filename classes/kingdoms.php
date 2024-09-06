<?php

class Kingdoms {
    private $mysqli;
    public $id,
        $food,
        $maxfood,
        $foodperhour,
        $wood,
        $maxwood,
        $woodperhour,
        $stone,
        $maxstone,
        $stoneperhour,
        $gold,
        $maxgold,
        $goldperhour,
        $villager,
        $maxvillager,
        $villagerperhour,
        $buildingid,
        $recruitingid,
        $map_x,
        $map_y;

    // Constructor
    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
    }

    // Function to create a new kingdom
    public function createKingdom($userid, $username): false|int {
        // Count available fields with no kingdom on it
        $result = $this->mysqli->execute_query("SELECT COUNT(*) FROM map WHERE kingdomid = '-1'");
        $rows = $result->fetch_row();

        echo "DEBUG: " . $rows[0] . "<br>";

        // If there is no free row, cancel
        if ($rows[0] == 0) {
            echo "<p class='error'>Zurzeit gibt es keine freien Plätze auf der Karte!</p><br><br>";

            $this->mysqli->execute_query("DELETE FROM users WHERE id = ?", [$userid]);
            return false;
        } else { // We found a free row
            echo "DEBUG: free row gefunden<br>";

            // Choose random x and y positions on map
            $count = 0;

            while ($count < MAX_MAP_SEARCHES) {
                echo "DEBUG: iteration<br>";

                $randx = rand(1, 100);
                $randy = rand(1, 100);
                $result = $this->mysqli->execute_query("SELECT kingdomid, fieldtype FROM map WHERE mapx = ? AND mapy = ? LIMIT 1", [$randx, $randy]);
                $row = $result->fetch_assoc();

                if ($row["kingdomid"] == -1) {
                    // Field is empty, so we take it
                    return $this->foundFreeField($row["fieldtype"], $randx, $randy, $userid, $username);
                } else {
                    // Found a kingdom on that spot on the map, search again
                    $count++;
                }
            }

            // Just go to the next available slot if no free slot found at random
            $result = $this->mysqli->execute_query("SELECT mapx, mapy, fieldtype FROM map WHERE kingdomid = '-1' LIMIT 1");
            $row = $result->fetch_assoc();
            $mapx = $row["mapx"];
            $mapy = $row["mapy"];

            // Insert it into kingdoms table
            $insertid = $this->foundFreeField($row->fieldtype, $mapx, $mapy, $userid, $username);
        }

        return $insertid;
    }

    public function getKingdomInfo($kingdomid) {
        // Fetch kingdom data
        $query = "SELECT * FROM kingdoms WHERE id = ?";
        $result = $this->mysqli->execute_query($query, [$kingdomid]);
        $row = $result->fetch_assoc();
        $this->id = $row["id"];
        $this->map_x = $row["mapx"];
        $this->map_y = $row["mapy"];
        $this->food = $row["food"];
        $this->maxfood = $row["maxfood"];
        $this->foodperhour = $row["foodperhour"];
        $this->wood = $row["wood"];
        $this->maxwood = $row["maxwood"];
        $this->woodperhour = $row["woodperhour"];
        $this->stone = $row["stone"];
        $this->maxstone = $row["maxstone"];
        $this->stoneperhour = $row["stoneperhour"];
        $this->gold = $row["gold"];
        $this->maxgold = $row["maxgold"];
        $this->goldperhour = $row["goldperhour"];
        $this->villager = $row["villager"];
        $this->maxvillager = $row["maxvillager"];
        $this->villagerperhour = $row["villagerperhour"];

        return $this->id;
    }

    public function getIcon($buildingid, $bname): string {
        if (isset($buildingid)) {
            return "<img src='images/icons/icon_building$buildingid.png' class='menu-icons' alt='$bname'/>";
        } else {
            return "";
        }
    }

    public function getKingdomBuildings($kingdomid): void {
        $result = $this->mysqli->execute_query("SELECT buildingid, buildingname FROM buildings WHERE kingdomid = ?", [$kingdomid]);

        foreach ($result as $row) {
            $buildingid = $row["buildingid"];
            $bname = $row["buildingname"];

            echo "<div class='box" . (!isset($_GET["action"]) && isset($_GET["id"]) && $_GET["id"] == $buildingid ? ' active' : '') . "' onclick=\"navigateTo('buildings.php?id=$buildingid', this)\">" . $this->getIcon($buildingid, $bname) . " $bname</div>";
        }
    }

    public function isKingdomRecruiting($kingdomid): bool {
        $result = $this->mysqli->execute_query("SELECT soldierid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1", [$kingdomid, ACTION_BUILD_TROOPS]);
        $row = $result->fetch_assoc();
        if ($row) {
            $this->recruitingid = $row["soldierid"];
        }
        return $result->num_rows == 1;
    }

    public function getKingdomRecruitingID() {
        return $this->recruitingid;
    }

    public function isKingdomBuilding($kingdomid): bool {
        $result = $this->mysqli->execute_query("SELECT buildingid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1", [$kingdomid, ACTION_BUILD_BUILDING]);
        $row = $result->fetch_assoc();
        if ($row) {
            $this->buildingid = $row["buildingid"];
        }
        return $result->num_rows == 1;
    }

    public function getKingdomBuildingID() {
        return $this->buildingid;
    }

    public function getKingdomWood() {
        return $this->wood;
    }

    public function getKingdomMaxWood() {
        return $this->maxwood;
    }

    public function getKingdomWoodPerHour() {
        return $this->woodperhour;
    }

    public function giveKingdomWood($kingdomid, $amount): void {
        if ($this->wood + $amount > $this->getKingdomMaxWood()) {
            $amount = $this->getKingdomMaxWood() - $this->getKingdomWood();
        }
        $this->wood += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET wood = wood + ? WHERE id = ?", [$amount, $kingdomid]);
    }

    public function setKingdomWood($kingdomid, $amount): void {
        $this->wood = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET wood = ? WHERE id = ?", [$this->wood, $kingdomid]);
    }

    public function getKingdomFood() {
        return $this->food;
    }

    public function getKingdomMaxFood() {
        return $this->maxfood;
    }

    public function getKingdomFoodPerHour() {
        return $this->foodperhour;
    }

    public function giveKingdomFood($kingdomid, $amount): void {
        if ($this->food + $amount > $this->getKingdomMaxFood()) {
            $amount = $this->getKingdomMaxFood() - $this->getKingdomFood();
        }
        $this->food += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET food = food + ? WHERE id = ?", [$amount, $kingdomid]);
    }

    public function setKingdomFood($kingdomid, $amount) {
        $this->food = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET food = ? WHERE id = ?", [$this->food, $kingdomid]);
        return $this->food;
    }

    public function getKingdomStone() {
        return $this->stone;
    }

    public function getKingdomMaxStone() {
        return $this->maxstone;
    }

    public function getKingdomStonePerHour() {
        return $this->stoneperhour;
    }

    public function giveKingdomStone($kingdomid, $amount): void {
        if ($this->stone + $amount > $this->getKingdomMaxStone()) {
            $amount = $this->getKingdomMaxStone() - $this->getKingdomStone();
        }
        $this->stone += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET stone = stone + ? WHERE id = ?", [$this->stone, $kingdomid]);
    }

    public function setKingdomStone($kingdomid, $amount): void {
        $this->stone = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET stone = ? WHERE id = ?", [$this->stone, $kingdomid]);
    }

    public function getKingdomGold() {
        return $this->gold;
    }

    public function getKingdomMaxGold() {
        return $this->maxgold;
    }

    public function getKingdomGoldPerHour() {
        return $this->goldperhour;
    }

    public function giveKingdomGold($kingdomid, $amount): void {
        if ($this->gold + $amount > $this->getKingdomMaxGold()) {
            $amount = $this->getKingdomMaxGold() - $this->getKingdomGold();
        }
        $this->gold += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET gold = gold + ? WHERE id = ?", [$amount, $kingdomid]);
    }

    public function setKingdomGold($kingdomid, $amount) {
        $this->gold = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET gold = ? WHERE id = ?", [$this->gold, $kingdomid]);
        return $this->gold;
    }

    public function getKingdomVillager() {
        return $this->villager;
    }

    public function getKingdomMaxVillager() {
        return $this->maxvillager;
    }

    public function getKingdomVillagerPerHour() {
        return $this->villagerperhour;
    }

    public function foundFreeField($fieldtype, $randx, $randy, $userid, $username): int {
        $food = STARTING_FOOD;
        $wood = STARTING_WOOD;
        $stone = STARTING_STONE;
        $gold = STARTING_GOLD;

        // Get ressource gain rates based on fieldtype
        $result = $this->mysqli->execute_query("SELECT foodrate, woodrate, stonerate, goldrate FROM fieldtypes WHERE fieldid = ?", [$fieldtype]);
        $row = $result->fetch_object();

        $foodrate = BASE_FOOD_GAIN * $row->foodrate;
        $woodrate = BASE_WOOD_GAIN * $row->woodrate;
        $stonerate = BASE_STONE_GAIN * $row->stonerate;
        $goldrate = BASE_GOLD_GAIN * $row->goldrate;

        // Insert kingdom
        $kingdomname = "{$username}_Koenigreich_$userid";

        $query = "
                    INSERT INTO kingdoms (kingdomname, userid, username, mapx, mapy, food, wood, stone, gold, foodperhour, woodperhour, stoneperhour, goldperhour) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $this->mysqli->execute_query($query, [$kingdomname, $userid, $username, $randx, $randy, $food, $wood, $stone, $gold, $foodrate, $woodrate, $stonerate, $goldrate]);
        $insertid = $this->mysqli->insert_id;

        // Update map properties of x and y
        $this->mysqli->execute_query("UPDATE map SET kingdomid = ? WHERE mapx = ? AND mapy = ?", [$insertid, $randx, $randy]);

        // Insert standard buildings for this kingdom
        $query = "
                    INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel)
                    SELECT ?, '0', 'Dorfzentrum', '1'
                    UNION ALL
                    SELECT ?, '3', 'Mauer', '1'
                    UNION ALL
                    SELECT ?, '9', 'Lager', '1'
        ";
        $this->mysqli->execute_query($query, [$insertid, $insertid, $insertid]);
        return $insertid;
    }
}
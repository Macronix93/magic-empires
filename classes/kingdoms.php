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
        $recruitingid;

    // Constructor
    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
    }

    // Function to create a new kingdom
    public function createKingdom($userid, $username): false|int {
        $usernameVar = $username;

        // Count available fields with no kingdom on it
        $result = $this->mysqli->query("SELECT COUNT(*) FROM map WHERE kingdomid = '-1'");
        $rows = $result->fetch_row();
        $result->close();

        echo $rows[0];

        // If there is no free row, cancel
        if ($rows[0] == 0) {
            echo "<p class='error'>Zurzeit gibt es keine freien Plätze auf der Karte!</p><br><br>";

            $this->mysqli->query("DELETE FROM users WHERE id = '$userid'");

            return false;
        } else { // We found a free row
            echo "free row gefunden";

            // Choose random x and y positions on map
            $count = 0;

            while ($count < MAX_MAP_SEARCHES) {
                echo "iteration";

                $randx = rand(1, 100);
                $randy = rand(1, 100);

                $result = $this->mysqli->query("SELECT kingdomid, fieldtype FROM map WHERE mapx = '$randx' AND mapy = '$randy' LIMIT 1");
                $row = $result->fetch_object();
                $result->close();

                if ($row->kingdomid == -1) {
                    // Field is empty, so we take it
                    return $this->foundFreeField($row->fieldtype, $randx, $randy, $userid, $usernameVar);
                } else {
                    // Found a kingdom, search again
                    $count++;
                }
            }

            // Just go to the next available slot if no free slot found at random
            $result = $this->mysqli->query("SELECT mapx, mapy, fieldtype FROM map WHERE kingdomid = '-1' LIMIT 1");
            $row = $result->fetch_object();
            $mapx = $row->mapx;
            $mapy = $row->mapy;
            $result->free();

            // Insert it into kingdoms table
            $insertid = $this->foundFreeField($row->fieldtype, $mapx, $mapy, $userid, $usernameVar);
        }

        return $insertid;
    }

    public function getKingdomRessources($kingdomid) {
        // Fetch kingdom data
        $stmt = $this->mysqli->prepare("SELECT id,food,maxfood,foodperhour,wood,maxwood,woodperhour,stone,maxstone,stoneperhour,gold,maxgold,goldperhour,villager,maxvillager,villagerperhour FROM kingdoms WHERE id = ?");
        $stmt->bind_param('i', $kingdomid);
        $stmt->execute();
        $stmt->bind_result($this->id, $this->food, $this->maxfood, $this->foodperhour, $this->wood, $this->maxwood, $this->woodperhour, $this->stone,
            $this->maxstone, $this->stoneperhour, $this->gold, $this->maxgold, $this->goldperhour, $this->villager, $this->maxvillager, $this->villagerperhour);
        $stmt->fetch();
        $stmt->close();

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
        $stmt = $this->mysqli->prepare("SELECT buildingid, buildingname FROM buildings WHERE kingdomid = ?");
        $stmt->bind_param('i', $kingdomid);
        $stmt->execute();
        $buildingid = -1;
        $bname = "";
        $stmt->bind_result($buildingid, $bname);

        while ($stmt->fetch()) {
            echo "<div class='box" . (!isset($_GET["action"]) && isset($_GET["bid"]) && $_GET["bid"] == $buildingid ? ' active' : '') . "' onclick=\"navigateTo('buildings.php?bid=$buildingid', this)\">" . $this->getIcon($buildingid, $bname) . " $bname</div>";
        }

        $stmt->close();
    }

    public function isKingdomRecruiting($kingdomid): bool {
        $stmt = $this->mysqli->prepare("SELECT soldierid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1");
        $actionid = ACTION_BUILD_TROOPS;
        $stmt->bind_param('ii', $kingdomid, $actionid);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($this->recruitingid);
        $stmt->fetch();
        $found = $stmt->num_rows == 1;
        $stmt->close();

        return $found;
    }

    public function getKingdomRecruitingID() {
        return $this->recruitingid;
    }

    public function isKingdomBuilding($kingdomid): bool {
        $stmt = $this->mysqli->prepare("SELECT buildingid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1");
        $actionid = ACTION_BUILD_BUILDING;
        $stmt->bind_param('ii', $kingdomid, $actionid);
        $stmt->execute();
        $stmt->store_result();
        $stmt->bind_result($this->buildingid);
        $stmt->fetch();
        $found = $stmt->num_rows == 1;
        $stmt->close();

        return $found;
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

    public function setKingdomWood($kingdomid, $amount): void {
        $this->wood = $amount;
        $this->mysqli->query("UPDATE kingdoms SET wood = '$this->wood' WHERE id = '$kingdomid';");
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

    public function setKingdomFood($kingdomid, $amount) {
        $this->food = $amount;
        $this->mysqli->query("UPDATE kingdoms SET food = '$this->food' WHERE id = '$kingdomid';");
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

    public function setKingdomStone($kingdomid, $amount): void {
        $this->stone = $amount;
        $this->mysqli->query("UPDATE kingdoms SET stone = '$this->stone' WHERE id = '$kingdomid';");
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

    public function setKingdomGold($kingdomid, $amount) {
        $this->gold = $amount;
        $this->mysqli->query("UPDATE kingdoms SET gold = '$this->gold' WHERE id = '$kingdomid';");
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
        $result = $this->mysqli->query("SELECT foodrate, woodrate, stonerate, goldrate FROM fieldtypes WHERE fieldid = $fieldtype");
        $row = $result->fetch_object();
        $foodrate = STARTING_GAIN * $row->foodrate;
        $woodrate = STARTING_GAIN * $row->woodrate;
        $stonerate = STARTING_GAIN * $row->stonerate;
        $goldrate = STARTING_GAIN * $row->goldrate;
        $result->free();

        // Insert kingdom
        $kingdomname = "{$username}_Koenigreich_$userid";
        $stmt = $this->mysqli->prepare("INSERT INTO kingdoms (kingdomname, userid, username, mapx, mapy, food, wood, stone, gold, foodperhour, woodperhour, stoneperhour, goldperhour) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sisiiiiiiiiii', $kingdomname, $userid, $username, $randx, $randy, $food, $wood, $stone, $gold, $foodrate, $woodrate, $stonerate, $goldrate);
        $stmt->execute();
        $insertid = $stmt->insert_id;
        $stmt->close();

        // Update map properties of x and y
        $this->mysqli->query("UPDATE map SET kingdomid = '$insertid' WHERE mapx = '$randx' AND mapy = '$randy';");

        // Insert standard buildings for this kingdom
        $this->mysqli->query("
            INSERT INTO buildings (kingdomid, buildingid, buildingname, buildinglevel)
            SELECT '$insertid', '0', 'Dorfzentrum', '1'
            UNION ALL
            SELECT '$insertid', '3', 'Mauer', '1'
            UNION ALL
            SELECT '$insertid', '9', 'Lager', '1'
        ");
        return $insertid;
    }
}
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
    public function create_kingdom($userid, $username): false|int {
        // Select a random map entry and deny registration, if no map entry was found
        $result = $this->mysqli->execute_query("SELECT mapx, mapy, fieldtype FROM map WHERE kingdomid = -1 ORDER BY RAND() LIMIT 1;");
        $row = $result->fetch_assoc();

        if (!$row) {
            return false;
        } else {
            return $this->found_free_field($row["fieldtype"], $row["mapx"], $row["mapy"], $userid, $username);
        }
    }

    public function get_kingdom_info($kingdomid) {
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

    public function get_icon($buildingid, $bname): string {
        if (isset($buildingid)) {
            return "<img src='images/icons/icon_building$buildingid.png' class='menu-icons' alt='$bname'/>";
        } else {
            return "";
        }
    }

    public function get_kingdom_buildings($kingdomid): void {
        $result = $this->mysqli->execute_query("SELECT buildingid, buildingname FROM buildings WHERE kingdomid = ?", [$kingdomid]);

        if ($result->num_rows === 0) {
            echo "Es wurden alle Gebäude gebaut.";
        } else {

            foreach ($result as $row) {
                $buildingid = $row["buildingid"];
                $bname = $row["buildingname"];

                echo "<div class='box" . (isset($_GET["id"]) && $_GET["id"] == $buildingid ? ' active' : '') . "' onclick=\"navigateTo('buildings.php?id=$buildingid', this)\">" . $this->get_icon($buildingid, $bname) . " $bname</div>";
            }
        }
    }

    public function is_kingdom_recruiting($kingdomid): bool {
        $result = $this->mysqli->execute_query("SELECT soldierid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1", [$kingdomid, ACTION_BUILD_TROOPS]);
        $row = $result->fetch_assoc();
        if ($row) {
            $this->recruitingid = $row["soldierid"];
        }
        return $result->num_rows == 1;
    }

    public function get_kingdom_recruiting_id() {
        return $this->recruitingid;
    }

    public function is_kingdom_building($kingdomid): bool {
        $result = $this->mysqli->execute_query("SELECT buildingid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1", [$kingdomid, ACTION_BUILD_BUILDING]);
        $row = $result->fetch_assoc();
        if ($row) {
            $this->buildingid = $row["buildingid"];
        }
        return $result->num_rows == 1;
    }

    public function get_kingdom_building_id() {
        return $this->buildingid;
    }

    public function get_kingdom_wood() {
        return $this->wood;
    }

    public function get_kingdom_max_wood() {
        return $this->maxwood;
    }

    public function get_kingdom_wood_per_hour() {
        return $this->woodperhour;
    }

    public function give_kingdom_wood($kingdomid, $amount): void {
        if ($this->wood + $amount > $this->get_kingdom_max_wood()) {
            $amount = $this->get_kingdom_max_wood() - $this->get_kingdom_wood();
        }
        $this->wood += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET wood = wood + ? WHERE id = ?", [$amount, $kingdomid]);
    }

    public function set_kingdom_wood($kingdomid, $amount): void {
        $this->wood = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET wood = ? WHERE id = ?", [$this->wood, $kingdomid]);
    }

    public function get_kingdom_food() {
        return $this->food;
    }

    public function get_kingdom_max_food() {
        return $this->maxfood;
    }

    public function get_kingdom_food_per_hour() {
        return $this->foodperhour;
    }

    public function give_kingdom_food($kingdomid, $amount): void {
        if ($this->food + $amount > $this->get_kingdom_max_food()) {
            $amount = $this->get_kingdom_max_food() - $this->get_kingdom_food();
        }
        $this->food += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET food = food + ? WHERE id = ?", [$amount, $kingdomid]);
    }

    public function set_kingdom_food($kingdomid, $amount) {
        $this->food = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET food = ? WHERE id = ?", [$this->food, $kingdomid]);
        return $this->food;
    }

    public function get_kingdom_stone() {
        return $this->stone;
    }

    public function get_kingdom_max_stone() {
        return $this->maxstone;
    }

    public function get_kingdom_stone_per_hour() {
        return $this->stoneperhour;
    }

    public function give_kingdom_stone($kingdomid, $amount): void {
        if ($this->stone + $amount > $this->get_kingdom_max_stone()) {
            $amount = $this->get_kingdom_max_stone() - $this->get_kingdom_stone();
        }
        $this->stone += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET stone = stone + ? WHERE id = ?", [$amount, $kingdomid]);
    }

    public function set_kingdom_stone($kingdomid, $amount): void {
        $this->stone = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET stone = ? WHERE id = ?", [$this->stone, $kingdomid]);
    }

    public function get_kingdom_gold() {
        return $this->gold;
    }

    public function get_kingdom_max_gold() {
        return $this->maxgold;
    }

    public function get_kingdom_gold_per_hour() {
        return $this->goldperhour;
    }

    public function give_kingdom_gold($kingdomid, $amount): void {
        if ($this->gold + $amount > $this->get_kingdom_max_gold()) {
            $amount = $this->get_kingdom_max_gold() - $this->get_kingdom_gold();
        }
        $this->gold += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET gold = gold + ? WHERE id = ?", [$amount, $kingdomid]);
    }

    public function set_kingdom_gold($kingdomid, $amount) {
        $this->gold = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET gold = ? WHERE id = ?", [$this->gold, $kingdomid]);
        return $this->gold;
    }

    public function get_kingdom_villager() {
        return $this->villager;
    }

    public function get_kingdom_max_villager() {
        return $this->maxvillager;
    }

    public function get_kingdom_villager_per_hour() {
        return $this->villagerperhour;
    }

    public function found_free_field($fieldtype, $randx, $randy, $userid, $username): int {
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
        $kingdomname = "Königreich_$username";

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

    public function render_kingdom_info(): void {
        echo "     <div class='split-content'>
                        <div><img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung' title='Nahrung'/>
                        <span class='" . ($this->get_kingdom_food() >= $this->get_kingdom_max_food() ? "over-limit" : "under-limit") . "'>" . fnum($this->get_kingdom_food()) . "</span></div>
                        (" . fnum($this->get_kingdom_food_per_hour()) . "/h)
                    </div>
                    <div class='split-content'>
                        <div><img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz' title='Holz'/>
                        <span class='" . ($this->get_kingdom_wood() >= $this->get_kingdom_max_wood() ? "over-limit" : "under-limit") . "'>" . fnum($this->get_kingdom_wood()) . "</span></div>
                        (" . fnum($this->get_kingdom_wood_per_hour()) . "/h)
                    </div>
                    <div class='split-content'>
                        <div><img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein' title='Stein'/>
                        <span class='" . ($this->get_kingdom_stone() >= $this->get_kingdom_max_stone() ? "over-limit" : "under-limit") . "'>" . fnum($this->get_kingdom_stone()) . "</span></div>
                        (" . fnum($this->get_kingdom_stone_per_hour()) . "/h)
                    </div>
                    <div class='split-content'>
                        <div><img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold' title='Gold'/>
                        <span class='" . ($this->get_kingdom_gold() >= $this->get_kingdom_max_gold() ? "over-limit" : "under-limit") . "'>" . fnum($this->get_kingdom_gold()) . "</span></div>
                        (" . fnum($this->get_kingdom_gold_per_hour()) . "/h)
                    </div>
                    <div class='split-content'>
                        <div><img src='images/icons/icon_villager.png' class='ressource-icons' alt='Dorfbewohner' title='Dorfbewohner'/>
                        <span class='" . ($this->get_kingdom_villager() >= $this->get_kingdom_max_villager() ? "over-limit" : "under-limit") . "'>" . fnum($this->get_kingdom_villager()) . "</span></div>
                        (" . fnum($this->get_kingdom_villager_per_hour()) . "/h)
                    </div>";
    }
}
<?php

class Kingdoms {
    private object $mysqli;
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
    private int $buildingid;
    private int $recruiting_id;

    // Constructor
    public function __construct(object $db_conn) {
        $this->mysqli = $db_conn;
    }

    // Function to create a new kingdom
    public function create_kingdom(int $user_id, string $user_name): false|int {
        // Select a random map entry and deny registration, if no map entry was found
        $result = $this->mysqli->execute_query("SELECT mapx, mapy, fieldtype FROM map WHERE kingdomid = -1 ORDER BY RAND() LIMIT 1;");
        $row = $result->fetch_assoc();

        if (!$row) {
            return false;
        } else {
            return $this->found_free_field($row["fieldtype"], $row["mapx"], $row["mapy"], $user_id, $user_name);
        }
    }

    public function get_kingdom_info(int $kingdom_id) {
        // Fetch kingdom data
        $query = "SELECT * FROM kingdoms WHERE id = ?";
        $result = $this->mysqli->execute_query($query, [$kingdom_id]);
        $row = $result->fetch_assoc();

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

        return $row["id"];
    }

    public function get_icon(int $building_id, string $b_name): string {
        return "<img src='images/icons/icon_building$building_id.png' class='menu-icons' alt='$b_name'/>";
    }

    public function get_kingdom_buildings(int $kingdom_id): void {
        $result = $this->mysqli->execute_query("SELECT buildingid, buildingname FROM buildings WHERE kingdomid = ?", [$kingdom_id]);

        if ($result->num_rows === 0) {
            echo "Es wurden alle Gebäude gebaut.";
        } else {

            foreach ($result as $row) {
                $building_id = $row["buildingid"];
                $b_name = $row["buildingname"];

                echo "<div class='box" . (isset($_GET["id"]) && $_GET["id"] == $building_id ? ' active' : '') . "' onclick=\"navigateTo('buildings.php?id=$building_id', this)\">" . $this->get_icon($building_id, $b_name) . " $b_name</div>";
            }
        }
    }

    public function is_kingdom_recruiting(int $kingdom_id): bool {
        $result = $this->mysqli->execute_query("SELECT soldierid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1", [$kingdom_id, ACTION_BUILD_TROOPS]);
        $row = $result->fetch_assoc();
        if ($row) {
            $this->recruiting_id = $row["soldierid"];
        }
        return $result->num_rows == 1;
    }

    public function get_kingdom_recruiting_id(): int {
        return $this->recruiting_id;
    }

    public function is_kingdom_building(int $kingdom_id): bool {
        $result = $this->mysqli->execute_query("SELECT buildingid FROM events WHERE kingdomid = ? AND actionid = ? LIMIT 1", [$kingdom_id, ACTION_BUILD_BUILDING]);
        $row = $result->fetch_assoc();
        if ($row) {
            $this->buildingid = $row["buildingid"];
        }
        return $result->num_rows == 1;
    }

    public function get_kingdom_building_id(): int {
        return $this->buildingid;
    }

    public function get_kingdom_wood(): int {
        return $this->wood;
    }

    public function get_kingdom_max_wood(): int {
        return $this->max_wood;
    }

    public function get_kingdom_wood_per_hour(): int {
        return $this->wood_per_hour;
    }

    public function give_kingdom_wood(int $kingdom_id, int $amount): void {
        if ($this->wood + $amount > $this->get_kingdom_max_wood()) {
            $amount = $this->get_kingdom_max_wood() - $this->get_kingdom_wood();
        }
        $this->wood += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET wood = wood + ? WHERE id = ?", [$amount, $kingdom_id]);
    }

    public function set_kingdom_wood(int $kingdom_id, int $amount): void {
        $this->wood = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET wood = ? WHERE id = ?", [$this->wood, $kingdom_id]);
    }

    public function get_kingdom_food(): int {
        return $this->food;
    }

    public function get_kingdom_max_food(): int {
        return $this->max_food;
    }

    public function get_kingdom_food_per_hour(): int {
        return $this->food_per_hour;
    }

    public function give_kingdom_food(int $kingdom_id, int $amount): void {
        if ($this->food + $amount > $this->get_kingdom_max_food()) {
            $amount = $this->get_kingdom_max_food() - $this->get_kingdom_food();
        }
        $this->food += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET food = food + ? WHERE id = ?", [$amount, $kingdom_id]);
    }

    public function set_kingdom_food(int $kingdom_id, int $amount): void {
        $this->food = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET food = ? WHERE id = ?", [$this->food, $kingdom_id]);
    }

    public function get_kingdom_stone(): int {
        return $this->stone;
    }

    public function get_kingdom_max_stone(): int {
        return $this->max_stone;
    }

    public function get_kingdom_stone_per_hour(): int {
        return $this->stone_per_hour;
    }

    public function give_kingdom_stone(int $kingdom_id, int $amount): void {
        if ($this->stone + $amount > $this->get_kingdom_max_stone()) {
            $amount = $this->get_kingdom_max_stone() - $this->get_kingdom_stone();
        }
        $this->stone += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET stone = stone + ? WHERE id = ?", [$amount, $kingdom_id]);
    }

    public function set_kingdom_stone(int $kingdom_id, int $amount): void {
        $this->stone = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET stone = ? WHERE id = ?", [$this->stone, $kingdom_id]);
    }

    public function get_kingdom_gold(): int {
        return $this->gold;
    }

    public function get_kingdom_max_gold(): int {
        return $this->max_gold;
    }

    public function get_kingdom_gold_per_hour(): int {
        return $this->gold_per_hour;
    }

    public function give_kingdom_gold(int $kingdom_id, int $amount): void {
        if ($this->gold + $amount > $this->get_kingdom_max_gold()) {
            $amount = $this->get_kingdom_max_gold() - $this->get_kingdom_gold();
        }
        $this->gold += $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET gold = gold + ? WHERE id = ?", [$amount, $kingdom_id]);
    }

    public function set_kingdom_gold(int $kingdom_id, int $amount): void {
        $this->gold = $amount;
        $this->mysqli->execute_query("UPDATE kingdoms SET gold = ? WHERE id = ?", [$this->gold, $kingdom_id]);
    }

    public function get_kingdom_villager(): int {
        return $this->villager;
    }

    public function get_kingdom_max_villager(): int {
        return $this->max_villager;
    }

    public function get_kingdom_villager_per_hour(): int {
        return $this->villager_per_hour;
    }

    public function found_free_field(int $field_type, int $rand_x, int $rand_y, int $user_id, string $user_name): int {
        $food = STARTING_FOOD;
        $wood = STARTING_WOOD;
        $stone = STARTING_STONE;
        $gold = STARTING_GOLD;

        // Get ressource gain rates based on fieldtype
        $result = $this->mysqli->execute_query("SELECT foodrate, woodrate, stonerate, goldrate FROM fieldtypes WHERE fieldid = ?", [$field_type]);
        $row = $result->fetch_object();

        $food_rate = BASE_FOOD_GAIN * $row->foodrate;
        $wood_rate = BASE_WOOD_GAIN * $row->woodrate;
        $stone_rate = BASE_STONE_GAIN * $row->stonerate;
        $gold_rate = BASE_GOLD_GAIN * $row->goldrate;

        // Insert kingdom
        $kingdom_name = "Königreich_$user_name";

        $query = "
                    INSERT INTO kingdoms (kingdomname, userid, username, mapx, mapy, food, wood, stone, gold, foodperhour, woodperhour, stoneperhour, goldperhour) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $this->mysqli->execute_query($query, [$kingdom_name, $user_id, $user_name, $rand_x, $rand_y, $food, $wood, $stone, $gold, $food_rate, $wood_rate, $stone_rate, $gold_rate]);
        $insert_id = $this->mysqli->insert_id;

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

        return $insert_id;
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
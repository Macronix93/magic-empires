<?php

class Map
{
    private object $mysqli;
    private User $user;
    private static array $path_cache = [];
    private static ?array $map_cache = null;
    private static array $origin_path_cache = [];


    // Constructor
    public function __construct(User $user)
    {
        $this->mysqli = Database::get_instance()->get_connection();
        $this->user = $user;
    }

    public function render_minimap(int $target_x, int $target_y, int $radius = 6): string
    {
        $view_size = ($radius * 2) + 1;

        $min_x = $target_x - $radius;
        $max_x = $target_x + $radius;
        $min_y = $target_y - $radius;
        $max_y = $target_y + $radius;

        if ($min_x < 1) {
            $min_x = 1;
            $max_x = min(MAX_X, $view_size);
        }
        if ($max_x > MAX_X) {
            $max_x = MAX_X;
            $min_x = max(1, MAX_X - $view_size + 1);
        }

        if ($min_y < 1) {
            $min_y = 1;
            $max_y = min(MAX_Y, $view_size);
        }
        if ($max_y > MAX_Y) {
            $max_y = MAX_Y;
            $min_y = max(1, MAX_Y - $view_size + 1);
        }

        $query = "SELECT m.mapx, m.mapy, m.fieldtype, m.kingdomid, IFNULL(b.buildinglevel, 1) AS buildinglevel 
              FROM map m 
              LEFT JOIN buildings b ON m.kingdomid = b.kingdomid AND b.buildingid = ?
              WHERE m.mapx BETWEEN ? AND ? AND m.mapy BETWEEN ? AND ?
              ORDER BY m.mapy, m.mapx";

        $result = $this->mysqli->execute_query($query, [BuildingTypes::BUILDING_TOWNCENTER, $min_x, $max_x, $min_y, $max_y]);

        $tiles = [];
        foreach ($result as $row) {
            $tiles[$row["mapy"]][$row["mapx"]] = $row;
        }

        $num_cols = ($max_x - $min_x) + 1;

        $html = "<div class='minimap-container' style='grid-template-columns: 25px repeat($num_cols, 22px);'>";

        for ($y = $min_y; $y <= $max_y; $y++) {
            $html .= "<div class='minimap-label minimap-label-y'>$y</div>";

            for ($x = $min_x; $x <= $max_x; $x++) {
                $tile = $tiles[$y][$x] ?? null;

                if ($tile) {
                    $color = $this->get_field_type_color($tile["fieldtype"]);
                    $is_target = ($x == $target_x && $y == $target_y);
                    $kid = (int)$tile["kingdomid"];

                    $class = "minimap-tile";
                    if ($is_target) $class .= " minimap-target";

                    $content = "";
                    if ($kid > 0) {
                        $content = "🏰";
                    } else if ($kid === MapFieldTypes::MAP_FIELD_RESOURCE_TILE) {
                        $content = "💎";
                    } else if ($kid === MapFieldTypes::MAP_FIELD_MONSTER_CAMP) {
                        $content = "👹";
                    } else if ($kid === MapFieldTypes::MAP_FIELD_MINE) {
                        $content = "⛏️";
                    } else if ($kid === MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM) {
                        $content = "🏚️";
                    } else if ($kid === MapFieldTypes::MAP_FIELD_GIFT) {
                        $content = "🎁";
                    }

                    $html .= "<div class='" . e($class) . "' style='background-color: " . e($color) . ";'>" . e($content) . "</div>";
                } else {
                    $html .= "<div class='minimap-tile empty'></div>";
                }
            }
        }

        $html .= "<div class='minimap-label minimap-origin'>Y<br>X</div>";
        for ($x = $min_x; $x <= $max_x; $x++) {
            $html .= "<div class='minimap-label minimap-label-x'>$x</div>";
        }

        $html .= "</div>";
        return $html;
    }

    public function get_field_type_color(int $field_type): string
    {
        $is_winter = defined("WINTER_THEME_ACTIVE") && WINTER_THEME_ACTIVE;

        if ($is_winter) {
            return match ($field_type) {
                1 => "#5d738a", // Gebirge
                2 => "#1e5b82", // Küste
                3 => "#1b4337", // Wald
                4 => "#b5a895", // Wüste
                default => "#d0e0eb", // Hochland
            };
        }

        return match ($field_type) {
            1 => "#576574",
            2 => "#0984e3",
            3 => "#166733",
            4 => "#dca34b",
            default => "#78a55a",
        };
    }

    public function render_field_info(): void
    {
        echo '<div id="map-info-content"></div>';
    }

    public function get_arrival_time(int  $start_x,
                                     int  $start_y,
                                     int  $end_x,
                                     int  $end_y,
                                     int  $origin_kingdom_id = -1,
                                     ?int $target_id = null,
                                     bool $is_scouting = false,
                                     bool $is_caravan = false,
                                     bool $is_support = false,
                                     bool $is_ally_caravan = false): int
    {
        if (defined("DEBUG_FIXED_MARCH_SECONDS") && DEBUG_FIXED_MARCH_SECONDS > 0) {
            return DEBUG_FIXED_MARCH_SECONDS;
        }

        $actual_target_id = ($target_id !== null) ? $target_id : $this->get_field_kingdom_id($end_x, $end_y);

        $base_total_time = $this->get_base_travel_time($start_x, $start_y, $end_x, $end_y);
        if ($base_total_time === null) {
            return 999999;
        }

        $kid = ($origin_kingdom_id != -1) ? $origin_kingdom_id : $this->user->get_current_kingdom();
        $kingdom = new Kingdom($kid);
        $modified_time = $base_total_time * $kingdom->get_march_speed_multiplier();

        if ($is_support) {
            $g_sup_lvl = Guild::get_user_guild_tech_level($this->user->get_user_id(), GuildTechTypes::GUILD_TECH_SUPPORT_SPEED);
            $support_speed_mult = max(0.1, 1.0 - ($g_sup_lvl * GUILD_BONUS_SUPPORT_SPEED_PER_LVL));

            $modified_time *= (GUILD_SUPPORT_TRAVEL_BOOST * $support_speed_mult);
        } else if ($is_caravan) {
            $caravan_speed_mult = 1.0;

            if ($is_ally_caravan) {
                $g_trade_lvl = Guild::get_user_guild_tech_level($this->user->get_user_id(), GuildTechTypes::GUILD_TECH_ALLY_TRADE_SPEED);
                $caravan_speed_mult = max(0.1, 1.0 - ($g_trade_lvl * GUILD_BONUS_ALLY_TRADE_SPEED_PER_LVL));
            }

            $modified_time *= (CARAVAN_SPEED_FACTOR * $caravan_speed_mult);
        } else {
            if ($actual_target_id === MapFieldTypes::MAP_FIELD_GIFT) {
                $modified_time *= ADVENT_CALENDAR_TRAVEL_BOOST;
            } else if ($actual_target_id === MapFieldTypes::MAP_FIELD_WORLD_EVENT) {
                $we = new WorldEvent();
                $modified_time = $we->get_current_duration();
            } else if ($actual_target_id === MapFieldTypes::MAP_FIELD_MINE) {
                $boost = $is_scouting ? MONSTER_CAMP_SCOUT_BOOST : MINE_TRAVEL_BOOST;
                $modified_time *= $boost;
            } else if ($actual_target_id === MapFieldTypes::MAP_FIELD_MONSTER_CAMP || $actual_target_id === MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM) {
                $boost = $is_scouting ? MONSTER_CAMP_SCOUT_BOOST : MONSTER_CAMP_TRAVEL_BOOST;
                $modified_time *= $boost;
            } else if ($actual_target_id === MapFieldTypes::MAP_FIELD_RESOURCE_TILE && $is_scouting) {
                $modified_time *= MONSTER_CAMP_SCOUT_BOOST;
            } else if ($actual_target_id > 0 && $is_scouting) {
                $modified_time *= PLAYER_KINGDOM_SCOUT_BOOST;
            }
        }

        return (int)round($modified_time);
    }

    private function get_base_travel_time(int $start_x, int $start_y, int $end_x, int $end_y): ?int
    {
        if ($start_x === $end_x && $start_y === $end_y) {
            return 0;
        }

        $pair_key = "$start_x:$start_y:$end_x:$end_y";
        if (isset(self::$path_cache[$pair_key])) {
            return (int)self::$path_cache[$pair_key]["totaltime"];
        }

        $origin_key = "{$start_x}_$start_y";
        $cache_file = sys_get_temp_dir() . "/me_paths_from_$origin_key.json";

        if (!isset(self::$origin_path_cache[$origin_key])) {
            if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
                $decoded = json_decode((string)file_get_contents($cache_file), true);

                self::$origin_path_cache[$origin_key] = is_array($decoded) ? $decoded : [];
            } else {
                self::$origin_path_cache[$origin_key] = [];
            }
        }

        $target_key = "$end_x:$end_y";
        if (isset(self::$origin_path_cache[$origin_key][$target_key])) {
            return (int)self::$origin_path_cache[$origin_key][$target_key];
        }

        $result = $this->calculate_path($start_x, $start_y, $end_x, $end_y);
        if (empty($result) || !isset($result["totaltime"])) {
            return null;
        }

        $total_time = (int)$result["totaltime"];
        self::$origin_path_cache[$origin_key][$target_key] = $total_time;
        @file_put_contents($cache_file, json_encode(self::$origin_path_cache[$origin_key]), LOCK_EX);

        return $total_time;
    }

    public function calculate_path(int $start_x, int $start_y, int $end_x, int $end_y): array
    {
        if ($start_x === $end_x && $start_y === $end_y) {
            return ["path" => [["x" => $start_x, "y" => $start_y, "traversaltime" => 0]], "totaltime" => 0];
        }

        $cache_key = "$start_x:$start_y:$end_x:$end_y";
        if (isset(self::$path_cache[$cache_key])) {
            return self::$path_cache[$cache_key];
        }

        $map_data = $this->fetch_map_data();
        $costs = $map_data["costs"];
        $min_cost = $map_data["min_cost"];

        $start_node = ($start_x * 1000) + $start_y;
        $end_node = ($end_x * 1000) + $end_y;
        $h_start = (abs($start_x - $end_x) + abs($start_y - $end_y)) * $min_cost;

        $open_queue = new SplPriorityQueue();
        $open_queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $open_queue->insert($start_node, -$h_start);

        $g_scores = [$start_node => 0];
        $came_from = [];
        $closed = [];

        while (!$open_queue->isEmpty()) {
            $current_node = $open_queue->extract();
            if ($current_node === $end_node) {
                $curr = $current_node;
                $total_time = $g_scores[$end_node];
                $path = [];
                while (isset($came_from[$curr])) {
                    $cx = intdiv($curr, 1000);
                    $cy = $curr % 1000;
                    $path[] = ["x" => $cx, "y" => $cy, "traversaltime" => $costs[$curr] ?? 60];
                    $curr = $came_from[$curr];
                }
                $path[] = ["x" => $start_x, "y" => $start_y, "traversaltime" => 0];
                $res = ["path" => array_reverse($path), "totaltime" => $total_time];
                self::$path_cache[$cache_key] = $res;
                return $res;
            }
            if (isset($closed[$current_node])) {
                continue;
            }
            $closed[$current_node] = true;
            $cx = intdiv($current_node, 1000);
            $cy = $current_node % 1000;
            $current_g = $g_scores[$current_node];

            $neighbors = [];
            if ($cy < MAX_Y) $neighbors[] = [$cx, $cy + 1, $current_node + 1];
            if ($cy > 1) $neighbors[] = [$cx, $cy - 1, $current_node - 1];
            if ($cx < MAX_X) $neighbors[] = [$cx + 1, $cy, $current_node + 1000];
            if ($cx > 1) $neighbors[] = [$cx - 1, $cy, $current_node - 1000];

            foreach ($neighbors as [$nx, $ny, $n_node]) {
                if (isset($closed[$n_node])) {
                    continue;
                }
                $cost = $costs[$n_node] ?? 60;
                $tentative_g = $current_g + $cost;
                if (!isset($g_scores[$n_node]) || $tentative_g < $g_scores[$n_node]) {
                    $came_from[$n_node] = $current_node;
                    $g_scores[$n_node] = $tentative_g;
                    $h = (abs($nx - $end_x) + abs($ny - $end_y)) * $min_cost;
                    $f_score = $tentative_g + $h;
                    $open_queue->insert($n_node, -$f_score);
                }
            }
        }
        return [];
    }

    private function fetch_map_data(): array
    {
        if (self::$map_cache !== null) {
            return self::$map_cache;
        }

        $cache_file = sys_get_temp_dir() . "/me_world_traversals.json";
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
            $decoded = json_decode((string)file_get_contents($cache_file), true);
            if (is_array($decoded) && isset($decoded["costs"], $decoded["min_cost"])) {
                return self::$map_cache = $decoded;
            }
        }

        $query = "SELECT m.mapx, m.mapy, f.traversaltime FROM map m JOIN field_types f ON m.fieldtype = f.fieldid";
        $result = $this->mysqli->execute_query($query);
        $costs = [];
        $min_cost = null;

        foreach ($result as $row) {
            $t = (int)$row["traversaltime"];
            $node = ((int)$row["mapx"] * 1000) + (int)$row["mapy"];
            $costs[$node] = $t;
            if ($min_cost === null || $t < $min_cost) {
                $min_cost = $t;
            }
        }

        $data = [
            "costs" => $costs,
            "min_cost" => $min_cost ?? 40
        ];
        @file_put_contents($cache_file, json_encode($data), LOCK_EX);
        return self::$map_cache = $data;
    }

    public static function clear_cache(): void
    {
        self::$map_cache = null;
        self::$path_cache = [];
        self::$origin_path_cache = [];

        $cache_file = sys_get_temp_dir() . "/me_world_traversals.json";
        if (file_exists($cache_file)) {
            @unlink($cache_file);
        }

        $path_files = glob(sys_get_temp_dir() . "/me_paths_from_*.json");
        if (!empty($path_files)) {
            foreach ($path_files as $pf) {
                if (is_file($pf)) {
                    @unlink($pf);
                }
            }
        }
    }

    public function get_field_kingdom_id(int $map_x, int $map_y): int
    {
        $result = $this->mysqli->execute_query("SELECT kingdomid FROM map WHERE mapx = ? AND mapy = ?",
            [$map_x, $map_y]
        );
        $val = $result->fetch_column();
        return ($val !== false && $val !== null) ? (int)$val : MapFieldTypes::MAP_FIELD_EMPTY;
    }

    public function calculate_arrival_data(int $sx, int $sy, int $ex, int $ey, int $origin_id = -1, bool $is_caravan = false): array
    {
        $seconds = $this->get_arrival_time($sx, $sy, $ex, $ey, $origin_id, null, false, $is_caravan);
        return [
            "seconds" => $seconds,
            "timestamp" => time() + $seconds
        ];
    }

    public static function require_valid_coordinates(): array
    {
        $x = isset($_GET["x"]) ? get_int("x") : post_int("x");
        $y = isset($_GET["y"]) ? get_int("y") : post_int("y");

        if ($x < 1 || $x > MAX_X || $y < 1 || $y > MAX_Y) {
            echo show_error_box("Ungültige Koordinaten.");
            exit;
        }

        return [$x, $y];
    }
}
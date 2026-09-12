<?php

class Map
{
    private object $mysqli;
    private User $user;
    private static array $path_cache = [];

    // Constructor
    public function __construct(object $db_conn, User $user)
    {
        $this->mysqli = $db_conn;
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
              LEFT JOIN buildings b ON m.kingdomid = b.kingdomid AND b.buildingid = 0
              WHERE m.mapx BETWEEN ? AND ? AND m.mapy BETWEEN ? AND ?
              ORDER BY m.mapy, m.mapx";

        $result = $this->mysqli->execute_query($query, [$min_x, $max_x, $min_y, $max_y]);

        $tiles = [];
        foreach ($result as $row) {
            $tiles[$row["mapy"]][$row["mapx"]] = $row;
        }

        $num_cols = ($max_x - $min_x) + 1;

        $html = "<div class='minimap-container' style='grid-template-columns: 25px repeat($num_cols, 1fr);'>";

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
                    if ($is_target) {
                        $content = "⭐";
                    } elseif ($kid > 0) {
                        $content = "🏰";
                    } elseif ($kid === -2) {
                        $content = "💎";
                    } elseif ($kid === -3) {
                        $content = "👹";
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
                                     bool $is_support = false): int
    {
        $actual_target_id = ($target_id !== null) ? $target_id : $this->get_field_kingdom_id($end_x, $end_y);

        $result = $this->calculate_path($start_x, $start_y, $end_x, $end_y);

        if (empty($result) || !isset($result["totaltime"])) {
            return 999999;
        }

        $kid = ($origin_kingdom_id != -1) ? $origin_kingdom_id : $this->user->get_current_kingdom();
        $kingdom = new Kingdom($this->mysqli, $kid);

        $modified_time = $result["totaltime"] * $kingdom->get_march_speed_multiplier();

        if ($is_support) {
            $g_sup_lvl = Guild::get_user_guild_tech_level($this->user->get_user_id(), GuildTechTypes::GUILD_TECH_SUPPORT_SPEED);
            $support_speed_mult = max(0.1, 1.0 - ($g_sup_lvl * GUILD_BONUS_SUPPORT_SPEED_PER_LVL));

            $modified_time *= (GUILD_SUPPORT_TRAVEL_BOOST * $support_speed_mult);
        } else if ($is_caravan) {
            $g_trade_lvl = Guild::get_user_guild_tech_level($this->user->get_user_id(), GuildTechTypes::GUILD_TECH_ALLY_TRADE_SPEED);
            $caravan_speed_mult = max(0.1, 1.0 - ($g_trade_lvl * GUILD_BONUS_ALLY_TRADE_SPEED_PER_LVL));

            $modified_time *= (CARAVAN_SPEED_FACTOR * $caravan_speed_mult);
        } else {
            if ($actual_target_id === -3 || $actual_target_id === -4) {
                $boost = $is_scouting ? MONSTER_CAMP_SCOUT_BOOST : MONSTER_CAMP_TRAVEL_BOOST;
                $modified_time *= $boost;
            } else if ($actual_target_id === -2 && $is_scouting) {
                $modified_time *= MONSTER_CAMP_SCOUT_BOOST;
            } else if ($actual_target_id > 0 && $is_scouting) {
                $modified_time *= PLAYER_KINGDOM_SCOUT_BOOST;
            } else if ($actual_target_id === WORLD_EVENT_ID) {
                $we = new WorldEvent($this->mysqli);
                $modified_time = $we->get_current_duration();
            }
        }

        return (int)round($modified_time);
    }

    public function calculate_path(int $start_x, int $start_y, int $end_x, int $end_y): array
    {
        if ($start_x === $end_x && $start_y === $end_y) {
            return ["path" => [["x" => $start_x, "y" => $start_y, "traversaltime" => 0]], "totaltime" => 0];
        }

        $cache_key = ($start_x * 1000000) + ($start_y * 10000) + ($end_x * 100) + $end_y;
        $reverse_key = ($end_x * 1000000) + ($end_y * 10000) + ($start_x * 100) + $start_y;

        if (isset(self::$path_cache[$cache_key])) {
            return self::$path_cache[$cache_key];
        }
        if (isset(self::$path_cache[$reverse_key])) {
            return self::$path_cache[$reverse_key];
        }

        $map = $this->fetch_map_data();

        $start_node = ($start_x * 1000) + $start_y;
        $end_node = ($end_x * 1000) + $end_y;

        $h_start = (abs($start_x - $end_x) + abs($start_y - $end_y)) * 40;

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
                    $cx = (int)($curr / 1000);
                    $cy = $curr % 1000;
                    $path[] = ["x" => $cx, "y" => $cy, "traversaltime" => $map[$cx][$cy]["traversaltime"] ?? 60];
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

            $cx = (int)($current_node / 1000);
            $cy = $current_node % 1000;
            $current_g = $g_scores[$current_node];

            $neighbors = [
                (($cx) * 1000) + ($cy + 1),
                (($cx) * 1000) + ($cy - 1),
                (($cx + 1) * 1000) + ($cy),
                (($cx - 1) * 1000) + ($cy)
            ];

            foreach ($neighbors as $n_node) {
                if (isset($closed[$n_node])) {
                    continue;
                }

                $nx = (int)($n_node / 1000);
                $ny = $n_node % 1000;

                if ($nx < 1 || $nx > MAX_X || $ny < 1 || $ny > MAX_Y) {
                    continue;
                }

                $cost = $map[$nx][$ny]["traversaltime"] ?? 60;
                $tentative_g = $current_g + $cost;

                if (!isset($g_scores[$n_node]) || $tentative_g < $g_scores[$n_node]) {
                    $came_from[$n_node] = $current_node;
                    $g_scores[$n_node] = $tentative_g;

                    $h = (abs($nx - $end_x) + abs($ny - $end_y)) * 40;
                    $f_score = $tentative_g + $h;

                    $open_queue->insert($n_node, -$f_score);
                }
            }
        }

        return [];
    }

    private function fetch_map_data(): array
    {
        if (isset($_SESSION["cached_map_data"])) {
            return $_SESSION["cached_map_data"];
        }

        $query = "SELECT m.mapx, m.mapy, f.traversaltime FROM map m JOIN field_types f ON m.fieldtype = f.fieldid";
        $result = $this->mysqli->execute_query($query);
        $map = [];

        foreach ($result as $row) {
            $map[$row["mapx"]][$row["mapy"]] = ["traversaltime" => $row["traversaltime"]];
        }

        $_SESSION["cached_map_data"] = $map;
        return $map;
    }

    private function encode($node): string
    {
        return $node["x"] . ',' . $node["y"];
    }

    private function heuristic($a, $b): int
    {
        return abs($a["x"] - $b["x"]) + abs($a["y"] - $b["y"]);
    }

    private function decode($encoded): array
    {
        list($x, $y) = explode(',', $encoded);
        return ["x" => (int)$x, "y" => (int)$y];
    }

    private function reconstruct_path($came_from, $current, $map, $start_x, $start_y): array
    {
        $path = [$current];
        $total_time = 0;

        while (isset($came_from[$this->encode($current)])) {
            $current = $came_from[$this->encode($current)];
            $path[] = $current;
        }

        foreach ($path as &$coord) {
            if ($coord["x"] == $start_x && $coord["y"] == $start_y) {
                $coord["traversaltime"] = 0;
            } else {
                $coord["traversaltime"] = $map[$coord["x"]][$coord["y"]]["traversaltime"];
            }
            $total_time += $coord["traversaltime"];
        }

        $path = array_reverse($path);

        return ["path" => $path, "totaltime" => $total_time];
    }

    // Render and show the map
    private function get_neighbours($node, $map): array
    {
        $neighbors = [];
        $moves = [[0, 1], [1, 0], [0, -1], [-1, 0]];

        foreach ($moves as $move) {
            $x = $node["x"] + $move[0];
            $y = $node["y"] + $move[1];

            if (isset($map[$x][$y])) {
                $neighbors[] = ["x" => $x, "y" => $y];
            }
        }

        return $neighbors;
    }

    public function get_field_kingdom_id(int $map_x, int $map_y): int
    {
        $result = $this->mysqli->execute_query("SELECT kingdomid FROM map WHERE mapx = ? AND mapy = ?", [$map_x, $map_y]);
        return $result->fetch_column();
    }

    public function calculate_arrival_data(int $sx, int $sy, int $ex, int $ey, int $origin_id = -1, bool $is_caravan = false): array
    {
        $seconds = $this->get_arrival_time($sx, $sy, $ex, $ey, $origin_id, null, false, $is_caravan);
        return [
            "seconds" => $seconds,
            "timestamp" => time() + $seconds
        ];
    }
}
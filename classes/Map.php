<?php

class Map
{
    private object $mysqli;
    private User $user;

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

    public function get_arrival_time(int  $start_x, int $start_y, int $end_x, int $end_y, int $origin_kingdom_id = -1,
                                     ?int $target_id = null, bool $is_scouting = false, bool $is_caravan = false): int
    {
        $result = $this->calculate_path($start_x, $start_y, $end_x, $end_y);

        if (empty($result) || !isset($result["totaltime"])) {
            return 999999;
        }

        $kid = ($origin_kingdom_id != -1) ? $origin_kingdom_id : $this->user->get_current_kingdom();
        $kingdom = new Kingdom($this->mysqli, $kid);

        $modified_time = $result["totaltime"] * $kingdom->get_march_speed_multiplier();

        $actual_target_id = ($target_id !== null) ? $target_id : $this->get_field_kingdom_id($end_x, $end_y);

        if ($actual_target_id == WORLD_EVENT_ID) {
            return WORLD_EVENT_ATTACK_DURATION;
        }

        if ($actual_target_id === -3) {
            $boost = $is_scouting ? MONSTER_CAMP_SCOUT_BOOST : MONSTER_CAMP_TRAVEL_BOOST;
            $modified_time *= $boost;
        } else if ($actual_target_id === -2 && $is_scouting) {
            $modified_time *= MONSTER_CAMP_SCOUT_BOOST;
        }

        if ($is_caravan) {
            $modified_time *= CARAVAN_SPEED_FACTOR;
        }

        return (int)round($modified_time);
    }

    public function calculate_path(int $start_x, int $start_y, int $end_x, int $end_y): array
    {
        $start = ["x" => $start_x, "y" => $start_y];
        $end = ["x" => $end_x, "y" => $end_y];
        $map = $this->fetch_map_data();

        $open_list = [];
        $closed_list = [];
        $g_scores = [];
        $f_scores = [];
        $came_from = [];

        $open_list[$this->encode($start)] = 0;
        $g_scores[$this->encode($start)] = 0;
        $f_scores[$this->encode($start)] = $this->heuristic($start, $end);

        while (!empty($open_list)) {
            $current = array_search(min($open_list), $open_list);
            $current = $this->decode($current);

            if ($current["x"] == $end["x"] && $current["y"] == $end["y"]) {
                return $this->reconstruct_path($came_from, $current, $map, $start_x, $start_y);
            }

            unset($open_list[$this->encode($current)]);
            $closed_list[$this->encode($current)] = true;

            foreach ($this->get_neighbours($current, $map) as $neighbor) {
                if (isset($closed_list[$this->encode($neighbor)])) {
                    continue;
                }

                $traversal_time = $map[$neighbor["x"]][$neighbor["y"]]["traversaltime"];
                $tentative_g_score = $g_scores[$this->encode($current)] + $traversal_time;

                if (!isset($open_list[$this->encode($neighbor)]) || $tentative_g_score < $g_scores[$this->encode($neighbor)]) {
                    $came_from[$this->encode($neighbor)] = $current;
                    $g_scores[$this->encode($neighbor)] = $tentative_g_score;
                    $f_scores[$this->encode($neighbor)] = $tentative_g_score + $this->heuristic($neighbor, $end);
                    $open_list[$this->encode($neighbor)] = $f_scores[$this->encode($neighbor)];
                }
            }
        }

        return []; // No path found
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

    public function calculate_arrival_data(int $sx, int $sy, int $ex, int $ey, bool $is_caravan = false): array
    {
        $seconds = $this->get_arrival_time($sx, $sy, $ex, $ey, $is_caravan);
        return [
            "seconds" => $seconds,
            "timestamp" => time() + $seconds
        ];
    }
}
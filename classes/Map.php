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

    public function render_field_info(?int $field_id = null): void
    {
        $current_k_id = $this->user->get_current_kingdom();

        if (!isset($_SESSION["current_k_coords"]) || $_SESSION["current_k_coords"]["id"] != $current_k_id) {
            $res = $this->mysqli->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$current_k_id]);
            $row = $res->fetch_assoc();
            $_SESSION["current_k_coords"] = ["id" => $current_k_id, "x" => $row["mapx"], "y" => $row["mapy"]];
        }
        $my_x = $_SESSION["current_k_coords"]["x"];
        $my_y = $_SESSION["current_k_coords"]["y"];

        $field_x = (int)($_GET["x"] ?? $_GET["startx"] ?? $my_x);
        $field_y = (int)($_GET["y"] ?? $_GET["starty"] ?? $my_y);

        if ($field_id === null) {
            $field_id = (int)($_GET["clickedfield"] ?? -1);
        }
        $now = time();

        $res_units = $this->mysqli->execute_query(
            "SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ? AND soldierid IN (?, ?, ?)",
            [$current_k_id, Soldiers::SOLDIER_SETTLER_WAGON, Soldiers::SOLDIER_RAIDER, Soldiers::SOLDIER_SCOUT]
        );
        $my_troops = [
            Soldiers::SOLDIER_SETTLER_WAGON => 0,
            Soldiers::SOLDIER_RAIDER => 0,
            Soldiers::SOLDIER_SCOUT => 0
        ];
        while ($u = $res_units->fetch_assoc()) {
            $my_troops[(int)$u["soldierid"]] = (int)$u["soldiercount"];
        }

        $arrival_time_atk = $this->get_arrival_time($my_x, $my_y, $field_x, $field_y, $current_k_id, $field_id);
        $target_url = "sendtroops.php?x=$field_x&y=$field_y";

        if ($field_id == -1) {
            // --- EMPTY FIELD ---
            $res_ft = $this->mysqli->execute_query("SELECT ft.fieldname FROM map m JOIN field_types ft ON m.fieldtype = ft.fieldid WHERE m.mapx = ? AND m.mapy = ?", [$field_x, $field_y]);
            $field_name = $res_ft->fetch_column() ?: "Unbesiedeltes Land";

            echo '<div class="title-border">' . e($field_name) . '</div>
          <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
              <tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>' . $field_x . ':' . $field_y . '</td></tr>
              <tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>' . convert_sec_to_str($arrival_time_atk) . '</td></tr>
              <tr><td colspan="2" class="td-mapinfo" style="text-align: center;">';
            if ($my_troops[Soldiers::SOLDIER_SETTLER_WAGON] > 0) {
                echo "<button data-on-click='redirect' data-url='$target_url'>Erobern</button>";
            } else {
                echo "<small class='error'>Gründungskarren benötigt!</small>";
            }
            echo '</td></tr></table>';

        } else if ($field_id == -2) {
            $res_data = $this->mysqli->execute_query("SELECT expires_at FROM resource_tiles_data WHERE mapx = ? AND mapy = ?", [$field_x, $field_y])->fetch_assoc();
            $lifetime = $res_data["expires_at"] - $now;

            if ($lifetime <= 0) {
                echo '<div class="title-border">Verschwunden</div>
                  <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                      <tr><td class="td-mapinfo" style="text-align:center;">Das Vorkommen ist bereits erschöpft.</td></tr>
                  </table>';
                return;
            }

            $can_plunder = ($my_troops[Soldiers::SOLDIER_RAIDER] > 0);
            $can_spy = ($my_troops[Soldiers::SOLDIER_SCOUT] > 0);
            $arrival_time_scout = (int)round($arrival_time_atk * MONSTER_CAMP_SCOUT_BOOST);

            $mode = $can_plunder ? "plunder" : "spy";
            $target_url = "sendtroops.php?x=$field_x&y=$field_y&mode=$mode";

            // --- RESOURCE TILE ---
            echo '<div class="title-border">Verlassenes Vorratslager</div>
          <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
              <tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>' . $field_x . ':' . $field_y . '</td></tr>
              <tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>
              ' . convert_sec_to_str($arrival_time_atk) . '<br><small>(' . convert_sec_to_str($arrival_time_scout) . ' Spionage)</small></td></tr>
              <tr><td class="td-mapinfo"><b>Restzeit</b></td><td>' . convert_sec_to_str($lifetime, false, false) . '</td></tr>
              <tr><td colspan="2" class="td-mapinfo" style="text-align: center;">';

            if ($can_plunder || $can_spy) {
                $label = $can_plunder ? "Plündern" : "Spionieren";

                echo "<button data-on-click='redirect' data-url='$target_url'>$label</button>";
            } else {
                echo "<small class='error'>Räuber oder Späher benötigt!</small>";
            }

            echo '</td></tr></table>';

        } else if ($field_id == -3) {
            // --- MONSTERCAMP ---
            $res_camp = $this->mysqli->execute_query("SELECT level, expires_at FROM monster_camps WHERE mapx = ? AND mapy = ?", [$field_x, $field_y]);
            $camp_data = $res_camp->fetch_assoc();
            $lifetime = $camp_data["expires_at"] - $now;

            if ($lifetime <= 0) {
                echo '<div class="title-border">Verschwunden</div>
              <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                  <tr><td class="td-mapinfo" style="text-align:center;">Das Lager wurde bereits aufgegeben.</td></tr>
              </table>';
                return;
            }

            $arrival_time_scout = (int)round(($arrival_time_atk / MONSTER_CAMP_TRAVEL_BOOST) * MONSTER_CAMP_SCOUT_BOOST);

            echo '<div class="title-border">Monstercamp (Stufe ' . $camp_data["level"] . ')</div>
            <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                <tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>' . $field_x . ':' . $field_y . '</td></tr>
                <tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>' .
                convert_sec_to_str($arrival_time_atk) . '<br><small>(' . convert_sec_to_str($arrival_time_scout) . ' Spionage)</small></td></tr>
                <tr><td class="td-mapinfo"><b>Restzeit</b></td><td>' . convert_sec_to_str($lifetime, false, false) . '</td></tr>
                <tr><td colspan="2" class="td-mapinfo" style="text-align: center;">
                    <button data-on-click="redirect" data-url="' . $target_url . '">Camp angreifen</button>
                </td></tr>
            </table>';

        } else {
            if (!isset($_GET["owner"])) {
                $query_details = "
                    SELECT k.username, k.userid, k.kingdomname, u.ranking_points AS score
                    FROM kingdoms k 
                    JOIN users u ON k.userid = u.id 
                    WHERE k.id = ?
                ";
                $res_details = $this->mysqli->execute_query($query_details, [$field_id]);
                $details = $res_details->fetch_assoc();

                if ($details) {
                    $owner_name = $details["username"];
                    $owner_id = (int)$details["userid"];
                    $kname = $details["kingdomname"];
                    $score = (int)$details["score"];
                } else {
                    $owner_name = "Unbekannt";
                    $owner_id = 0;
                    $kname = "Verlassenes Königreich";
                    $score = 0;
                }
            } else {
                $owner_name = $_GET["owner"];
                $owner_id = (int)($_GET["owner_id"] ?? 0);
                $kname = $_GET["kname"];
                $score = (int)($_GET["score"] ?? 0);
            }

            $score_icon = "<img src='images/icons/icon_score.png' class='ressource-icons' alt=''>";
            $owner_display = "<a href='#' 
                             data-on-click='openOverlay' 
                             data-url='userinfo.php?userid=$owner_id' 
                             data-title='Spieler-Info'>" . e($owner_name) . "</a>";

            echo '<div class="title-border">Königreich-Info</div>
          <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
              <tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>' . $field_x . ':' . $field_y . '</td></tr>
              <tr><td class="td-mapinfo"><b>Königreich</b></td><td>' . e($kname) . '</td></tr>
              <tr><td class="td-mapinfo"><b>Besitzer</b></td><td>' . $owner_display . ' ' . $score_icon . ' ' . fnum($score) . '</td></tr>';

            if ($field_id != $current_k_id) {
                echo '<tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>' . convert_sec_to_str($arrival_time_atk) . '</td></tr>';
                $btn_text = ($owner_name != $this->user->get_user_name()) ? "Angreifen" : "Stationieren";
                echo "<tr><td colspan='2' class='td-mapinfo' style='text-align: center;'>
                <button data-on-click='redirect' data-url='$target_url'>$btn_text</button>
              </td></tr>";
            }
            echo "</table>";
        }
    }

    public function get_arrival_time(int  $start_x, int $start_y, int $end_x, int $end_y, int $origin_kingdom_id = -1,
                                     ?int $target_id = null, bool $is_scouting = false): int
    {
        $result = $this->calculate_path($start_x, $start_y, $end_x, $end_y);
        $kid = ($origin_kingdom_id != -1) ? $origin_kingdom_id : $this->user->get_current_kingdom();
        $kingdom = new Kingdom($this->mysqli, $kid);

        $modified_time = $result["totaltime"] * $kingdom->get_march_speed_multiplier();

        $actual_target_id = ($target_id !== null) ? $target_id : $this->get_field_kingdom_id($end_x, $end_y);

        if ($actual_target_id === -3) {
            $boost = $is_scouting ? MONSTER_CAMP_SCOUT_BOOST : MONSTER_CAMP_TRAVEL_BOOST;
            $modified_time *= $boost;
        } else if ($actual_target_id === -2 && $is_scouting) {
            $modified_time *= MONSTER_CAMP_SCOUT_BOOST;
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

    public function calculate_arrival_data(int $sx, int $sy, int $ex, int $ey): array
    {
        $seconds = $this->get_arrival_time($sx, $sy, $ex, $ey);
        return [
            "seconds" => $seconds,
            "timestamp" => time() + $seconds
        ];
    }
}
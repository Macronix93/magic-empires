<?php

class Map
{
    private object $mysqli;
    private int $start_x;
    private int $start_y;
    private User $user;

    // Constructor
    public function __construct(object $db_conn, User $user)
    {
        $this->mysqli = $db_conn;
        $this->user = $user;
    }

    public function get_start_x(): int
    {
        return $this->start_x;
    }

    public function set_start_x(int $start_x): void
    {
        $this->start_x = $start_x;
    }

    public function get_start_y(): int
    {
        return $this->start_y;
    }

    public function set_start_y(int $start_y): void
    {
        $this->start_y = $start_y;
    }

    public function render_map(int $start_x, int $start_y): void
    {
        $arrow_up = "<a href='javascript:void(0);' onclick='updateMap(\"" . $start_x . "\", \"" . max(1, $start_y - 10) . "\")'><img class='map-arrows' src='images/icons/icon_right_fast.png' style='transform: rotate(-90deg);' alt='+10' title='+10'/></a>";
        $arrow_up_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . $start_x . "\", \"" . max(1, $start_y - 1) . "\")'><img class='map-arrows' src='images/icons/icon_right_slow.png' style='transform: rotate(-90deg);' alt='+1' title='+1'/></a>";
        $arrow_left = "<a href='javascript:void(0);' onclick='updateMap(\"" . max(1, $start_x - 10) . "\", \"" . $start_y . "\")'><img class='map-arrows' src='images/icons/icon_right_fast.png' style='transform: rotate(180deg);' alt='+10' title='+10'/></a>";
        $arrow_left_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . max(1, $start_x - 1) . "\", \"" . $start_y . "\")'><img class='map-arrows' src='images/icons/icon_right_slow.png' style='transform: rotate(180deg);' alt='+1' title='+1'/></a>";
        $arrow_right = "<a href='javascript:void(0);' onclick='updateMap(\"" . min(91, $start_x + 10) . "\", \"" . $start_y . "\")'><img class='map-arrows' src='images/icons/icon_right_fast.png' alt='+10' title='+10'/></a>";
        $arrow_right_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . min(91, $start_x + 1) . "\", \"" . $start_y . "\")'><img class='map-arrows' src='images/icons/icon_right_slow.png' alt='+1' title='+1'/></a>";
        $arrow_down = "<a href='javascript:void(0);' onclick='updateMap(\"" . $start_x . "\", \"" . min(91, $start_y + 10) . "\")'><img class='map-arrows' src='images/icons/icon_right_fast.png' style='transform: rotate(90deg);' alt='+10' title='+10'/></a>";
        $arrow_down_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . $start_x . "\", \"" . min(91, $start_y + 1) . "\")'><img  class='map-arrows' src='images/icons/icon_right_slow.png' style='transform: rotate(90deg);' alt='+1' title='+1'/></a>";

        $x_start = $start_x;
        $x_end = $start_x + 9;
        $y_start = $start_y;
        $y_end = $start_y + 9;
        $query = "SELECT m.*, IFNULL(b.buildinglevel, 1) AS buildinglevel FROM map m LEFT JOIN buildings b ON m.kingdomid = b.kingdomid AND b.buildingid = 0 WHERE m.mapx BETWEEN ? AND ? AND m.mapy BETWEEN ? AND ?";
        $result = $this->mysqli->execute_query($query, [$x_start, $x_end, $y_start, $y_end]);

        // MAP WRAPPER START
        echo '<div class="map-view-container">';

        // 1. DIE MAP TABELLE (Rein für die Grafik)
        echo '<table class="table">';

        $field_color = array(array());
        $my_coords = array(array());
        $coords = array(array());

        foreach ($result as $row) {
            $field_color[$row["mapx"]][$row["mapy"]] = $this->get_field_type_color($row["fieldtype"]);
            $my_coords[$row["mapx"]][$row["mapy"]] = ($row["kingdomid"] != -1) ? $row["kingdomid"] : -1;
            $coords[$row["mapx"]][$row["mapy"]] = ($row["kingdomid"] != -1) ? "<div class='cell-container'><img src='" . $this->get_kingdom_icon_by_level($row["buildinglevel"]) . "' class='kingdom-img' alt=''></div>" : "";
        }

        for ($i = $start_y; $i <= $start_y + 9; $i++) {
            echo "<tr><td>$i</td>";
            for ($j = $start_x; $j <= $start_x + 9; $j++) {
                echo "<td data-fieldid='" . $my_coords[$j][$i] . "' data-x='$j' data-y='$i' style='background-color: " . $field_color[$j][$i] . "' onclick='highlightField(parseInt(\"" . $my_coords[$j][$i] . "\"), parseInt(\"" . $j . "\"), parseInt(\"" . $i . "\"))'>{$coords[$j][$i]}</td>";
            }
            echo "</tr>";
        }
        // X-Achse unten
        echo "<tr><td>Y/X</td>";
        for ($j = $start_x; $j <= $start_x + 9; $j++) echo "<td>$j</td>";
        echo "</tr></table>";

        // 2. DIE STEUERUNG (Unter der Map)
        echo '<div class="map-navigation-pad">';
        echo '    <div class="nav-grid">';
        echo '        <div class="grid-cell up-fast">' . $arrow_up . '</div>';
        echo '        <div class="grid-cell up-slow">' . $arrow_up_1 . '</div>';
        echo '        <div class="grid-cell left-fast">' . $arrow_left . '</div>';
        echo '        <div class="grid-cell left-slow">' . $arrow_left_1 . '</div>';
        echo '        <div class="grid-cell center-icon"><img src="images/icons/icon_score.png" style="width:24px; opacity:0.5;"></div>';
        echo '        <div class="grid-cell right-slow">' . $arrow_right_1 . '</div>';
        echo '        <div class="grid-cell right-fast">' . $arrow_right . '</div>';
        echo '        <div class="grid-cell down-slow">' . $arrow_down_1 . '</div>';
        echo '        <div class="grid-cell down-fast">' . $arrow_down . '</div>';
        echo '    </div>';
        echo '</div>';

        echo '</div>';
    }

    public function get_field_type_color(int $field_type): string
    {
        return match ($field_type) {
            1 => "rgb(185, 122, 87)",
            2 => "rgb(0, 162, 232)",
            3 => "rgb(34, 177, 76)",
            4 => "rgb(255, 201, 14)",
            default => "rgb(181, 230, 29)",
        };
    }

    private function get_kingdom_icon_by_level(int $building_level): string
    {
        return match (true) {
            $building_level >= 3 && $building_level < 6 => "images/icons/town.png",
            $building_level >= 6 && $building_level < 8 => "images/icons/tower2.png",
            $building_level >= 8 => "images/icons/castle.png",
            default => "images/icons/house.png",
        };
    }

    public function render_field_info(int $field): void
    {
        // Get the coords of the current kingdom of the user
        $result = $this->mysqli->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$this->user->get_current_kingdom()]);
        $row = $result->fetch_assoc();
        $x = $row["mapx"];
        $y = $row["mapy"];
        $field_x = isset($_GET["x"]) && $_GET["x"] != -1 ? $_GET["x"] : $x;
        $field_y = isset($_GET["y"]) && $_GET["y"] != -1 ? $_GET["y"] : $y;
        $url = "window.location.href='sendtroops.php?x=" . $field_x . "&y=" . $field_y . "'";

        $query = "
                    SELECT m.fieldtype, f.fieldname FROM map m
                    JOIN fieldtypes f ON m.fieldtype = f.fieldid
                    WHERE mapx = ? AND mapy = ?
        ";
        $result = $this->mysqli->execute_query($query, [$field_x, $field_y]);
        $field_name = $result->fetch_assoc()["fieldname"];

        if ($field == -1) {
            echo '<div class="title-border">' . $field_name . '</div>
                  <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                      <tr>
                          <td class="td-mapinfo"><b>Koordinaten</b></td>
                          <td>' . $field_x . ':' . $field_y . '</td>
                      </tr>
                      <tr>
                          <td class="td-mapinfo"><b>Ankunftszeit</b></td>
                          <td>' . convert_sec_to_str($this->get_arrival_time($x, $y, $field_x, $field_y)) . '</td>
                      </tr>
                      <tr>
                          <td colspan="2" class="td-mapinfo" style="text-align: center;">
                              <button onclick="' . $url . '">Erobern</button>
                          </td>
                      </tr>
                  </table>';
        } else {
            $query = "
                    SELECT k.userid, k.username, k.kingdomname, k.mapx, k.mapy, u.score
                    FROM kingdoms k
                    JOIN users u ON k.userid = u.id
                    WHERE k.id = ?
            ";
            $result_2 = $this->mysqli->execute_query($query, [$field]);
            $row_2 = $result_2->fetch_assoc();
            $kingdom_name = $row_2["kingdomname"];
            $user_name = $row_2["username"];
            $user_id = $row_2["userid"];
            $user_score = "<img src='images/icons/icon_score.png' class='ressource-icons' alt='Punkte' title='Punkte'/>" . fnum($row_2["score"]);
            $field_x = $row_2["mapx"];
            $field_y = $row_2["mapy"];

            $query = "
                    SELECT m.fieldtype, f.fieldname FROM map m
                    JOIN fieldtypes f ON m.fieldtype = f.fieldid
                    WHERE mapx = ? AND mapy = ?
            ";
            $result = $this->mysqli->execute_query($query, [$field_x, $field_y]);
            $field_name = $result->fetch_assoc()["fieldname"];

            if ($result_2->num_rows == 0) {
                echo show_error_box("Dieses Königreich existiert nicht!");
            } else {
                echo '<div class="title-border">Königreich-Info (' . $field_name . ')</div>
                      <table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">
                          <tr>
                              <td class="td-mapinfo"><b>Koordinaten</b></td>
                              <td>' . $field_x . ':' . $field_y . '</td>
                          </tr>
                          <tr>
                              <td class="td-mapinfo"><b>Königreich</b></td>
                              <td>' . $kingdom_name . '</td>
                          </tr>
                          <tr>
                              <td class="td-mapinfo"><b>Besitzer</b></td>
                              <td><a href="javascript:void(0);" onclick="openPopup(\'userinfo.php?userid=' . $user_id . '\');">' . $user_name . '</a> ' . $user_score . '</td>
                          </tr>

                      ';
                if ($kingdom_name == "" || $field != $this->user->get_current_kingdom()) {
                    echo '<tr>
                            <td class="td-mapinfo"><b>Ankunftszeit</b></td>
                            <td>' . convert_sec_to_str($this->get_arrival_time($x, $y, $field_x, $field_y)) . '</td>
                        </tr>';
                }

                if ($user_name != $this->user->get_user_name()) {
                    echo "<tr><td colspan='2' class='td-mapinfo' style='text-align: center;'>
                                <button onclick=\"" . $url . "\">Angreifen</button>
                            </td>
                            </tr>";
                } else {
                    if ($field != $this->user->get_current_kingdom()) {
                        echo "<tr><td colspan='2' class='td-mapinfo' style='text-align: center;'>
                                <button onclick=\"" . $url . "\">Truppen stationieren</button>
                            </td>
                            </tr>";
                    }
                }
            }
        }
        echo "</table>";
    }

    public function get_arrival_time(int $start_x, int $start_y, int $end_x, int $end_y): int
    {
        $result = $this->calculate_path($start_x, $start_y, $end_x, $end_y);

        // DEBUGGING: print the path
        /*$path = $result['path'];

        echo "Path:<br>";
        array_map(function ($coord) {
            return "{x: {$coord['x']},
                    y: {$coord['y']},
                    traversalTime: {$coord['traversalTime']}
                    }";
        }, $path);

        foreach ($path as $coord) {
            echo "x: {$coord['x']}, y: {$coord['y']}, time: {$coord['traversalTime']}<br>";
        }*/

        return $result["totalTime"];
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

            if ($current['x'] == $end['x'] && $current['y'] == $end['y']) {
                return $this->reconstruct_path($came_from, $current, $map);
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
        $query = "
            SELECT m.mapx, m.mapy, f.traversaltime
            FROM map m
            JOIN fieldtypes f ON m.fieldtype = f.fieldid
        ";

        $result = $this->mysqli->execute_query($query);
        $map = [];

        foreach ($result as $row) {
            $map[$row["mapx"]][$row["mapy"]] = [
                    "traversaltime" => $row["traversaltime"]
            ];
        }

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
        return ['x' => (int)$x, 'y' => (int)$y];
    }

    private function reconstruct_path($came_from, $current, $map): array
    {
        $path = [$current];
        $total_time = 0;

        while (isset($came_from[$this->encode($current)])) {
            $current = $came_from[$this->encode($current)];
            $path[] = $current;
        }

        // Add traversal time information to the path
        foreach ($path as &$coord) {
            $coord["traversalTime"] = $map[$coord["x"]][$coord["y"]]["traversaltime"];
            $total_time += $coord["traversalTime"];
        }

        // Reverse the path to start from the beginning
        $path = array_reverse($path);

        return ["path" => $path, "totalTime" => $total_time];
    }

    // Render and show the map

    private function get_neighbours($node, $map): array
    {
        $neighbors = [];
        $moves = [[0, 1], [1, 0], [0, -1], [-1, 0]];

        foreach ($moves as $move) {
            $x = $node['x'] + $move[0];
            $y = $node['y'] + $move[1];

            if (isset($map[$x][$y])) {
                $neighbors[] = ['x' => $x, 'y' => $y];
            }
        }

        return $neighbors;
    }

    public function get_field_kingdom_id(int $map_x, int $map_y): int
    {
        $result = $this->mysqli->execute_query("SELECT kingdomid FROM map WHERE mapx = ? AND mapy = ?", [$map_x, $map_y]);
        return $result->fetch_column();
    }

    function clamp_value(int $value)
    {
        if ($value > 91) {
            return 91;
        }
        return max(min($value, MAX_X), 1);
    }
}
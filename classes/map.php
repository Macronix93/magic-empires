<?php

class Map {
    private $mysqli;
    public $startx, $starty;

    // Constructor
    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
    }

    private function getFieldTypeColor($fieldtype): string {
        $color = "";
        switch ($fieldtype) {
            case 1:
                $color = "rgb(185, 122, 87)";
                break;
            case 2:
                $color = "rgb(0, 162, 232)";
                break;
            case 3:
                $color = "rgb(34, 177, 76)";
                break;
            case 4:
                $color = "rgb(255, 201, 14)";
                break;
            case 5:
                $color = "rgb(181, 230, 29)";
                break;
        }
        return $color;
    }

    private function getKingdomIconByLevel($buildinglevel): string {
        return match (true) {
            $buildinglevel >= 3 && $buildinglevel < 6 => "images/town.png",
            $buildinglevel >= 6 && $buildinglevel < 8 => "images/tower2.png",
            $buildinglevel >= 8 => "images/castle.png",
            default => "images/house.png",
        };
    }

    public function calculatePath($startx, $starty, $endx, $endy): array {
        $start = ['x' => $startx, 'y' => $starty];
        $end = ['x' => $endx, 'y' => $endy];
        $map = $this->fetchMapData();

        $openList = [];
        $closedList = [];
        $gScores = [];
        $fScores = [];
        $cameFrom = [];

        $openList[$this->encode($start)] = 0;
        $gScores[$this->encode($start)] = 0;
        $fScores[$this->encode($start)] = $this->heuristic($start, $end);

        while (!empty($openList)) {
            $current = array_search(min($openList), $openList);
            $current = $this->decode($current);

            if ($current['x'] == $end['x'] && $current['y'] == $end['y']) {
                return $this->reconstructPath($cameFrom, $current, $map);
            }

            unset($openList[$this->encode($current)]);
            $closedList[$this->encode($current)] = true;

            foreach ($this->getNeighbors($current, $map) as $neighbor) {
                if (isset($closedList[$this->encode($neighbor)])) {
                    continue;
                }

                $traversalTime = $map[$neighbor['x']][$neighbor['y']]['traversaltime'];
                $tentativeGScore = $gScores[$this->encode($current)] + $traversalTime;

                if (!isset($openList[$this->encode($neighbor)]) || $tentativeGScore < $gScores[$this->encode($neighbor)]) {
                    $cameFrom[$this->encode($neighbor)] = $current;
                    $gScores[$this->encode($neighbor)] = $tentativeGScore;
                    $fScores[$this->encode($neighbor)] = $tentativeGScore + $this->heuristic($neighbor, $end);
                    $openList[$this->encode($neighbor)] = $fScores[$this->encode($neighbor)];
                }
            }
        }

        return []; // No path found
    }

    private function fetchMapData(): array {
        $query = "
            SELECT m.mapx, m.mapy, f.traversaltime
            FROM map m
            JOIN fieldtypes f ON m.fieldtype = f.fieldid
        ";

        $result = $this->mysqli->execute_query($query);
        $map = [];

        foreach ($result as $row) {
            $map[$row['mapx']][$row['mapy']] = [
                'traversaltime' => $row['traversaltime']
            ];
        }

        return $map;
    }

    private function heuristic($a, $b): int {
        return abs($a['x'] - $b['x']) + abs($a['y'] - $b['y']);
    }

    private function getNeighbors($node, $map): array {
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

    private function reconstructPath($cameFrom, $current, $map): array {
        $path = [$current];
        $totalTime = 0;

        while (isset($cameFrom[$this->encode($current)])) {
            $current = $cameFrom[$this->encode($current)];
            $path[] = $current;
        }

        // Add traversal time information to the path
        foreach ($path as &$coord) {
            $coord['traversalTime'] = $map[$coord['x']][$coord['y']]['traversaltime'];
            $totalTime += $coord['traversalTime'];
        }

        // Reverse the path to start from the beginning
        $path = array_reverse($path);

        return ['path' => $path, 'totalTime' => $totalTime];
    }

    private function encode($node): string {
        return $node['x'] . ',' . $node['y'];
    }

    private function decode($encoded): array {
        list($x, $y) = explode(',', $encoded);
        return ['x' => (int)$x, 'y' => (int)$y];
    }

    private function getArrivalTime($startx, $starty, $endx, $endy): int {
        $result = $this->calculatePath($startx, $starty, $endx, $endy);

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

        return $result['totalTime'];
    }

    // Render and show the map
    public function renderMap($startx, $starty): void {
        // Generate URL for each arrow button
        $arrowup = "<a href='javascript:void(0);' onclick='updateMap(\"" . $startx . "\", \"" . max(1, $starty - 10) . "\")'><img class='map-arrows' src='images/icon_right_fast.png' style='transform: rotate(-90deg);' alt='+10' title='+10'/></a>";
        $arrowup_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . $startx . "\", \"" . max(1, $starty - 1) . "\")'><img class='map-arrows' src='images/icon_right_slow.png' style='transform: rotate(-90deg);' alt='+1' title='+1'/></a>";
        $arrowleft = "<a href='javascript:void(0);' onclick='updateMap(\"" . max(1, $startx - 10) . "\", \"" . $starty . "\")'><img class='map-arrows' src='images/icon_right_fast.png' style='transform: rotate(180deg);' alt='+10' title='+10'/></a>";
        $arrowleft_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . max(1, $startx - 1) . "\", \"" . $starty . "\")'><img class='map-arrows' src='images/icon_right_slow.png' style='transform: rotate(180deg);' alt='+1' title='+1'/></a>";
        $arrowright = "<a href='javascript:void(0);' onclick='updateMap(\"" . min(91, $startx + 10) . "\", \"" . $starty . "\")'><img class='map-arrows' src='images/icon_right_fast.png' alt='+10' title='+10'/></a>";
        $arrowright_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . min(91, $startx + 1) . "\", \"" . $starty . "\")'><img class='map-arrows' src='images/icon_right_slow.png' alt='+1' title='+1'/></a>";
        $arrowdown = "<a href='javascript:void(0);' onclick='updateMap(\"" . $startx . "\", \"" . min(91, $starty + 10) . "\")'><img class='map-arrows' src='images/icon_right_fast.png' style='transform: rotate(90deg);' alt='+10' title='+10'/></a>";
        $arrowdown_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . $startx . "\", \"" . min(91, $starty + 1) . "\")'><img  class='map-arrows' src='images/icon_right_slow.png' style='transform: rotate(90deg);' alt='+1' title='+1'/></a>";

        // Coords Variable
        $coords = array();
        for ($c = 1; $c <= 100; $c++) {
            $coords[$c] = array();

            for ($r = 1; $r <= 100; $r++) {
                $coords[$c][$r] = "";
            }
        }

        $xstart = $startx;
        $xend = $startx + 9;
        $ystart = $starty;
        $yend = $starty + 9;


        $query = "
                    SELECT m.*, IFNULL(b.buildinglevel, 1) AS buildinglevel 
                    FROM map m 
                    LEFT JOIN buildings b 
                    ON m.kingdomid = b.kingdomid AND b.buildingid = 0 
                    WHERE m.mapx BETWEEN ? AND ? AND m.mapy BETWEEN ? AND ?
        ";
        $result = $this->mysqli->execute_query($query, [$xstart, $xend, $ystart, $yend]);
        ?>
        <table class="table">
            <tr>
                <td colspan="13" class="top-bottom-cell td-gradient">
                    <?php echo $arrowup . $arrowup_1 ?>
                </td>
            </tr>
            <tr>
                <td rowspan="12" class="td-gradient">
                    <?php echo $arrowleft . $arrowleft_1 ?>
                </td>
                <?php
                $fieldcolor = array(array());
                $mycoords = array(array());

                foreach ($result as $row) {
                    $mycoords[$row["mapx"]][$row["mapy"]] = false;
                    $fieldImage = "";
                    $fieldcolor[$row["mapx"]][$row["mapy"]] = $this->getFieldTypeColor($row["fieldtype"]);

                    if ($row["kingdomid"] != -1) {
                        $mycoords[$row["mapx"]][$row["mapy"]] = $row["kingdomid"];

                        $fieldImage = "<div class='cell-container'><a href='javascript:void(0);'>
                            <img src='" . $this->getKingdomIconByLevel($row["buildinglevel"]) . "' class='kingdom-img' alt=''>
                        </a></div>";
                    } else {
                        $mycoords[$row["mapx"]][$row["mapy"]] = -1;
                    }

                    $coords[$row["mapx"]][$row["mapy"]] = $fieldImage;
                }

                for ($i = $starty; $i <= $starty + 9; $i++) {
                    echo "<tr>";
                    echo "<td>$i</td>";

                    for ($j = $startx; $j <= $startx + 9; $j++) {
                        if ($mycoords[$j][$i] == $_SESSION["kingdomid"]) {
                            echo "<td data-fieldid='" . $mycoords[$j][$i] . "' data-x='$j' data-y='$i' class='highlight' style='background-color: " . $fieldcolor[$j][$i] . "' 
                                onclick='highlightField(this, parseInt(\"" . $mycoords[$j][$i] . "\"), parseInt(\"" . $j . "\"), parseInt(\"" . $i . "\"))'>{$coords[$j][$i]}</td>";
                            echo "<script type='text/javascript'>
                                        let cell = document.querySelector(`td[data-x=\"$j\"][data-y=\"$i\"]`);
                                        if (cell) {
                                            let fieldID = cell.getAttribute('data-fieldid');
                                            j = $j;
                                            i = $i;
                                            highlightField(cell, parseInt(fieldID), j, i);
                                        }
                                    </script>";
                        } else {
                            echo "<td data-fieldid='" . $mycoords[$j][$i] . "' data-x='$j' data-y='$i' style='background-color: " . $fieldcolor[$j][$i] . "' 
                                onclick='highlightField(this, parseInt(\"" . $mycoords[$j][$i] . "\"), parseInt(\"" . $j . "\"), parseInt(\"" . $i . "\"))'>{$coords[$j][$i]}</td>";
                        }

                        if ($j == $xend && $i == $ystart) {
                            echo "<td rowspan='11' class='td-gradient'>$arrowright$arrowright_1</td>";
                        }
                    }

                    echo "</tr>";
                }

                echo "<tr><td>Y<br>X</td><td>$startx</td><td>" . $startx + 1 . "</td><td>" . $startx + 2 . "</td><td>" . $startx + 3 . "</td><td>" . $startx + 4 . "</td><td>" . $startx + 5 . "</td>
                        <td>" . $startx + 6 . "</td><td>" . $startx + 7 . "</td><td>" . $startx + 8 . "</td><td>" . $startx + 9 . "</td></tr>
                        <tr><td colspan='13' class='top-bottom-cell td-gradient'>$arrowdown$arrowdown_1</td></tr>";
                ?>
        </table>
        <div id='field-info'></div>
        <br>
        <form id="update-map">
            X: <label>
                <input type="text" id="startx" name="startx" size="3" maxlength="3">
            </label>
            Y: <label>
                <input type="text" id="starty" name="starty" size="3" maxlength="3">
            </label>
            <input type="button" value="Anzeigen" onclick="sendUpdateMapRequest()">
        </form>
        <?php
    }

    public function renderFieldInfo($field): void {
        // Get the coords of the current kingdom of the user
        $result = $this->mysqli->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$_SESSION["kingdomid"]]);
        $row = $result->fetch_assoc();
        $x = $row["mapx"];
        $y = $row["mapy"];

        if ($field == -1) {
            $field_x = isset($_GET["x"]) && $_GET["x"] != -1 ? $_GET["x"] : 1;
            $field_y = isset($_GET["y"]) && $_GET["y"] != -1 ? $_GET["y"] : 1;

            // No kingdom on the current field - get the fieldtype and name
            $query = "
                    SELECT m.fieldtype, f.fieldname FROM map m
                    JOIN fieldtypes f ON m.fieldtype = f.fieldid
                    WHERE mapx = ? AND mapy = ?
            ";
            $result = $this->mysqli->execute_query($query, [$field_x, $field_y]);
            $row = $result->fetch_assoc();
            ?>
            <div style="border-bottom: 2px solid rgba(0, 0, 0, 0.5); width: 50%; margin: auto; line-height: 40px">
                <?php
                echo $row["fieldname"];
                ?>
            </div>
            <table class="table"
                   style="margin-top: 20px; max-width: 400px; text-align: left;">
                <tr>
                    <td class="td-main"><b>Koordinaten</b></td>
                    <?php
                    echo "<td>" . $field_x . ":" . $field_y . "</td>";
                    ?>
                </tr>
                <tr>
                    <td class="td-main"><b>Ankunftszeit</b></td>
                    <?php
                    echo "<td>" . convertSecToStr($this->getArrivalTime($x, $y, $field_x, $field_y)) . "</td>";
                    ?>
                </tr>
                <tr>
                    <td colspan='2' class='td-main' style='text-align: center;'>
                        <button type='submit'>Erobern</button>
                    </td>
                </tr>
            </table>
            <?php
        } else {
            $result = $this->mysqli->execute_query("SELECT userid, username, kingdomname, mapx, mapy FROM kingdoms WHERE id = ?", [$field]);
            $row = $result->fetch_assoc();

            if ($result->num_rows == 0) {
                echo "<br><br>Dieses Königreich existiert nicht!";
            } else {
                ?>
                <div style="border-bottom: 2px solid rgba(0, 0, 0, 0.5); width: 50%; margin: auto; line-height: 40px">
                    Königreich-Info
                </div>
                <table class="table"
                style="margin-top: 20px; max-width: 400px; text-align: left;">
                <tr>
                    <td class="td-main"><b>Königreich</b></td>
                    <?php
                    echo "<td>" . $row["kingdomname"] . "</td>";
                    ?>
                </tr>
                <tr>
                    <td class="td-main"><b>Besitzer</b></td>
                    <?php
                    echo "<td><a href='javascript:void(0);' onclick='openUserDetails(\"userinfo.php?userid=" . $row["userid"] . "\");'>{$row["username"]}</a></td>";
                    ?>
                </tr>
                <tr>
                    <td class="td-main"><b>Koordinaten</b></td>
                    <?php
                    echo "<td>" . $row["mapx"] . ":" . $row["mapy"] . "</td>";
                    ?>
                </tr>
                <tr>
                    <?php
                    // Get the coords of the current kingdom of the user
                    $my_kingdom_id = $_SESSION["kingdomid"];

                    $result = $this->mysqli->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$my_kingdom_id]);
                    $row2 = $result->fetch_assoc();
                    $x = $row2["mapx"];
                    $y = $row2["mapy"];

                    if ($field != $my_kingdom_id) {
                        echo "<td class='td-main'><b>Ankunftszeit</b></td>";
                        echo "<td>" . convertSecToStr($this->getArrivalTime($x, $y, $row['mapx'], $row['mapy'])) . "</td>";
                    }
                    ?>
                </tr>
                <?php
                if ($row["username"] != $_SESSION["username"]) {
                    echo "<tr><td colspan='2' class='td-main' style='text-align: center;'>
                                            <button type='submit' style=''>Angreifen</button>
                                            <button type='submit' style=''>Handeln</button>
                                        </td>
                                        </tr>";
                }
            }
        }
        ?>
        </table>
        <?php
    }
}
<?php

class Map {
    private $mysqli;
    public $startx, $starty;

    // Constructor
    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
    }

    public function getFieldTypeColor($fieldtype): string {
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

    public function getKingdomIconByLevel($buildinglevel): string {
        return match (true) {
            $buildinglevel >= 3 && $buildinglevel < 6 => "images/town.png",
            $buildinglevel >= 6 && $buildinglevel < 8 => "images/tower2.png",
            $buildinglevel >= 8 => "images/castle.png",
            default => "images/house.png",
        };
    }

    public function getArrivalTime($startx, $starty, $endx, $endy): int {
        $minX = min($startx, $endx);
        $maxX = max($startx, $endx);
        $minY = min($starty, $endy);
        $maxY = max($starty, $endy);

        // Get the path and calculate total time
        $path = [];
        $totaltime = 0;

        $query = "
                    SELECT mapx, mapy, fieldtype FROM map
                    WHERE (mapx BETWEEN ? AND ?)
                    AND (mapy BETWEEN ? AND ?)
                    ORDER BY mapx, mapy
        ";
        $result = $this->mysqli->execute_query($query, [$minX, $maxX, $minY, $maxY]);

        foreach ($result as $row) {
            $path[] = ['x' => $row['mapx'], 'y' => $row['mapy'], 'fieldtype' => $row['fieldtype']];
        }

        // Print the path
        /*echo "Path:\n";
        foreach ($path as $coord) {
            echo "x: {$coord['x']}, y: {$coord['y']}, fieldtype: {$coord['fieldtype']}\n";
        }*/

        // Calculate total time
        foreach ($path as $index => $coord) {
            if ($index > 0) {
                $totaltime += $this->getFieldTraversalTime($coord['fieldtype']);
            }
        }

        return $totaltime;
    }

    public function getFieldTraversalTime($fieldtype): int {
        $time = 0;
        switch ($fieldtype) {
            case 1:
                $time = 150;
                break;
            case 2:
                $time = 80;
                break;
            case 3:
                $time = 70;
                break;
            case 4:
                $time = 120;
                break;
            case 5:
                $time = 60;
                break;
        }
        return $time;
    }

    // Render and show the map
    public function renderMap($startx, $starty): void {
        // Generate URL for each arrow button
        $arrowstyle = "width:24px; height:24px; margin: 5px;";
        $arrowup = "<a href='javascript:void(0);' onclick='updateMap(\"" . $startx . "\", \"" . max(1, $starty - 10) . "\")'><img src='images/icon_right_fast.png' style='" . $arrowstyle . " transform: rotate(-90deg);' alt='' title='+10'/></a>";
        $arrowup_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . $startx . "\", \"" . max(1, $starty - 1) . "\")'><img src='images/icon_right_slow.png' style='" . $arrowstyle . " transform: rotate(-90deg);' alt='' title='+1'/></a>";
        $arrowleft = "<a href='javascript:void(0);' onclick='updateMap(\"" . max(1, $startx - 10) . "\", \"" . $starty . "\")'><img src='images/icon_right_fast.png' style='" . $arrowstyle . " transform: rotate(180deg);' alt='' title='+10'/></a>";
        $arrowleft_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . max(1, $startx - 1) . "\", \"" . $starty . "\")'><img src='images/icon_right_slow.png' style='" . $arrowstyle . " transform: rotate(180deg);' alt='' title='+1'/></a>";
        $arrowright = "<a href='javascript:void(0);' onclick='updateMap(\"" . min(91, $startx + 10) . "\", \"" . $starty . "\")'><img src='images/icon_right_fast.png' style='" . $arrowstyle . "' alt='' title='+10'/></a>";
        $arrowright_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . min(91, $startx + 1) . "\", \"" . $starty . "\")'><img src='images/icon_right_slow.png' style='" . $arrowstyle . "' alt='' title='+1'/></a>";
        $arrowdown = "<a href='javascript:void(0);' onclick='updateMap(\"" . $startx . "\", \"" . min(91, $starty + 10) . "\")'><img src='images/icon_right_fast.png' style='" . $arrowstyle . " transform: rotate(90deg);' alt='' title='+10'/></a>";
        $arrowdown_1 = "<a href='javascript:void(0);' onclick='updateMap(\"" . $startx . "\", \"" . min(91, $starty + 1) . "\")'><img src='images/icon_right_slow.png' style='" . $arrowstyle . " transform: rotate(90deg);' alt='' title='+1'/></a>";

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
        /*$stmt = $this->mysqli->prepare("SELECT m.*, IFNULL(b.buildinglevel, 1) AS buildinglevel
                                FROM map m 
                                LEFT JOIN buildings b 
                                ON m.kingdomid = b.kingdomid AND b.buildingid = 0 
                                WHERE m.mapx BETWEEN ? AND ? AND m.mapy BETWEEN ? AND ?");
        $stmt->bind_param('iiii', $xstart, $xend, $ystart, $yend);
        $stmt->execute();
        $result2 = $stmt->get_result();*/


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
        $result = $this->mysqli->execute_query("SELECT userid, username, kingdomname, mapx, mapy FROM kingdoms WHERE id = ?", [$field]);
        $row = $result->fetch_assoc();

        if ($result->num_rows == 0) {
            echo "<br><br>Dieses Königreich existiert nicht!";
        } else {
            ?>
            <br>
            <div style="border-bottom: 2px solid rgba(0, 0, 0, 0.5); width: 50%; margin: auto; line-height: 40px">
                Königreich-Info
            </div>
            <table class="table"
            style="margin-top: 20px; min-width: 400px; text-align: left;">
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
                <td class="td-main"><b>Ankunftszeit</b></td>
                <?php
                // Get the coords of the current kingdom of the user
                $result = $this->mysqli->execute_query("SELECT mapx, mapy FROM kingdoms WHERE id = ?", [$_SESSION["kingdomid"]]);
                $row2 = $result->fetch_assoc();
                $x = $row2["mapx"];
                $y = $row2["mapy"];

                echo "<td>" . convertSecToStr($this->getArrivalTime($x, $y, $row["mapx"], $row["mapy"])) . "</td>";
                ?>
            </tr>
            <?php
            if ($row["username"] != $_SESSION["username"]) {
                echo "<tr><td colspan='2' class='td-main' style='text-align: center;'>
                                            <button type='submit' style='margin: 10px;'>Angreifen</button>
                                            <button type='submit' style='margin: 10px;'>Handeln</button>
                                        </td>
                                        </tr>";
            }
        }
        ?>
        </table>
        <?php
    }
}
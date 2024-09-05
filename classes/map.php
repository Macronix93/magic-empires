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

    public function getKingdomIconByLevel($kingdomid): string {
        $stmt = $this->mysqli->prepare("SELECT buildinglevel FROM buildings WHERE kingdomid = ? AND buildingid = 0");
        $stmt->bind_param('i', $kingdomid);
        $stmt->execute();
        $level = 1;
        $stmt->bind_result($level);
        $stmt->fetch();
        $stmt->close();

        return match (true) {
            $level >= 3 && $level < 6 => "images/town.png",
            $level >= 6 && $level < 8 => "images/tower2.png",
            $level >= 8 && $level => "images/castle.png",
            default => "images/house.png",
        };
    }

    public function getArrivalTime($startx, $starty, $endx, $endy): int {
        $minX = min($startx, $endx);
        $maxX = max($startx, $endx);
        $minY = min($starty, $endy);
        $maxY = max($starty, $endy);

        $stmt = $this->mysqli->prepare("SELECT mapx, mapy, fieldtype FROM map
                                WHERE (mapx BETWEEN ? AND ?)
                                  AND (mapy BETWEEN ? AND ?)
                                ORDER BY mapx, mapy");
        $stmt->bind_param('iiii', $minX, $maxX, $minY, $maxY);
        $stmt->execute();
        $result = $stmt->get_result();

        // Process the result to get the path and calculate total time
        $path = [];
        $totaltime = 0;

        while ($row = $result->fetch_assoc()) {
            $path[] = ['x' => $row['mapx'], 'y' => $row['mapy'], 'fieldtype' => $row['fieldtype']];
        }

        $stmt->close();

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

        $stmt = $this->mysqli->prepare("SELECT * FROM map WHERE mapx BETWEEN ? AND ? AND mapy BETWEEN ? AND ?");
        $stmt->bind_param('iiii', $xstart, $xend, $ystart, $yend);
        $stmt->execute();
        $result2 = $stmt->get_result();
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

                while ($row2 = $result2->fetch_assoc()) {
                    $mycoords[$row2["mapx"]][$row2["mapy"]] = false;
                    $fieldImage = "";
                    $fieldcolor[$row2["mapx"]][$row2["mapy"]] = $this->getFieldTypeColor($row2["fieldtype"]);

                    if ($row2["kingdomid"] != -1) {
                        $mycoords[$row2["mapx"]][$row2["mapy"]] = $row2["kingdomid"];

                        $fieldImage = "<div class='cell-container'>
                                            <img src='" . $this->getKingdomIconByLevel($row2["kingdomid"]) . "' class='kingdom-img' alt=''>
                                        </div>";
                    } else {
                        $mycoords[$row2["mapx"]][$row2["mapy"]] = -1;
                    }

                    $coords[$row2["mapx"]][$row2["mapy"]] = $fieldImage;
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
        $stmt = $this->mysqli->prepare("SELECT userid, username, kingdomname, mapx, mapy FROM kingdoms WHERE id = ?");
        $stmt->bind_param('i', $field);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

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
                $x = 0;
                $y = 0;
                $stmt = $this->mysqli->prepare("SELECT mapx, mapy FROM kingdoms WHERE id = ?");
                $stmt->bind_param('i', $_SESSION["kingdomid"]);
                $stmt->execute();
                $stmt->bind_result($x, $y);
                $stmt->fetch();
                $stmt->close();

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
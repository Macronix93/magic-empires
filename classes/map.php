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
        // Show info about the fields
        echo "<img src='images/hochland.png' class='map-legend' alt=''> Hochland 
              <img src='images/küste.png' class='map-legend' alt=''> Küste 
              <img src='images/wald.png' class='map-legend' alt=''> Wald 
              <img src='images/wüste.png' class='map-legend' alt=''> Wüste 
              <img src='images/gebirge.png' class='map-legend' alt=''> Gebirge<br><br>";

        // Generate URL for each arrow button
        $url_up = "<a href='javascript:void(0);' onclick='updateMap($startx, " . max(1, $starty - 10) . ", -1)'><img src='images/icon_up.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        $link_links = "<a href='javascript:void(0);' onclick='updateMap(" . max(1, $startx - 10) . ", $starty, -1)'><img src='images/icon_left.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        $link_rechts = "<a href='javascript:void(0);' onclick='updateMap(" . min(91, $startx + 10) . ", $starty, -1)'><img src='images/icon_right.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        $link_unten = "<a href='javascript:void(0);' onclick='updateMap($startx, " . min(91, $starty + 10) . ", -1)'><img src='images/icon_down.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";

        // Coords Variable
        $coords = array();
        for ($c = 1; $c <= 100; $c++) {
            $coords[$c] = array();

            for ($r = 1; $r <= 100; $r++) {
                $coords[$c][$r] = "<img src='/images/hochland.png' alt=''/>";
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
        <style>
            .cell-container {
                width: 100%;
                height: 100%;
            }

            .kingdom-img {
                width: 100%;
                height: 100%;
            }

            td {
                text-align: center;
                margin: 0;
                padding: 0;
                width: 50px;
                height: 50px;
            }

            .td-main {
                background-color: var(--table-color);
                border: solid 1px rgb(29, 33, 39);
                font-size: 18px;
                padding: 5px 10px;
                text-align: left;
            }

            .top-bottom-cell {
                height: 30px;
            }
        </style>
        <table class="table">
            <tr>
                <td colspan="13" class="top-bottom-cell td-gradient">
                    <?php echo $url_up ?>
                </td>
            </tr>
            <tr>
                <td rowspan="12" height=12 class="left-right-cell td-gradient"><?php echo $link_links ?></td>

                <?php
                $fieldcolor = array(array());
                $mycoords = array(array());

                while ($row2 = $result2->fetch_assoc()) {
                    $mycoords[$row2["mapx"]][$row2["mapy"]] = false;
                    $fieldImage = "";
                    $fieldcolor[$row2["mapx"]][$row2["mapy"]] = $this->getFieldTypeColor($row2["fieldtype"]);

                    if ($row2["kingdomid"] != -1) {
                        if ($row2["kingdomid"] == $_SESSION["kingdomid"]) {
                            $mycoords[$row2["mapx"]][$row2["mapy"]] = true;
                        }

                        $fieldImage = "<div class='cell-container'><a href='javascript:void(0);' onclick='updateMap($startx, $starty, " . $row2['kingdomid'] . ")'>
                                            <img src='" . $this->getKingdomIconByLevel($row2["kingdomid"]) . "' class='kingdom-img' alt=''>
                                        </a></div>";
                    }

                    $coords[$row2["mapx"]][$row2["mapy"]] = $fieldImage;
                }

                for ($i = $starty; $i <= $starty + 9; $i++) {
                    echo "<tr>";
                    echo "<td style='padding: 15px;'>$i</td>";

                    for ($j = $startx; $j <= $startx + 9; $j++) {
                        if ($mycoords[$j][$i]) {
                            echo "<td style='border: 2px solid red; background-color: " . $fieldcolor[$j][$i] . "'>{$coords[$j][$i]}</td>";
                        } else {
                            echo "<td style='background-color: " . $fieldcolor[$j][$i] . "'>{$coords[$j][$i]}</td>";
                        }
                        if ($j == $xend && $i == $ystart) echo "<td rowspan='12' class='left-right-cell td-gradient'>$link_rechts</td>";
                    }

                    echo "</tr>";
                }

                echo "<tr><td>Y<br>X</td><td>$startx</td><td>" . $startx + 1 . "</td><td>" . $startx + 2 . "</td><td>" . $startx + 3 . "</td><td>" . $startx + 4 . "</td><td>" . $startx + 5 . "</td>
                        <td>" . $startx + 6 . "</td><td>" . $startx + 7 . "</td><td>" . $startx + 8 . "</td><td>" . $startx + 9 . "</td></tr>
                        <tr><td colspan='13' class='top-bottom-cell td-gradient'>$link_unten</td></tr>";
                ?>
        </table>
        <br>
        <form action="<?php echo $_SERVER["PHP_SELF"]; ?>" method="GET">
            X: <label>
                <input type="text" name="startx" size=3 maxlength=3>
            </label> Y: <label>
                <input type="text" name="starty" size=3 maxlength=3>
            </label>&nbsp;<input type="submit" value="Anzeigen">
        </form>
        <?php
        if (isset($_GET["kid"])) {
            $stmt = $this->mysqli->prepare("SELECT userid, username, kingdomname, mapx, mapy FROM kingdoms WHERE id = ?");
            $stmt->bind_param('i', $_GET["kid"]);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if ($result->num_rows == 0) {
                echo "<br><br>Dieses Königreich existiert nicht!";
            } else {
                ?>
                <br><br>
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
                    ?>
                </table>
                <?php
            }
        }
        echo "<script>
                    function updateMap(newStartX, newStartY, kID) {
                        console.log(newStartX, newStartY);
                        
                        // Make an AJAX request to update the map
                        let xhttp = new XMLHttpRequest();
                        xhttp.onreadystatechange = function() {
                            if (this.readyState === 4 && this.status === 200) {
                                // Update the map HTML with the response
                                document.getElementById('map-container').innerHTML = this.responseText;
                            }
                        };
                        xhttp.open('GET', 'map_update.php?startx=' + newStartX + '&starty=' + newStartY + (kID !== -1 ? '&kid=' + kID : ''), true);
                        xhttp.send();
                    }
                </script>";
    }
}

?>
<?php

class Map {
    private $mysqli;
    public $startx, $starty;

    // Constructor
    public function __construct($db_conn) {
        $this->mysqli = $db_conn;
    }

    // Get specific map field icon
    public function getFieldIcon($fieldtype) {
        $field = "";
        switch ($fieldtype) {
            case 1:
                $field = "images/gebirge.png";
                break;
            case 2:
                $field = "images/küste.png";
                break;
            case 3:
                $field = "images/wald.png";
                break;
            case 4:
                $field = "images/wüste.png";
                break;
            case 5:
                $field = "images/hochland.png";
                break;
        }
        return $field;
    }

    public function getKingdomIconByLevel($kingdomid) {
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

    public function getArrivalTime($startx, $starty, $endx, $endy) {
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

    public function getFieldTraversalTime($fieldtype) {
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
    public function renderMap() {
        // Show info about the fields
        echo "<img src='images/hochland.png' class='map-legend' alt=''> Hochland 
              <img src='images/küste.png' class='map-legend' alt=''> Küste 
              <img src='images/wald.png' class='map-legend' alt=''> Wald 
              <img src='images/wüste.png' class='map-legend' alt=''> Wüste 
              <img src='images/gebirge.png' class='map-legend' alt=''> Gebirge<br><br>";

        // Generate URL for each arrow button
        if ($this->starty - 10 <= 0) {
            $url_up = "<a href='{$_SERVER["PHP_SELF"]}?startx=$this->startx&starty=1'><img src='images/icon_up.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        } else {
            $url_up = "<a href='{$_SERVER["PHP_SELF"]}?startx=" . $this->startx . "&starty=" . round($this->starty - 10) . "'><img src='images/icon_up.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        }

        if ($this->startx - 10 <= 0) {
            $link_links = "<a href='{$_SERVER["PHP_SELF"]}?startx=1&starty=" . $this->starty . "'><img src='images/icon_left.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        } else {
            $link_links = "<a href='{$_SERVER["PHP_SELF"]}?startx=" . round($this->startx - 10) . "&starty=$this->starty'><img src='images/icon_left.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        }

        if ($this->startx >= 90 && $this->startx <= 100) {
            $link_rechts = "<a href='{$_SERVER["PHP_SELF"]}?startx=91&starty=" . $this->starty . "'><img src='images/icon_right.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        } else {
            $link_rechts = "<a href='{$_SERVER["PHP_SELF"]}?startx=" . (min($this->startx + 10, 91)) . "&starty=" . $this->starty . "'><img src='images/icon_right.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        }

        if ($this->starty >= 90 && $this->starty <= 100) {
            $link_unten = "<a href='{$_SERVER["PHP_SELF"]}?startx=" . $this->startx . "&starty=91'><img src='images/icon_down.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        } else {
            $link_unten = "<a href='{$_SERVER["PHP_SELF"]}?startx=" . $this->startx . "&starty=" . (min($this->starty + 10, 91)) . "'><img src='images/icon_down.png' style='width:24px; height:24px; margin: 5px;' alt=''/></a>";
        }

        // Coords Variable
        $coords = array();
        for ($c = 1; $c <= 100; $c++) {
            $coords[$c] = array();

            for ($r = 1; $r <= 100; $r++) {
                $coords[$c][$r] = "<img src='/images/hochland.png' alt=''/>";
            }
        }

        $xstart = $this->startx;
        $xend = $this->startx + 9;
        $ystart = $this->starty;
        $yend = $this->starty + 9;
        $stmt = $this->mysqli->prepare("SELECT * FROM map WHERE mapx BETWEEN ? AND ? AND mapy BETWEEN ? AND ?");
        $stmt->bind_param('iiii', $xstart, $xend, $ystart, $yend);
        $stmt->execute();
        $result2 = $stmt->get_result();

        ?>
        <style>
            .image-container {
                position: relative;
                width: 50px;
                height: 50px;
            }

            .overlay-img {
                position: absolute;
                top: 0;
                left: 0;
                width: 50px;
            }

            td {
                text-align: center;
                margin: 0;
                padding: 0;
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
                while ($row2 = $result2->fetch_assoc()) {
                    $fieldImage = "<div class='image-container'>";

                    if ($row2["kingdomid"] != -1) {
                        $fieldImage .= "<a href='map.php?startx=" . $this->startx . "&starty=" . $this->starty . "&kid=" . $row2["kingdomid"] . "'>
                                            <img src=" . $this->getFieldIcon($row2["fieldtype"]) . " class='map-img' alt=''>
                                            <img src='" . $this->getKingdomIconByLevel($row2["kingdomid"]) . "' class='map-img overlay-img' alt=''>
                                            </a>";
                    } else {
                        $fieldImage .= "<img src=" . $this->getFieldIcon($row2["fieldtype"]) . " class='map-img' alt=''>";
                    }

                    $fieldImage .= "</div>";

                    $coords[$row2["mapx"]][$row2["mapy"]] = "<div class='cell-container'>" . $fieldImage . "</div>";
                }

                for ($i = $this->starty; $i <= $this->starty + 9; $i++) {
                    echo "<tr>";
                    echo "<td style='padding: 15px;'>$i</td>";

                    for ($j = $this->startx; $j <= $this->startx + 9; $j++) {
                        echo "<td>{$coords[$j][$i]}</td>";
                        if ($j == $xend && $i == $ystart) echo "<td rowspan='12' class='left-right-cell td-gradient'>$link_rechts</td>";
                    }

                    echo "</tr>";
                }

                echo "<tr><td>Y<br>X</td><td>$this->startx</td><td>" . $this->startx + 1 . "</td><td>" . $this->startx + 2 . "</td><td>" . $this->startx + 3 . "</td><td>" . $this->startx + 4 . "</td><td>" . $this->startx + 5 . "</td>
                        <td>" . $this->startx + 6 . "</td><td>" . $this->startx + 7 . "</td><td>" . $this->startx + 8 . "</td><td>" . $this->startx + 9 . "</td></tr>
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
    }
}

?>
<?php
global $db_instance, $user;
require_once("functions.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php", 0);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/banner.html");
?>
<div class="content">
    <div class="content-box">
        <div class="left-container">
            <?php
            include_once("layout/left.php");
            ?>
        </div>

        <div class="middle-container">
            <div class="big-box-container">
                <div class="big-box-header"><p>Rangliste</p></div>
                <div class="big-box-content">
                    <?php
                    $mysqli = $db_instance;
                    $rowsperpage = 10;

                    // Get the current page or set a default
                    $currentpage = isset($_GET["currentpage"]) ? max(1, $_GET["currentpage"]) : 1;

                    // Get the total number of rows
                    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM users");
                    $stmt->execute();
                    $stmt->bind_result($numrows);
                    $stmt->fetch();
                    $stmt->close();

                    $totalpages = ceil($numrows / $rowsperpage);

                    // Calculate the offset
                    $offset = ($currentpage - 1) * $rowsperpage;

                    // Get the data for the current page
                    $stmt = $mysqli->prepare("SELECT * FROM users ORDER BY score DESC LIMIT ?, ?");
                    $stmt->bind_param('ii', $offset, $rowsperpage);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    ?>

                    <table class="table">
                        <tr>
                            <td class="td-center td-gradient"
                                style="width: 20%;"
                                colspan="2">
                                <b>Rang</b></td>
                            <td class="td-center td-gradient"
                                style="width: 50%;">
                                <b>Benutzer</b></td>
                            <td class="td-center td-gradient"
                                style="width: 20%;">
                                <b>Punkte</b></td>
                        </tr>
                        <?php
                        $position = ($currentpage - 1) * $rowsperpage + 1;

                        while ($row = $result->fetch_assoc()) {
                            $icon = "";
                            $change = "";
                            $color = (time() - $row["lastactivity"] > TIMEOUT_MAX_SECONDS) ? "#F55353" : (time() - $row["lastactivity"] > AFK_SECONDS ? "#FEDC56" : "#0BDA51");
                            $lastactivity = ($row["lastactivity"] == 0) ? "Nicht verfügbar" : (date('d.m.Y', $row['lastactivity']) . " um " . date('H:i:s', $row['lastactivity']));
                            $diff = $row['lastrank'] - $position; // Check last rank (since 00:00) and compare with current rank

                            if ($position < $row["lastrank"]) {
                                $icon = "<img src='images/icons/icon_arrow_up.png' class='ressource-icons' alt=''>";
                                $change = "+" . $diff;
                            } else if ($position > $row["lastrank"]) {
                                $icon = "<img src='images/icons/icon_arrow_down.png' class='ressource-icons' alt=''>";
                                $change = $diff;
                            }

                            echo "<tr>
                                        <td class='td-center' style='min-width: 12%; text-align: right; border-right: none;'>$position</td>
                                        <td style='border-left: none; padding: 0; margin:0;'>
                                            <div class='popup' id='description" . $position . "'>$icon</div>
                                            <div id='description" . $position . "_box' class='popupbox'>Rang um 0 Uhr: {$row['lastrank']} ($change)</div>
                                        </td>
                                        <td>
                                            <div>
                                                <a href='javascript:void(0);' onclick='openUserDetails(\"userinfo.php?userid=" . $row["id"] . "\");' class='popup' id='activity" . $position . "' style='color: $color; cursor: pointer;'>{$row["username"]}</a>
                                                <div id='activity" . $position . "_box' class='popupbox'>Letzte Aktivität: $lastactivity</div>
                                            </div>
                                        </td>
                                        <td class='td-center'>{$row["score"]}</td>
                                    </tr>";

                            $position++;
                        }
                        $stmt->close();
                        ?>
                    </table>

                    <br>

                    <!-- Build the pagination links -->
                    <?php
                    if ($currentpage > 1) {
                        echo "<a href='ranking.php?currentpage=1'> Erste </a>";
                        $prevpage = $currentpage - 1;
                        echo "<a href='ranking.php?currentpage=$prevpage'> Zurück </a>";
                    }

                    for ($x = max(1, $currentpage - 3); $x <= min($totalpages, $currentpage + 3); $x++) {
                        if ($x == $currentpage) {
                            echo "<span class='current'> [$x] </span>";
                        } else {
                            echo "<a href='ranking.php?currentpage=$x'> $x </a>";
                        }
                    }

                    if ($currentpage < $totalpages) {
                        $nextpage = $currentpage + 1;
                        echo "<a href='ranking.php?currentpage=$nextpage'> Vor </a>";
                        echo "<a href='ranking.php?currentpage=$totalpages'> Letzte </a>";
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="right-container">
            <?php
            include_once("layout/right.php");
            ?>
        </div>
    </div>
</div>
<?php
include_once("layout/footer.php");
?>
</body>
</html>

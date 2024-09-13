<?php
global $user, $db_instance;
?>
<div class="box-container" id="ressource-box">
    <div class="box-header">Königreich-Info</div>
    <div class="box-content" style="padding: 10px; background-color: var(--box-content-color);">
        <?php
        // Get all kingdoms of a player for him to change anytime
        $userid = $user->getUserID();

        $result = $db_instance->execute_query("SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ?", [$userid]);
        $row = $result->fetch_assoc();
        ?>
        <form action="index.php" method="POST">
            <label>
                <select name="chooseKingdom" onchange="updateKingdom()"
                        style="width: 100%;">
                    <?php
                    foreach ($result as $row) {
                        $kingdom_name = $row["kingdomname"];
                        $map_coords = "($row[mapx]:$row[mapy])";
                        $selected = ($row["id"] == $_SESSION["kingdomid"]) ? "selected='selected'" : "";

                        echo "<option value='{$row["id"]}' $selected>$kingdom_name $map_coords</option>";
                    }
                    ?>
                </select>
            </label>
        </form>
        <br><br>
        <div style='border-bottom: 2px solid rgba(0, 0, 0, 0.5); margin-bottom: 5px; padding-bottom: 5px;'>
            <img src='images/icons/icon_time.png' class='ressource-icons' alt='Serverzeit' title='Serverzeit'/><span
                    id='servertime'><script
                        type="text/javascript">updateTime(<?php echo time(); ?>)</script></span>
        </div>
        <img src='images/icons/icon_score.png' class='ressource-icons' alt='Punkte'
             title='Punkte'/> <?= ($user->getUserScore() == 0 ? "0" : fnum($user->getUserScore())) ?>
        <div id="kingdom-info">
            <?php
            // Get kingdom resources and show information
            $kingdom = new Kingdoms($db_instance);
            $kingdom->getKingdomInfo($_SESSION["kingdomid"]);
            $kingdom->renderKingdomInfo();
            ?>
        </div>
    </div>
</div>
<div class="box-container">
    <div class="box-header">Gebäude</div>
    <div class="box-content">
        <div id="kingdom-buildings">
            <?php
            // Show kingdom buildings
            $kingdom->getKingdomBuildings($_SESSION["kingdomid"]);
            ?>
        </div>
    </div>
</div>
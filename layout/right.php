<?php
global $user, $db_instance;
?>
<div class="box-container" id="ressource-box">
    <div class="box-header">Königreich-Info</div>
    <div class="box-content" style="padding: 10px 10px 20px; background-color: var(--box-content-color);">
        <?php
        // Check if user has changed kingdom via dropdown menu
        if (isset($_POST["chooseKingdom"])) {
            $_SESSION["kingdomid"] = $_POST["chooseKingdom"];
            unset($_POST);
        }

        // Get all kingdoms of a player for him to change anytime
        $mysqli = $db_instance;
        $userid = $user->getUserID();

        $stmt = $mysqli->prepare("SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ?");
        $stmt->bind_param('i', $userid);
        $stmt->execute();
        $stmt->bind_result($kingdomid, $kingdomname, $x, $y);
        ?>

        <form action="index.php" method="POST">
            <label>
                <select name="chooseKingdom" onchange="this.form.submit();" style="width:170px">
                    <?php
                    while ($stmt->fetch()) {
                        if ($kingdomid == $_SESSION["kingdomid"]) {
                            echo "<option value='{$_SESSION["kingdomid"]}' selected='selected'>$kingdomname ($x:$y)</option>";
                        } else {
                            echo "<option value='$kingdomid'>$kingdomname ($x:$y)</option>";
                        }
                    }
                    $stmt->close();
                    ?>
                </select>
            </label>
        </form>
        <br><br>

        <?php
        // Get kingdom ressources and show information
        $kingdom = new Kingdoms($db_instance);
        $kingdom->getKingdomRessources($_SESSION["kingdomid"]);
        $serverTime = time();
        ?>
        <div style='border-bottom: 2px solid rgba(0, 0, 0, 0.5); margin-bottom: 5px; padding-bottom: 5px;'>
            <img src='images/icons/icon_time.png' class='ressource-icons' alt='Serverzeit'/><span id='servertime'><script>updateTime(<?php echo $serverTime ?>)</script></span>
        </div>
        <?php
        echo "     <img src='images/icons/icon_score.png' class='ressource-icons' alt='Punkte'> " . ($user->getUserScore() == 0 ? "0" : $user->getUserScore()) . "
                    <div class='split-content'>
                        <div><img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung'> 
                        <span style='color: " . ($kingdom->getKingdomFood() == $kingdom->getKingdomMaxFood() ? "#FFFF7F" : "#FFFFFF") . ";'>" . $kingdom->getKingdomFood() . "</span></div>
                        <div>({$kingdom->getKingdomFoodPerHour()}/h)</div>
                    </div>
                    <div class='split-content'>
                        <div><img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz'> 
                        <span style='color: " . ($kingdom->getKingdomWood() == $kingdom->getKingdomMaxWood() ? "#FFFF7F" : "#FFFFFF") . ";'>" . $kingdom->getKingdomWood() . "</span></div>
                        <div>({$kingdom->getKingdomWoodPerHour()}/h)</div>
                    </div>
                    <div class='split-content'>
                        <div><img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein'> 
                        <span style='color: " . ($kingdom->getKingdomStone() == $kingdom->getKingdomMaxStone() ? "#FFFF7F" : "#FFFFFF") . ";'>" . $kingdom->getKingdomStone() . "</span></div>
                        <div>({$kingdom->getKingdomStonePerHour()}/h)</div>
                    </div>
                    <div class='split-content'>
                        <div><img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold'> 
                        <span style='color: " . ($kingdom->getKingdomGold() == $kingdom->getKingdomMaxGold() ? "#FFFF7F" : "#FFFFFF") . ";'>" . $kingdom->getKingdomGold() . "</span></div>
                        <div>({$kingdom->getKingdomGoldPerHour()}/h)</div>
                    </div>
                    <div class='split-content'>
                        <div><img src='images/icons/icon_villager.png' class='ressource-icons' alt='Dorfbewohner'> 
                        <span style='color: " . ($kingdom->getKingdomVillager() == $kingdom->getKingdomMaxVillager() ? "#FFFF7F" : "#FFFFFF") . ";'>" . $kingdom->getKingdomVillager() . "</span></div>
                        <div>({$kingdom->getKingdomVillagerPerHour()}/h)</div>
                    </div>";
        ?>
    </div>
</div>
<div class="box-container">
    <div class="box-header">Gebäude</div>
    <div class="box-content">
        <?php
        // Show kingdom buildings
        $kingdom->getKingdomBuildings($_SESSION["kingdomid"]);
        ?>
    </div>
</div>
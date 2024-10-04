<?php
global $user, $db_instance;
?>
<div class="box-container" id="ressource-box">
    <div class="box-header">Königreich-Info</div>
    <div class="box-content" style="padding: 10px; background-color: var(--box-content-color);">
        <?php
        // Get all kingdoms of a player for him to change anytime
        $result = $db_instance->execute_query("SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ?", [$user->get_user_id()]);
        $row = $result->fetch_assoc();
        $current_timestamp = time();
        ?>
        <form method="POST">
            <label>
                <select id="choosekingdom" name="choosekingdom" onchange="updateKingdom(this)"
                        style="width: 100%;">
                    <?php
                    foreach ($result as $row) {
                        $selected = ($row["id"] == $user->get_current_kingdom()) ? "selected='selected'" : "";

                        echo "<option value='{$row["id"]}' $selected>{$row["kingdomname"]} ({$row["mapx"]}:{$row["mapy"]})</option>";
                    }
                    ?>
                </select>
            </label>
        </form>
        <br>
        <div style='border-bottom: 2px solid rgba(0, 0, 0, 0.5); margin-bottom: 5px; padding-bottom: 5px;'>
            <img src='images/icons/icon_time.png' class='ressource-icons' alt='Serverzeit' title='Serverzeit'/>
            <span class='servertime'><?= date("H:i:s", $current_timestamp); ?></span>
        </div>
        <img src='images/icons/icon_score.png' class='ressource-icons' alt='Punkte'
             title='Punkte'/> <?php echo fnum($user->get_user_score()); ?>
        <div id="kingdom-info">
            <?php
            // Get kingdom resources and show information
            $kingdom = new Kingdoms($db_instance);
            $kingdom->get_kingdom_info($user->get_current_kingdom());
            $kingdom->render_kingdom_info();
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
            $kingdom->get_kingdom_buildings($user->get_current_kingdom());
            ?>
        </div>
    </div>
</div>
<script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function () {
        updateServerTime(<?= $current_timestamp; ?>);
    });
</script>
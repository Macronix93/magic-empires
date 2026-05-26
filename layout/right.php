<div class="box-container" id="ressource-box">
    <div class="box-header">Königreich-Info</div>
    <div class="box-content" style="padding: 10px; background-color: var(--box-content-color);">
        <?php
        $kingdom = new Kingdom($db_instance, $user->get_current_kingdom());

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
             title='Punkte'/> <?= fnum($user->get_user_score()); ?>
        <div id="kingdom-info">
            <div class='split-content'>
                <div><?= get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) ?>
                    <span class='<?= $kingdom->get_kingdom_food() >= $kingdom->get_kingdom_max_food() ? "over-limit" : "under-limit" ?>'>
                        <?= fnum($kingdom->get_kingdom_food()) ?>
                    </span>
                </div>
                (<?= fnum($kingdom->get_kingdom_food_per_hour()) ?>/h)
            </div>
            <div class='split-content'>
                <div><?= get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) ?>
                    <span class='<?= $kingdom->get_kingdom_wood() >= $kingdom->get_kingdom_max_wood() ? "over-limit" : "under-limit" ?>'>
                        <?= fnum($kingdom->get_kingdom_wood()) ?>
                    </span>
                </div>
                (<?= fnum($kingdom->get_kingdom_wood_per_hour()) ?>/h)
            </div>
            <div class='split-content'>
                <div><?= get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) ?>
                    <span class='<?= $kingdom->get_kingdom_stone() >= $kingdom->get_kingdom_max_stone() ? "over-limit" : "under-limit" ?>'>
                        <?= fnum($kingdom->get_kingdom_stone()) ?>
                    </span>
                </div>
                (<?= fnum($kingdom->get_kingdom_stone_per_hour()) ?>/h)
            </div>
            <div class='split-content'>
                <div><?= get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) ?>
                    <span class='<?= $kingdom->get_kingdom_gold() >= $kingdom->get_kingdom_max_gold() ? "over-limit" : "under-limit" ?>'>
                        <?= fnum($kingdom->get_kingdom_gold()) ?>
                    </span>
                </div>
                (<?= fnum($kingdom->get_kingdom_gold_per_hour()) ?>/h)
            </div>
            <div class='split-content'>
                <div>
                    <img src='images/icons/icon_villager.png' class='ressource-icons' alt='Dorfbewohner'
                         title='Dorfbewohner'/>
                    <span class='<?= $kingdom->get_kingdom_villager() >= $kingdom->get_kingdom_max_villager() ? "over-limit" : "under-limit" ?>'>
                        <?= fnum($kingdom->get_kingdom_villager()) ?> / <?= fnum($kingdom->get_kingdom_max_villager()) ?>
                    </span>
                </div>
                (<?= fnum($kingdom->get_kingdom_villager_per_hour()) ?>/h)
            </div>
            <img src='images/icons/icon_coins.png' class='ressource-icons' alt='Münzen'
                 title='Münzen'/> <?= fnum($user->get_user_coins()); ?>
        </div>
    </div>
</div>
<div class="box-container">
    <div class="box-header">Gebäude</div>
    <div class="box-content">
        <div id="kingdom-buildings">
            <?php
            // Show kingdom buildings
            $kingdom_buildings = $kingdom->get_kingdom_buildings($user->get_current_kingdom());
            $current_page = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

            foreach ($kingdom_buildings as $building) {
                $building_file = $building['buildingfile'] . ".php";
                $building_obj = new Building($db_instance);
                $building_obj->set_building_id($building["buildingid"]);
                $building_obj->set_building_name($building["buildingname"]);

                echo "<div class='box" . ($current_page === $building_file ? ' active' : '') . "' onclick=\"navigateTo('" . $building_file . "', this)\">" .
                        $building_obj->get_building_icon("menu-icons") . " {$building['buildingname']}</div>";
            }
            ?>
        </div>
    </div>
</div>
<script type="text/javascript">
    document.addEventListener("DOMContentLoaded", function () {
        updateServerTime(<?= $current_timestamp; ?>);
    });
</script>
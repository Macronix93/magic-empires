<div class="box-container" id="ressource-box">
    <div class="box-header">Königreich-Info</div>
    <div class="box-content" style="padding: 10px; background-color: var(--box-content-color);">
        <?php
        $kingdom = new Kingdom($db_instance, $user->get_current_kingdom());

        // Get all kingdoms of a player for him to change anytime
        $result = $db_instance->execute_query("SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ?", [$user->get_user_id()]);
        $row = $result->fetch_assoc();
        $current_timestamp = time();

        $active_boosts = $kingdom->get_active_boosts(); // Get active resource boosts
        $display_food = $kingdom->get_kingdom_food_per_hour() + ($active_boosts[ResourceTypes::RESOURCE_TYPE_FOOD]["amount"] ?? 0);
        $display_wood = $kingdom->get_kingdom_wood_per_hour() + ($active_boosts[ResourceTypes::RESOURCE_TYPE_WOOD]["amount"] ?? 0);
        $display_stone = $kingdom->get_kingdom_stone_per_hour() + ($active_boosts[ResourceTypes::RESOURCE_TYPE_STONE]["amount"] ?? 0);
        $display_gold = $kingdom->get_kingdom_gold_per_hour() + ($active_boosts[ResourceTypes::RESOURCE_TYPE_GOLD]["amount"] ?? 0);
        ?>
        <form method="POST">
            <div class="kingdom-switch-container">
                <?php
                $kingdom_count = $result->num_rows;

                if ($kingdom_count > 1): ?>
                    <img src="images/icons/icon_right_slow.png"
                         class="arrow-nav arrow-left"
                         data-on-click="switchKingdomPrev"
                         title="Vorheriges Königreich" alt="">
                <?php endif; ?>


                <label for="choosekingdom" style="display: none;">Königreich wählen</label>
                <select id='choosekingdom' name='choosekingdom' data-on-change='changeKingdomSelect'>
                    <?php
                    $result->data_seek(0);

                    foreach ($result as $row) {
                        $selected = ($row["id"] == $user->get_current_kingdom()) ? "selected='selected'" : "";

                        echo "<option value='{$row["id"]}' $selected>{$row["kingdomname"]} ({$row["mapx"]}:{$row["mapy"]})</option>";
                    }
                    ?>
                </select>

                <?php if ($kingdom_count > 1): ?>
                    <img src="images/icons/icon_right_slow.png"
                         class="arrow-nav"
                         data-on-click="switchKingdomNext"
                         title="Nächstes Königreich" alt="">
                <?php endif; ?>
            </div>
        </form>
        <div class="resource-tick-wrapper">
            <div class="tick-label">
                <span>Nächster Ertrag in:</span>
                <span class="tick-timer">--:--</span>
            </div>
            <div class="tick-progress-bg">
                <div class="tick-progress-fill"></div>
            </div>
        </div>
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
                (<?= fnum($display_food) ?>/h)
            </div>
            <div class='split-content'>
                <div><?= get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) ?>
                    <span class='<?= $kingdom->get_kingdom_wood() >= $kingdom->get_kingdom_max_wood() ? "over-limit" : "under-limit" ?>'>
                        <?= fnum($kingdom->get_kingdom_wood()) ?>
                    </span>
                </div>
                (<?= fnum($display_wood) ?>/h)
            </div>
            <div class='split-content'>
                <div><?= get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) ?>
                    <span class='<?= $kingdom->get_kingdom_stone() >= $kingdom->get_kingdom_max_stone() ? "over-limit" : "under-limit" ?>'>
                        <?= fnum($kingdom->get_kingdom_stone()) ?>
                    </span>
                </div>
                (<?= fnum($display_stone) ?>/h)
            </div>
            <div class='split-content'>
                <div><?= get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) ?>
                    <span class='<?= $kingdom->get_kingdom_gold() >= $kingdom->get_kingdom_max_gold() ? "over-limit" : "under-limit" ?>'>
                        <?= fnum($kingdom->get_kingdom_gold()) ?>
                    </span>
                </div>
                (<?= fnum($display_gold) ?>/h)
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
            <div class="split-content">
                <div>
                    <img src='images/icons/icon_coins.png' class='ressource-icons' alt='Münzen'
                         title='Münzen'/> <?= fnum($user->get_user_coins()); ?>
                </div>
                (5/h)
            </div>
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
                $building_obj = new Building();
                $building_obj->set_building_id($building["buildingid"]);
                $building_obj->set_building_name($building["buildingname"]);

                echo "<div class='menu-icons-small box" . ($current_page === $building_file ? ' active' : '') . "' 
                           data-on-click='navigate' 
                           data-url='" . e($building_file) . "'>" .
                        $building_obj->get_building_icon("menu-icons") . " " . e($building['buildingname']) . "</div>";
            }
            ?>
        </div>
    </div>
</div>
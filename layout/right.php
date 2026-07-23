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
        $current_align = $kingdom->get_kingdom_alignment();
        $shrine_mod = $kingdom->get_shrine_modifier();
        $shrine_malus = SHRINE_MALUS_BASE;

        $resource_config = [
                ResourceTypes::RESOURCE_TYPE_FOOD => [
                        "icon" => ResourceTypes::RESOURCE_TYPE_FOOD,
                        "val" => $kingdom->get_kingdom_food(), "max" => $kingdom->get_kingdom_max_food(),
                        "base_prod" => $kingdom->get_base_food_rate(),
                        "total_prod_with_shrine" => $kingdom->get_kingdom_food_per_hour()
                ],
                ResourceTypes::RESOURCE_TYPE_WOOD => [
                        "icon" => ResourceTypes::RESOURCE_TYPE_WOOD,
                        "val" => $kingdom->get_kingdom_wood(), "max" => $kingdom->get_kingdom_max_wood(),
                        "base_prod" => $kingdom->get_base_wood_rate(),
                        "total_prod_with_shrine" => $kingdom->get_kingdom_wood_per_hour()
                ],
                ResourceTypes::RESOURCE_TYPE_STONE => [
                        "icon" => ResourceTypes::RESOURCE_TYPE_STONE,
                        "val" => $kingdom->get_kingdom_stone(), "max" => $kingdom->get_kingdom_max_stone(),
                        "base_prod" => $kingdom->get_base_stone_rate(),
                        "total_prod_with_shrine" => $kingdom->get_kingdom_stone_per_hour()
                ],
                ResourceTypes::RESOURCE_TYPE_GOLD => [
                        "icon" => ResourceTypes::RESOURCE_TYPE_GOLD,
                        "val" => $kingdom->get_kingdom_gold(), "max" => $kingdom->get_kingdom_max_gold(),
                        "base_prod" => $kingdom->get_base_gold_rate(),
                        "total_prod_with_shrine" => $kingdom->get_kingdom_gold_per_hour()
                ],
        ];
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
            <?php
            foreach ($resource_config as $type => $data) {
                $item_boost = $active_boosts[$type]["amount"] ?? 0;
                $ticks_left = $active_boosts[$type]["ticks"] ?? 0;

                // Actual difference of shrine bonus
                $actual_shrine_diff = $data["total_prod_with_shrine"] - $data["base_prod"];

                $has_shrine_effect = ($actual_shrine_diff != 0 && $current_align != AlignmentTypes::ALIGN_NONE);
                $has_item_boost = ($item_boost > 0);

                // Should the Popup Box be shown?
                $show_popup = ($has_shrine_effect || $has_item_boost);

                $total_display_prod = $data["total_prod_with_shrine"] + $item_boost;

                // Set CSS classes
                $prod_class = $show_popup ? "popup" : "";

                if ($total_display_prod > $data["base_prod"]) {
                    $prod_class .= " passed";
                } else if ($total_display_prod < $data["base_prod"]) {
                    $prod_class .= " error";
                }

                $prod_id = "boost_info_" . $type;

                echo "<div class='split-content'>
            <div>" . get_resource_icon($data["icon"]) . "
                <span class='" . ($data["val"] >= $data["max"] ? "over-limit" : "under-limit") . "'>
                    " . fnum($data["val"]) . "
                </span>
            </div>
            <div class='$prod_class' id='$prod_id'>
                (" . fnum($total_display_prod) . "/h)";

                if ($show_popup) {
                    echo "<div id='{$prod_id}_box' class='popupbox'>
                            <b>Aktive Boosts:</b><br>
                            Basis & Forschung: " . fnum($data["base_prod"]) . "/h<br>";

                    if ($has_shrine_effect) {
                        if ($actual_shrine_diff > 0) {
                            echo "<span class='passed'>Schrein-Bonus: +" . fnum($actual_shrine_diff) . "/h</span><br>";
                        } else {
                            echo "<span class='error'>Schrein-Malus: -" . fnum(abs($actual_shrine_diff)) . "/h</span><br>";
                        }
                    }

                    if ($has_item_boost) {
                        echo "<span class='passed'>Gebäude-Boost: +" . fnum($item_boost) . "/h</span> <small>(Noch $ticks_left Erträge)</small>";
                    }

                    echo "</div>";
                }

                echo "</div></div>";
            }
            ?>
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
            // Number of marketplace offers
            $res_market = $db_instance->query("SELECT COUNT(*) FROM marketplace");
            $total_market_offers = $res_market->fetch_row()[0] ?? 0;

            // Show kingdom buildings
            $kingdom_buildings = $kingdom->get_kingdom_buildings($user->get_current_kingdom());
            $current_page = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

            foreach ($kingdom_buildings as $building) {
                $building_file = $building["buildingfile"] . ".php";
                $building_obj = new Building();
                $building_obj->set_building_id($building["buildingid"]);
                $building_obj->set_building_name($building["buildingname"]);

                $display_name = e($building["buildingname"]);
                if ($building["buildingid"] == BuildingTypes::BUILDING_MARKETPLACE && $total_market_offers > 0) {
                    $display_name .= "&nbsp;($total_market_offers)";
                }

                echo "<div class='menu-icons-small box" . ($current_page === $building_file ? ' active' : '') . "' 
                           data-on-click='navigate' 
                           data-url='" . e($building_file) . "'>" .
                        $building_obj->get_building_icon("menu-icons") . " " . $display_name . "</div>";
            }
            ?>
        </div>
    </div>
</div>
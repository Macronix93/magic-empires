<?php

class ResourceBuilding {
    private const array CONFIG = [
        BuildingTypes::BUILDING_MILL => [
            "res_type"  => ResourceTypes::RESOURCE_TYPE_FOOD,
            "rate_col"  => "foodrate",
            "base_gain" => BASE_FOOD_GAIN,
            "label"     => "Nahrungsertrag",
            "file"      => "mill.php"
        ],
        BuildingTypes::BUILDING_SAWMILL => [
            "res_type"  => ResourceTypes::RESOURCE_TYPE_WOOD,
            "rate_col"  => "woodrate",
            "base_gain" => BASE_WOOD_GAIN,
            "label"     => "Holzertrag",
            "file"      => "sawmill.php"
        ],
        BuildingTypes::BUILDING_STONEMINE => [
            "res_type"  => ResourceTypes::RESOURCE_TYPE_STONE,
            "rate_col"  => "stonerate",
            "base_gain" => BASE_STONE_GAIN,
            "label"     => "Steinertrag",
            "file"      => "stonemine.php"
        ],
        BuildingTypes::BUILDING_GOLDMINE => [
            "res_type"  => ResourceTypes::RESOURCE_TYPE_GOLD,
            "rate_col"  => "goldrate",
            "base_gain" => BASE_GOLD_GAIN,
            "label"     => "Goldertrag",
            "file"      => "goldmine.php"
        ],
    ];

    public static function handle(User $user, int $building_type): void {
        global $db_instance, $error, $view, $title, $header, $script_files;

        if (!isset(self::CONFIG[$building_type])) {
            change_location("overview.php");
            exit;
        }

        $cfg = self::CONFIG[$building_type];
        $res_type = $cfg["res_type"];
        $rate_col = $cfg["rate_col"];
        $base_gain = $cfg["base_gain"];
        $label = $cfg["label"];
        $page_file = $cfg["file"];

        $result = $user->check_user_login_and_kingdom($building_type);
        $current_kingdom = $result['current_kingdom'];
        $building = $result['building'];
        $building_name = $building->get_building_name();
        $kingdom = $result['kingdom'];
        $lvl = $building->get_building_level();

        $boost_cost = BOOST_COIN_BASE + BOOST_COIN_FACTOR * max(0, $lvl - 1);
        $active_boosts = $kingdom->get_active_boosts($res_type);
        $this_boost = $active_boosts[$res_type] ?? null;
        $boost_amount = $this_boost["amount"] ?? 0;
        $ticks_left = $this_boost["ticks"] ?? 0;
        $boost_display = ($boost_amount > 0) ? " <span class='passed'>(+" . fnum($boost_amount) . ")</span>" : "";
        $boost_duration_ticks = $lvl + BASE_BOOST_DURATION;

        if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["activate_boost"])) {
            if ($ticks_left == 0) {
                if ($user->get_user_coins() >= $boost_cost) {
                    $user->give_user_coins(-$boost_cost);
                    $hourly_boost = BOOST_BASE_AMOUNT + ($lvl * BOOST_AMOUNT_PER_LEVEL);
                    $db_instance->execute_query(
                        "INSERT INTO kingdom_boosts (kingdomid, resource_type, ticks_remaining, boost_amount) 
                         VALUES (?, ?, ?, ?) 
                         ON DUPLICATE KEY UPDATE ticks_remaining = VALUES(ticks_remaining), boost_amount = VALUES(boost_amount)",
                        [$current_kingdom, $res_type, $boost_duration_ticks, $hourly_boost]
                    );
                    Achievement::unlock($user->get_user_id(), AchievementTypes::ACHIEVEMENT_BOOST_ACTIVATED);
                    change_location($page_file);
                    exit;
                } else {
                    $error = "Zu wenig Münzen!";
                }
            } else {
                $error = "Der Boost ist bereits aktiv!";
            }
        }

        /*
         * HTML Content Part
         */
        $can_afford = ($user->get_user_coins() >= $boost_cost);
        $disabled = ($can_afford && $lvl > 0) ? "" : "disabled";
        $cost_display_html = get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " " . ($can_afford ? $boost_cost : "<b class='error'>$boost_cost</b>");

        $res_ft = $db_instance->execute_query("
            SELECT ft.$rate_col AS fieldrate 
            FROM map m 
            JOIN field_types ft ON m.fieldtype = ft.fieldid 
            WHERE m.kingdomid = ?", [$current_kingdom]);
        $ft_res = $res_ft->fetch_assoc();
        $field_rate = (float)($ft_res["fieldrate"] ?? 1.0);

        $base_rate = match ($res_type) {
            ResourceTypes::RESOURCE_TYPE_FOOD  => $kingdom->get_base_food_rate(),
            ResourceTypes::RESOURCE_TYPE_WOOD  => $kingdom->get_base_wood_rate(),
            ResourceTypes::RESOURCE_TYPE_STONE => $kingdom->get_base_stone_rate(),
            ResourceTypes::RESOURCE_TYPE_GOLD  => $kingdom->get_base_gold_rate(),
            default => 0
        };

        $boost_value = BOOST_BASE_AMOUNT + ($lvl * BOOST_AMOUNT_PER_LEVEL);
        $next_level_text = "";
        if ($lvl < MAX_BUILDING_LEVEL) {
            $next_increase = (int)round($base_gain * $field_rate);
            $next_level_text = "<div class='split-content'><b>Nächste Stufe:</b><div class='passed'>" . fnum($base_rate + $next_increase) . " (+" . fnum($next_increase) . ")</div></div>";
        }

        $view .= "<div class='res-building-content'>
            <div class='split-content'><b>$label pro Stunde:</b> <div>" . fnum($base_rate) . " $boost_display</div></div>
            $next_level_text
        </div> ";

        if ($ticks_left > 0) {
            $view .= "<p style='margin-bottom: 0;'>Ertragsboost aktiv!<br>Verbleibende Erträge: <span style='font-weight: bold;'>$ticks_left</span></p>";
        } else {
            $view .= "<p>Ein Boost erhöht den Basis-Ertrag für die nächsten <b>$boost_duration_ticks Erträge</b> um <b>" . fnum($boost_value) . "</b>.</p>";
            $view .= "<form method='POST'>
                <button type='submit' name='activate_boost' class='boost-button' $disabled>
                    Boost aktivieren für $cost_display_html
                </button>
            </form>";
        }

        /*
         * HTML Section
         */
        $title = $building_name;
        $header = $building_name . " (" . $lvl . ")";
        $script_files = ["timer"];

        include("layout/base.php");
    }
}
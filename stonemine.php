<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_STONEMINE);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

$res_type = ResourceTypes::RESOURCE_TYPE_STONE;
$lvl = $building->get_building_level();
$boost_cost = $lvl * BOOST_COST_PER_LEVEL;
$active_boosts = $kingdom->get_active_boosts($res_type);
$this_boost = $active_boosts[$res_type] ?? null;
$boost_amount = $this_boost["amount"] ?? 0;
$expiry = $kingdom->get_boost_expiry($res_type);
$boost_display = ($boost_amount > 0) ? " <span class='passed'>(+" . fnum($boost_amount) . ")</span>" : "";

if (isset($_POST["activate_boost"])) {
    if ($expiry == 0) {
        if ($kingdom->get_kingdom_stone() >= $boost_cost) {
            $kingdom->give_kingdom_stone(-$boost_cost);

            $res_ft = $db_instance->execute_query("SELECT ft.stonerate FROM map m JOIN field_types ft ON m.fieldtype = ft.fieldid WHERE m.kingdomid = ?", [$current_kingdom]);
            $ft = $res_ft->fetch_assoc();

            $hourly_boost = (int)round(BASE_STONE_GAIN * $ft["stonerate"] * BOOST_PRODUCTION_BONUS);
            $until = time() + ($lvl * 3600 * BOOST_DURATION_MULTIPLIER);

            $db_instance->execute_query(
                "INSERT INTO kingdom_boosts (kingdomid, resource_type, expires_at, boost_amount) 
                        VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE expires_at = ?, boost_amount = ?",
                [$current_kingdom, $res_type, $until, $hourly_boost, $until, $hourly_boost]
            );

            $kingdom->recalculate_production();

            change_location("stonemine.php");
            exit;
        } else {
            $error = "Zu wenig Gold!";
        }
    } else {
        $error = "Der Boost ist bereits aktiv!";
    }
}

/*
 * HTML Content Part
 */
$view .= "<div style='margin-bottom: 15px;'><b>Steinertrag pro Stunde:</b> " . fnum($kingdom->get_base_stone_rate()) . " $boost_display</div>";

if ($expiry > 0) {
    $view .= "Ertragsboost aktiv!<br>Ende in: <span class='js-countdown' data-seconds='" . ($expiry - time()) . "'></span>";
} else {
    $view .= "<form method='POST'>
                <button type='submit' name='activate_boost'>
                    Boost für " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " " . fnum($boost_cost) . "
                </button>
            </form>";
}


/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = ["counter"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
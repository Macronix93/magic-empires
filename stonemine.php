<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_STONEMINE);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

$res_type = ResourceTypes::RESOURCE_TYPE_STONE;
$lvl = $building->get_building_level();
$boost_cost = BOOST_COIN_BASE + BOOST_COIN_FACTOR * max(0, $lvl - 1);
$active_boosts = $kingdom->get_active_boosts($res_type);
$this_boost = $active_boosts[$res_type] ?? null;
$boost_amount = $this_boost["amount"] ?? 0;
$ticks_left = $this_boost["ticks"] ?? 0;
$boost_display = ($boost_amount > 0) ? " <span class='passed'>(+" . fnum($boost_amount) . ")</span>" : "";
$boost_duration_ticks = $lvl + BASE_BOOST_DURATION;

if (isset($_POST["activate_boost"])) {
    if ($ticks_left == 0) {
        if ($user->get_user_coins() >= $boost_cost) {
            $user->give_user_coins(-$boost_cost);

            $res_ft = $db_instance->execute_query("SELECT ft.stonerate FROM map m JOIN field_types ft ON m.fieldtype = ft.fieldid WHERE m.kingdomid = ?", [$current_kingdom]);
            $ft = $res_ft->fetch_assoc();

            $hourly_boost = (int)round(BASE_STONE_GAIN * $ft["stonerate"] * BOOST_PRODUCTION_BONUS);

            $db_instance->execute_query(
                "INSERT INTO kingdom_boosts (kingdomid, resource_type, ticks_remaining, boost_amount) 
                         VALUES (?, ?, ?, ?) 
                         ON DUPLICATE KEY UPDATE ticks_remaining = VALUES(ticks_remaining), boost_amount = VALUES(boost_amount)",
                [$current_kingdom, $res_type, $boost_duration_ticks, $hourly_boost]
            );

            change_location("stonemine.php");
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

$view .= "<div style='margin-bottom: 15px;'><b>Steinertrag pro Stunde:</b> " . fnum($kingdom->get_base_stone_rate()) . " $boost_display</div>";

if ($ticks_left > 0) {
    $view .= "Ertragsboost aktiv!<br>Verbleibende Erträge: <span>$ticks_left</span>";
} else {
    $view .= "<p>Ein Boost verdoppelt den Basis-Ertrag für die nächsten <b>$boost_duration_ticks Erträge</b>.</p>";

    $view .= "<form method='POST'>
                <button type='submit' name='activate_boost' $disabled>
                    Boost aktivieren für $cost_display_html
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
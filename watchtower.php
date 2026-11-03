<?php
require_once("includes/core.php");

$result = $user->check_user_login_and_kingdom(BuildingTypes::BUILDING_WATCHTOWER);
$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];
$level = $building->get_building_level();

$base_visibility = $level * WATCHTOWER_DETECTION_PER_LEVEL;
$next_visibility = ($level + 1) * WATCHTOWER_DETECTION_PER_LEVEL;

$boost_percent = BOOST_WATCHTOWER_BASE_PERCENT + (max(0, $level - BOOST_MIN_BUILDING_LEVEL) * BOOST_WATCHTOWER_STEP_PERCENT);
$raw_boost_seconds = (int)round($base_visibility * $boost_percent);
$boost_seconds = (int)(floor($raw_boost_seconds / 60) * 60);

$boost_duration_ticks = (int)round(($level + BASE_BOOST_DURATION) * BOOST_WATCHTOWER_DURATION_FACTOR);
$boost_cost = BOOST_COIN_BASE + BOOST_COIN_FACTOR * max(0, $level - 1);

$active_boosts = $kingdom->get_active_boosts(ResourceTypes::RESOURCE_TYPE_WATCHTOWER);
$this_boost = $active_boosts[ResourceTypes::RESOURCE_TYPE_WATCHTOWER] ?? null;
$boost_amount = $this_boost["amount"] ?? 0;
$ticks_left = $this_boost["ticks"] ?? 0;

$boost_display = ($boost_amount > 0) ? " <span class='passed'>(+" . convert_sec_to_str($boost_amount, false, false) . ")</span>" : "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["activate_boost"])) {
    if ($level < BOOST_MIN_BUILDING_LEVEL) {
        $error = "$building_name Stufe " . BOOST_MIN_BUILDING_LEVEL . " benötigt!";
    } else if ($ticks_left == 0) {
        if ($user->get_user_coins() >= $boost_cost) {
            $user->give_user_coins(-$boost_cost);

            $db_instance->execute_query(
                "INSERT INTO kingdom_boosts (kingdomid, resource_type, ticks_remaining, boost_amount) 
                 VALUES (?, ?, ?, ?) 
                 ON DUPLICATE KEY UPDATE ticks_remaining = VALUES(ticks_remaining), boost_amount = VALUES(boost_amount)",
                [$current_kingdom, ResourceTypes::RESOURCE_TYPE_WATCHTOWER, $boost_duration_ticks, $boost_seconds]
            );

            Achievement::unlock($user->get_user_id(), AchievementTypes::ACHIEVEMENT_BOOST_ACTIVATED);

            $_SESSION["game_success"] = "Sichtweitenboost erfolgreich aktiviert!";
            change_location("watchtower.php");
            exit;
        } else {
            $error = "Zu wenig Münzen!";
        }
    } else {
        $error = "Der Boost ist bereits aktiv!";
    }
}

$next_level_text = "";
if ($level < MAX_BUILDING_LEVEL) {
    $next_level_text = "
        <div class='split-content'>
            <b>Nächste Stufe:</b>
            <div class='passed'>" . convert_sec_to_str($next_visibility) . "</div>
        </div>";
}

/*
 * HTML Content Part
 */
$boost_class = ($boost_amount > 0) ? " has-boost" : "";

$view .= "<div class='res-building-content watchtower-content$boost_class'>
    <div class='split-content'>
        <b>Sichtweite:</b>
        <div>" . convert_sec_to_str($base_visibility) . " $boost_display</div>
    </div>
    $next_level_text
</div>";

if ($level >= BOOST_MIN_BUILDING_LEVEL) {
    if ($ticks_left > 0) {
        $view .= "<p class='res-building-boost-text' style='margin-bottom: 0;'>Sichtweitenboost aktiv!<br>Verbleibende Erträge: <span style='font-weight: bold;'>$ticks_left</span></p>";
    } else {
        $can_afford = ($user->get_user_coins() >= $boost_cost);
        $disabled = ($can_afford && $level > 0) ? "" : "disabled";
        $cost_display_html = get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " " . ($can_afford ? $boost_cost : "<b class='error'>$boost_cost</b>");
        $boost_perc_display = fdec($boost_percent * 100);

        $view .= "<p class='res-building-boost-text'>Ein Boost erhöht die Sichtweite für die nächsten <b>$boost_duration_ticks Erträge</b> um <b>+$boost_perc_display%</b> (+" . convert_sec_to_str($boost_seconds, false, false) . ").</p>";
        $view .= "<form method='POST'>
            <button type='submit' name='activate_boost' class='boost-button' $disabled>
                Boost aktivieren für $cost_display_html
            </button>
        </form>";
    }
} else {
    $view .= "<p class='res-building-boost-text'><i>Sichtweitenboost ab $building_name Stufe " . BOOST_MIN_BUILDING_LEVEL . " verfügbar.</i></p>";
}

$view .= "<p class='res-building-desc'>
    <i>Der Wachturm ermöglicht es, herannahende Feinde frühzeitig zu entdecken. 
    Je höher der Turm, desto eher wird die Stadtwache Alarm schlagen.</i>
</p>";

/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $level . ")";
$script_files = ["timer"];

include("layout/base.php");
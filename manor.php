<?php
require_once("includes/core.php");

$result = $user->check_user_login_and_kingdom(BuildingTypes::BUILDING_ESTATE);
$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];
$level = $building->get_building_level();

$boost_villager_amount = ($level >= BOOST_ESTATE_HIGH_BUILDING_LEVEL)
    ? BOOST_ESTATE_VILLAGER_HIGH_AMOUNT
    : BOOST_ESTATE_VILLAGER_BASE_AMOUNT;

$boost_cost = BOOST_COIN_BASE + BOOST_COIN_FACTOR * max(0, $level - 1);
$active_boosts = $kingdom->get_active_boosts(ResourceTypes::RESOURCE_TYPE_VILLAGER);
$this_boost = $active_boosts[ResourceTypes::RESOURCE_TYPE_VILLAGER] ?? null;
$boost_amount = $this_boost["amount"] ?? 0;
$ticks_left = $this_boost["ticks"] ?? 0;
$boost_duration_ticks = $level + BASE_BOOST_DURATION;
$boost_display = ($boost_amount > 0) ? " <span class='passed'>(+" . fnum($boost_amount) . ")</span>" : "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["activate_boost"])) {
    if (!verify_csrf_token()) {
        $error = "Ungültiger Sicherheits-Token!";
    } else {
        if ($level < BOOST_MIN_BUILDING_LEVEL) {
            $error = "$building_name Stufe " . BOOST_MIN_BUILDING_LEVEL . " benötigt!";
        } else if ($ticks_left == 0) {
            if ($user->get_user_coins() >= $boost_cost) {
                $user->give_user_coins(-$boost_cost);

                $db_instance->execute_query(
                    "INSERT INTO kingdom_boosts (kingdomid, resource_type, ticks_remaining, boost_amount) 
                 VALUES (?, ?, ?, ?) 
                 ON DUPLICATE KEY UPDATE ticks_remaining = VALUES(ticks_remaining), boost_amount = VALUES(boost_amount)",
                    [$current_kingdom, ResourceTypes::RESOURCE_TYPE_VILLAGER, $boost_duration_ticks, $boost_villager_amount]
                );

                Achievement::unlock($user->get_user_id(), AchievementTypes::ACHIEVEMENT_BOOST_ACTIVATED);

                $_SESSION["game_success"] = "Dorfbewohner-Boost erfolgreich aktiviert!";

                change_location("manor.php");
            } else {
                $error = "Zu wenig Münzen!";
            }
        } else {
            $error = "Der Boost ist bereits aktiv!";
        }
    }
}

$next_level_text = "";
if ($level < MAX_BUILDING_LEVEL) {
    $next_level = $level + 1;
    $next_increase = (int)round(ESTATE_VILLAGER_BASE_INC + pow($next_level, 1.25) * ESTATE_VILLAGER_BASE_STEP);
    $next_level_text = "
        <div class='split-content'>
            <b>Nächste Stufe:</b>
            <div class='passed'>+" . fnum($next_increase) . " Bewohner</div>
        </div>";
}

/*
 * HTML Content Part
 */
$view .= "<div class='res-building-content'>
    <div class='split-content'>
        <b>Dorfbewohner Zuwachs:</b>
        <div>" . fnum($kingdom->get_kingdom_villager_per_hour()) . " / Std. $boost_display</div>
    </div>
    <div class='split-content'>
        <b>Wohnraum Kapazität:</b>
        <div>" . fnum($kingdom->get_kingdom_max_villager()) . " Bewohner</div>
    </div>
    $next_level_text
</div>";

if ($level >= BOOST_MIN_BUILDING_LEVEL) {
    if ($ticks_left > 0) {
        $view .= "<p class='res-building-boost-text' style='margin-bottom: 0;'>Zuwachsboost aktiv!<br>Verbleibende Erträge: <span style='font-weight: bold;'>$ticks_left</span></p>";
    } else {
        $can_afford = ($user->get_user_coins() >= $boost_cost);
        $disabled = ($can_afford && $level > 0) ? "" : "disabled";
        $cost_display_html = get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " " . ($can_afford ? $boost_cost : "<b class='error'>$boost_cost</b>");

        $view .= "<p class='res-building-boost-text'>Ein Boost erhöht den Zuwachs für die nächsten <b>$boost_duration_ticks Erträge</b> um <b>+" . $boost_villager_amount . " Dorfbewohner</b>.</p>";
        $view .= "<form method='POST'>
                    " . csrf_input() . "
                    <button type='submit' name='activate_boost' class='boost-button' $disabled>
                        Boost aktivieren für $cost_display_html
                    </button>
                </form>";
    }
} else {
    $view .= "<p class='res-building-boost-text'><i>Dorfbewohner-Boost ab $building_name Stufe " . BOOST_MIN_BUILDING_LEVEL . " verfügbar.</i></p>";
}

$view .= "<p class='res-building-desc'>
    <i>Das Anwesen bietet deinen Untertanen Raum zum Leben. 
    Alle " . ESTATE_VILLAGER_GROWTH_STEP . " Stufen erhöht sich die Geburtenrate deiner Bevölkerung.</i>
</p>";

/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $level . ")";
$script_files = ["timer"];

include("layout/base.php");
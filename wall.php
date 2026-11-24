<?php
require_once("includes/core.php");

$result = $user->check_user_login_and_kingdom(BuildingTypes::BUILDING_WALL);

$current_kingdom = $result["current_kingdom"];
$building = $result["building"];
$building_name = $building->get_building_name();
$kingdom = $result["kingdom"];

$wall_hp = $kingdom->get_wall_hp();
$wall_max_hp = $kingdom->get_wall_max_hp();
$wall_level = $building->get_building_level();
$wall_tech_level = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_WALL_HP_INC);
$bonus_defense = $kingdom->calculate_wall_defense($wall_hp, $building->get_building_level());
$kingdom_stone = $kingdom->get_kingdom_stone();

$hp_difference = $wall_max_hp - $wall_hp;
$maintenance_mult = $kingdom->get_repair_cost_multiplier();
$bonus_defense_text = $bonus_defense == 0 ? "0" : "+$bonus_defense";

$next_level_text = "";
if ($wall_level < MAX_BUILDING_LEVEL) {
    $next_lvl = $wall_level + 1;
    $next_max_hp = DEFAULT_WALL_HP * $next_lvl + ($wall_tech_level * RESEARCH_WALL_HP_INC);
    $next_defense = $kingdom->calculate_wall_defense($next_max_hp, $next_lvl);

    $next_level_text = "
        <div class='split-content'>
            <div><b>Nächste Stufe:</b></div>
            <div class='passed'>" . fnum($next_max_hp) . " HP (" . fnum($next_defense) . " DEF)</div>
        </div>";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf_token()) {
        change_location("wall.php", error: "Ungültiger Sicherheits-Token!");
    }

    if (!isset($_POST["repair_step"])) {
        change_location("wall.php");
    }

    $step_percent = post_int("repair_percent");
    if (!in_array($step_percent, [10, 25, 100], true)) {
        change_location("wall.php", error: "Ungültige Reparatur-Option!");
    }

    $db_instance->begin_transaction();
    try {
        $locked_k = $db_instance->execute_query(
            "SELECT wallhp, stone FROM kingdoms WHERE id = ? FOR UPDATE",
            [$current_kingdom]
        )->fetch_assoc();

        $cur_hp = (int)($locked_k["wallhp"] ?? 0);
        $cur_stone = (int)($locked_k["stone"] ?? 0);
        $cur_hp_diff = max(0, $wall_max_hp - $cur_hp);

        $hp_to_repair = ($step_percent === 100) ? $cur_hp_diff : (int)($wall_max_hp * ($step_percent / 100));
        $actual_repair = min($hp_to_repair, $cur_hp_diff);
        $repair_cost = (int)round($actual_repair * BASE_WALL_REPAIR_COST * $maintenance_mult);

        if ($actual_repair <= 0) {
            throw new RuntimeException("Die Mauer ist bereits vollständig repariert!");
        }
        if ($cur_stone < $repair_cost) {
            throw new RuntimeException("Nicht genügend Stein vorhanden!");
        }

        $db_instance->execute_query(
            "UPDATE kingdoms SET stone = stone - ?, wallhp = LEAST(?, wallhp + ?) WHERE id = ?",
            [$repair_cost, $wall_max_hp, $actual_repair, $current_kingdom]
        );
        $db_instance->commit();

        $logger->log_game("ECONOMY", "WALL_REPAIR", [
            "hp_gained" => $actual_repair,
            "cost_stone" => $repair_cost,
            "percent_step" => $step_percent
        ], $current_kingdom);

        change_location("wall.php", success: "Die Mauer wurde um " . fnum($actual_repair) . " HP repariert.");
    } catch (RuntimeException $e) {
        $db_instance->rollback();

        change_location("wall.php", error: $e->getMessage());
    } catch (Throwable $e) {
        $db_instance->rollback();
        $logger->error("wall repair error: " . $e->getMessage());

        change_location("wall.php", error: "Interner Fehler bei der Reparatur.");
    }
}

/*
 * HTML Content Part
 */
$view .= "<div class='res-building-content'>
            <div class='split-content'><b>Verteidigung (aktuell):</b> $bonus_defense_text</div>
            $next_level_text
          </div>
";
$view .= "<div style='display: flex; flex-direction: column; gap: 15px; align-items: center; margin-top: 15px;'>
            <div style='width: 100%; max-width: 400px;'>
                <div style='display: flex; align-items: center; gap: 10px; font-size: 14px; margin-bottom: 5px;'>
                    <span><img src='images/icons/icon_health.png' class='ressource-icons' alt='Haltbarkeit'></span>
                    <span>" . fnum($wall_hp) . " / " . fnum($wall_max_hp) . "</span>
                </div>
                <div class='wall-hp-bar'>
                    <div style='width: " . ($wall_max_hp > 0 ? (($wall_hp / $wall_max_hp) * 100) : 0) . "%; height: 100%; background: linear-gradient(90deg, #15803b, #2ecc71); transition: width 0.5s;'></div>
                </div>
            </div>
            <div class='title-border'>Reparatur-Optionen</div>
            <div style='display: flex; flex-direction: column; gap: 10px; width: 100%; max-width: 350px;'>";

$steps = [
    10 => "Kleine Reparatur (+10%)",
    25 => "Große Reparatur (+25%)",
    100 => "Vollständige Reparatur"
];

foreach ($steps as $percent => $label) {
    if ($percent === 100) {
        $repair_amount = $hp_difference;
    } else {
        $repair_amount = min($hp_difference, (int)($wall_max_hp * ($percent / 100)));
    }

    $cost = (int)round($repair_amount * BASE_WALL_REPAIR_COST * $maintenance_mult);
    $can_afford = ($kingdom_stone >= $cost);
    $disabled = ($repair_amount <= 0 || !$can_afford) ? "disabled" : "";

    $cost_color = !$can_afford ? "error" : "";

    $view .= "
        <form method='POST' style='width: 100%;'>
            <input type='hidden' name='repair_step' value='1'>
            <input type='hidden' name='repair_percent' value='$percent'>
            <button type='submit' style='width: 100%; display: flex; justify-content: space-between; align-items: center; padding: 10px;' $disabled>
                <span>$label</span>
                <span>
                    " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " 
                    <b class='$cost_color'>" . fnum($cost) . "</b>
                </span>
            </button>
        </form>";
}

$view .= "</div></div>";


/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = [];

include("layout/base.php");
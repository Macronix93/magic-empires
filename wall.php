<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_WALL);

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
$repair_cost = (int)round($hp_difference * BASE_WALL_REPAIR_COST * $maintenance_mult);
$disabled = $repair_cost > $kingdom_stone || $hp_difference == 0 ? "disabled" : "";
$bonus_defense_text = $bonus_defense == 0 ? "0" : "+$bonus_defense";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["repair_step"])) {
    $step_percent = (int)$_POST["repair_percent"];

    if ($step_percent === 100) {
        $hp_to_repair = $hp_difference;
    } else {
        $hp_to_repair = (int)($wall_max_hp * ($step_percent / 100));
    }

    $actual_repair = min($hp_to_repair, $hp_difference);
    $repair_cost = (int)round($actual_repair * BASE_WALL_REPAIR_COST * $maintenance_mult);

    if ($actual_repair <= 0) {
        $error = "Die Mauer ist bereits vollständig repariert!";
    } else if ($kingdom_stone < $repair_cost) {
        $error = "Nicht genügend Stein vorhanden!";
    } else {
        $kingdom->give_kingdom_stone(-$repair_cost);
        $new_hp = $wall_hp + $actual_repair;
        $kingdom->set_wall_hp($new_hp);

        $logger->log_game("ECONOMY", "WALL_REPAIR", [
            "hp_gained" => $actual_repair,
            "cost_stone" => $repair_cost,
            "percent_step" => $step_percent
        ], $current_kingdom);

        $_SESSION["game_success"] = "Die Mauer wurde um " . fnum($actual_repair) . " HP repariert.";
        change_location("wall.php");
        exit;
    }
}


/*
 * HTML Content Part
 */
$view .= "<div style='display: flex; flex-direction: column; gap: 15px; align-items: center;'>
            <div><b>Verteidigungswert:</b> $bonus_defense_text</div>
            
            <div style='width: 100%; max-width: 400px;'>
                <div style='display: flex; align-items: center; gap: 10px; font-size: 14px; margin-bottom: 5px;'>
                    <span><img src='images/icons/icon_health.png' class='ressource-icons' alt='Haltbarkeit'></span>
                    <span>" . fnum($wall_hp) . " / " . fnum($wall_max_hp) . "</span>
                </div>
                <div style='width: 100%; height: 20px; background: #333; border: 1px solid var(--border-gold); border-radius: 3px; overflow: hidden;'>
                    <div style='width: " . (($wall_hp / $wall_max_hp) * 100) . "%; height: 100%; background: linear-gradient(90deg, #15803b, #2ecc71); transition: width 0.5s;'></div>
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

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");
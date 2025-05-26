<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_WALL);

$current_kingdom = $result["current_kingdom"];
$building = $result["building"];
$building_name = $building->get_building_name();
$kingdom = $result["kingdom"];

$wall_hp = $kingdom->get_wall_hp();
$wall_level = $building->get_building_level();
$bonus_defense = $kingdom->calculate_wall_defense($wall_hp, $building->get_building_level());
$kingdom_stone = $kingdom->get_kingdom_stone();

$hp_difference = $wall_level * DEFAULT_WALL_HP - $wall_hp;
$repair_cost = $hp_difference * BASE_WALL_REPAIR_COST;
$disabled = $repair_cost > $kingdom_stone || $hp_difference == 0 ? "disabled" : "";
$bonus_defense_text = $bonus_defense == 0 ? "0" : "+$bonus_defense";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["repair"])) {
    if ($hp_difference > 0 && $kingdom_stone >= $repair_cost) {
        $kingdom->give_kingdom_stone(-$repair_cost);
        $kingdom->set_wall_hp($wall_level * DEFAULT_WALL_HP);

        change_location("wall.php");
    } else {
        $error = "Nicht genügend Stein oder Mauer ist bereits voll repariert!";
    }
}

/*
 * HTML Content Part
 */
$view .= "<div style='display: flex; flex-direction: column; gap: 10px;'>
                    <div><b>Verteidigungswert:</b> $bonus_defense_text</div>
                    <div>
                        <span class='resource-icons'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_HEALTH) . "
                            <span class='" . ($wall_hp >= DEFAULT_WALL_HP * $wall_level ? "over-limit" : "under-limit") . "'>
                                " . fnum($wall_hp) . " / " . fnum(DEFAULT_WALL_HP * $wall_level) . "
                            </span>
                        </span>
                    </div>
                    <form method='POST'>
                        <button type='submit' name='repair' style='margin: auto;' $disabled>
                            Reparieren für
                            <span class='ressource-icons'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " " . fnum($repair_cost) . "</span>
                        </button>
                    </form>
            </div>";


/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = [];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include('layout/base.php');
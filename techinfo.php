<?php
require_once("includes/core.php");

check_user_login($user);
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
$building_id = $_GET['bid'] ?? null;
$tech_id = $_GET['tid'] ?? null;

if ($building_id != null) {
    $query = "
                SELECT 
                    b.*, 
                    t.total_buildings
                FROM 
                    buildinglist b
                CROSS JOIN 
                    (SELECT COUNT(*) AS total_buildings FROM buildinglist) t
                WHERE 
                    b.id = ?
    ";
    $result = $db_instance->execute_query($query, [$building_id]);
    $row = $result->fetch_assoc();

    if ($building_id >= BuildingTypes::BUILDING_TOWNCENTER && $building_id < $row["total_buildings"]) {
        $building = (new Kingdom($db_instance))->fetch_kingdom_building($user->get_current_kingdom(), $building_id);

        $view .= "<div class='big-box-container'>
                    <div class='big-box-header'>{$row["buildingname"]}</div>";
        $view .= "<div class='big-box-content'>
                <table class='table' style='width: 100%;'>
                <tr>
                    <td class='td-center td-gradient' style='width: 15%;'>Lvl</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . "</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . "</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . "</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . "</td>
                    <td class='td-center td-gradient' style='width: 20%;'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_TIME) . "</td>
                </tr>
        ";

        $current_level_value = $building ? $building->get_building_level() : 0;

        for ($i = 0; $i < MAX_BUILDING_LEVEL; $i++) {
            $wood_cost = fnum($row["woodcost"] + round($row["woodcost"] * $row["multiplicator"] * $i));
            $food_cost = fnum($row["foodcost"] + round($row["foodcost"] * $row["multiplicator"] * $i));
            $stone_cost = fnum($row["stonecost"] + round($row["stonecost"] * $row["multiplicator"] * $i));
            $gold_cost = fnum($row["goldcost"] + round($row["goldcost"] * $row["multiplicator"] * $i));
            $time_to_build = convert_sec_to_str($row["timetobuild"] + round($row["timetobuild"] * $i));

            $style = ($i == $current_level_value) ? "style='background-color: green;'" : "";
            $current_level = "$i → " . ($i + 1);

            $view .= "<tr>
                <td class='td-center' $style>$current_level</td>
                <td class='td-center' $style>$wood_cost</td>
                <td class='td-center' $style>$food_cost</td>
                <td class='td-center' $style>$stone_cost</td>
                <td class='td-center' $style>$gold_cost</td>
                <td class='td-center' $style>$time_to_build</td>
              </tr>";
        }

        $view .= "</table></div></div>";
    } else {
        $view .= show_error_box("Dieses Gebäude existiert nicht!");
    }
} else if ($tech_id != null) {
    $query = "
                SELECT 
                    t.*, 
                    tt.total_techs
                FROM 
                    techlist t
                CROSS JOIN 
                    (SELECT COUNT(*) AS total_techs FROM techlist) tt
                WHERE 
                    t.id = ?
    ";
    $result = $db_instance->execute_query($query, [$tech_id]);
    $row = $result->fetch_assoc();

    if ($tech_id >= TechTypes::TECH_TYPE_FOOD_INC && $tech_id < $row["total_techs"]) {
        $tech = (new Kingdom($db_instance))->fetch_kingdom_tech($user->get_current_kingdom(), $tech_id);

        $view .= "<div class='big-box-container'>
                    <div class='big-box-header'>{$row["techname"]}</div>";
        $view .= "<div class='big-box-content'>
                <table class='table' style='width: 100%;'>
                <tr>
                    <td class='td-center td-gradient' style='width: 15%;'>Lvl</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . "</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . "</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . "</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . "</td>
                    <td class='td-center td-gradient' style='width: 20%;'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME) . "</td>
                </tr>
        ";

        $current_level_value = $tech ? $tech->get_tech_level() : 0;

        for ($i = 0; $i < $row["maxlevel"]; $i++) {
            $wood_cost = fnum($row["woodcost"] + round($row["woodcost"] * $row["multiplicator"] * $i));
            $food_cost = fnum($row["foodcost"] + round($row["foodcost"] * $row["multiplicator"] * $i));
            $stone_cost = fnum($row["stonecost"] + round($row["stonecost"] * $row["multiplicator"] * $i));
            $gold_cost = fnum($row["goldcost"] + round($row["goldcost"] * $row["multiplicator"] * $i));
            $time_to_research = convert_sec_to_str($row["timetoresearch"] + round($row["timetoresearch"] * $i));

            $style = ($i == $current_level_value) ? "style='background-color: green;'" : "";
            $current_level = "$i → " . ($i + 1);

            $view .= "<tr>
                <td class='td-center' $style>$current_level</td>
                <td class='td-center' $style>$wood_cost</td>
                <td class='td-center' $style>$food_cost</td>
                <td class='td-center' $style>$stone_cost</td>
                <td class='td-center' $style>$gold_cost</td>
                <td class='td-center' $style>$time_to_research</td>
              </tr>";
        }

        $view .= "</table></div></div>";
    } else {
        $view .= show_error_box("Diese Forschung existiert nicht!");
    }
} else {
    $view .= show_error_box("Nichts zum Anzeigen gefunden!");
}

echo $view;

?>
<br>
<div style="text-align:center">
    <a href="#" onclick="closeOverlay()"
       style="background-color: rgba(0, 0, 0, 0.7); display: inline-block; padding: 10px;">[Schließen]</a>
</div>
</body>
</html>
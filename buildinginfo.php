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
$building_id = $_GET['bid'];

if (isset($building_id)) {
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
        $building = fetch_kingdom_building($user->get_current_kingdom(), $building_id);

        $view .= "<div class='big-box-container'>
                    <div class='big-box-header'>{$row["buildingname"]}</div>";
        $view .= "<div class='big-box-content'>
                <table class='table'>
                <tr>
                    <td class='td-center td-gradient' style='width: 15%;'>Lvl</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(RESOURCE_TYPE_WOOD) . "</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(RESOURCE_TYPE_FOOD) . "</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(RESOURCE_TYPE_STONE) . "</td>
                    <td class='td-center td-gradient'>" . get_resource_icon(RESOURCE_TYPE_GOLD) . "</td>
                    <td class='td-center td-gradient' style='width: 20%;'>" . get_resource_icon(RESOURCE_TYPE_TIME) . "</td>
                </tr>
        ";

        for ($i = 0; $i < MAX_BUILDING_LEVEL; $i++) {
            $wood_cost = fnum($row["woodcost"] + round($row["woodcost"] * $row["multiplicator"] * $i));
            $food_cost = fnum($row["foodcost"] + round($row["foodcost"] * $row["multiplicator"] * $i));
            $stone_cost = fnum($row["stonecost"] + round($row["stonecost"] * $row["multiplicator"] * $i));
            $gold_cost = fnum($row["goldcost"] + round($row["goldcost"] * $row["multiplicator"] * $i));
            $time_to_build = convert_sec_to_str($row["timetobuild"] + round($row["timetobuild"] * $i));
            $current_level = ($i == ($building->is_built() ? $building->get_building_level() : 0))
                ? "<span class='passed'>$i → " . ($i + 1) . "</span>"
                : "$i → " . ($i + 1);

            $view .= "<tr>
                    <td class='td-center'>$current_level</td>
                    <td class='td-center'>$wood_cost</td>
                    <td class='td-center'>$food_cost</td>
                    <td class='td-center'>$stone_cost</td>
                    <td class='td-center'>$gold_cost</td>
                    <td class='td-center'>$time_to_build</td>
                </tr>";
        }

        $view .= "</table></div></div>";
    } else {
        $view .= show_error_box("Dieses Gebäude existiert nicht!");
    }
}

echo $view;

?>
<br>
<div style="text-align:center">
    <a href="javascript:window.close()"
       style="background-color: rgba(0, 0, 0, 0.7); display: inline-block; padding: 10px;">[Schließen]</a>
</div>
</body>
</html>
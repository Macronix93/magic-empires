<?php
require_once("includes/core.php");
check_user_login($user);
?>
<!DOCTYPE html>
<html lang="de">
<?php include_once("layout/head.html"); ?>
<body>
<?php
$building_id = isset($_GET["bid"]) ? (int)$_GET["bid"] : null;
$tech_id = isset($_GET["tid"]) ? (int)$_GET["tid"] : null;
$soldier_id = isset($_GET["sid"]) ? (int)$_GET["sid"] : null;

$row = null;
$current_level_value = 0;
$max_lvl_to_show = 0;
$time_key = "";
$time_icon_type = 0;
$is_soldier = false;

// Get table data
if ($building_id !== null) {
    $row = $db_instance->execute_query("SELECT * FROM building_list WHERE id = ?", [$building_id])->fetch_assoc();

    if ($row) {
        $building = new Kingdom($db_instance)->fetch_kingdom_building($user->get_current_kingdom(), $building_id);
        $current_level_value = $building ? $building->get_building_level() : 0;
        $max_lvl_to_show = MAX_BUILDING_LEVEL;
        $time_key = "timetobuild";
        $time_icon_type = ResourceTypes::RESOURCE_TYPE_TIME;
    }
} elseif ($tech_id !== null) {
    $row = $db_instance->execute_query("SELECT * FROM tech_list WHERE id = ?", [$tech_id])->fetch_assoc();

    if ($row) {
        $tech = new Kingdom($db_instance)->fetch_kingdom_tech($user->get_current_kingdom(), $tech_id);
        $current_level_value = $tech ? $tech->get_tech_level() : 0;
        $max_lvl_to_show = $row["maxlevel"];
        $time_key = "timetoresearch";
        $time_icon_type = ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME;
    }
} elseif ($soldier_id !== null) {
    $row = $db_instance->execute_query("SELECT * FROM soldier_list WHERE id = ?", [$soldier_id])->fetch_assoc();

    if ($row) {
        $is_soldier = true;
    }
}

// Display logic
if ($row) {
    if ($is_soldier) {
        $is_hero = ($row["id"] == Soldiers::SOLDIER_HERO);

        $active_res = [];
        if ($row["food"] > 0) $active_res["food"] = ResourceTypes::RESOURCE_TYPE_FOOD;
        if ($row["wood"] > 0) $active_res["wood"] = ResourceTypes::RESOURCE_TYPE_WOOD;
        if ($row["stone"] > 0) $active_res["stone"] = ResourceTypes::RESOURCE_TYPE_STONE;
        if ($row["gold"] > 0) $active_res["gold"] = ResourceTypes::RESOURCE_TYPE_GOLD;

        $view .= "<div class='big-box-container'>
                    <div class='big-box-header'>Einheit: {$row["soldiername"]}</div>
                    <div class='big-box-content'>
                        <p style='font-style: italic; color: #ccc; margin-top: 0;'>" . e($row["description"]) . "</p>
                        
                        <table class='table' style='width: 100%;'>
                            <tr>
                                <td class='td-center td-gradient' title='Angriff'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . "</td>
                                <td class='td-center td-gradient' title='Verteidigung'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . "</td>";

        if ($row["villager"] > 0) {
            $view .= "<td class='td-center td-gradient' title='Dorfbewohner'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_VILLAGER) . "</td>";
        }

        foreach ($active_res as $res_id) {
            $view .= "<td class='td-center td-gradient'>" . get_resource_icon($res_id) . "</td>";
        }

        $view .= "              <td class='td-center td-gradient'>" . ($is_hero ? "Status" : get_resource_icon(ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME)) . "</td>
                            </tr>
                            <tr>
                                <td class='td-center'>" . fnum($row["attack"]) . "</td>
                                <td class='td-center'>" . fnum($row["defense"]) . "</td>";

        if ($row["villager"] > 0) {
            $view .= "<td class='td-center'>{$row["villager"]}</td>";
        }

        foreach ($active_res as $key => $res_id) {
            $view .= "<td class='td-center'>" . fnum($row[$key]) . "</td>";
        }

        $time_display = $is_hero ? "<i class='passed'>Einzigartig</i>" : convert_sec_to_str($row["requiredtime"]);
        $view .= "              <td class='td-center'>$time_display</td>
                            </tr>
                        </table>
                        <p style='font-size: 13px; margin-top: 15px; opacity: 0.7;'>";

        if ($is_hero) {
            $view .= "Helden können nicht ausgebildet werden. Sie werden alle 24 Stunden zufällig an einen Herrscher verteilt.";
        } else {
            $view .= "Voraussetzung: Kaserne Stufe {$row["requiredlevel"]} | Ranglisten-Punkte: " . fnum($row["scoregain"]);
        }

        $view .= "      </p>
                    </div>
                  </div>";
    } else {
        $m = $row["multiplicator"];
        $calc_cost = fn($base, $lvl) => ($base <= 0) ? 0 : (int)round($base * pow($m, $lvl));

        $active_res = [];
        $res_map = [
                "food" => "foodcost",
                "wood" => 'woodcost',
                "stone" => "stonecost",
                "gold" => "goldcost"
        ];
        $res_icons = [
                ResourceTypes::RESOURCE_TYPE_FOOD,
                ResourceTypes::RESOURCE_TYPE_WOOD,
                ResourceTypes::RESOURCE_TYPE_STONE,
                ResourceTypes::RESOURCE_TYPE_GOLD
        ];

        $idx = 0;
        foreach ($res_map as $key => $db_col) {
            if ($row[$db_col] > 0) $active_res[$key] = $res_icons[$idx];

            $idx++;
        }

        $name = $row["buildingname"] ?? $row["techname"];
        $view .= "<div class='big-box-container'>
                    <div class='big-box-header'>$name</div>
                    <div class='big-box-content'>
                        <p style='font-style: italic; color: #ccc; margin-top: 0;'>" . e($row["description"]) . "</p>
                        <table class='table' style='width: 100%;'>
                            <tr>
                                <td class='td-center td-gradient' style='width: 15%;'>Lvl</td>";

        foreach ($active_res as $res_id) {
            $view .= "<td class='td-center td-gradient'>" . get_resource_icon($res_id) . "</td>";
        }

        $view .= "              <td class='td-center td-gradient' style='width: 25%;'>" . get_resource_icon($time_icon_type) . "</td>
                            </tr>";

        for ($i = 0; $i < $max_lvl_to_show; $i++) {
            $style = ($i == $current_level_value) ? "style='background-color: rgba(11, 218, 81, 0.2); font-weight: bold;'" : "";
            $time_val = convert_sec_to_str((int)round($row[$time_key] * pow($m, $i)));
            $view .= "<tr><td class='td-center' $style>$i &rarr; " . ($i + 1) . "</td>";

            foreach ($active_res as $col => $res_id) {
                $view .= "<td class='td-center' $style>" . fnum($calc_cost($row[$col . "cost"], $i)) . "</td>";
            }

            $view .= "<td class='td-center' $style>$time_val</td></tr>";
        }
        $view .= "</table></div></div>";
    }
} else {
    $view .= show_error_box("Nichts zum Anzeigen gefunden!");
}
echo $view;
?>
<br>
<div style="text-align:center">
    <a href="#" data-on-click="closeOverlay"
       style="background-color: rgba(0, 0, 0, 0.7); display: inline-block; padding: 10px;">[Schließen]</a>
</div>
</body>
</html>
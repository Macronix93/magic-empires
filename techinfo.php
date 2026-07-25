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
} else if ($tech_id !== null) {
    $row = $db_instance->execute_query("SELECT * FROM tech_list WHERE id = ?", [$tech_id])->fetch_assoc();

    if ($row) {
        $tech = new Kingdom($db_instance)->fetch_kingdom_tech($user->get_current_kingdom(), $tech_id);
        $current_level_value = $tech ? $tech->get_tech_level() : 0;
        $max_lvl_to_show = $row["maxlevel"];
        $time_key = "timetoresearch";
        $time_icon_type = ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME;
    }
} else if ($soldier_id !== null) {
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
        }

        $view .= "      </p>
                    </div>
                  </div>";
    } else {
        $m = $row["multiplicator"];
        $calc_cost = fn($base, $lvl) => ($base <= 0) ? 0 : (int)round($base * pow($m, $lvl));

        $biome_info = "";
        $is_prod_building = in_array($building_id, [
                BuildingTypes::BUILDING_MILL,
                BuildingTypes::BUILDING_SAWMILL,
                BuildingTypes::BUILDING_STONEMINE,
                BuildingTypes::BUILDING_GOLDMINE
        ]);

        if ($building_id !== null && $is_prod_building) {
            $res_map = [
                    BuildingTypes::BUILDING_MILL => ["field" => "foodrate", "base" => BASE_FOOD_GAIN],
                    BuildingTypes::BUILDING_SAWMILL => ["field" => "woodrate", "base" => BASE_WOOD_GAIN],
                    BuildingTypes::BUILDING_STONEMINE => ["field" => "stonerate", "base" => BASE_STONE_GAIN],
                    BuildingTypes::BUILDING_GOLDMINE => ["field" => "goldrate", "base" => BASE_GOLD_GAIN]
            ];

            $config = $res_map[$building_id];
            $ft_res = $db_instance->query("SELECT fieldname, {$config["field"]} as rate FROM field_types");

            $biome_info = "<div style='margin-bottom:15px; border:1px ridge var(--border-gold); padding:8px; background:rgba(0,0,0,0.3); font-size:14px;'>";
            $biome_info .= "<b>Ertrag pro Stunde nach Gelände:</b><br>";
            
            while ($ft = $ft_res->fetch_assoc()) {
                $biome_info .= e($ft["fieldname"]) . ": <span class='passed'>+" . fnum((int)($config["base"] * $ft["rate"])) . "</span><br>";
            }

            $biome_info .= "</div>";
        }

        $tech_bonus_info = "";
        $res_techs = [
                TechTypes::TECH_TYPE_FOOD_INC => ["name" => "Nahrung", "val" => RESEARCH_FOOD_INC],
                TechTypes::TECH_TYPE_WOOD_INC => ["name" => "Holz", "val" => RESEARCH_WOOD_INC],
                TechTypes::TECH_TYPE_STONE_INC => ["name" => "Stein", "val" => RESEARCH_STONE_INC],
                TechTypes::TECH_TYPE_GOLD_INC => ["name" => "Gold", "val" => RESEARCH_GOLD_INC]
        ];

        if ($tech_id !== null && isset($res_techs[$tech_id])) {
            $t_cfg = $res_techs[$tech_id];
            $tech_bonus_info = "<div style='margin-bottom:15px; border:1px ridge var(--border-gold); padding:8px; background:rgba(0,0,0,0.3); font-size:14px;'>";
            $tech_bonus_info .= "<b>Forschungs-Effekt:</b><br>";
            $tech_bonus_info .= "Jede Stufe erhöht den Basis-Ertrag von {$t_cfg["name"]} dauerhaft um <span class='passed'>+" . fnum($t_cfg["val"]) . "</span> pro Stunde.";
            $tech_bonus_info .= "</div>";
        }

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
        $dynamic_effect_sentence = "";
        if ($tech_id !== null) {
            $res = match ($tech_id) {
                TechTypes::TECH_TYPE_ARCHITECTURE => [(ARCHITECTURE_TIME_REDUCTION * 100) . "%", "Reduktion der Bauzeit"],
                TechTypes::TECH_TYPE_CARTOGRAPHY => [(CARTOGRAPHY_SPEED_BONUS * 100) . "%", "höhere Marschgeschwindigkeit"],
                TechTypes::TECH_TYPE_MAINTENANCE => [(MAINTENANCE_REPAIR_REDUCTION * 100) . "%", "Reduktion der Reparaturkosten"],
                TechTypes::TECH_TYPE_PLUNDER => [(PLUNDER_CAPACITY_BONUS * 100) . "%", "mehr Beute-Kapazität"],
                TechTypes::TECH_TYPE_ANCESTRAL_RITES => [(SHRINE_TECH_STEP * 100) . "%", "stärkerer Schrein-Effekt"],
                TechTypes::TECH_TYPE_WALL_HP_INC => [RESEARCH_WALL_HP_INC, "zusätzliche HP pro Mauerstufe"],
                TechTypes::TECH_TYPE_STORAGE_INC => [fnum(RESEARCH_STORAGE_INC), "zusätzliche Kapazität pro Ressource"],
                TechTypes::TECH_TYPE_IMPERIAL => ["", "Ermöglicht den Bau einer weiteren Siedlung"],
                TechTypes::TECH_TYPE_ARCANE_INTEL => ["", "Erweitert die Informationen herannahender Truppen im Wachturm:<br>" .
                        "<div style='margin-top: 5px;'>" .
                        "• <span class='passed'>Stufe 1:</span> Anzeige der verbleibenden Ankunftszeit<br>" .
                        "• <span class='passed'>Stufe 2:</span> Name & Herkunft des Angreifers<br>" .
                        "• <span class='passed'>Stufe 3:</span> Grobe Schätzung der Truppenzahl<br>" .
                        "• <span class='passed'>Stufe 4:</span> Exakte Einheitenliste & Anzahl<br>" .
                        "• <span class='passed'>Stufe 5:</span> Berechnung der gegnerischen Kampfkraft" .
                        "</div>"
                ],

                TechTypes::TECH_TYPE_BLADES => ["+" . SMITHY_INF_ATK_BONUS, "Angriff für Infanterie-Einheiten"],
                TechTypes::TECH_TYPE_SHIELDWALL => ["+" . SMITHY_INF_DEF_BONUS, "Verteidigung für Infanterie-Einheiten"],
                TechTypes::TECH_TYPE_LANCE_RIDING => ["+" . SMITHY_CAV_ATK_BONUS, "Angriff für Kavallerie-Einheiten"],
                TechTypes::TECH_TYPE_CUIRASS => ["+" . SMITHY_CAV_DEF_BONUS, "Verteidigung für Kavallerie-Einheiten"],
                TechTypes::TECH_TYPE_ARROWHEADS => ["+" . SMITHY_ARC_ATK_BONUS, "Angriff für Schützen-Einheiten"],
                TechTypes::TECH_TYPE_DOUBLET => ["+" . SMITHY_ARC_DEF_BONUS, "Verteidigung für Schützen-Einheiten"],
                TechTypes::TECH_TYPE_WEIGHT => [(SMITHY_WEIGHT_REDUCTION * 100) . "%", "Reduktion der Rekrutierungszeit"],
                TechTypes::TECH_TYPE_SIEGE => ["+" . (SMITHY_SIEGE_BONUS * 100) . "%", "zusätzlicher Schaden an Mauern"],

                default => null
            };

            if ($res) {
                $value = $res[0];
                $text = $res[1];

                $formatted_value = !empty($value) ? "<span class='passed'>$value</span> " : "";
                $suffix = ($tech_id === TechTypes::TECH_TYPE_ARCANE_INTEL) ? "" : " pro Stufe.";

                $tech_bonus_info = "<div style='margin-bottom:15px; border:1px ridge var(--border-gold); padding:8px; background:rgba(0,0,0,0.3); font-size:14px;'>";
                $tech_bonus_info .= "<b>Forschungs-Effekt:</b><br>";
                $tech_bonus_info .= "$formatted_value$text$suffix";
                $tech_bonus_info .= "</div>";
            }
        }

        $view .= "<div class='big-box-container'>
                    <div class='big-box-header'>$name</div>
                    <div class='big-box-content'>
                        <p style='font-style: italic; color: #ccc; margin-top: 0;'>
                            " . e($row["description"]) . " $dynamic_effect_sentence
                        </p>
                        $biome_info
                        $tech_bonus_info
                        <table class='table' style='width: 100%;'>
                            <tr>
                                <td class='td-center td-gradient' style='width: 15%;'>Lvl</td>";

        if ($building_id === BuildingTypes::BUILDING_STORAGE) {
            $view .= "<td class='td-center td-gradient'>Kapazität</td>";
        }

        foreach ($active_res as $res_id) {
            $view .= "<td class='td-center td-gradient'>" . get_resource_icon($res_id) . "</td>";
        }

        $view .= "              <td class='td-center td-gradient' style='width: 25%;'>" . get_resource_icon($time_icon_type) . "</td>
                            </tr>";

        for ($i = 0; $i < $max_lvl_to_show; $i++) {
            $style = ($i == $current_level_value) ? "style='background-color: rgba(11, 218, 81, 0.2); font-weight: bold;'" : "";
            $time_val = convert_sec_to_str((int)round($row[$time_key] * pow($m, $i)));

            $view .= "<tr><td class='td-center' $style>$i &rarr; " . ($i + 1) . "</td>";

            if ($building_id === BuildingTypes::BUILDING_STORAGE) {
                $cap = (int)round(STORAGE_STARTING_VALUE * pow(STORAGE_INC_FACTOR, $i));
                $view .= "<td class='td-center' $style>" . fnum($cap) . "</td>";
            }

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
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
$guild_tech_id = isset($_GET["gtid"]) ? (int)$_GET["gtid"] : null;

$row = null;
$current_level_value = 0;
$max_lvl_to_show = 0;
$time_key = "";
$time_icon_type = 0;
$is_soldier = false;
$is_guild_tech = false;

// Get table data
if ($building_id !== null) {
    $row = $db_instance->execute_query("SELECT * FROM building_list WHERE id = ?", [$building_id])->fetch_assoc();

    if ($row) {
        $building = new Kingdom()->fetch_kingdom_building($user->get_current_kingdom(), $building_id);
        $current_level_value = $building ? $building->get_building_level() : 0;
        $max_lvl_to_show = ($building_id == BuildingTypes::BUILDING_EMBASSY) ? 1 : MAX_BUILDING_LEVEL;
        $time_key = "timetobuild";
        $time_icon_type = ResourceTypes::RESOURCE_TYPE_TIME;
    }
} else if ($tech_id !== null) {
    $row = $db_instance->execute_query("SELECT * FROM tech_list WHERE id = ?", [$tech_id])->fetch_assoc();

    if ($row) {
        $tech = new Kingdom()->fetch_kingdom_tech($user->get_current_kingdom(), $tech_id);
        $current_level_value = $tech ? $tech->get_tech_level() : 0;
        $max_lvl_to_show = ($tech_id === TechTypes::TECH_TYPE_IMPERIAL)
                ? max(0, GLOBAL_SETTLEMENT_MAX - BASE_SETTLEMENT_LIMIT)
                : $row["maxlevel"];
        $time_key = "timetoresearch";
        $time_icon_type = ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME;
    }
} else if ($soldier_id !== null) {
    $row = $db_instance->execute_query("SELECT * FROM soldier_list WHERE id = ?", [$soldier_id])->fetch_assoc();

    if ($row) {
        $is_soldier = true;
    }
} else if ($guild_tech_id !== null) {
    $row = $db_instance->execute_query("SELECT * FROM guild_tech_list WHERE id = ?", [$guild_tech_id])->fetch_assoc();

    if ($row) {
        $is_guild_tech = true;
        $my_gid = $user->get_user_guild_id();
        $guild_logic = new Guild($user, $my_gid);
        $current_level_value = ($my_gid > 0) ? $guild_logic->get_tech_level($guild_tech_id) : 0;
        $max_lvl_to_show = (int)$row["max_level"];
        $time_key = "base_time";
        $time_icon_type = ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME;
    }
}

// Display logic
if ($row) {
    if ($is_soldier) {
        $is_hero = ($row["id"] == Soldiers::SOLDIER_HERO);
        $is_raider = ($row["id"] == Soldiers::SOLDIER_RAIDER);
        $is_thief = ($row["id"] == Soldiers::SOLDIER_THIEF);

        $attack_val = (int)($row["attack"] ?? 0);
        $soldier_cat = (int)($row["category"] ?? 0);
        $mining_rate_per_sec = $attack_val * MINE_WORK_RATE_FACTOR;
        $mining_rate_formatted = fdec($mining_rate_per_sec, 3);

        $mining_info_html = "";
        if ($attack_val > 0 && $soldier_cat !== SoldierTypes::SOLDIER_TYPE_SPECIAL) {
            $mining_info_html = "
            <div class='tech-info-box' style='margin-top: 15px;'>
                <b>Bergbau-Effizienz:</b><br>
                Arbeitsleistung in Minen: <span class='passed'>$mining_rate_formatted Arbeitspunkte / Sekunde</span>.
            </div>";
        }

        $chance_info = "";
        if ($soldier_id === Soldiers::SOLDIER_CONQUEROR) {
            $base = (BASE_CONQUEST_CHANCE + MIN_CONQUEST_CHANCE) * 100;
            $step = MIN_CONQUEST_CHANCE * 100;
            $max = MAX_CONQUEST_CHANCE * 100;
            $chance_info = "Ein Eroberer hat eine Erfolgschance von <b>$base%</b>. " .
                    "Jeder weitere im Trupp erhöht diese um <b>$step%</b> (maximal <b>$max%</b>).";
        } else if ($soldier_id === Soldiers::SOLDIER_SETTLER_WAGON) {
            $res_founded = $db_instance->execute_query(
                    "SELECT COUNT(*) FROM kingdoms WHERE userid = ? AND creation_method = 0",
                    [$user->get_user_id()]
            );
            $curr_founded = (int)$res_founded->fetch_row()[0];

            $res_imp = $db_instance->execute_query(
                    "SELECT COUNT(*) FROM techs t JOIN kingdoms k ON t.kingdomid = k.id WHERE k.userid = ? AND t.techid = ? AND t.techlevel > 0",
                    [$user->get_user_id(), TechTypes::TECH_TYPE_IMPERIAL]
            );
            $imp_bonus = (int)$res_imp->fetch_row()[0];
            $limit = min(GLOBAL_SETTLEMENT_MAX, BASE_SETTLEMENT_LIMIT + $imp_bonus);

            $base = BASE_SETTLER_CHANCE * 100;
            $step = SETTLER_CHANCE_STEP * 100;
            $max = MAX_SETTLER_CHANCE * 100;

            $chance_info = "Ein Karren hat eine Erfolgschance von <b>$base%</b>. Jeder weitere erhöht diese um <b>$step%</b> (max. <b>$max%</b>).<br><br>";
            $chance_info .= "<b>Globaler Siedlungs-Status:</b><br>";
            $chance_info .= "Gegründete Dörfer: <b>$curr_founded</b><br>"; // Hier steht nun 2
            $chance_info .= "Aktuelles Limit: <b>$limit</b> (Maximal: " . GLOBAL_SETTLEMENT_MAX . ")<br>";
            $chance_info .= "<i>Eroberte Dörfer zählen nicht gegen dieses Limit.</i>";
        }

        $active_res = [];
        if ($row["food"] > 0) $active_res["food"] = ResourceTypes::RESOURCE_TYPE_FOOD;
        if ($row["wood"] > 0) $active_res["wood"] = ResourceTypes::RESOURCE_TYPE_WOOD;
        if ($row["stone"] > 0) $active_res["stone"] = ResourceTypes::RESOURCE_TYPE_STONE;
        if ($row["gold"] > 0) $active_res["gold"] = ResourceTypes::RESOURCE_TYPE_GOLD;

        $view .= "<div class='big-box-container tech-info-page'>
                    <div class='big-box-header tech-info-header'>{$row["soldiername"]}</div>
                    <div class='big-box-content tech-info-page'>
                        <p style='font-style: italic; color: #ccc; margin-top: 0;'>" . e($row["description"]) .
                ($is_raider ? "<br>Der Räuber hat eine Plünderkapazität von maximal " . RAIDER_BASE_CAPACITY . " Ressourcen." : "") .
                ($is_thief ? "<br>Der Dieb hat eine Tragekapazität von maximal " . THIEF_BASE_CAPACITY . " Ressourcen pro Einheit." : "") . " " . $chance_info . "</p>
                        $mining_info_html
                        <table class='table' style='width: 100%;'>
                            <tr>";
        if ($row["attack"] > 0) {
            $view .= "<td class='td-center td-gradient' title='Angriff'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . "</td>";
        }
        $view .= "<td class='td-center td-gradient' title='Verteidigung'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . "</td>";

        if ($row["villager"] > 0) {
            $view .= "<td class='td-center td-gradient' title='Dorfbewohner'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_VILLAGER) . "</td>";
        }

        foreach ($active_res as $res_id) {
            $view .= "<td class='td-center td-gradient'>" . get_resource_icon($res_id) . "</td>";
        }

        $view .= "              <td class='td-center td-gradient'>" . ($is_hero ? "Status" : get_resource_icon(ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME)) . "</td>
                            </tr>
                            <tr>";
        if ($row["attack"] > 0) {
            $view .= "<td class='td-center'>" . fnum($row["attack"]) . "</td>";
        }
        $view .= "<td class='td-center'>" . fnum($row["defense"]) . "</td>";

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
                        <p style='font-size: 18px; margin-top: 15px;'>";

        if ($is_hero) {
            $view .= "Helden können nicht ausgebildet werden. Sie werden alle 24 Stunden zufällig an einen Herrscher verteilt.";
        } else {
            $view .= "Punkte pro Einheit: <b class='passed'>" . $row["scoregain"] . "</b>";
        }

        $view .= "      </p>
                    </div>
                  </div>";
    } else if ($is_guild_tech) {
        $m = $row["multiplicator"];
        $calc_cost = fn($base, $lvl) => ($base <= 0) ? 0 : (int)round($base * pow($m, $lvl));

        $active_res = [];
        $res_fields = [
                "food_cost" => ResourceTypes::RESOURCE_TYPE_FOOD,
                "wood_cost" => ResourceTypes::RESOURCE_TYPE_WOOD,
                "stone_cost" => ResourceTypes::RESOURCE_TYPE_STONE,
                "gold_cost" => ResourceTypes::RESOURCE_TYPE_GOLD,
                "coal_cost" => ResourceTypes::RESOURCE_TYPE_COAL,
                "iron_cost" => ResourceTypes::RESOURCE_TYPE_IRON,
                "sapphire_cost" => ResourceTypes::RESOURCE_TYPE_SAPPHIRE,
                "diamond_cost" => ResourceTypes::RESOURCE_TYPE_DIAMOND
        ];

        foreach ($res_fields as $col => $icon_id) {
            if (($row[$col] ?? 0) > 0) {
                $active_res[$col] = $icon_id;
            }
        }

        $guild_storage_res = [
                "coal" => ["icon" => ResourceTypes::RESOURCE_TYPE_COAL, "base" => GUILD_STORAGE_BASE_COAL],
                "iron" => ["icon" => ResourceTypes::RESOURCE_TYPE_IRON, "base" => GUILD_STORAGE_BASE_IRON],
                "sapphire" => ["icon" => ResourceTypes::RESOURCE_TYPE_SAPPHIRE, "base" => GUILD_STORAGE_BASE_SAPPHIRE],
                "diamond" => ["icon" => ResourceTypes::RESOURCE_TYPE_DIAMOND, "base" => GUILD_STORAGE_BASE_DIAMOND]
        ];

        $res = match ($guild_tech_id) {
            GuildTechTypes::GUILD_TECH_TYPE_STORAGE => ["", "Erhöht die max. Lagerkapazität der Gilden-Schatzkammer"],
            GuildTechTypes::GUILD_TECH_EVENT_GOLD => ["+" . fdec(GUILD_BONUS_EVENT_GOLD_PER_LVL * 100) . "%", "Gold-Belohnung bei Welt-Events"],
            GuildTechTypes::GUILD_TECH_ALLY_TRADE_SPEED => ["-" . fdec(GUILD_BONUS_ALLY_TRADE_SPEED_PER_LVL * 100) . "%", "Laufzeit für Karawanen zu Verbündeten"],
            GuildTechTypes::GUILD_TECH_SUPPORT_CAPACITY => ["+" . fnum(GUILD_BONUS_SUPPORT_CAP_PER_LVL), "zusätzliche Unterstützungstruppen"],
            GuildTechTypes::GUILD_TECH_SUPPORT_SPEED => ["-" . fdec(GUILD_BONUS_SUPPORT_SPEED_PER_LVL * 100) . "%", "Marschzeit für Unterstützungstruppen"],
            GuildTechTypes::GUILD_TECH_MEMBER_LIMIT => ["+" . GUILD_BONUS_MEMBER_LIMIT_PER_LVL, "maximale Gilden-Mitglieder"],
            default => null
        };

        $guild_tech_bonus_info = "";
        if ($res) {
            $value = $res[0];
            $text = $res[1];

            $formatted_value = !empty($value) ? "<span class='passed'>$value</span> " : "";
            $suffix = ($guild_tech_id === GuildTechTypes::GUILD_TECH_TYPE_STORAGE) ? "." : " pro Stufe.";

            $guild_tech_bonus_info = "<div class='tech-info-box'>";
            $guild_tech_bonus_info .= "<b>Forschungs-Effekt:</b><br>";
            $guild_tech_bonus_info .= "$formatted_value$text$suffix";
            $guild_tech_bonus_info .= "</div>";
        }

        $view .= "<div class='big-box-container tech-info-page'>
                    <div class='big-box-header tech-info-header'>" . e($row["name"]) . "</div>
                    <div class='big-box-content tech-info-page'>
                        <p style='font-style: italic; color: #ccc; margin-top: 0;'>
                            " . e($row["description"]) . "
                        </p>
                        $guild_tech_bonus_info
                        <table class='table' style='width: 100%;'>
                            <tr>
                                <td class='td-center td-gradient' style='width: 12%;'>Stufe</td>";

        if ($guild_tech_id === GuildTechTypes::GUILD_TECH_TYPE_STORAGE) {
            $view .= "<td class='td-center td-gradient' style='width: 32%;'>Kapazität</td>";
        }

        foreach ($active_res as $icon_id) {
            $view .= "<td class='td-center td-gradient'>" . get_resource_icon($icon_id) . "</td>";
        }

        $view .= "              <td class='td-center td-gradient' style='width: 18%;'>" . get_resource_icon($time_icon_type) . "</td>
                            </tr>";

        for ($i = 0; $i < $max_lvl_to_show; $i++) {
            $style = ($i == $current_level_value) ? "style='background-color: rgba(11, 218, 81, 0.2); font-weight: bold;'" : "";
            $time_val = convert_sec_to_str((int)round($row[$time_key] * pow($m, $i)));

            $view .= "<tr><td class='td-center' $style>$i &rarr; " . ($i + 1) . "</td>";

            if ($guild_tech_id === GuildTechTypes::GUILD_TECH_TYPE_STORAGE) {
                $target_lvl = $i + 1;
                $cells = "";

                $count = 1;
                $justify_style = "";
                foreach ($guild_storage_res as $info) {
                    $cap = (int)round($info["base"] * pow(GUILD_STORAGE_INC_FACTOR, $target_lvl));

                    if ($count % 2 == 0 && $count !== 0) {
                        $justify_style = "justify-content: flex-end;";
                    } else {
                        $justify_style = "justify-content: flex-start;";
                    }

                    $cells .= "<div style='display: flex; align-items: center; $justify_style gap: 4px; white-space: nowrap;'>" .
                            get_resource_icon($info["icon"]) . " <span>" . fnum($cap) . "</span>" .
                            "</div>";

                    $count++;
                }

                $view .= "<td class='td-center' $style>" .
                        "<div style='display: grid; grid-template-columns: 1fr 1fr; gap: 4px 4px; max-width: 200px; margin: 0 10px;'>" .
                        $cells .
                        "</div>" .
                        "</td>";
            }

            foreach ($active_res as $col => $icon_id) {
                $view .= "<td class='td-center' $style>" . fnum($calc_cost($row[$col], $i)) . "</td>";
            }

            $view .= "<td class='td-center' $style>$time_val</td></tr>";
        }
        $view .= "</table>";
        $view .= "</div></div>";
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

            $biome_info = "<div class='tech-info-box'>";
            $biome_info .= "<b>Ertrag pro Stunde nach Gelände:</b><br>";

            while ($ft = $ft_res->fetch_assoc()) {
                $biome_info .= e($ft["fieldname"]) . ": <span class='passed'>+" . fnum((int)($config["base"] * $ft["rate"])) . "</span><br>";
            }

            $biome_info .= "</div>";
        }

        switch ($building_id) {
            case BuildingTypes::BUILDING_WATCHTOWER:
                $time_bonus = convert_sec_to_str(WATCHTOWER_DETECTION_PER_LEVEL);

                $biome_info = "<div class='tech-info-box'>";
                $biome_info .= "<b>Wachturm-Effekt:</b><br>";
                $biome_info .= "Jede Stufe erhöht die Sichtweite für herannahende Truppen dauerhaft um <span class='passed'>+" . $time_bonus . "</span>.";
                $biome_info .= "</div>";
                break;
            case BuildingTypes::BUILDING_SHRINE:
                $res_aligns = $db_instance->query("SELECT name, required_level, bonus_text, malus_text, base_bonus, base_malus FROM shrine_alignments ORDER BY required_level");

                $biome_info = "<div class='tech-info-box'>";
                $biome_info .= "<b>Freischaltungen nach Stufe:</b><br>";

                while ($sa = $res_aligns->fetch_assoc()) {
                    $b_val = (int)($sa["base_bonus"] * 100);
                    $m_val = (int)($sa["base_malus"] * 100);

                    $biome_info .= "<span class='passed'>Stufe {$sa["required_level"]}:</span> <b>" . e($sa["name"]) . "</b>";
                    $biome_info .= "<small style='opacity:0.8; margin-left: 10px;'>" .
                            "(<span class='passed'>+$b_val% " . e($sa["bonus_text"]) . "</span> / " .
                            "<span class='error'>-$m_val% " . e($sa["malus_text"]) . "</span>)" .
                            "</small><br>";
                }

                $biome_info .= "</div>";
                break;
            case BuildingTypes::BUILDING_MARKETPLACE:
                $base_trades = MARKET_DAILY_TRADES_BASE;
                $trade_per_upg = MARKET_TRADES_PER_UPGRADE;
                $max_mkt_lvl = MARKET_UPGRADE_LIMIT;

                $biome_info = "<div class='tech-info-box'>";
                $biome_info .= "<b>Tägliche Handelsaktionen:</b><br>";
                $biome_info .= "Jede Ausbaustufe eines Marktplatzes (bis max. Stufe $max_mkt_lvl) aller deiner Königreiche 
                                erhöht dein tägliches globales Handelslimit dauerhaft um <span class='passed'>+" . fdec($trade_per_upg) . " Aktionen</span>.<br>";
                $biome_info .= "Formel: $base_trades Basis + (Summe aller Stufen bis $max_mkt_lvl × " . fdec($trade_per_upg) . "), abgerundet.";
                $biome_info .= "</div>";
                break;
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
            $tech_bonus_info = "<div class='tech-info-box'>";
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
                TechTypes::TECH_TYPE_ARCHITECTURE => [fdec(ARCHITECTURE_TIME_REDUCTION * 100) . "%", "Reduktion der Bauzeit"],
                TechTypes::TECH_TYPE_CARTOGRAPHY => [fdec(CARTOGRAPHY_SPEED_BONUS * 100) . "%", "höhere Marschgeschwindigkeit"],
                TechTypes::TECH_TYPE_MAINTENANCE => [fdec(MAINTENANCE_REPAIR_REDUCTION * 100) . "%", "Reduktion der Reparaturkosten"],
                TechTypes::TECH_TYPE_PLUNDER => [fdec(PLUNDER_CAPACITY_BONUS * 100) . "%", "mehr Beute-Kapazität"],
                TechTypes::TECH_TYPE_ANCESTRAL_RITES => [fdec(SHRINE_TECH_STEP * 100) . "%", "stärkerer Schrein-Effekt"],
                TechTypes::TECH_TYPE_WALL_HP_INC => [RESEARCH_WALL_HP_INC, "zusätzliche HP pro Mauerstufe"],
                TechTypes::TECH_TYPE_STORAGE_INC => [fnum(RESEARCH_STORAGE_INC), "zusätzliche Kapazität pro Ressource"],
                TechTypes::TECH_TYPE_IMPERIAL => ["", "Ermöglicht die Gründung einer weiteren Siedlung"],
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
                TechTypes::TECH_TYPE_WEIGHT => [fdec(SMITHY_WEIGHT_REDUCTION * 100) . "%", "Reduktion der Rekrutierungszeit"],
                TechTypes::TECH_TYPE_SIEGE => ["+" . fdec(SMITHY_SIEGE_BONUS * 100) . "%", "zusätzlicher Schaden an Mauern"],

                default => null
            };

            if ($res) {
                $value = $res[0];
                $text = $res[1];

                $formatted_value = !empty($value) ? "<span class='passed'>$value</span> " : "";
                $suffix = ($tech_id === TechTypes::TECH_TYPE_ARCANE_INTEL || $tech_id === TechTypes::TECH_TYPE_IMPERIAL) ? "" : " pro Stufe.";

                $tech_bonus_info = "<div class='tech-info-box'>";
                $tech_bonus_info .= "<b>Forschungs-Effekt:</b><br>";
                $tech_bonus_info .= "$formatted_value$text$suffix";
                $tech_bonus_info .= "</div>";
            }
        }

        $view .= "<div class='big-box-container tech-info-page'>
                    <div class='big-box-header tech-info-header'>$name</div>
                    <div class='big-box-content tech-info-page'>
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
        $view .= "</table>";

        $score_val = ($building_id !== null) ? $row["buildingscore"] : $row["techscore"];
        $view .= "<p style='font-size: 18px; margin-top: 15px;'>
                    Punkte pro Stufe: <b class='passed'>$score_val</b>
                  </p>";

        $view .= "</div></div>";
    }
} else {
    $view .= show_error_box("Nichts zum Anzeigen gefunden!");
}
echo $view;
?>
<br>
<div style="text-align:center">
    <button data-on-click="closeOverlay">
        Schließen
    </button>
</div>
</body>
</html>
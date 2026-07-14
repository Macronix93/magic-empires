<?php
require_once("includes/core.php");

check_user_login($user);

$res_query = "SELECT 
                SUM(food) as total_f, SUM(wood) as total_w, 
                SUM(stone) as total_s, SUM(gold) as total_g,
                SUM(foodperhour) as ph_f, SUM(woodperhour) as ph_w,
                SUM(stoneperhour) as ph_s, SUM(goldperhour) as ph_g
              FROM kingdoms";
$world_res = $db_instance->execute_query($res_query)->fetch_assoc();

$total_coins = $db_instance->execute_query("SELECT SUM(coins) FROM users")->fetch_row()[0];

$total_fields = MAX_X * MAX_Y;
$occupied_fields = $db_instance->execute_query("SELECT COUNT(*) FROM map WHERE kingdomid > 0")->fetch_row()[0];
$resource_tiles = $db_instance->execute_query("SELECT COUNT(*) FROM map WHERE kingdomid = -2")->fetch_row()[0];
$map_percentage = round(($occupied_fields / $total_fields) * 100, 2);

$total_soldiers = $db_instance->execute_query("SELECT SUM(soldiercount) FROM soldiers")->fetch_row()[0] ?? 0;
$avg_building_lvl = $db_instance->execute_query("SELECT AVG(buildinglevel) FROM buildings")->fetch_row()[0] ?? 0;

$view = "
<div style='display: flex; gap: 20px; flex-wrap: wrap; justify-content: center;'>
    <div class='box-container' style='width: 350px;'>
        <div class='box-header'>Globale Wirtschaft</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            <div style='text-align:center; margin-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;'>
                <b>Gesamte Vorräte aller Reiche</b>
            </div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " <span>Nahrung:</span></div><b>" . fnum($world_res["total_f"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " <span>Holz:</span></div><b>" . fnum($world_res["total_w"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " <span>Stein:</span></div><b>" . fnum($world_res["total_s"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " <span>Gold:</span></div><b>" . fnum($world_res["total_g"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " <span>Münzen:</span></div><b>" . fnum($total_coins) . "</b></div>
            <hr>
            <div style='font-size: 13px; opacity: 0.8; text-align: center;'>
                Globale Produktion: " . fnum($world_res["ph_f"] + $world_res["ph_w"] + $world_res["ph_s"] + $world_res["ph_g"]) . " Res./Std.
            </div>
        </div>
    </div>
    <div class='box-container' style='width: 350px;'>
        <div class='box-header'>Globale Entwicklung</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            <div class='split-content'><span>Besiedelte Fläche:</span> <b>$map_percentage %</b></div>
            <div class='split-content'><span>Königreiche:</span> <b>$occupied_fields</b></div>
            <div class='split-content'><span>Vorratslager (Karte):</span> <b>$resource_tiles</b></div>
            <div class='split-content'><span>Ø Gebäude-Stufe:</span> <b>" . round($avg_building_lvl, 1) . "</b></div>
            <hr>
            <div style='text-align:center; margin-bottom: 5px;'><b>Militärische Stärke</b></div>
            <div class='split-content'><span>Summe aller Truppen:</span> <b>" . fnum($total_soldiers) . "</b></div>
            <div class='split-content'><span>Durchgeführte Schlachten:</span> <b>" . fnum($db_instance->execute_query("SELECT COUNT(*) FROM gamelogs WHERE action = 'RESULT'")->fetch_row()[0]) . "</b></div>
        </div>
    </div>
</div>

<br>
<div class='title-border'>Top 5 Spieler</div>
<table class='table' style='max-width: 600px;'>
    <tr>
        <td class='td-center td-gradient'><b>Rang</b></td>
        <td class='td-gradient'><b>Herrscher</b></td>
        <td class='td-center td-gradient'><b>Punkte</b></td>
    </tr>";

$top5 = $db_instance->execute_query("SELECT username, score FROM users WHERE status = 1 ORDER BY score DESC LIMIT 5");
$rank = 1;

foreach ($top5 as $row) {
    $color = ($rank == 1) ? "style='color: #d4af37; font-weight: bold;'" : "";
    $view .= "<tr>
                <td class='td-center' $color>$rank</td>
                <td $color>" . e($row["username"]) . "</td>
                <td class='td-center' $color>" . fnum($row["score"]) . "</td>
              </tr>";
    $rank++;
}

$view .= "</table>";

$title = "Statistiken";
$header = "Statistiken";
include("layout/base.php");
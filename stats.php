<?php
require_once("includes/core.php");

check_user_login($user);

$stats_query = "
    SELECT 
        -- Kingdom Data
        SUM(k.food) as total_f, SUM(k.wood) as total_w, SUM(k.stone) as total_s, SUM(k.gold) as total_g,
        SUM(k.foodperhour) as ph_f, SUM(k.woodperhour) as ph_w, SUM(k.stoneperhour) as ph_s, SUM(k.goldperhour) as ph_g,
        SUM(k.villager) as total_pop,
        
        -- User Data
        (SELECT COUNT(*) FROM users WHERE status = 1) as total_users,
        (SELECT COUNT(*) FROM users WHERE lastactivity > (UNIX_TIMESTAMP() - 86400)) as active_users_24h,
        (SELECT SUM(coins) FROM users) as total_coins,
        
        -- Map Data
        (SELECT COUNT(*) FROM map WHERE kingdomid > 0) as occupied_fields,
        (SELECT COUNT(*) FROM map WHERE kingdomid = -2) as resource_tiles,
        
        -- Military
        ((SELECT IFNULL(SUM(soldiercount), 0) FROM soldiers) + (SELECT IFNULL(SUM(soldiercount), 0) FROM sent_troops)) as total_soldiers,
        (SELECT IFNULL(AVG(buildinglevel), 0) FROM buildings) as avg_building_lvl,
        (SELECT IFNULL(SUM(techlevel), 0) FROM techs) as total_tech_lvls,
        (SELECT IFNULL(value, 0) FROM system_settings WHERE name = 'total_fallen_soldiers') as total_fallen,
        (SELECT COUNT(*) FROM game_logs WHERE action = 'RESULT') as total_battles,
        
        -- Other
        (SELECT COUNT(*) FROM messages) as total_msgs,
        (SELECT COUNT(*) FROM game_logs WHERE action = 'OFFER_ACCEPT') as total_trades,
        (SELECT IFNULL(SUM(supplyvalue), 0) FROM marketplace) as market_volume
        
    FROM kingdoms k";

$stats = $db_instance->execute_query($stats_query)->fetch_assoc();

$total_fields = MAX_X * MAX_Y;
$map_percentage = round(($stats['occupied_fields'] / $total_fields) * 100, 2);

/* --- VIEW --- */
$view = "
<div style='display: flex; gap: 20px; flex-wrap: wrap; justify-content: center;'>
    <div class='box-container' style='width: 350px;'>
        <div class='box-header'>Globale Wirtschaft</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            <div style='text-align:center; margin-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;'>
                <b>Gesamte Vorräte aller Reiche</b>
            </div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " <span>Nahrung:</span></div><b>" . fnum($stats["total_f"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " <span>Holz:</span></div><b>" . fnum($stats["total_w"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " <span>Stein:</span></div><b>" . fnum($stats["total_s"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " <span>Gold:</span></div><b>" . fnum($stats["total_g"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " <span>Münzen:</span></div><b>" . fnum($stats["total_coins"]) . "</b></div>
            <div style='font-size: 13px; opacity: 0.8; text-align: center; margin-top: 10px;'>
                Globale Produktion: " . fnum($stats["ph_f"] + $stats["ph_w"] + $stats["ph_s"] + $stats["ph_g"]) . " Res./Std.
            </div>
            <hr>
            <div class='split-content'><span>Markt-Angebote:</span> <b>" . fnum($stats["market_volume"]) . " Res.</b></div>
            <div class='split-content'><span>Handelsabschlüsse:</span> <b>" . fnum($stats["total_trades"]) . "</b></div>
        </div>
    </div>
    <div class='box-container' style='width: 350px;'>
        <div class='box-header'>Globale Entwicklung</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            <div class='split-content'><span>Besiedelte Fläche:</span> <b>$map_percentage %</b></div>
            <div class='split-content'><span>Königreiche:</span> <b>{$stats['occupied_fields']}</b></div>
            <div class='split-content'><span>Vorratslager (Karte):</span> <b>{$stats['resource_tiles']}</b></div>
            <div class='split-content'><span>Ø Gebäude-Stufe:</span> <b>" . round($stats['avg_building_lvl'], 1) . "</b></div>
            <div class='split-content'><span>Erforschte Tech-Stufen:</span> <b>" . fnum($stats['total_tech_lvls']) . "</b></div>
            <hr>
            <div style='text-align:center; margin-bottom: 5px;'><b>Militär</b></div>
            <div class='split-content'><span>Truppen:</span> <b>" . fnum($stats['total_soldiers']) . "</b></div>
            <div class='split-content'><span>Gefallene Truppen:</span> <b>" . fnum($stats['total_fallen']) . "</b></div>
            <div class='split-content'><span>Schlachten:</span> <b>" . fnum($stats['total_battles']) . "</b></div>
        </div>
    </div>
    <div class='box-container' style='width: 350px;'>
        <div class='box-header'>Server-Statistiken</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            <div class='split-content'><span>Registrierte Nutzer:</span> <b>{$stats['total_users']}</b></div>
            <div class='split-content'><span>Aktive Nutzer (24h):</span> <b>{$stats['active_users_24h']}</b></div>
            <div class='split-content'><span>Gesamtbevölkerung:</span> <b>" . fnum($stats["total_pop"]) . "</b></div>
            <div class='split-content'><span>Privatnachrichten:</span> <b>" . fnum($stats["total_msgs"]) . "</b></div>
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

// Top 5 Query
$top5 = $db_instance->execute_query("SELECT username, score FROM users WHERE status = 1 ORDER BY score DESC, id LIMIT 5");
$rank = 1;
foreach ($top5 as $row) {
    $rank_class = match ($rank) {
        1 => "rank-gold",
        2 => "rank-silver",
        3 => "rank-bronze",
        default => ""
    };
    $view .= "<tr>
                <td class='td-center $rank_class'>$rank</td>
                <td class='" . $rank_class . "'>" . e($row["username"]) . "</td>
                <td class='td-center $rank_class'>" . fnum($row["score"]) . "</td>
              </tr>";
    $rank++;
}
$view .= "</table>";

$title = "Statistiken";
$header = "Statistiken";

include("layout/base.php");
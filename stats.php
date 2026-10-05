<?php
require_once("includes/core.php");

$user->check_user_login();

$stats_manager = new Stats();
$uid = $user->get_user_id();

$stats = $stats_manager->get_global_stats();
$my_stats = $stats_manager->get_player_stats($uid);
$breakdown = $stats_manager->calculate_user_score_breakdown($uid);

$score_buildings = $breakdown["buildings"];
$score_techs = $breakdown["techs"];
$score_troops = $breakdown["troops"];
$perc_b = $breakdown["perc_b"];
$perc_t = $breakdown["perc_t"];
$perc_u = $breakdown["perc_u"];

$total_fields = MAX_X * MAX_Y;
$map_percentage = round(($stats['occupied_fields'] / $total_fields) * 100, 2);

/* --- VIEW --- */

$view = "
<div style='display: flex; justify-content: center; margin-bottom: 30px; text-align:left; padding: 0 10px;'>
    <div class='box-container stats-box-main'>
        <div class='box-header'>Persönliche Statistik</div>
        <div class='box-content box-content-bg' style='padding: 15px; display: flex; flex-wrap: wrap; gap: 20px; justify-content: center;'>
            <div class='stats-inner-column'>
                <div style='text-align: center; margin-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;'>
                    <b>Militär & Kampf</b>
                </div>
                <div class='split-content'><span>Monster besiegt:</span> <b>" . fnum($my_stats["monster_kills"]) . "</b></div>
                <div class='split-content'><span>Camps gesäubert:</span> <b>" . fnum($my_stats["camps_cleared"]) . "</b></div>
                <div class='split-content'><span>Lager geplündert:</span> <b>" . fnum($my_stats["res_tiles_cleared"]) . "</b></div>
                <div class='split-content'><span>Minen abgebaut:</span> <b>" . fnum($my_stats["mines_depleted"]) . "</b></div>
                <div class='split-content'><span>Spionagen:</span> <b>" . fnum($my_stats["spy_count"]) . "</b></div>
                <div class='split-content'><span>Truppen besiegt (PvP):</span> <b>" . fnum($my_stats["units_defeated_pvp"] ?? 0) . "</b></div>
                <div class='split-content'><span>Truppen verloren (PvP):</span> <b>" . fnum($my_stats["units_fallen_pvp"]) . "</b></div>
                <div class='split-content'><span>Truppen verloren (PvE):</span> <b>" . fnum($my_stats["units_fallen_pve"]) . "</b></div>
                <hr>
                <div style='text-align: center; margin-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;'>
                    <b>Wirtschaft & Expansion</b>
                </div>
                <div class='split-content'><span>Gebäude-Upgrades:</span> <b>" . fnum($my_stats["buildings_upgraded"]) . "</b></div>
                <div class='split-content'><span>Truppen rekrutiert:</span> <b>" . fnum($my_stats["units_produced"]) . "</b></div>
                <div class='split-content'><span>Truppen aufgewertet:</span> <b>" . fnum($my_stats["units_upgraded"]) . "</b></div>
                <div class='split-content'><span>Beute (Camps/Lager):</span> <b>" . fnum($my_stats["resources_looted"]) . "</b></div>
                <div class='split-content'><span>Spezial-Erze geschürft:</span> <b>" . fnum($my_stats["special_resources_mined"]) . "</b></div>
                <div class='split-content'><span>Spieler beklaut:</span> <b>" . fnum($my_stats["resources_stolen"]) . "</b></div>
                <hr>
                <div style='text-align: center; margin-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;'>
                    <b>Punkte-Aufschlüsselung</b>
                </div>
                " . Messages::wrap_emojis("<div class='split-content'><span>🏰 Gebäude:</span> <b>" . fnum($score_buildings) . " <small style='opacity: 0.7;'>($perc_b %)</small></b></div>
                <div class='split-content'><span>📜 Forschung:</span> <b>" . fnum($score_techs) . " <small style='opacity: 0.7;'>($perc_t %)</small></b></div>
                <div class='split-content'><span>⚔️ Armee:</span> <b>" . fnum($score_troops) . " <small style='opacity: 0.7;'>($perc_u %)</small></b></div>
                <div class='split-content' style='margin-top: 18px; padding-top: 4px; border-top: 1px solid rgba(255,255,255,0.1);'>
                    <span>Gesamtpunkte:</span> <b class='passed'>" . fnum($score_buildings + $score_techs + $score_troops, true) . "</b>
                </div>") . "
            </div>

            <hr class='hr-mobile-only'>
            <div class='badge-hide-mobile' style='width: 1px; background: rgba(255,255,255,0.1); align-self: stretch;'></div>

            <div class='stats-inner-column'>
                <div style='text-align: center; margin-bottom: 10px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;'>
                    <b>Handel</b>
                </div>
                <div class='split-content'><span>Handelsabschlüsse:</span><b>" . fnum($my_stats["trades_count"]) . "</b></div>
                <div class='split-content'><span>Angebote:</span> <b>" . fnum($stats["market_volume"]) . " Res.</b></div>
                
                <div style='margin-top: 15px;'>
                    <div class='stats-import-export'>Exportiert (Gesendet):</div>
                    <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;'>
                        <div class='trade-grid-item'> " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " <span>" . fnum($my_stats["trade_sent_food"]) . "</span></div>
                        <div class='trade-grid-item'> " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " <span>" . fnum($my_stats["trade_sent_wood"]) . "</span></div>
                        <div class='trade-grid-item'> " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " <span>" . fnum($my_stats["trade_sent_stone"]) . "</span></div>
                        <div class='trade-grid-item'> " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " <span>" . fnum($my_stats["trade_sent_gold"]) . "</span></div>
                    </div>
                    
                    <div class='stats-import-export'>Importiert (Erhalten):</div>
                    <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 10px;'>
                        <div class='trade-grid-item'> " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " <span>" . fnum($my_stats["trade_received_food"]) . "</span></div>
                        <div class='trade-grid-item'> " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " <span>" . fnum($my_stats["trade_received_wood"]) . "</span></div>
                        <div class='trade-grid-item'> " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " <span>" . fnum($my_stats["trade_received_stone"]) . "</span></div>
                        <div class='trade-grid-item'> " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " <span>" . fnum($my_stats["trade_received_gold"]) . "</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div style='display: flex; gap: 20px; flex-wrap: wrap; justify-content: center;'>
    <div class='box-container' style='width: 310px;'>
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
            <hr>
            <div style='font-size: 13px; opacity: 0.8; text-align: center; margin-top: 10px;'>
                Globale Produktion: " . fnum($stats["ph_f"] + $stats["ph_w"] + $stats["ph_s"] + $stats["ph_g"]) . " Res./Std.
            </div>
            <div style='text-align:center; margin: 15px 0 10px 0; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;'>
                <b>Vorratslager (Karte)</b>
            </div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " <span>Nahrung:</span></div><b>" . fnum($stats["map_food"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " <span>Holz:</span></div><b>" . fnum($stats["map_wood"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " <span>Stein:</span></div><b>" . fnum($stats["map_stone"]) . "</b></div>
            <div class='split-content'><div>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " <span>Gold:</span></div><b>" . fnum($stats["map_gold"]) . "</b></div>
            <div class='split-content' style='margin-top: 5px;'>
                <span>Gesamt auf Karte:</span> <b>" . fnum($stats["map_total"]) . "</b>
            </div>
        </div>
    </div>
    <div class='box-container' style='width: 310px;'>
        <div class='box-header'>Globale Entwicklung</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            <div class='split-content'><span>Besiedelte Fläche:</span> <b>" . fdec($map_percentage, 2) . " %</b></div>
            <div class='split-content'><span>Königreiche:</span> <b>{$stats["occupied_fields"]}</b></div>
            <div class='split-content'><span>Vorratslager:</span> <b>{$stats["resource_tiles"]}</b></div>
            <div class='split-content'><span>Monstercamps:</span> <b>{$stats["monster_camps"]}</b></div>
            <div class='split-content'><span>Erzminen:</span> <b>{$stats["active_mines"]}</b></div>
            <div class='split-content'><span>Ø Gebäude-Stufe:</span> <b>" . fdec($stats["avg_building_lvl"]) . "</b></div>
            <div class='split-content'><span>Erforschte Tech-Stufen:</span> <b>" . fnum($stats["total_tech_lvls"]) . "</b></div>
            <hr>
            <div style='text-align:center; margin-bottom: 5px;'><b>Militär</b></div>
            <div class='split-content'><span>Truppen:</span> <b>" . fnum($stats["total_soldiers"]) . "</b></div>
            <div class='split-content'><span>Gefallene Truppen:</span> <b>" . fnum($stats["total_fallen"]) . "</b></div>
            <div class='split-content'><span>Besiegte Monster:</span> <b>" . fnum($stats["total_monsters_slain"]) . "</b></div> 
            <div class='split-content'><span>Schlachten:</span> <b>" . fnum($stats["total_battles"]) . "</b></div>
        </div>
    </div>
    <div class='box-container' style='width: 310px;'>
        <div class='box-header'>Server-Statistiken</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            <div class='split-content'><span>Registrierte Nutzer:</span> <b>{$stats["total_users"]}</b></div>
            <div class='split-content'><span>Aktive Nutzer (24h):</span> <b>{$stats["active_users_24h"]}</b></div>
            <div class='split-content'><span>Gesamtbevölkerung:</span> <b>" . fnum($stats["total_pop"]) . "</b></div>
            <div class='split-content'><span>Privatnachrichten:</span> <b>" . fnum($stats["total_msgs"]) . "</b></div>
        </div>
    </div>
</div>";

$title = "Statistiken";
$header = "Statistiken";

include("layout/base.php");
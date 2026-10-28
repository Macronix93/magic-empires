<?php
require_once("../includes/core.php");
if (!isset($_SERVER["HTTP_X_REQUESTED_WITH"]) || strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) !== "xmlhttprequest") {
    change_location("arena.php");
    exit;
}

$uid = $user->get_user_id();
$arena = new Arena($uid);
$player_data = $arena->get_player_data();

$defender_id = (int)($_GET["defender_id"] ?? 0);
$defender = $arena->get_player_data($defender_id);
if (!$defender) {
    echo show_error_box("Gegner nicht gefunden.");
    exit;
}

$res_sol = $db_instance->execute_query("SELECT id, soldiername, category, attack, defense, icon FROM soldier_list WHERE id IN (" . implode(',', ARENA_ALLOWED_SOLDIER_IDS) . ") ORDER BY category, id");
$soldiers = $res_sol->fetch_all(MYSQLI_ASSOC);

$res_tech = $db_instance->execute_query("SELECT id, techname FROM tech_list WHERE id IN (" . implode(',', ARENA_ALLOWED_TECH_IDS) . ") ORDER BY id");
$techs = $res_tech->fetch_all(MYSQLI_ASSOC);

$hero_info = $arena->get_hero_full_data();
$h_data = $hero_info["hero"];
$active_skill = $hero_info["active_skill"];

$prefill_troops = $player_data["defense_troops"] ?? [];
$prefill_techs = $player_data["defense_techs"] ?? [];
?>

<div id="modal-title-data" data-title="Duell: <?= e($defender["username"]) ?> (<?= $defender["elo"] ?> ELO)"
     data-width="650px" style="display: none;"></div>
<div style="padding: 10px; max-width: 600px; margin: 0 auto; text-align: left;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid var(--border-gold); padding-bottom: 10px;">
        <span>Gegner: <b><?= e($defender["username"]) ?></b></span>
        <span style="color: var(--link-color); font-weight: bold;"><?= fnum($defender["elo"]) ?> ELO</span>
    </div>
    <form id="arena-attack-form" data-on-submit="executeArenaAttack">
        <input type="hidden" name="defender_id" value="<?= $defender_id ?>">
        <div style="display: flex; justify-content: center; gap: 20px; padding: 10px; margin-bottom: 15px; background: rgba(0,0,0,0.35); border: 1px solid var(--border-gold); border-radius: 5px;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <img src="images/icons/icon_sword.png" class="ressource-icons" alt="ATK"> <span
                        class="js-arena-total-atk">0</span>
            </div>
            <div style="display: flex; align-items: center; gap: 6px;">
                <img src="images/icons/icon_shield.png" class="ressource-icons" alt="DEF"> <span
                        class="js-arena-total-def">0</span>
            </div>
        </div>
        <div style="background: rgba(0, 0, 0, 0.35); border: 1px solid var(--border-gold); border-radius: 5px; padding: 10px 14px; margin-bottom: 16px; box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.4);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; border-bottom: 1px solid rgba(255, 255, 255, 0.1); padding-bottom: 8px; margin-bottom: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <img src="images/icons/icon_hero.png" style="width: 24px; height: 24px; vertical-align: middle;"
                         alt="Held">
                    <span style="font-weight: bold; color: var(--link-color); font-size: 14px;">Dein Champion</span>
                    <span class="passed"
                          style="font-size: 12px; font-weight: bold;">(Stufe <?= $h_data["level"] ?>)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 12px; font-size: 13px;">
                    <div style="display: flex; align-items: center; gap: 5px;" title="Helden-Angriff">
                        <span><?= get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) ?></span>
                        <b><?= fnum($h_data["atk"], true) ?></b>
                    </div>
                    <div style="display: flex; align-items: center; gap: 5px;" title="Helden-Verteidigung">
                        <span><?= get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) ?></span>
                        <b><?= fnum($h_data["def"], true) ?></b>
                    </div>
                </div>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; font-size: 12px;">
                <span style="opacity: 0.8;">Spezialisierung:</span>
                <div style="background: rgba(212, 175, 55, 0.15); border: 1px solid var(--link-color); border-radius: 3px; padding: 2px 8px;">
                    <b><?= e($active_skill["name"]) ?></b> &nbsp;|&nbsp;
                    <span class="passed"><b>+<?= round($active_skill["rps_bonus"] * 100, 1) ?>%</b> vs. <?= e($active_skill["target_name"]) ?></span>
                </div>
            </div>
        </div>
        <div id="hero-data-node" data-atk="<?= $h_data["atk"] ?>" data-def="<?= $h_data["def"] ?>"
             style="display: none;"></div>
        <div class="split-content" style="margin-bottom: 10px;">
            <b>Angriffstruppen:</b> <b id="attack-troops-counter" class="passed">0
                / <?= fnum(ARENA_ARMY_UNIT_LIMIT) ?></b>
        </div>
        <?php foreach ($soldiers as $s):
            $sid = (int)$s["id"];
            $val = (int)($prefill_troops[$sid] ?? 0);
            ?>
            <div class="arena-slider-row">
                <div class="arena-unit-label">
                    <img src="images/icons/<?= $s["icon"] ?>.png" class="ressource-icons" alt="">
                    <span class="arena-soldier-name"><?= $s["soldiername"] ?></span>
                </div>

                <input type="range" class="js-arena-slider" data-target="atk_sol_<?= $sid ?>"
                       min="0" max="<?= ARENA_ARMY_UNIT_LIMIT ?>" step="1" value="<?= $val ?>"
                       aria-label="<?= e($s["soldiername"]) ?> Regler">

                <input type="text" inputmode="numeric" pattern="[0-9]*" id="atk_sol_<?= $sid ?>"
                       name="troops[<?= $sid ?>]"
                       class="js-arena-input js-numeric-input"
                       data-atk="<?= $s["attack"] ?>" data-def="<?= $s["defense"] ?>" data-cat="<?= $s["category"] ?>"
                       value="<?= $val ?>" style="width: 60px; text-align: center;"
                       aria-label="<?= e($s["soldiername"]) ?> Anzahl">
            </div>
        <?php endforeach; ?>
        <div class="split-content" style="margin: 20px 0 10px 0;">
            <b>Schmiede-Boni:</b> <b id="attack-techs-counter" class="passed">0 / <?= ARENA_MAX_TECH_POINTS ?></b>
        </div>
        <?php foreach ($techs as $t):
            $tid = (int)$t["id"];
            $t_val = (int)($prefill_techs[$tid] ?? 0);
            ?>
            <div class="arena-slider-row">
                <div class="arena-unit-label">
                    <img src="images/icons/icon_tech<?= $tid ?>.png" class="ressource-icons" alt="">
                    <span class="arena-tech-name"><?= $t["techname"] ?></span>
                </div>

                <input type="range" class="js-arena-tech-slider" data-target="atk_tech_<?= $tid ?>"
                       min="0" max="<?= ARENA_MAX_TECH_LEVEL ?>" step="1" value="<?= $t_val ?>"
                       aria-label="<?= e($t["techname"]) ?> Regler">

                <input type="text" inputmode="numeric" pattern="[0-9]*" id="atk_tech_<?= $tid ?>"
                       name="techs[<?= $tid ?>]"
                       class="js-arena-tech-input js-numeric-input"
                       data-tech-id="<?= $tid ?>" value="<?= $t_val ?>" style="width: 60px; text-align: center;"
                       aria-label="<?= e($t["techname"]) ?> Stufe">
            </div>
        <?php endforeach; ?>
        <div style="text-align: center; margin-top: 25px;">
            <button type="submit" id="btn-start-arena-attack" style="padding: 10px 30px; font-weight: bold;">
                ⚔️ Kampf beginnen (1 Münze)
            </button>
        </div>
    </form>
</div>
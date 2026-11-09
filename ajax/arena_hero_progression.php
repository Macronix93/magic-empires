<?php
require_once("../includes/ajax_core.php");

$uid = $user->get_user_id();
$arena = new Arena($uid);
$hero_info = $arena->get_hero_full_data();
$type = $_GET["type"] ?? "hero";

$render_row = function (int $lvl, int $cur_lvl, int $max_lvl, int $xp_needed, int $cum_xp, string $val_html): string {
    $is_max = ($lvl === $max_lvl);
    $highlight = ($lvl === $cur_lvl) ? "style='background-color: rgba(11, 218, 81, 0.2); font-weight: bold;'" : "";
    $xp_col = $is_max ? "<span class='passed'>MAX</span>" : fnum($xp_needed);

    return "<tr>
        <td class='td-center' $highlight>$lvl</td>
        <td class='td-center' $highlight>$xp_col</td>
        <td class='td-center' $highlight>" . fnum($cum_xp) . "</td>
        <td class='td-center' $highlight>$val_html</td>
    </tr>";
};

if ($type === "hero"):
    $cur_lvl = (int)$hero_info["hero"]["level"];
    ?>
    <div id="modal-title-data" data-title="Champion: Werte & Progression" data-width="650px"
         style="display: none;"></div>
    <div style="padding: 10px; margin: 0 auto; text-align: center;">
        <h3 class="title-border" style="margin-top: 0;">Helden-Progression</h3>
        <p style="font-size: 13px; opacity: 0.85; margin-bottom: 15px;">
            Dein Held kämpft in jedem Arena-Duell an vorderster Front. Seine Basiswerte steigen mit jedem Level-Up:
        </p>

        <table class="table" style="width: 100%; font-size: 13px;">
            <tr>
                <td class="td-gradient td-center" style="width: 15%;"><b>Lv.</b></td>
                <td class="td-gradient td-center" style="width: 25%;"><b>XP für Stufe</b></td>
                <td class="td-gradient td-center" style="width: 25%;"><b>Gesamt-XP</b></td>
                <td class="td-gradient td-center" style="width: 35%;"><b>ATK | DEF</b></td>
            </tr>
            <?php
            $cumulative_xp = 0;
            for ($lvl = 1; $lvl <= ARENA_HERO_MAX_LEVEL; $lvl++) {
                $xp_needed = Arena::get_hero_xp_needed($lvl);
                $hero_atk = (int)round(ARENA_HERO_BASE_ATK * pow(ARENA_HERO_STAT_FACTOR, $lvl - 1));
                $hero_def = (int)round(ARENA_HERO_BASE_DEF * pow(ARENA_HERO_STAT_FACTOR, $lvl - 1));

                echo $render_row($lvl, $cur_lvl, ARENA_HERO_MAX_LEVEL, $xp_needed, $cumulative_xp, fnum($hero_atk) . " | " . fnum($hero_def));

                if ($lvl < ARENA_HERO_MAX_LEVEL) $cumulative_xp += $xp_needed;
            }
            ?>
        </table>
    </div>
<?php
elseif ($type === "skill"):
    $skill_id = (int)($_GET["id"] ?? 1);
    if (!isset(ARENA_HERO_SKILLS[$skill_id])) $skill_id = 1;
    $skill_meta = ARENA_HERO_SKILLS[$skill_id];
    $cur_lvl = (int)($hero_info["skills"][$skill_id]["level"] ?? 1);
    ?>
    <div id="modal-title-data" data-title="<?= e($skill_meta["name"]) ?>: Progression" data-width="650px"
         style="display: none;"></div>
    <div style="padding: 10px; margin: 0 auto; text-align: center;">
        <h3 class="title-border" style="margin-top: 0;"><?= e($skill_meta["name"]) ?></h3>
        <p style="font-size: 13px; opacity: 0.85; margin-bottom: 15px;">
            <i><?= e($skill_meta["desc"]) ?></i><br>
            Fokussiert <b><?= e($skill_meta["target_name"]) ?></b> mit zusätzlichem Konter-Schaden.
        </p>

        <table class="table" style="width: 100%; font-size: 13px;">
            <tr>
                <td class="td-gradient td-center" style="width: 15%;"><b>Lv.</b></td>
                <td class="td-gradient td-center" style="width: 25%;"><b>XP für Stufe</b></td>
                <td class="td-gradient td-center" style="width: 25%;"><b>Gesamt-XP</b></td>
                <td class="td-gradient td-center" style="width: 35%;"><b>Konter-Bonus</b></td>
            </tr>
            <?php
            $cumulative_skill_xp = 0;
            for ($s_lvl = 1; $s_lvl <= ARENA_SKILL_MAX_LEVEL; $s_lvl++) {
                $skill_xp_needed = Arena::get_skill_xp_needed($s_lvl);
                $skill_rps = Arena::get_skill_rps_bonus($s_lvl) * 100;

                echo $render_row($s_lvl, $cur_lvl, ARENA_SKILL_MAX_LEVEL, $skill_xp_needed, $cumulative_skill_xp, "<span class='passed'>+" . fdec($skill_rps) . "%</span>");

                if ($s_lvl < ARENA_SKILL_MAX_LEVEL) $cumulative_skill_xp += $skill_xp_needed;
            }
            ?>
        </table>
    </div>
<?php endif; ?>
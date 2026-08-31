<?php
require_once("includes/core.php");

check_user_login($user);

$kingdom = new Kingdom($db_instance, $user->get_current_kingdom());

$mil_tech_ids_attacker = [
    TechTypes::TECH_TYPE_BLADES,
    TechTypes::TECH_TYPE_SHIELDWALL,
    TechTypes::TECH_TYPE_LANCE_RIDING,
    TechTypes::TECH_TYPE_CUIRASS,
    TechTypes::TECH_TYPE_ARROWHEADS,
    TechTypes::TECH_TYPE_DOUBLET,
    TechTypes::TECH_TYPE_SIEGE,
    TechTypes::TECH_TYPE_ANCESTRAL_RITES
];
$mil_tech_ids_defender = [
    TechTypes::TECH_TYPE_BLADES,
    TechTypes::TECH_TYPE_SHIELDWALL,
    TechTypes::TECH_TYPE_LANCE_RIDING,
    TechTypes::TECH_TYPE_CUIRASS,
    TechTypes::TECH_TYPE_ARROWHEADS,
    TechTypes::TECH_TYPE_DOUBLET,
    TechTypes::TECH_TYPE_WALL_HP_INC,
    TechTypes::TECH_TYPE_ANCESTRAL_RITES
];

$tech_meta = [];
$res_meta = $db_instance->execute_query("SELECT id, techname, maxlevel FROM tech_list WHERE id IN (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
    TechTypes::TECH_TYPE_BLADES,
    TechTypes::TECH_TYPE_SHIELDWALL,
    TechTypes::TECH_TYPE_LANCE_RIDING,
    TechTypes::TECH_TYPE_CUIRASS,
    TechTypes::TECH_TYPE_ARROWHEADS,
    TechTypes::TECH_TYPE_DOUBLET,
    TechTypes::TECH_TYPE_WALL_HP_INC,
    TechTypes::TECH_TYPE_SIEGE,
    TechTypes::TECH_TYPE_ANCESTRAL_RITES
]);
foreach ($res_meta as $row) {
    $tech_meta[$row["id"]] = $row;
}

$render_tech_side = function ($side_prefix, $tech_meta, $ids_to_render, $kingdom_obj = null) {
    $html = "";
    foreach ($ids_to_render as $id) {
        if (!isset($tech_meta[$id])) continue;
        $name = $tech_meta[$id]["techname"];
        $max = $tech_meta[$id]["maxlevel"];
        $icon = "images/icons/icon_tech" . $id . ".png";
        $val = ($kingdom_obj) ? $kingdom_obj->get_kingdom_tech_level($id) : 0;

        $html .= "
        <div class='split-content' style='margin-bottom: 5px; gap: 10px;'>
            <div style='display: flex; align-items: center; gap: 8px;'>
                <img src='$icon' class='ressource-icons' title='$name' alt=''>
                <span style='font-size: 13px;'>$name</span>
            </div>
            <input type='number' id='{$side_prefix}_tech_$id' 
                   class='js-tech-input' value='$val' min='0' max='$max' inputmode='numeric' pattern='[0-9]*' 
                   style='width: 45px;'>
        </div>";
    }
    return $html;
};

// Soldierlist
$soldiers = [];
$result = $db_instance->execute_query("SELECT id, soldiername, category, attack, defense, icon FROM soldier_list");

foreach ($result as $row) {
    $soldier = new Soldier();
    $soldier->fill_from_row($row);

    $soldiers[] = $soldier;
}

$soldiers_array = json_encode(array_map(function ($soldier) {
    return $soldier->get_soldier_name();
}, $soldiers));

// Owned Troops
$owned_troops = [];
$query_all_troops = "
    SELECT s_name, SUM(amount) as total
    FROM (
        SELECT soldiername AS s_name, soldiercount AS amount FROM soldiers WHERE kingdomid = ?
        UNION ALL
        SELECT sl.soldiername AS s_name, st.soldiercount AS amount 
        FROM sent_troops st 
        JOIN events e ON st.eventid = e.eventid 
        JOIN soldier_list sl ON st.soldierid = sl.id 
        WHERE e.kingdomid = ?
    ) AS combined_troops
    GROUP BY s_name";
$res_owned = $db_instance->execute_query($query_all_troops, [
    $user->get_current_kingdom(),
    $user->get_current_kingdom()
]);
while ($row = $res_owned->fetch_assoc()) {
    $owned_troops[$row["s_name"]] = (int)$row["total"];
}

// Monsterlist
$monsters = [];
$res_m = $db_instance->execute_query("SELECT id, monster_name, attack, defense, icon FROM monster_list ORDER BY level, id");
foreach ($res_m as $row) {
    $monsters[] = $row;
}

// Shrine bonus
$res_war_data = $db_instance->execute_query("SELECT base_bonus FROM shrine_alignments WHERE id = 1");
$war_base_bonus = $res_war_data->fetch_column() ?: 0.08;
$is_war_god = ($kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR);

// Wall bonus
$initial_wall_lvl = 1;
$initial_wall_hp = $initial_wall_lvl * DEFAULT_WALL_HP;
$initial_wall_def = MIN_WALL_DEFENSE;

$view = "Hier kannst du das Ergebnis eines Kampfes berechnen.<br><br>";

$view .= '<div style="display: flex; gap: 30px; justify-content: center; flex-wrap: wrap; margin-bottom: 20px;">';
$view .= '<div class="box-container" style="max-width: 250px; margin: 0;">
    <div class="box-header">Deine Forschungen</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        ' . $render_tech_side("my", $tech_meta, $mil_tech_ids_attacker, $kingdom) . '
        <div class="split-content" style="margin-top: 8px; padding-top: 5px; border-top: 1px solid #555;">
            <span>Kriegsgott aktiv:</span>
            <input type="checkbox" id="my_shrine_war" class="js-tech-input" ' . ($is_war_god ? "checked" : "") . '>
        </div>
    </div>
</div>';

$view .= '<div class="box-container" id="enemy-tech-box" style="max-width: 250px; margin: 0;">
    <div class="box-header">Gegnerische Boni</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        ' . $render_tech_side("en", $tech_meta, $mil_tech_ids_defender) . '
        <div class="split-content" style="margin-top: 8px; padding-top: 5px; border-top: 1px solid #555;">
            <span>Kriegsgott aktiv:</span>
            <input type="checkbox" id="en_shrine_war" class="js-tech-input">
        </div>
        <div style="margin-top: 10px; padding-top: 10px; border-top: 2px solid var(--border-gold);">
            <b>Mauer-Zustand</b>
            <div class="split-content" style="margin-top: 5px;">
                <span>Stufe:</span>
                <input type="number" id="en_wall_lvl" value="1" min="1" max="' . MAX_BUILDING_LEVEL . '" inputmode="numeric" pattern="[0-9]*" 
                style="width: 60px;">
            </div>
            <div style="text-align: left; font-size: 12px; opacity: 0.8; margin-top: 5px;">
                HP: <span id="wall_hp_display">' . fnum($initial_wall_hp) . '</span> / <span id="wall_hp_display_max">' . fnum($initial_wall_hp) . '</span>
            </div>
            <div style="text-align: right; font-weight: bold; color: var(--link-color); margin-top: 5px;">
                Bonus: +<span id="wall_def_display">' . $initial_wall_def . '</span> DEF
            </div>
        </div>
    </div>
</div>';
$view .= '</div>';

$view .= '<div id="live-power-container" style="display: flex; justify-content: center; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;">
                <div class="box-container" style="max-width: 230px; margin: 0;">
                    <div class="box-header" style="font-size: 18px;">Stärke Spieler</div>
                    <div class="box-content box-content-bg" style="padding: 10px; display: flex; justify-content: space-around;">
                        <div title="Gesamt-Angriffswert">
                            <img src="images/icons/icon_sword.png" class="ressource-icons" alt=""> <b id="live-atk-own">0</b>
                        </div>
                        <div title="Gesamt-Verteidigungswert">
                            <img src="images/icons/icon_shield.png" class="ressource-icons" alt=""> <b id="live-def-own">0</b>
                        </div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; font-weight: bold; color: var(--link-color); font-size: 24px;">VS</div>
                <div class="box-container" style="max-width: 230px; margin: 0;">
                    <div class="box-header" style="font-size: 18px;">Stärke Gegner</div>
                    <div class="box-content box-content-bg" style="padding: 10px; display: flex; justify-content: space-around;">
                        <div title="Gesamt-Angriffswert">
                            <img src="images/icons/icon_sword.png" class="ressource-icons" alt=""> <b id="live-atk-enemy">0</b>
                        </div>
                        <div title="Gesamt-Verteidigungswert">
                            <img src="images/icons/icon_shield.png" class="ressource-icons" alt=""> <b id="live-def-enemy">0</b>
                        </div>
                    </div>
                </div>
            </div>';

$view .= '<div style="display: flex; justify-content: center; gap: 5px; margin-bottom: 10px;">
              <button type="button" data-on-click="calculateWarOutcome">Berechnen</button>
              <button type="button" id="btn-undo" data-on-click="undoWarSim" disabled>Rückgängig</button>
              <button type="button" data-on-click="resetFields">Reset</button>
          </div>';
$view .= '<div style="text-align: center; margin-bottom: 15px;">
            <label style="font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; user-select: none;">
                <input type="checkbox" id="toggle-relevant-units" data-on-change="filterRelevantRows">
                <span>Nur relevante Einheiten anzeigen</span>
            </label>
          </div>';

$view .= '<div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start;">';
$view .= '<div class="box-container" style="flex: 1; min-width: 320px; margin: 0;">
                <div class="box-header">Deine Armee</div>
                <table class="table warsim-table" style="width: 100%;">
                    <tr><td class="td-center td-gradient">Einheit</td><td class="td-center td-gradient" style="width: 80px;">Anzahl</td></tr>';

foreach ($soldiers as $s) {
    $name = $s->get_soldier_name();
    $cat = $s->get_soldier_category();

    $owned_count = $owned_troops[$name] ?? 0;

    $view .= "<tr>
                <td style='padding: 0; max-width: 0;'>
                    <div style='display: flex; align-items: center; padding: 5px 10px; min-height: 38px; width: 100%;'>
                        <div style='flex: 0 0 28px;'>" . $s->get_soldier_icon("ressource-icons") . "</div>
                        <div style='cursor: pointer; display: flex; align-items: center; justify-content: space-between; flex: 1; min-width: 0; overflow: hidden; margin-right: 10px;' 
                             title='Klicken zum Befüllen: $name'
                             data-on-click='fillSimMax' 
                             data-target='{$name}_own' 
                             data-value='$owned_count'>
                            <div class='popup' id='pop_own_$name' style='min-width: 0; flex: 1; overflow: hidden;'>
                                <span style='white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;'>$name</span>
                                <div id='pop_own_{$name}_box' class='popupbox'>$name</div>
                            </div>
                            <small style='opacity: 0.7; flex-shrink: 0;'>(" . fnum($owned_count) . ")</small>
                        </div>

                        <div style='flex: 0 0 50px; display: flex; justify-content: flex-end;'>
                            <div style='display: flex; align-items: center; gap: 2px; width: 55px;'>
                                <img src='images/icons/icon_sword.png' style='width: 16px; height: 16px;' alt=''>
                                <span>" . $s->get_soldier_attack() . "</span>
                            </div>
                            <div style='display: flex; align-items: center; gap: 2px; width: 55px;'>
                                <img src='images/icons/icon_shield.png' style='width: 16px; height: 16px;' alt=''>
                                <span>" . $s->get_soldier_defense() . "</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td class='td-center'>
                    <input type='text' id='{$name}_own' size='4' maxlength='5' inputmode='numeric' pattern='[0-9]*' placeholder='0' data-on-input='updateLivePower' style='width: 100%;'>
                </td>
                <div id='{$name}_atk' data-attack='{$s->get_soldier_attack()}' data-category='$cat' style='display:none;'></div>
                <div id='{$name}_def' data-defense='{$s->get_soldier_defense()}' style='display:none;'></div>
              </tr>";
}
$view .= '</table></div>';
$view .= '<div class="box-container" style="flex: 1; min-width: 320px; margin: 0;">
                <div class="tab" style="margin-bottom: 0; border-bottom: none; padding: 0;">
                    <div class=' . "'tablinks active' data-on-click='switchSimTab' data-tab='players' style='padding: 7px;'>Spieler</div>
                    <div class='tablinks' data-on-click='switchSimTab' data-tab='monsters' style='padding: 7px;'>Monster</div>
                </div>";

// TAB 1: Normal Enemy
$view .= "<div id='sim-players' class='sim-tab-content' style='background: var(--box-content-color);'>";
$view .= '<table class="table warsim-table" style="width: 100%;">
                    <tr><td class="td-center td-gradient">Einheit</td><td class="td-center td-gradient" style="width: 80px;">Anzahl</td></tr>';
foreach ($soldiers as $s) {
    $name = $s->get_soldier_name();

    $view .= "<tr>
                <td style='padding: 0; width: auto; max-width: 0;'>
                    <div style='display: flex; justify-content: space-between; align-items: center; padding: 5px 10px; min-height: 38px;'>
                        <div style='display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;'>
                            " . $s->get_soldier_icon("ressource-icons") . "
                            <span style='white-space: nowrap; overflow: hidden; text-overflow: ellipsis;'>$name</span>
                        </div>
                        <div class='warsim-stats-wrap' style='display: flex; gap: 12px; flex: 0 0 125px; justify-content: flex-end; margin-left: 10px;'>
                            <div style='display: flex; align-items: center; gap: 4px; width: 55px; flex-shrink: 0;'>
                                <img src='images/icons/icon_sword.png' style='width: 16px; height: 16px;' alt=''>
                                <span>" . $s->get_soldier_attack() . "</span>
                            </div>
                            <div style='display: flex; align-items: center; gap: 4px; width: 55px; flex-shrink: 0;'>
                                <img src='images/icons/icon_shield.png' style='width: 16px; height: 16px;' alt=''>
                                <span>" . $s->get_soldier_defense() . "</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td class='td-center' style='width: 80px;'>
                    <input type='text' id='{$name}_enemy' maxlength='5' inputmode='numeric' pattern='[0-9]*' placeholder='0' 
                            data-on-input='updateLivePower' style='width: 100%;'>
                </td>
              </tr>";
}
$view .= '</table></div>';

// TAB 2: Monster
$view .= "<div id='sim-monsters' class='sim-tab-content' style='display:none; background: var(--box-content-color);'>";
$view .= '<table class="table warsim-table" style="width: 100%;">
                    <tr><td class="td-center td-gradient">Monster</td><td class="td-center td-gradient" style="width: 80px;">Anzahl</td></tr>';
foreach ($monsters as $m) {
    $m_name = e($m["monster_name"]);

    $view .= "<tr>
                <td style='padding: 0; width: auto; max-width: 0;'>
                    <div style='display:flex; justify-content:space-between; align-items:center; padding: 5px 10px; min-height: 38px;'>
                        <div style='display:flex; align-items:center; gap: 8px; min-width: 0; flex: 1;'>
                            <img src='images/icons/{$m["icon"]}.png' class='ressource-icons' alt=''>
                            <div class='popup' id='pop_mon_{$m["id"]}' style='min-width: 0; flex: 1; overflow: hidden;'>
                                <span style='white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;'>$m_name</span>
                                <div id='pop_mon_{$m["id"]}_box' class='popupbox'>$m_name</div>
                            </div>
                        </div>
                        <div class='warsim-stats-wrap' style='display: flex; gap: 12px; flex: 0 0 125px; justify-content: flex-end; margin-left: 10px;'>
                            <div style='display: flex; align-items: center; gap: 4px; width: 55px; flex-shrink: 0;'>
                                <img src='images/icons/icon_sword.png' style='width: 16px; height: 16px;' alt=''>
                                <span>{$m["attack"]}</span>
                            </div>
                            <div style='display: flex; align-items: center; gap: 4px; width: 55px; flex-shrink: 0;'>
                                <img src='images/icons/icon_shield.png' style='width: 16px; height: 16px;' alt=''>
                                <span>{$m["defense"]}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td class='td-center' style='width: 80px;'>
                    <input type='text' id='m_{$m["id"]}_count' class='js-mon-input' maxlength='5' inputmode='numeric' pattern='[0-9]*' placeholder='0' 
                           data-atk='{$m["attack"]}' data-def='{$m["defense"]}' data-on-input='updateLivePower' style='width: 100%;'>
                </td>
              </tr>";
}
$view .= '</table></div>';

$view .= '</div>';
$view .= '</div>';

// --- BUTTONS & DATA ---
$shrine_atk_mult = 1.0;
if ($kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_WAR) {
    $shrine_atk_mult += $kingdom->get_shrine_modifier();
}

$view .= '<div id="warsim-data" 
                data-soldiers="' . e(json_encode(array_map(fn($s) => $s->get_soldier_name(), $soldiers))) . '"
                data-shrine-atk-mult="' . $shrine_atk_mult . '"></div> 
          <div id="warsim-const" 
                data-inf_atk="' . SMITHY_INF_ATK_BONUS . '" data-inf_def="' . SMITHY_INF_DEF_BONUS . '"
                data-cav_atk="' . SMITHY_CAV_ATK_BONUS . '" data-cav_def="' . SMITHY_CAV_DEF_BONUS . '"
                data-arc_atk="' . SMITHY_ARC_ATK_BONUS . '" data-arc_def="' . SMITHY_ARC_DEF_BONUS . '"
                data-wall_default_hp="' . DEFAULT_WALL_HP . '"
                data-wall_hp_inc="' . RESEARCH_WALL_HP_INC . '"
                data-wall_min_def="' . MIN_WALL_DEFENSE . '"
                data-wall_max_def="' . MAX_WALL_DEFENSE . '"
                data-wall_factor="' . WALL_DEFENSE_FACTOR . '"
                data-wall_absorption_per_lvl="' . WALL_ABSORPTION_PER_LEVEL . '"
                data-wall_eff_dmg_factor="' . WALL_EFFECTIVE_DMG_FACTOR . '"
                data-wall_acc_dmg_factor="' . WALL_ACCUMULATED_DMG_FACTOR . '"
                data-siege_bonus="' . SMITHY_SIEGE_BONUS . '"
                data-ram_factor="' . RAM_WALL_DAMAGE_FACTOR . '" 
                data-ram_limit="' . RAM_WALL_DAMAGE_LIMIT . '"
                data-ram_flat="' . RAM_FLAT_DAMAGE . '"
                data-shrine_base="' . $war_base_bonus . '" 
                data-shrine_step="' . SHRINE_TECH_STEP . '" 
                data-max_lvl="' . MAX_BUILDING_LEVEL . '"
                data-rps_bonus="' . RPS_BONUS . '"
                data-lethality_pvp="' . LETHALITY_PVP . '"
                data-lethality_pve="' . LETHALITY_PVE . '"
                data-monster_dmg_clamped_max_val="' . MONSTER_DMG_CLAMPED_MAX_VAL . '"
                data-monster_dmg_loss_exponent="' . MONSTER_DMG_LOSS_EXPONENT . '">
            </div>';

$monster_import = $_GET["import_monsters"] ?? "";

$view .= '<div id="monster-import-data" data-import="' . e($monster_import) . '"></div>';

/*
 * HTML Section
 */
$title = "War Simulator";
$header = "War Simulator";
$script_files = ["warsim"];

include("layout/base.php");
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
    TechTypes::TECH_TYPE_SIEGE
];
$mil_tech_ids_defender = [
    TechTypes::TECH_TYPE_BLADES,
    TechTypes::TECH_TYPE_SHIELDWALL,
    TechTypes::TECH_TYPE_LANCE_RIDING,
    TechTypes::TECH_TYPE_CUIRASS,
    TechTypes::TECH_TYPE_ARROWHEADS,
    TechTypes::TECH_TYPE_DOUBLET,
    TechTypes::TECH_TYPE_WALL_HP_INC
];

$tech_meta = [];
$res_meta = $db_instance->execute_query("SELECT id, techname, maxlevel FROM tech_list WHERE id IN (?, ?, ?, ?, ?, ?, ?, ?)", [
    TechTypes::TECH_TYPE_BLADES,
    TechTypes::TECH_TYPE_SHIELDWALL,
    TechTypes::TECH_TYPE_LANCE_RIDING,
    TechTypes::TECH_TYPE_CUIRASS,
    TechTypes::TECH_TYPE_ARROWHEADS,
    TechTypes::TECH_TYPE_DOUBLET,
    TechTypes::TECH_TYPE_WALL_HP_INC,
    TechTypes::TECH_TYPE_SIEGE
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
                   class='js-tech-input' value='$val' min='0' max='$max' style='width: 45px;'>
        </div>";
    }
    return $html;
};

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

$view = "Hier kannst du das Ergebnis eines Kampfes berechnen.<br><br>";
$view .= '<div style="display: flex; gap: 30px; justify-content: center; flex-wrap: wrap; margin-bottom: 20px;">';
$view .= '<div class="box-container" style="max-width: 250px; margin: 0;">
    <div class="box-header">Deine Forschung</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        ' . $render_tech_side("my", $tech_meta, $mil_tech_ids_attacker, $kingdom) . '
    </div>
</div>';
$view .= '<div class="box-container" style="max-width: 250px; margin: 0;">
    <div class="box-header">Gegnerische Boni</div>
    <div class="box-content box-content-bg" style="padding: 10px;">
        ' . $render_tech_side("en", $tech_meta, $mil_tech_ids_defender) . '
        <div style="margin-top: 10px; padding-top: 10px; border-top: 2px solid var(--border-gold);">
            <b>Mauer-Zustand</b>
            <div class="split-content" style="margin-top: 5px;">
                <span>Stufe:</span>
                <input type="number" id="en_wall_lvl" value="1" min="1" max="' . MAX_BUILDING_LEVEL . '" style="width: 60px;">
            </div>
            <div style="text-align: left; font-size: 12px; opacity: 0.8; margin-top: 5px;">
                HP: <span id="wall_hp_display">0</span> / <span id="wall_hp_display_max">0</span>
            </div>
            <div style="text-align: right; font-weight: bold; color: var(--link-color); margin-top: 5px;">
                Bonus: +<span id="wall_def_display">0</span> DEF
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
$view .= '<table class="table warsim-table">
    <colgroup>
        <col>
        <col style="width: 75px;">
        <col style="width: 75px;">
    </colgroup>
    <tr>
        <td class="td-center td-gradient">Einheit</td>
        <td class="td-center td-gradient">Spieler</td>
        <td class="td-center td-gradient">Gegner</td>
    </tr>';

for ($i = 0; $i < count($soldiers); $i++) {
    $soldier_name = $soldiers[$i]->get_soldier_name();
    $category = $soldiers[$i]->get_soldier_category();

    $view .= "<tr>
                <td style='padding: 0;'>
                    <div style='display: flex; justify-content: space-between; align-items: center; padding: 5px 10px; min-height: 38px;'>
                        <div style='display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0;'>
                            " . $soldiers[$i]->get_soldier_icon("ressource-icons") . "
                            " . $soldier_name . "
                        </div>
                        <div class='warsim-stats-wrap' style='display: flex; gap: 5px; width: 120px; justify-content: flex-end;'>
                            <div id='" . $soldier_name . "_atk' 
                                 data-attack='" . $soldiers[$i]->get_soldier_attack() . "' 
                                 data-category='" . $category . "' 
                                 style='display: flex; align-items: center; gap: 4px; width: 55px;'>
                                <img src='images/icons/icon_sword.png' style='width: 16px; height: 16px;' alt=''>
                                <span>" . $soldiers[$i]->get_soldier_attack() . "</span>
                            </div>
                            <div id='" . $soldier_name . "_def' 
                                 data-defense='" . $soldiers[$i]->get_soldier_defense() . "' 
                                 style='display: flex; align-items: center; gap: 4px; width: 55px;'>
                                <img src='images/icons/icon_shield.png' style='width: 16px; height: 16px;' alt=''>
                                <span>" . $soldiers[$i]->get_soldier_defense() . "</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td class='td-center'>
                    <input type='text' id='" . $soldier_name . "_own' name='" . $soldier_name . "_own' size='4' maxlength='5' placeholder='0' data-on-input='updateLivePower'>
                </td>
                <td class='td-center'>
                    <input type='text' id='" . $soldier_name . "_enemy' name='" . $soldier_name . "_enemy' size='4' maxlength='5' placeholder='0' data-on-input='updateLivePower'>
                </td>
              </tr>";
}

$view .= '</table>
    <div id="warsim-data" data-soldiers="' . e(json_encode(array_map(function ($soldier) {
        return $soldier->get_soldier_name();
    }, $soldiers))) . '">
    </div> 
    <button type="button" style="margin-top: 10px;" data-on-click="calculateWarOutcome">Berechnen</button>
    <button type="button" data-on-click="resetFields">Reset</button>';

$view .= '<div id="warsim-const" 
                data-inf_atk="' . SMITHY_INF_ATK_BONUS . '" data-inf_def="' . SMITHY_INF_DEF_BONUS . '"
                data-cav_atk="' . SMITHY_CAV_ATK_BONUS . '" data-cav_def="' . SMITHY_CAV_DEF_BONUS . '"
                data-arc_atk="' . SMITHY_ARC_ATK_BONUS . '" data-arc_def="' . SMITHY_ARC_DEF_BONUS . '"
                data-wall_default_hp="' . DEFAULT_WALL_HP . '"
                data-wall_hp_inc="' . RESEARCH_WALL_HP_INC . '"
                data-wall_min_def="' . MIN_WALL_DEFENSE . '"
                data-wall_max_def="' . MAX_WALL_DEFENSE . '"
                data-wall_factor="' . WALL_DEFENSE_FACTOR . '"
                data-siege_bonus="' . SMITHY_SIEGE_BONUS . '"
                data-max_lvl="' . MAX_BUILDING_LEVEL . '">
            </div>';

/*
 * HTML Section
 */
$title = "War Simulator";
$header = "War Simulator";
$script_files = ["warsim"];

include("layout/base.php");
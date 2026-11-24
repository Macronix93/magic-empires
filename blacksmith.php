<?php
require_once("includes/core.php");

[
    "current_kingdom" => $current_kingdom,
    "building" => $building,
    "building_name" => $building_name,
    "kingdom" => $kingdom
] = $user->check_user_login_and_kingdom(BuildingTypes::BUILDING_SMITHY);

// Fetch all buildings and their dependencies
$all_techs_for_check = $kingdom->fetch_all_kingdom_techs();
$techs = array_filter(
    $all_techs_for_check,
    fn($t) => $t->get_origin_building() === BuildingTypes::BUILDING_SMITHY
);
$buildings = $kingdom->fetch_all_kingdom_buildings();

$kingdom_is_researching = $kingdom->is_kingdom_smithing($current_kingdom);
$kingdom_research_id = $kingdom_is_researching ? $kingdom->get_kingdom_research_id() : -1;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf_token()) {
        change_location("blacksmith.php", error: "Ungültiger Sicherheits-Token!");
    }

    $tech_id = post_int("tid", -1);
    $action = post_str("action");
    if (!isset($techs[$tech_id])) {
        change_location("blacksmith.php", error: "Diese Forschung existiert nicht!");
    }

    $selected_tech = $techs[$tech_id];
    $tech_level = $selected_tech->get_tech_level();
    $costs = $selected_tech->calculate_tech_cost();

    if ($action === "research") {
        if ($selected_tech->is_max_level()) {
            change_location("blacksmith.php", error: "Die Forschung ist schon maximal erforscht!");
        }

        $target_level = $tech_level + 1;
        $current_smithy_lvl = $building->get_building_level();
        $required_smithy_lvl = Tech::get_required_smithy_level($tech_id, $target_level);
        if ($current_smithy_lvl < $required_smithy_lvl) {
            change_location("blacksmith.php", error: "Für Stufe $target_level benötigst du Schmiede Stufe $required_smithy_lvl!");
        }

        $dep_err = $selected_tech->check_dependencies($buildings, $all_techs_for_check);
        if ($dep_err !== "") {
            change_location("blacksmith.php", error: $dep_err);
        }

        $err = $selected_tech->start_research($kingdom, $user->get_user_id(), ActionTypes::ACTION_SMITHY_UPGRADE, $kingdom->get_smithy_research_multiplier());
        if ($err !== null) {
            change_location("blacksmith.php", error: $err);
        }

        change_location("blacksmith.php");
    }

    if ($action === "cancel") {
        $db_instance->begin_transaction();
        try {
            $db_instance->execute_query("SELECT id FROM kingdoms WHERE id = ? FOR UPDATE", [$current_kingdom]);
            $db_instance->execute_query(
                "DELETE FROM events WHERE userid = ? AND buildingid = ? AND kingdomid = ? AND actionid = ? AND is_processing = 0",
                [$user->get_user_id(), $tech_id, $current_kingdom, ActionTypes::ACTION_SMITHY_UPGRADE]
            );

            if ($db_instance->affected_rows !== 1) {
                throw new RuntimeException("Du forschst gerade nichts oder die Forschung ist bereits abgeschlossen!");
            }

            $kingdom->refund_costs($costs);
            $db_instance->commit();

            change_location("blacksmith.php");
        } catch (RuntimeException $e) {
            $db_instance->rollback();

            change_location("blacksmith.php", error: $e->getMessage());
        } catch (Throwable $e) {
            $db_instance->rollback();
            $logger->error("blacksmith cancel error: " . $e->getMessage());

            change_location("blacksmith.php", error: "Interner Fehler beim Abbrechen der Forschung.");
        }
    }

    change_location("blacksmith.php", error: "Diese Aktion existiert nicht!");
}

$flash_box = $user->consume_last_researched_flash($current_kingdom, BuildingTypes::BUILDING_SMITHY);

/*
 * HTML Content Part
 */
$smithy_boni_view = "";

$blades_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_BLADES);
$shield_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SHIELDWALL);
if ($blades_lvl > 0 || $shield_lvl > 0) {
    $atk = $blades_lvl * SMITHY_INF_ATK_BONUS;
    $def = $shield_lvl * SMITHY_INF_DEF_BONUS;
    $smithy_boni_view .= "<tr><td>Infanterie:</td><td class='passed'>+$atk Angriff / +$def Verteidigung</td></tr>";
}

$lance_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_LANCE_RIDING);
$harn_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_CUIRASS);
if ($lance_lvl > 0 || $harn_lvl > 0) {
    $atk = $lance_lvl * SMITHY_CAV_ATK_BONUS;
    $def = $harn_lvl * SMITHY_CAV_DEF_BONUS;
    $smithy_boni_view .= "<tr><td>Kavallerie:</td><td class='passed'>+$atk Angriff / +$def Verteidigung</td></tr>";
}

$arrow_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_ARROWHEADS);
$wams_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_DOUBLET);
if ($arrow_lvl > 0 || $wams_lvl > 0) {
    $atk = $arrow_lvl * SMITHY_ARC_ATK_BONUS;
    $def = $wams_lvl * SMITHY_ARC_DEF_BONUS;
    $smithy_boni_view .= "<tr><td>Fernkampf:</td><td class='passed'>+$atk Angriff / +$def Verteidigung</td></tr>";
}

$weight_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_WEIGHT);
if ($weight_lvl > 0) {
    $percent = fdec($weight_lvl * (SMITHY_WEIGHT_REDUCTION * 100));
    $smithy_boni_view .= "<tr><td>Waffengewicht:</td><td class='passed'>-$percent% Rekrutierungszeit</td></tr>";
}

$siege_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_SIEGE);
if ($siege_lvl > 0) {
    $percent = fdec($siege_lvl * (SMITHY_SIEGE_BONUS * 100));
    $smithy_boni_view .= "<tr><td>Belagerung:</td><td class='passed'>+$percent% Schaden an Mauern</td></tr>";
}

$metallurgy_lvl = $kingdom->get_kingdom_tech_level(TechTypes::TECH_TYPE_METALLURGY);
if ($metallurgy_lvl > 0) {
    $percent = fdec($metallurgy_lvl * (METALLURGY_RESEARCH_REDUCTION * 100));
    $smithy_boni_view .= "<tr><td>Metallurgie:</td><td class='passed'>-$percent% Forschungszeit</td></tr>";
}

if (!empty($smithy_boni_view)) {
    $view .= "<div class='title-border'>Aktive Schmiede-Boni</div>";
    $view .= "<table class='table' style='max-width: 500px; margin-bottom: 20px;'>$smithy_boni_view</table>";
}

$view .= '<table class="table building-table">
            <colgroup>
                <col class="col-description">
                <col class="col-action">
            </colgroup>
            <tr>
                <td class="td-center td-gradient">
                    <b>Verbesserung</b></td>
                <td class="td-center td-gradient">
                    <b>Aktion</b></td>
            </tr>';

foreach ($techs as $i => $tech) {
    $level = (int)$tech->get_tech_level();
    $max_level = (int)$tech->get_tech_max_level();
    $is_maxed = ($level >= $max_level);

    if (!$is_maxed && !$tech->meets_dependencies($buildings, $all_techs_for_check)) {
        continue;
    }

    $text_build = "";
    $res_html = "";

    if ($is_maxed) {
        $text_build = "<b class='passed'>MAX</b>";
    } else {
        $costs = $tech->calculate_tech_cost();
        $res_html = $kingdom->render_cost_legend($costs);

        if ($kingdom_is_researching) {
            if ($kingdom_research_id == $i) {
                $result_ev = $db_instance->execute_query("SELECT buildingtime FROM events WHERE kingdomid = ? AND buildingid = ? AND actionid = ?",
                    [$current_kingdom, $i, ActionTypes::ACTION_SMITHY_UPGRADE]);
                $row_ev = $result_ev->fetch_assoc();

                $difference_time = $row_ev["buildingtime"] - time();

                $text_build = "Forschungszeit:<br><span class='js-countdown' 
                                       data-seconds='$difference_time' 
                                       data-hide-id='cancel-form'>" . format_time_for_js($difference_time) . "</span><br>
                              <form id='cancel-form' action='blacksmith.php' method='POST'>
                                <input type='hidden' name='action' value='cancel'>
                                <input type='hidden' name='tid' value='" . $i . "'>
                                <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                              </form>";
            } else {
                $text_build = "-";
            }
        } else {
            $target_level = $level + 1;
            $current_smithy_lvl = $building->get_building_level();
            $required_smithy_lvl = Tech::get_required_smithy_level($i, $target_level);
            $is_smithy_locked = ($current_smithy_lvl < $required_smithy_lvl);

            if ($is_smithy_locked) {
                $text_build = "<span class='error item-needed'>Schmiede Stufe $required_smithy_lvl benötigt</span>";
            } else {
                $disabled = $kingdom->can_afford_costs($costs) ? "" : "disabled";

                $text_build = "<form action='blacksmith.php' method='POST'>
                                    <input type='hidden' name='action' value='research'>
                                    <input type='hidden' name='tid' value='" . $i . "'>
                                    <input type='submit' value='" . ($level > 0 ? "Upgrade" : "Forschen") . "' $disabled>
                                </form>";
            }
        }
    }

    $view .= "<tr>
        <td>
            <div class='map-legend' style='justify-content: left;'>
            <div class='legend-item'>" . $tech->get_tech_icon() . "</div>
                <div class='legend-item'>
                    <b class='popup' id='description" . $i . "'>" . $tech->get_tech_name() . " 
                        <div id='description" . $i . "_box' class='popupbox'>" . $tech->get_tech_description() . "</div> ($level)
                    </b>
                </div>
            </div>";

    if (!$is_maxed) {
        $duration = $tech->calculate_research_seconds($level, $kingdom->get_smithy_research_multiplier());

        $view .= "
            <div class='map-legend' style='justify-content: left; margin-top: 10px; gap: 5px;'>
                $res_html
            </div>
            " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME) . " 
            " . convert_sec_to_str($duration);
    }

    $view .= "
        </td>
        <td class='td-center'>" . $text_build . "</td>
    </tr>";
}
$view .= "</table>";

/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = ["timer"];

include("layout/base.php");
<?php
require_once("includes/core.php");

$result = $user->check_user_login_and_kingdom(BuildingTypes::BUILDING_SMITHY);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];
$kingdom_wood = $result["k_wood"];
$kingdom_food = $result["k_food"];
$kingdom_stone = $result["k_stone"];
$kingdom_gold = $result["k_gold"];

// Fetch all buildings and their dependencies
$techs = $kingdom->fetch_all_kingdom_techs(BuildingTypes::BUILDING_SMITHY);
$buildings = $kingdom->fetch_all_kingdom_buildings();
$all_techs_for_check = $kingdom->fetch_all_kingdom_techs();

$kingdom_is_researching = $kingdom->is_kingdom_smithing($current_kingdom);
$kingdom_research_id = $kingdom_is_researching ? $kingdom->get_kingdom_research_id() : -1;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    if (!verify_csrf_token()) {
        $error = "Ungültiger Sicherheits-Token!";
    } else {
        $tech_id = (int)($_POST["tid"] ?? 0);
        $action = $_POST["action"];

        if (isset($techs[$tech_id])) {
            $selected_tech = $techs[$tech_id];
            $tech_level = $selected_tech->get_tech_level();
            $costs = $selected_tech->calculate_tech_cost();

            if ($action == "research") {
                if ($selected_tech->is_max_level()) {
                    $error = "Die Forschung ist schon maximal erforscht!";
                } else if ($kingdom_is_researching) {
                    $error = "Du forschst bereits!";
                } else {
                    $target_level = $tech_level + 1;
                    $current_smithy_lvl = $building->get_building_level();
                    $required_smithy_lvl = Tech::get_required_smithy_level($tech_id, $target_level);

                    if ($current_smithy_lvl < $required_smithy_lvl) {
                        $error = "Für Stufe $target_level benötigst du Schmiede Stufe $required_smithy_lvl!";
                    } else {
                        $error .= $techs[$tech_id]->check_dependencies($buildings, $all_techs_for_check);
                    }

                    // Dependency check passed - research/upgrade tech!
                    if (empty($error)) {
                        if (!$kingdom->can_afford_costs($costs)) {
                            $error = "Nicht genügend Ressourcen!";
                        } else {
                            $duration = $techs[$tech_id]->calculate_research_seconds($tech_level, $kingdom->get_smithy_research_multiplier());
                            $tech_time = time() + $duration;

                            // Subtract research costs from kingdom resources
                            $kingdom->pay_costs($costs);

                            $db_instance->execute_query("INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname) VALUES (?, ?, ?, ?, ?, ?, ?)",
                                [ActionTypes::ACTION_SMITHY_UPGRADE, $user->get_user_id(), $current_kingdom, $tech_id, $tech_time, $tech_level, $techs[$tech_id]->get_tech_name()]);

                            $kingdom_is_researching = true;
                            $kingdom_research_id = $tech_id;

                            change_location("blacksmith.php");
                        }
                    }
                }
            } else if ($action == "cancel") {
                if ($kingdom_is_researching && $kingdom_research_id == $tech_id) {
                    $db_instance->execute_query(
                        "DELETE FROM events WHERE userid = ? AND buildingid = ? AND kingdomid = ? AND actionid = ?",
                        [$user->get_user_id(), $tech_id, $user->get_current_kingdom(), ActionTypes::ACTION_SMITHY_UPGRADE]
                    );

                    $kingdom_is_researching = false;
                    $kingdom_research_id = -1;

                    // Refund the player
                    $kingdom->refund_costs($costs);

                    change_location("blacksmith.php");
                } else {
                    $error = "Du forschst gerade nichts!";
                }
            }
        }
    }
}

$flash_box = $user->consume_last_researched_flash($current_kingdom, BuildingTypes::BUILDING_SMITHY);

/*
 * HTML Content Part
 */
$kingdom_wood = $kingdom->get_kingdom_wood();
$kingdom_food = $kingdom->get_kingdom_food();
$kingdom_stone = $kingdom->get_kingdom_stone();
$kingdom_gold = $kingdom->get_kingdom_gold();

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

    if (!$is_maxed) {
        $show_tech = true;
        $tech_dependencies = $tech->get_tech_dependencies();

        foreach ($tech_dependencies as $dependency) {
            if (!empty($dependency["dependencyid"]) && $dependency["dependencyid"] > 0) {
                if ($dependency["dependencylevel"] > $buildings[$dependency["dependencyid"]]->get_building_level()) {
                    $show_tech = false;
                    break;
                }
            }
            if (!empty($dependency["techdepid"]) && $dependency["techdepid"] > 0) {
                if ($dependency["techdeplevel"] > $all_techs_for_check[$dependency["techdepid"]]->get_tech_level()) {
                    $show_tech = false;
                    break;
                }
            }
        }

        if (!$show_tech) continue;
    }

    $text_build = "";
    $res_html = "";

    if ($is_maxed) {
        $text_build = "<b class='passed'>MAX</b>";
    } else {
        $costs = $tech->calculate_tech_cost();
        $res_html = render_cost_legend($costs, $kingdom);

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
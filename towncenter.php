<?php
require_once("includes/core.php");

[
    "current_kingdom" => $current_kingdom,
    "building" => $building,
    "building_name" => $building_name,
    "kingdom" => $kingdom
] = $user->check_user_login_and_kingdom(BuildingTypes::BUILDING_TOWNCENTER);

// Fetch all buildings and their dependencies
$buildings = $kingdom->fetch_all_kingdom_buildings();
$tc_level = $buildings[BuildingTypes::BUILDING_TOWNCENTER]->get_building_level();

$kingdom_is_building = $kingdom->is_kingdom_building($current_kingdom);
$kingdom_building_id = $kingdom_is_building ? $kingdom->get_kingdom_building_id() : -1;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf_token()) {
        change_location("towncenter.php", error: "Ungültiger Sicherheits-Token!");
    }

    $action = post_str("action");
    $build_id = post_int("bid", -1);
    if (!isset($buildings[$build_id])) {
        change_location("towncenter.php", error: "Dieses Gebäude existiert nicht!");
    }

    $building_level = $buildings[$build_id]->get_building_level();
    $costs = $buildings[$build_id]->calculate_building_cost();

    if ($action === "build") {
        $max_allowed_for_this_building = Building::get_max_building_level($build_id);
        if ($building_level >= $max_allowed_for_this_building) {
            change_location("towncenter.php", error: "Das Gebäude ist schon maximal ausgebaut!");
        }

        if ($build_id == BuildingTypes::BUILDING_EMBASSY) {
            if ($current_kingdom != $user->get_main_kingdom()) {
                change_location("towncenter.php", error: "Dieses Gebäude kannst du nur auf deinem Hauptkönigreich ausbauen!");
            }

            if ($building_level >= 1) {
                change_location("towncenter.php", error: "Die Botschaft kann maximal auf Stufe 1 ausgebaut werden!");
            }
        }

        $target_level = $building_level + 1;
        $max_allowed_level = ($build_id == BuildingTypes::BUILDING_STORAGE) ? ($tc_level + 1) : $tc_level;
        if ($build_id != BuildingTypes::BUILDING_TOWNCENTER && $target_level > $max_allowed_level) {
            $needed_tc = ($build_id == BuildingTypes::BUILDING_STORAGE) ? $target_level - 1 : $target_level;

            change_location("towncenter.php", error: "Dein Dorfzentrum ist zu niedrig! (Dorfzentrum Stufe $needed_tc benötigt)");
        }

        $dep_errors = [];
        foreach ($buildings[$build_id]->get_building_dependencies() as $dependency) {
            $dep_id = $dependency["dependencyid"];

            if (isset($buildings[$dep_id]) && $dependency["dependencylevel"] > $buildings[$dep_id]->get_building_level()) {
                $dep_errors[] = $buildings[$build_id]->get_building_name() . " setzt " . $buildings[$dep_id]->get_building_name() . " Stufe " . $dependency["dependencylevel"] . " voraus!";
            }
        }

        if (!empty($dep_errors)) {
            change_location("towncenter.php", error: implode("<br>", $dep_errors));
        }

        $db_instance->begin_transaction();
        try {
            $db_instance->execute_query("SELECT id FROM kingdoms WHERE id = ? FOR UPDATE", [$current_kingdom]);
            $already_building = (int)$db_instance->execute_query(
                "SELECT COUNT(*) FROM events WHERE kingdomid = ? AND actionid = ?",
                [$current_kingdom, ActionTypes::ACTION_BUILD_BUILDING]
            )->fetch_column();
            if ($already_building > 0) {
                throw new RuntimeException("Du baust bereits!");
            }

            $fresh_lvl = (int)($db_instance->execute_query(
                "SELECT buildinglevel FROM buildings WHERE kingdomid = ? AND buildingid = ?",
                [$current_kingdom, $build_id]
            )->fetch_column() ?? 0);
            if ($fresh_lvl !== $building_level) {
                throw new RuntimeException("Die Gebäudestufe hat sich gerade geändert. Bitte versuche es erneut.");
            }

            if (!$kingdom->pay_costs($costs)) {
                throw new RuntimeException("Nicht genügend Ressourcen!");
            }

            $reduction = $kingdom->get_construction_time_multiplier();
            $base_time = round($buildings[$build_id]->get_building_time() * pow($buildings[$build_id]->get_building_mult(), $building_level));
            $building_time = time() + (int)round($base_time * $reduction);

            $db_instance->execute_query(
                "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildingtime, buildinglevel, buildingname) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [ActionTypes::ACTION_BUILD_BUILDING, $user->get_user_id(), $current_kingdom, $build_id, $building_time, $building_level, $buildings[$build_id]->get_building_name()]
            );
            $db_instance->commit();

            change_location("towncenter.php");
        } catch (RuntimeException $e) {
            $db_instance->rollback();

            change_location("towncenter.php", error: $e->getMessage());
        } catch (Throwable $e) {
            $db_instance->rollback();
            $logger->error("towncenter build error: " . $e->getMessage());

            change_location("towncenter.php", error: "Interner Fehler beim Starten des Baus.");
        }
    }

    if ($action === "cancel") {
        $db_instance->begin_transaction();
        try {
            $db_instance->execute_query("SELECT id FROM kingdoms WHERE id = ? FOR UPDATE", [$current_kingdom]);
            $db_instance->execute_query(
                "DELETE FROM events WHERE userid = ? AND buildingid = ? AND kingdomid = ? AND actionid = ? AND is_processing = 0",
                [$user->get_user_id(), $build_id, $current_kingdom, ActionTypes::ACTION_BUILD_BUILDING]
            );
            if ($db_instance->affected_rows !== 1) {
                throw new RuntimeException("Du baust gerade nichts oder der Bau ist bereits abgeschlossen!");
            }

            $kingdom->refund_costs($costs);

            $db_instance->commit();
            $logger->log_game("ECONOMY", "BUILDING_CANCEL", ["building_id" => $build_id, "refund" => $costs], $current_kingdom);

            change_location("towncenter.php");
        } catch (RuntimeException $e) {
            $db_instance->rollback();

            change_location("towncenter.php", error: $e->getMessage());
        } catch (Throwable $e) {
            $db_instance->rollback();
            $logger->error("towncenter cancel error: " . $e->getMessage());

            change_location("towncenter.php", error: "Interner Fehler beim Abbrechen des Baus.");
        }
    }

    change_location("towncenter.php", error: "Diese Aktion existiert nicht!");
}

$last_built_building = $user->get_last_built_building($current_kingdom);

if (!empty($last_built_building)) {
    $built_building_name = $last_built_building["buildingname"];
    $built_building_level = $last_built_building["buildinglevel"];

    $flash_box = show_weighted_box("$built_building_name (" . $built_building_level . " → " . ($built_building_level + 1) . ")", "Bau abgeschlossen:");

    $user->clear_last_built_building($current_kingdom);
}

/*
 * HTML Content Part
 */
$view .= '<table class="table building-table">
            <colgroup>
                <col class="col-description">
                <col class="col-action">
            </colgroup>
                <tr>
                    <td class="td-center td-gradient">
                        <b>Gebäude</b></td>
                    <td class="td-center td-gradient">
                        <b>Aktion</b></td>
                </tr>';

foreach ($buildings as $i => $building_obj) {
    $building_id = $building_obj->get_building_id();

    if ($building_id == BuildingTypes::BUILDING_EMBASSY && $current_kingdom != $user->get_main_kingdom()) {
        continue;
    }

    $level = (int)$building_obj->get_building_level();
    $max_allowed_for_this_building = Building::get_max_building_level($building_id);
    $is_maxed = ($level >= $max_allowed_for_this_building);

    $show_building = true;
    $building_dependencies = $building_obj->get_building_dependencies();

    foreach ($building_dependencies as $dependency) {
        $dep_id = (int)$dependency["dependencyid"];
        $dep_lvl = (int)$dependency["dependencylevel"];

        if (isset($buildings[$dep_id]) && $dep_lvl > $buildings[$dep_id]->get_building_level()) {
            $show_building = false;
            break;
        }
    }

    if (!$show_building) {
        continue;
    }

    $text_build = "";
    $resource_costs = "";
    $time_text = "";

    if ($is_maxed) {
        $text_build = "<b class='passed'>MAX</b>";
    } else {
        $costs = $building_obj->calculate_building_cost();
        $resource_costs = $kingdom->render_cost_legend($costs);

        if ($kingdom_is_building) {
            if ($kingdom_building_id == $i) {
                $result = $db_instance->execute_query("SELECT buildingtime FROM events WHERE kingdomid = ? AND buildingid = ? AND actionid = ?",
                    [$current_kingdom, $i, ActionTypes::ACTION_BUILD_BUILDING]);
                $row = $result->fetch_assoc();

                $difference_time = $row["buildingtime"] - time();

                $text_build = "Bauzeit:<br>
                                <span class='js-countdown' 
                                               data-seconds='$difference_time' 
                                               data-hide-id='cancel-form'>
                                               " . format_time_for_js($difference_time) . "
                                </span><br>
                                <form id='cancel-form' action='towncenter.php' method='POST'>
                                    <input type='hidden' name='action' value='cancel'>
                                    <input type='hidden' name='bid' value='" . $i . "'>
                                    <input type='submit' value='Abbruch' style='margin-top: 5px;'>
                                </form>";
            } else {
                $text_build = "-";
            }
        } else {
            $current_max_by_tc = ($i == BuildingTypes::BUILDING_STORAGE) ? ($tc_level + 1) : $tc_level;
            $is_tc_limit_reached = ($i != BuildingTypes::BUILDING_TOWNCENTER && ($level + 1) > $current_max_by_tc);

            if ($is_tc_limit_reached) {
                $needed_tc = ($i == BuildingTypes::BUILDING_STORAGE) ? $level : $level + 1;

                $text_build = "<span class='error item-needed'>Dorfzentrum Stufe $needed_tc benötigt</span>";
            } else {
                $is_disabled = $kingdom->can_afford_costs($costs) ? "" : "disabled";

                $text_build = "<form action='towncenter.php' method='POST'>
                                    <input type='hidden' name='action' value='build'>
                                    <input type='hidden' name='bid' value='" . $i . "'>
                                    <input type='submit' value='" . ($level > 0 ? "Upgrade" : "Bauen") . "' $is_disabled>
                                </form>";
            }
        }

        $base_sec = round($building_obj->get_building_time() * pow($building_obj->get_building_mult(), $level));
        $time_text = convert_sec_to_str((int)round($base_sec * $kingdom->get_construction_time_multiplier()));
    }

    $view .= "<tr>
        <td>
            <div class='map-legend' style='justify-content: left;'>
                <div class='legend-item'>" . $building_obj->get_building_icon() . "</div>
                <div class='legend-item'>
                    <b class='popup' id='description" . $i . "'>" . $building_obj->get_building_name() . " (" . $building_obj->get_building_level() . ")
                        <div id='description" . $i . "_box' class='popupbox'>
                            " . $building_obj->get_building_description() . "
                        </div>
                    </b>
                </div>
            </div>";

    if (!$is_maxed) {
        $view .= "
            <div class='map-legend' style='justify-content: left; margin-top: 10px; gap: 5px;'>
                $resource_costs
            </div>
            <div class='map-legend' style='justify-content: left;'>
                <div class='legend-item'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_TIME) . " " . $time_text . "</div>
            </div>";
    }

    $view .= "</td>
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
<?php
require_once("../includes/ajax_core.php");
require_post();

$uid = $user->get_user_id();
$arena = new Arena($uid);
$action = post_str("action");

switch ($action) {
    case "save_defense":
        if (!Arena::is_season_active()) {
            echo json_encode(["success" => false, "error" => "Die Arena befindet sich in der Saisonpause."]);
            exit;
        }

        $raw_troops = post_str("troops", "{}");
        $raw_techs = post_str("techs", "{}");
        if (strlen($raw_troops) > 4096 || strlen($raw_techs) > 4096) {
            echo json_encode(["success" => false, "error" => "Aufstellungsdaten zu groß."]);
            exit;
        }

        $troops = json_decode($raw_troops, true);
        $techs = json_decode($raw_techs, true);
        if (!is_array($troops) || !is_array($techs)) {
            echo json_encode(["success" => false, "error" => "Ungültige Aufstellungsdaten."]);
            exit;
        }

        $err = $arena->save_defense_setup($troops, $techs, post_int("reward_kingdom_id"));
        if ($err !== null) {
            echo json_encode(["success" => false, "error" => $err]);
        } else {
            echo json_encode(["success" => true, "message" => "Verteidigung erfolgreich aufgestellt!"]);
        }
        break;

    case "select_skill":
        if (!Arena::is_season_active()) {
            echo json_encode(["success" => false, "error" => "Die Arena befindet sich in der Saisonpause."]);
            exit;
        }

        $skill_id = post_int("skill_id", 1);
        if ($arena->select_hero_skill($skill_id)) {
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => "Ungültige Fähigkeit gewählt."]);
        }
        break;

    case "reroll_opponents":
        if (!Arena::is_season_active()) {
            echo json_encode(["success" => false, "error" => "Die Arena befindet sich in der Saisonpause."]);
            exit;
        }

        echo json_encode($arena->reroll_opponents_locked());
        break;

    case "attack":
        if (!Arena::is_season_active()) {
            echo json_encode(["success" => false, "error" => "Die Arena befindet sich in der Saisonpause."]);
            exit;
        }

        $raw_troops = post_str("troops", "{}");
        $raw_techs = post_str("techs", "{}");
        if (strlen($raw_troops) > 4096 || strlen($raw_techs) > 4096) {
            echo json_encode(["success" => false, "error" => "Aufstellungsdaten zu groß."]);
            exit;
        }

        $troops = json_decode($raw_troops, true);
        $techs = json_decode($raw_techs, true);
        if (!is_array($troops) || !is_array($techs)) {
            echo json_encode(["success" => false, "error" => "Ungültige Aufstellungsdaten."]);
            exit;
        }

        $defender_id = post_int("defender_id");
        $skill_id = isset($_POST["skill_id"]) ? post_int("skill_id") : null;
        $result = $arena->attack_opponent($defender_id, $troops, $techs, $skill_id);
        echo json_encode($result);
        break;

    default:
        echo json_encode(["success" => false, "error" => "Unbekannte Aktion"]);
        break;
}
exit;
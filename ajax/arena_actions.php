<?php
require_once("../includes/core.php");

if (!isset($_SERVER["HTTP_X_REQUESTED_WITH"]) || strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) !== "xmlhttprequest") {
    change_location("overview.php");
    exit;
}

if (!$user->is_logged_in() || $user->get_user_id() <= 0) {
    echo json_encode(["success" => false, "error" => "Nicht eingeloggt"]);
    exit;
}

$uid = $user->get_user_id();
$arena = new Arena($uid);
$action = $_POST["action"] ?? $_GET["action"] ?? "";

switch ($action) {
    case "save_defense":
        $troops = json_decode($_POST["troops"] ?? "{}", true);
        $techs = json_decode($_POST["techs"] ?? "{}", true);
        $reward_kid = isset($_POST["reward_kingdom_id"]) ? (int)$_POST["reward_kingdom_id"] : 0;

        $err = $arena->save_defense_setup($troops, $techs, $reward_kid);
        if ($err !== null) {
            echo json_encode(["success" => false, "error" => $err]);
        } else {
            echo json_encode(["success" => true, "message" => "Verteidigung erfolgreich aufgestellt!"]);
        }
        break;
    case "select_skill":
        $skill_id = (int)($_POST["skill_id"] ?? 1);

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

        $arena->check_daily_reset();

        $rerolls_left = $arena->get_rerolls_left();
        if ($rerolls_left <= 0) {
            echo json_encode(["success" => false, "error" => "Du hast deine " . ARENA_MAX_REROLLS_PER_DAY . " täglichen Rerolls bereits aufgebraucht!"]);
            exit;
        }

        $opponents = $arena->get_or_roll_opponents(true);
        $new_rerolls_left = $arena->get_rerolls_left();
        $html = Arena::render_opponents_html($opponents, $arena->get_player_data(), $uid);

        echo json_encode([
            "success" => true,
            "html" => $html,
            "rerolls_left" => $new_rerolls_left,
            "max_rerolls" => ARENA_MAX_REROLLS_PER_DAY
        ]);
        break;
    case "attack":
        if (!Arena::is_season_active()) {
            echo json_encode(["success" => false, "error" => "Die Arena befindet sich in der Saisonpause."]);
            exit;
        }

        $defender_id = (int)($_POST["defender_id"] ?? 0);
        $troops = json_decode($_POST["troops"] ?? "{}", true);
        $techs = json_decode($_POST["techs"] ?? "{}", true);
        $result = $arena->attack_opponent($defender_id, $troops, $techs);

        echo json_encode($result);
        break;
    default:
        echo json_encode(["success" => false, "error" => "Unbekannte Aktion"]);
        break;
}
exit;
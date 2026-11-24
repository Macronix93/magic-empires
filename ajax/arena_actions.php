<?php
require_once("../includes/ajax_core.php");
require_post();

$uid = $user->get_user_id();
$arena = new Arena($uid);
$action = post_str("action");

if (!in_array($action, ["save_defense", "select_skill", "reroll_opponents", "attack"], true)) {
    send_json(["success" => false, "error" => "Unbekannte Aktion"]);
}

if (!Arena::is_season_active()) {
    send_json(["success" => false, "error" => "Die Arena befindet sich in der Saisonpause."]);
}

function parse_arena_setup_from_post(): array {
    $raw_troops = post_str("troops", "{}");
    $raw_techs = post_str("techs", "{}");
    if (strlen($raw_troops) > 4096 || strlen($raw_techs) > 4096) {
        send_json(["success" => false, "error" => "Aufstellungsdaten zu groß."]);
    }

    $troops = json_decode($raw_troops, true);
    $techs = json_decode($raw_techs, true);
    if (!is_array($troops) || !is_array($techs)) {
        send_json(["success" => false, "error" => "Ungültige Aufstellungsdaten."]);
    }

    return [$troops, $techs];
}

switch ($action) {
    case "save_defense":
        [$troops, $techs] = parse_arena_setup_from_post();
        $err = $arena->save_defense_setup($troops, $techs, post_int("reward_kingdom_id"));

        if ($err !== null) {
            send_json(["success" => false, "error" => $err]);
        }

        send_json(["success" => true, "message" => "Verteidigung erfolgreich aufgestellt!"]);

    case "select_skill":
        $skill_id = post_int("skill_id", 1);

        if ($arena->select_hero_skill($skill_id)) {
            send_json(["success" => true]);
        }

        send_json(["success" => false, "error" => "Ungültige Fähigkeit gewählt."]);

    case "reroll_opponents":
        send_json($arena->reroll_opponents_locked());

    case "attack":
        [$troops, $techs] = parse_arena_setup_from_post();
        $defender_id = post_int("defender_id");
        $skill_id = isset($_POST["skill_id"]) ? post_int("skill_id") : null;

        send_json($arena->attack_opponent($defender_id, $troops, $techs, $skill_id));
}
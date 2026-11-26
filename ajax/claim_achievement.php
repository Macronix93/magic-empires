<?php
require_once("../includes/ajax_core.php");
require_post();

$uid = $user->get_user_id();
$ach_id = post_int("id");

if ($ach_id <= 0) {
    send_json(["success" => false, "error" => "Ungültige ID."]);
}


if (Achievement::claim_reward($uid, $ach_id, $user)) {
    $remaining_claims = (int)$db_instance->execute_query(
        "SELECT COUNT(*) FROM user_achievements ua
         JOIN achievements a ON ua.achievement_id = a.id
         WHERE ua.user_id = ? AND ua.is_claimed = 0 AND a.reward_coins > 0",
        [$uid]
    )->fetch_column();

    send_json([
        "success" => true,
        "id" => $ach_id,
        "new_coins" => $user->get_user_coins(),
        "coin_limit" => $user->get_coin_limit(),
        "remaining_claims" => $remaining_claims
    ]);
}

send_json([
    "success" => false,
    "error" => "Belohnung konnte nicht abgeholt werden oder wurde bereits eingelöst."
]);
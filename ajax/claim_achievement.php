<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $user->check_user_login();
    $uid = $user->get_user_id();
    $ach_id = (int)($_POST["id"] ?? 0);

    if ($ach_id <= 0) {
        echo json_encode(["success" => false, "error" => "Ungültige ID."]);
        exit;
    }

    if (Achievement::claim_reward($uid, $ach_id)) {
        echo json_encode([
            "success" => true,
            "id" => $ach_id,
            "new_coins" => $user->get_user_coins(),
            "coin_limit" => $user->get_coin_limit(),
            "remaining_claims" => Achievement::get_claimable_count($uid)
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "error" => "Belohnung konnte nicht abgeholt werden oder wurde bereits eingelöst."
        ]);
    }
    exit;
}
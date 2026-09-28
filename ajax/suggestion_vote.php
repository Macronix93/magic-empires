<?php
require_once("../includes/core.php");
session_write_close();

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    check_user_login($user);

    $sug_id = (int)($_POST["id"] ?? 0);
    $type = $_POST["type"] ?? ""; // "up" oder "down"
    $uid = $user->get_user_id();

    if ($sug_id <= 0 || !in_array($type, ["up", "down"])) {
        echo json_encode(["success" => false, "error" => "Ungültige Eingabe"]);
        exit;
    }

    $vote_val = ($type === "up") ? 1 : -1;

    $res_existing = $db_instance->execute_query(
        "SELECT vote FROM suggestion_votes WHERE suggestion_id = ? AND user_id = ?",
        [$sug_id, $uid]
    );
    $existing = $res_existing->fetch_assoc();

    if ($existing) {
        if ((int)$existing["vote"] === $vote_val) {
            $db_instance->execute_query(
                "DELETE FROM suggestion_votes WHERE suggestion_id = ? AND user_id = ?",
                [$sug_id, $uid]
            );
            $my_vote = 0;
        } else {
            $db_instance->execute_query(
                "UPDATE suggestion_votes SET vote = ? WHERE suggestion_id = ? AND user_id = ?",
                [$vote_val, $sug_id, $uid]
            );
            $my_vote = $vote_val;
        }
    } else {
        $db_instance->execute_query(
            "INSERT INTO suggestion_votes (suggestion_id, user_id, vote, created_at) VALUES (?, ?, ?, ?)",
            [$sug_id, $uid, $vote_val, time()]
        );
        $my_vote = $vote_val;
    }
    
    $res_counts = $db_instance->execute_query("
        SELECT 
            COALESCE(SUM(CASE WHEN vote = 1 THEN 1 ELSE 0 END), 0) AS upvotes,
            COALESCE(SUM(CASE WHEN vote = -1 THEN 1 ELSE 0 END), 0) AS downvotes
        FROM suggestion_votes WHERE suggestion_id = ?
    ", [$sug_id])->fetch_assoc();

    echo json_encode([
        "success" => true,
        "upvotes" => (int)$res_counts["upvotes"],
        "downvotes" => (int)$res_counts["downvotes"],
        "my_vote" => $my_vote
    ]);
    exit;
}
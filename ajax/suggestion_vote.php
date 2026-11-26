<?php
require_once("../includes/ajax_core.php");
require_post();

if (!SUGGESTIONS_ENABLED) {
    send_json(["success" => false, "error" => "Deaktiviert."], 403);
}

session_write_close();

$sug_id = post_int("id");
$type = post_str("type"); // "up" or "down"
$uid = $user->get_user_id();

if ($sug_id <= 0 || $sug_id > PHP_INT_MAX || !in_array($type, ["up", "down"], true)) {
    send_json(["success" => false, "error" => "Ungültige Eingabe"]);
}

$vote_val = ($type === "up") ? 1 : -1;
$my_vote = 0;

$db_instance->begin_transaction();
try {
    $db_instance->execute_query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$uid]);

    $exists = $db_instance->execute_query("SELECT 1 FROM suggestions WHERE id = ? LIMIT 1", [$sug_id]);
    if (!$exists || $exists->num_rows === 0) {
        $db_instance->rollback();

        send_json(["success" => false, "error" => "Dieser Vorschlag existiert nicht."]);
    }

    $existing = $db_instance->execute_query(
        "SELECT vote FROM suggestion_votes WHERE suggestion_id = ? AND user_id = ?",
        [$sug_id, $uid]
    )->fetch_assoc();

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

    $db_instance->commit();
} catch (Throwable $e) {
    $db_instance->rollback();
    Logger::get_instance()->error("suggestion_vote error: " . $e->getMessage());

    send_json(["success" => false, "error" => "Fehler beim Abstimmen."]);
}

$res_counts = $db_instance->execute_query("
        SELECT 
            COALESCE(SUM(IF(vote = 1, 1, 0)), 0) AS upvotes,
            COALESCE(SUM(IF(vote = -1, 1, 0)), 0) AS downvotes
        FROM suggestion_votes WHERE suggestion_id = ?
    ", [$sug_id])->fetch_assoc();

$res_voters = $db_instance->execute_query("
        SELECT 
            (SELECT GROUP_CONCAT(u1.username ORDER BY v1.id ASC SEPARATOR ', ') 
             FROM suggestion_votes v1 JOIN users u1 ON v1.user_id = u1.id 
             WHERE v1.suggestion_id = ? AND v1.vote = 1) AS upvoters,
            (SELECT GROUP_CONCAT(u2.username ORDER BY v2.id ASC SEPARATOR ', ') 
             FROM suggestion_votes v2 JOIN users u2 ON v2.user_id = u2.id 
             WHERE v2.suggestion_id = ? AND v2.vote = -1) AS downvoters
    ", [$sug_id, $sug_id])->fetch_assoc();

send_json([
    "success" => true,
    "upvotes" => (int)$res_counts["upvotes"],
    "downvotes" => (int)$res_counts["downvotes"],
    "my_vote" => $my_vote,
    "upvoters" => !empty($res_voters["upvoters"]) ? e($res_voters["upvoters"]) : "<i>Noch keine Stimmen</i>",
    "downvoters" => !empty($res_voters["downvoters"]) ? e($res_voters["downvoters"]) : "<i>Noch keine Stimmen</i>"
]);
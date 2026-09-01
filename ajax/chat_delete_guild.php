<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $msg_id = (int)($_GET["m_id"] ?? 0);
    $u_id = $user->get_user_id();
    $guild_id = $user->get_user_guild_id();

    if ($guild_id <= 0) {
        echo json_encode(["success" => false, "error" => "Keine Gilde"]);
        exit;
    }

    $res = $db_instance->execute_query("SELECT userid, guild_id FROM guild_chat WHERE id = ?", [$msg_id]);
    $msg = $res->fetch_assoc();

    if (!$msg || (int)$msg["guild_id"] !== $guild_id) {
        echo json_encode(["success" => false, "error" => "Nachricht nicht gefunden"]);
        exit;
    }

    $is_owner = ((int)$msg["userid"] === $u_id);
    $is_admin = $user->is_admin();
    $my_rank = $user->get_guild_rank_id();
    $is_high_rank = ($my_rank > 0 && $my_rank <= GuildRanks::GUILD_OFFICER);

    if ($is_admin || $is_owner || $is_high_rank) {
        $db_instance->execute_query("UPDATE guild_chat SET deleted = 1 WHERE id = ?", [$msg_id]);

        echo json_encode(["success" => true, "id" => $msg_id]);
    } else {
        echo json_encode(["success" => false, "error" => "Keine Berechtigung"]);
    }
} else {
    change_location("guild.php");
}
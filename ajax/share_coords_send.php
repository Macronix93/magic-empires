<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    check_user_login($user);

    $target = $_POST["share_target"] ?? "world";
    $raw_msg = trim($_POST["message"] ?? "");
    $recipient_name = trim($_POST["recipient"] ?? "");

    if (empty($raw_msg)) {
        echo json_encode(["success" => false, "error" => "Bitte gib eine Nachricht ein."]);
        exit;
    }

    $uid = $user->get_user_id();
    $uname = $user->get_user_name();
    $now = time();

    if ($target === "world") {
        $db_instance->execute_query(
            "INSERT INTO world_chat (userid, username, message, date) VALUES (?, ?, ?, ?)",
            [$uid, $uname, $raw_msg, $now]
        );
        $msg_id = $db_instance->insert_id;
        $db_instance->execute_query("UPDATE users SET last_world_chat_id = ? WHERE id = ?", [$msg_id, $uid]);

        echo json_encode(["success" => true, "message" => "Erfolgreich im Welt-Chat geteilt!"]);
        exit;
    }

    if ($target === "guild") {
        $gid = $user->get_user_guild_id();
        if ($gid <= 0) {
            echo json_encode(["success" => false, "error" => "Du bist in keiner Gilde."]);
            exit;
        }

        $db_instance->execute_query(
            "INSERT INTO guild_chat (guild_id, userid, username, message, date) VALUES (?, ?, ?, ?, ?)",
            [$gid, $uid, $uname, $raw_msg, $now]
        );
        $msg_id = $db_instance->insert_id;
        $db_instance->execute_query("UPDATE users SET last_guild_chat_id = ? WHERE id = ?", [$msg_id, $uid]);

        echo json_encode(["success" => true, "message" => "Erfolgreich im Gilden-Chat geteilt!"]);
        exit;
    }

    if ($target === "private") {
        if (empty($recipient_name)) {
            echo json_encode(["success" => false, "error" => "Bitte gib einen Empfänger an."]);
            exit;
        }

        $res_rec = $db_instance->execute_query("SELECT id, username FROM users WHERE username = ? LIMIT 1", [$recipient_name]);
        $rec = $res_rec->fetch_assoc();

        if (!$rec) {
            echo json_encode(["success" => false, "error" => "Dieser Spieler existiert nicht."]);
            exit;
        }

        if ((int)$rec["id"] === $uid) {
            echo json_encode(["success" => false, "error" => "Du kannst dir selbst keine Nachricht schicken."]);
            exit;
        }

        $messages = new Messages($user);
        $messages->send_message($uid, $uname, (int)$rec["id"], $rec["username"], $now, $raw_msg);

        echo json_encode(["success" => true, "message" => "Nachricht an {$rec["username"]} gesendet!"]);
        exit;
    }

    echo json_encode(["success" => false, "error" => "Ungültiges Ziel."]);
}
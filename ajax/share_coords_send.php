<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $user->check_user_login();
    $uid = $user->get_user_id();

    $target = $_POST["share_target"] ?? "world";
    $raw_msg = trim($_POST["message"] ?? "");
    $recipient_name = trim($_POST["recipient"] ?? "");
    $report_msg_id = (int)($_POST["report_msg_id"] ?? 0);

    if ($report_msg_id > 0) {
        $res = $db_instance->execute_query(
            "SELECT data_json FROM server_messages WHERE id = ? AND receiverid = ? AND category = ?",
            [$report_msg_id, $uid, MessageCategories::CATEGORY_WAR]
        );
        $row = $res->fetch_assoc();
        if ($row) {
            $data = json_decode($row["data_json"] ?? "{}", true);
            $label = Messages::get_report_share_label($data) ?? "Kampfbericht";
            $now = time();
            $expires_at = $now + (SHARED_REPORT_RETENTION_DAYS * 86400);

            $res_shared = $db_instance->execute_query(
                "SELECT id FROM shared_battle_reports WHERE original_message_id = ? AND shared_by_user_id = ? AND expires_at > ?",
                [$report_msg_id, $uid, $now]
            );
            $existing = $res_shared->fetch_assoc();

            if ($existing) {
                $shared_id = (int)$existing["id"];
                $db_instance->execute_query("UPDATE shared_battle_reports SET expires_at = ? WHERE id = ?", [$expires_at, $shared_id]);
            } else {
                $report_type = $data["template"] ?? "battle";
                $data_json = json_encode($data, JSON_UNESCAPED_UNICODE);
                $db_instance->execute_query(
                    "INSERT INTO shared_battle_reports (shared_by_user_id, original_message_id, report_type, data_json, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)",
                    [$uid, $report_msg_id, $report_type, $data_json, $now, $expires_at]
                );
                $shared_id = (int)$db_instance->insert_id;
            }

            $hash = substr(hash_hmac('sha256', (string)$shared_id, AVATAR_SALT), 0, 8);
            $raw_msg = "[report:$shared_id:$hash|$label]";
        }
    }

    if (empty($raw_msg)) {
        echo json_encode(["success" => false, "error" => "Keine Nachricht übergeben."]);
        exit;
    }

    if ($target === "world") {
        echo json_encode([
            "success" => true,
            "message" => $raw_msg,
            "redirect_url" => "messages.php?worldchat"
        ]);
        exit;
    }

    if ($target === "guild") {
        $gid = $user->get_user_guild_id();
        if ($gid <= 0) {
            echo json_encode(["success" => false, "error" => "Du bist in keiner Gilde."]);
            exit;
        }
        echo json_encode([
            "success" => true,
            "message" => $raw_msg,
            "redirect_url" => "guild.php?tab=chat"
        ]);
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

        $target_uid = (int)$rec["id"];
        $res_conv = $db_instance->execute_query(
            "SELECT 1 FROM messages WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) AND deleted = 0 LIMIT 1",
            [$uid, $target_uid, $target_uid, $uid]
        );

        $redirect_url = ($res_conv->num_rows > 0)
            ? "messages.php?action=read&s=" . $target_uid
            : "messages.php?action=new&receiver=" . urlencode($rec["username"]);

        echo json_encode([
            "success" => true,
            "message" => $raw_msg,
            "redirect_url" => $redirect_url
        ]);
        exit;
    }

    echo json_encode(["success" => false, "error" => "Ungültiges Ziel."]);
}
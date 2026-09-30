<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    check_user_login($user);

    $target = $_POST["share_target"] ?? "world";
    $raw_msg = trim($_POST["message"] ?? "");
    $recipient_name = trim($_POST["recipient"] ?? "");

    if (empty($raw_msg)) {
        echo json_encode(["success" => false, "error" => "Keine Koordinaten übergeben."]);
        exit;
    }

    $uid = $user->get_user_id();

    if ($target === "world") {
        echo json_encode([
            "success" => true,
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

        $res_conv = $db_instance->execute_query("
            SELECT 1 FROM messages 
            WHERE ((senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)) 
              AND deleted = 0 
            LIMIT 1",
            [$uid, $target_uid, $target_uid, $uid]
        );

        $redirect_url = ($res_conv->num_rows > 0)
            ? "messages.php?action=read&s=" . $target_uid
            : "messages.php?action=new&receiver=" . urlencode($rec["username"]);

        echo json_encode([
            "success" => true,
            "redirect_url" => $redirect_url
        ]);
        exit;
    }

    echo json_encode(["success" => false, "error" => "Ungültiges Ziel."]);
}
<?php
// Timeout and session ID check
if ($user->is_logged_in()) {
    $user->check_session_id();

    if (MAINTENANCE_MODE && !$user->is_admin()) {
        if (basename($_SERVER["PHP_SELF"]) !== "index.php") {
            change_location("index.php?logout=maintenance");
            exit;
        }
    }

    $current_k_id = $user->get_current_kingdom();
    $current_ip = $_SERVER["REMOTE_ADDR"];

    $query = "
        SELECT 
            u.is_banned, u.ban_reason, u.mainkingdom,
            k.id AS current_k_exists
        FROM users u
        LEFT JOIN kingdoms k ON k.id = ? AND k.userid = u.id
        WHERE u.id = ? OR (u.ip = ? AND u.is_banned = 1)
        LIMIT 1
    ";
    $check_res = $db_instance->execute_query($query, [$current_k_id, $user->get_user_id(), $current_ip]);
    $user_status = $check_res->fetch_assoc();

    if ($user_status && $user_status["is_banned"] == 1) {
        $reason = $user_status["ban_reason"] ?? "Sicherheitsbann (IP-Match)";
        session_destroy();

        change_location("index.php?banned=" . urlencode($reason));
        exit;
    }

    if (!$user_status || $user_status["current_k_exists"] === null) {
        $main_k = $user_status["mainkingdom"] ?? -1;
        $_SESSION["kingdomid"] = $main_k;
        $user->set_current_kingdom($main_k);

        if (basename($_SERVER["PHP_SELF"]) !== "overview.php") {
            change_location(basename($_SERVER["PHP_SELF"]));
            exit;
        }
    }

    $timestamp = time();

    if (!isset($_SESSION["lastactivity"])) {
        $_SESSION["lastactivity"] = $timestamp;
    }

    // last activity is more than TIMEOUT_MAX_SECONDS seconds ago
    if ($timestamp - $_SESSION["lastactivity"] > TIMEOUT_MAX_SECONDS) {
        if (basename($_SERVER["PHP_SELF"]) !== "index.php") {
            session_unset();
            session_destroy();
            change_location("index.php?logout=session");
            exit;
        }
    } else {
        if (!isset($_SESSION["last_db_update"])) {
            $_SESSION["last_db_update"] = 0;
        }

        if ($timestamp - $_SESSION["last_db_update"] > USER_UPDATE_TICK) {
            $db_instance->execute_query("UPDATE users SET lastactivity = $timestamp WHERE id = ?", [$user->get_user_id()]);

            $_SESSION["last_db_update"] = $timestamp;
        }

        $is_ajax = (!empty($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest");

        if (!$is_ajax) {
            $_SESSION["lastactivity"] = $timestamp;
        }

        // Process all events for the user
        $user->process_user_events();

        // Update villager count after events were processed (villager cap)
        apply_villager_cap($user->get_current_kingdom());
    }
}
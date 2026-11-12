<?php
// Cookie/Remember Me Check
$is_logging_out = isset($_POST["logout"]) || isset($_GET["logout"]);

if (!$is_logging_out && !$user->is_logged_in() && isset($_COOKIE["me_remember"])) {
    if (str_contains($_COOKIE["me_remember"], ':')) {
        list($uid, $token) = explode(':', $_COOKIE["me_remember"], 2);
        $uid = (int)$uid;
        $token_hash = hash("sha256", $token);

        $res = $db_instance->execute_query(
            "SELECT userid FROM user_remember_tokens WHERE userid = ? AND token_hash = ? AND expires_at > ?",
            [$uid, $token_hash, time()]
        );

        if ($row = $res->fetch_assoc()) {
            $u_vac = $db_instance->execute_query("SELECT is_vacation, vacation_until FROM users WHERE id = ?", [$row["userid"]])->fetch_assoc();

            if (!empty($u_vac["is_vacation"]) && $u_vac["vacation_until"] > time()) {
                setcookie("me_remember", '', time() - 3600, '/');

                change_location("index.php?vacation_locked=1");
            }

            $user->login_user((int)$row["userid"]);
            $user->refresh_remember_me_token($token, $token_hash);
            $user->set_current_kingdom($_SESSION["kingdomid"] ?? -1);
            $_SESSION["lastactivity"] = time();
        } else {
            setcookie("me_remember", '', time() - 3600, '/');
        }
    }
}


// Timeout and session ID check
if ($user->is_logged_in()) {
    if (MAINTENANCE_MODE && !$user->is_admin()) {
        if (basename($_SERVER["PHP_SELF"]) === "index.php" && $is_logging_out) {
            return;
        }

        if (basename($_SERVER["PHP_SELF"]) !== "maintenance.php") {
            change_location("maintenance.php");
        }

        return;
    }

    $current_k_id = $user->get_current_kingdom();
    $current_ip = $_SERVER["REMOTE_ADDR"];
    $current_uid = $user->get_user_id();

    // Check current user
    $query = "
        SELECT u.sessionid, u.is_banned, u.ban_reason, u.mainkingdom, k.id AS current_k_exists
        FROM users u
        LEFT JOIN kingdoms k ON k.id = ? AND k.userid = u.id
        WHERE u.id = ?
        LIMIT 1
    ";
    $check_res = $db_instance->execute_query($query, [$current_k_id, $current_uid]);
    $user_status = $check_res->fetch_assoc();

    // Session id validation
    if (!$user_status || $user_status["sessionid"] !== session_id()) {
        $has_valid_remember = false;

        if (isset($_COOKIE["me_remember"]) && str_contains($_COOKIE["me_remember"], ':')) {
            list(, $token) = explode(':', $_COOKIE["me_remember"], 2);
            $token_hash = hash("sha256", $token);

            $res_token = $db_instance->execute_query(
                "SELECT 1 FROM user_remember_tokens WHERE userid = ? AND token_hash = ? AND expires_at > ?",
                [$current_uid, $token_hash, time()]
            );

            $has_valid_remember = ($res_token && $res_token->num_rows > 0);
        }

        if (!$has_valid_remember) {
            session_destroy();
            change_location("index.php?logout=session", 0, 401, "Session abgelaufen.");
        }
    }

    // Account is banned
    if ($user_status && (int)$user_status["is_banned"] === 1) {
        $reason = $user_status["ban_reason"] ?? "Dein Account wurde gesperrt.";

        setcookie("me_remember", '', time() - 3600, '/');

        session_destroy();
        change_location("index.php?banned=" . urlencode($reason));
    }

    // Safety check: is the current IP banned?
    $res_ip_ban = $db_instance->execute_query(
        "SELECT reason FROM banned_ips WHERE ip = ? LIMIT 1",
        [$current_ip]
    );
    if ($res_ip_ban && $res_ip_ban->num_rows > 0) {
        $ip_ban_row = $res_ip_ban->fetch_assoc();
        $reason = $ip_ban_row["reason"] ?? "Deine IP-Adresse wurde gesperrt.";

        setcookie("me_remember", '', time() - 3600, '/');
        session_destroy();

        change_location("index.php?banned=" . urlencode($reason));
    }

    // Kingdom doesn't exist anymore
    if (!$user_status || $user_status["current_k_exists"] === null) {
        $main_k = $user_status["mainkingdom"] ?? -1;
        $_SESSION["kingdomid"] = $main_k;
        $user->set_current_kingdom($main_k);

        if (basename($_SERVER["PHP_SELF"]) !== "overview.php") {
            change_location(basename($_SERVER["PHP_SELF"]));
        }
    }

    $timestamp = time();

    if (!isset($_SESSION["lastactivity"])) {
        $_SESSION["lastactivity"] = $timestamp;
    }

    // last activity is more than TIMEOUT_MAX_SECONDS seconds ago
    if ($timestamp - $_SESSION["lastactivity"] > TIMEOUT_MAX_SECONDS) {
        if (isset($_COOKIE["me_remember"])) {
            $_SESSION["lastactivity"] = $timestamp;
        } else {
            if (basename($_SERVER["PHP_SELF"]) !== "index.php") {
                $token = bin2hex(random_bytes(16));

                setcookie("logout_verify", $token, time() + 30, "/", "", false, false);
                change_location("index.php?logout=session&v=" . $token);
            }
        }
    } else {
        if (!MAINTENANCE_MODE || $user->is_admin()) {
            $_SESSION["lastactivity"] = $timestamp;

            $is_ajax = is_ajax_request();

            if (!isset($_SESSION["last_db_update"])) {
                $_SESSION["last_db_update"] = 0;
            }
            if ($timestamp - $_SESSION["last_db_update"] > USER_UPDATE_TICK) {
                $db_instance->execute_query("UPDATE users SET lastactivity = ? WHERE id = ?", [$timestamp, $user->get_user_id()]);
                $_SESSION["last_db_update"] = $timestamp;
            }

            if (!$is_ajax) {
                // Process all events for the user
                if ($timestamp - ($_SESSION["last_events_processed"] ?? 0) >= 2) {
                    $user->process_user_events();

                    $_SESSION["last_events_processed"] = $timestamp;
                }

                // Spawn advent calendar gifts
                if ($_SERVER["REQUEST_METHOD"] === "GET") {
                    if (AdventCalendar::is_active()) {
                        $today_str = date("Y-m-d");

                        if (($_SESSION["last_advent_check"] ?? "") !== $today_str) {
                            AdventCalendar::ensure_user_gifts($current_uid);

                            $_SESSION["last_advent_check"] = $today_str;
                        }
                    }

                    $_SESSION["active_attacks"] = $user->check_for_incoming_attacks();
                    $_SESSION["active_supports"] = $user->check_for_incoming_support();
                }
            }
        }
    }
}
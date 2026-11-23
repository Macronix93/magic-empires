<?php
$flash_and_logout = function (string $key, string $msg, int $status_code, string $ajax_msg): void {
    setcookie("me_remember", '', time() - 3600, '/');
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION["auth_flash"] = [$key => $msg];
    change_location("index.php", 0, $status_code, $ajax_msg);
};

// Cookie/Remember Me Check
$is_logging_out = isset($_POST["logout"]) || isset($_GET["logout"]);

if (!$is_logging_out && !$user->is_logged_in() && isset($_COOKIE["me_remember"]) && is_string($_COOKIE["me_remember"])) {
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
                $date_str = date("d.m.Y \u\m H:i", (int)$u_vac["vacation_until"]);
                $flash_and_logout("warning", "Dein Account befindet sich bis zum <b>$date_str Uhr</b> im Urlaubsmodus.", 403, "Urlaubsmodus aktiv.");
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
            change_location("maintenance.php", 0, 503, "Wartungsmodus aktiv.");
        }

        return;
    }

    $is_switching_k = false;
    if (!empty($_COOKIE["me_switch_kid"]) && is_numeric($_COOKIE["me_switch_kid"])) {
        $switch_kid = (int)$_COOKIE["me_switch_kid"];

        setcookie("me_switch_kid", "", time() - 3600, "/");
        $_SESSION["kingdomid"] = $switch_kid;

        $user->set_current_kingdom($switch_kid);
        $is_switching_k = true;
    }

    $current_k_id = $user->get_current_kingdom();
    $current_ip = $_SERVER["REMOTE_ADDR"];
    $current_uid = $user->get_user_id();

    // Check current user
    $query = "
        SELECT u.sessionid, u.is_banned, u.ban_reason, u.ban_until, u.mainkingdom, k.id AS current_k_exists
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

        if (isset($_COOKIE["me_remember"]) && is_string($_COOKIE["me_remember"]) && str_contains($_COOKIE["me_remember"], ':')) {
            list(, $token) = explode(':', $_COOKIE["me_remember"], 2);
            $token_hash = hash("sha256", $token);

            $res_token = $db_instance->execute_query(
                "SELECT 1 FROM user_remember_tokens WHERE userid = ? AND token_hash = ? AND expires_at > ?",
                [$current_uid, $token_hash, time()]
            );

            $has_valid_remember = ($res_token && $res_token->num_rows > 0);
        }

        if (!$has_valid_remember) {
            $flash_and_logout("warning", "Deine Session ist abgelaufen. Bitte logge dich erneut ein!", 401, "Session abgelaufen.");
        }
    }

    // Account is banned
    if (!empty($user_status["is_banned"])) {
        $ban_until = (int)($user_status["ban_until"] ?? 0);

        if ($ban_until > 0 && $ban_until <= time()) {
            $db_instance->execute_query("UPDATE users SET is_banned = 0, ban_reason = NULL, ban_until = 0 WHERE id = ?", [$current_uid]);
        } else {
            $reason = trim((string)($user_status["ban_reason"] ?? ""));
            $reason_text = !empty($reason) ? "<br />Grund: " . e($reason) : "";
            $until_text = ($ban_until > time()) ? "<br />Dauer: bis " . date("d.m.Y \u\m H:i", $ban_until) . " Uhr" : "<br />Dauer: Permanent";
            $flash_and_logout("error", "Dein Account wurde gesperrt!" . $reason_text . $until_text, 403, "Account gesperrt.");
        }
    }

    // Safety check: is the current IP banned?
    $res_ip_ban = $db_instance->execute_query(
        "SELECT reason FROM banned_ips WHERE ip = ? LIMIT 1",
        [$current_ip]
    );

    if ($res_ip_ban && $res_ip_ban->num_rows > 0) {
        $ip_ban_row = $res_ip_ban->fetch_assoc();
        $reason = trim((string)($ip_ban_row["reason"] ?? ""));
        $reason_text = !empty($reason) ? "<br />Grund: " . e($reason) : "";
        $flash_and_logout("error", "Deine IP-Adresse wurde gesperrt!" . $reason_text, 403, "IP gesperrt.");
    }

    // Kingdom doesn't exist anymore
    if (!$user_status || $user_status["current_k_exists"] === null) {
        $main_k = $user_status["mainkingdom"] ?? -1;
        $_SESSION["kingdomid"] = $main_k;
        $user->set_current_kingdom($main_k);

        setcookie("me_last_kid", (string)$main_k, time() + COOKIE_LIFETIME_ONE_YEAR, "/", "", false, true);

        if (basename($_SERVER["PHP_SELF"]) !== "overview.php") {
            change_location(basename($_SERVER["PHP_SELF"]));
        }
    } else if ($is_switching_k) {
        setcookie("me_last_kid", (string)$current_k_id, time() + COOKIE_LIFETIME_ONE_YEAR, "/", "", false, true);
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
                change_location("index.php?logout=session&v=" . $token, 0, 401, "Session abgelaufen.");
            }
        }
    } else {
        if (!MAINTENANCE_MODE || $user->is_admin()) {
            $is_ajax = is_ajax_request();
            $current_script = basename($_SERVER["PHP_SELF"] ?? '');
            $is_background_poll = $is_ajax
                && $_SERVER["REQUEST_METHOD"] === "GET"
                && in_array($current_script, ["badge_counts.php", "chat_update.php", "map_full_load.php"], true);

            if (!$is_background_poll) {
                $_SESSION["lastactivity"] = $timestamp;

                if (!isset($_SESSION["last_db_update"])) {
                    $_SESSION["last_db_update"] = 0;
                }

                if ($timestamp - $_SESSION["last_db_update"] > USER_UPDATE_TICK) {
                    $db_instance->execute_query("UPDATE users SET lastactivity = ? WHERE id = ?", [$timestamp, $user->get_user_id()]);
                    $_SESSION["last_db_update"] = $timestamp;
                }
            }

            if (!$is_ajax) {
                // Process all events for the user
                $user->process_user_events();

                // Spawn advent calendar gifts
                if ($_SERVER["REQUEST_METHOD"] === "GET") {
                    if (AdventCalendar::is_active()) {
                        $today_str = date("Y-m-d");

                        if (($_SESSION["last_advent_check"] ?? "") !== $today_str) {
                            new AdventCalendar()->ensure_user_gifts($current_uid);

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
<?php

class RateLimiter
{
    private static function make_key(string $scope, string $identifier): string
    {
        return hash("sha256", $scope . ":" . $identifier);
    }

    public static function is_blocked(string $scope, string $identifier): int
    {
        $db = Database::get_instance()->get_connection();
        $key = self::make_key($scope, $identifier);
        $now = time();

        $blocked_until = (int)($db->execute_query(
            "SELECT blocked_until FROM auth_rate_limits WHERE rate_key = ? LIMIT 1",
            [$key]
        )->fetch_column() ?? 0);

        return ($blocked_until > $now) ? ($blocked_until - $now) : 0;
    }

    public static function hit(string $scope, string $identifier, int $max_attempts, int $window_seconds, int $block_seconds): int
    {
        $db = Database::get_instance()->get_connection();
        $key = self::make_key($scope, $identifier);
        $now = time();

        $row = $db->execute_query(
            "SELECT attempts, window_start, blocked_until FROM auth_rate_limits WHERE rate_key = ? LIMIT 1",
            [$key]
        )->fetch_assoc();

        if (!$row || (($now - (int)$row["window_start"]) >= $window_seconds && (int)$row["blocked_until"] <= $now)) {
            $attempts = 1;
            $window_start = $now;
            $blocked_until = ($attempts >= $max_attempts) ? ($now + $block_seconds) : 0;
        } else {
            $attempts = (int)$row["attempts"] + 1;
            $window_start = (int)$row["window_start"];
            $blocked_until = (int)$row["blocked_until"];

            if ($attempts >= $max_attempts && $blocked_until <= $now) {
                $blocked_until = $now + $block_seconds;
            }
        }

        $db->execute_query("
            INSERT INTO auth_rate_limits (rate_key, attempts, window_start, blocked_until)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                attempts = VALUES(attempts),
                window_start = VALUES(window_start),
                blocked_until = VALUES(blocked_until)
        ", [$key, $attempts, $window_start, $blocked_until]);

        return ($blocked_until > $now) ? ($blocked_until - $now) : 0;
    }

    public static function clear(string $scope, string $identifier): void
    {
        $db = Database::get_instance()->get_connection();
        $key = self::make_key($scope, $identifier);
        $db->execute_query("DELETE FROM auth_rate_limits WHERE rate_key = ?", [$key]);
    }

    public static function cleanup(int $max_age_seconds = 86400): void
    {
        $db = Database::get_instance()->get_connection();
        $now = time();
        $db->execute_query(
            "DELETE FROM auth_rate_limits WHERE window_start < ? AND blocked_until < ?",
            [$now - $max_age_seconds, $now]
        );
    }

    private static function get_user_keys(
        string $ip,
        string $username,
        string $email,
        ?int   $user_id = null,
        string $device_id = ""
    ): array
    {
        $keys = [];

        if (!empty($ip)) {
            $keys[] = self::make_key("login_ip", $ip);
            $keys[] = self::make_key("forgot_ip", $ip);

            if (!empty($username)) {
                $keys[] = self::make_key("login_target", $ip . ":" . mb_strtolower($username, "UTF-8"));
            }

            if (!empty($email)) {
                $keys[] = self::make_key("login_target", $ip . ":" . mb_strtolower($email, "UTF-8"));
            }

            if ($user_id !== null && $user_id > 0) {
                $keys[] = self::make_key("login_uid", $ip . ":" . $user_id);
            }
        }

        if (!empty($device_id)) {
            $keys[] = self::make_key("login_device", $device_id);
        }

        if (!empty($email)) {
            $keys[] = self::make_key("forgot_mail", strtolower($email));
        }
        return array_values(array_unique($keys));
    }

    public static function get_user_status(string $ip, string $username, string $email, ?int $user_id = null,
                                           string $device_id = ""
    ): array
    {
        $keys = self::get_user_keys($ip, $username, $email, $user_id, $device_id);
        if (empty($keys)) {
            return ["has_entries" => false, "max_wait" => 0, "max_attempts" => 0];
        }

        $db = Database::get_instance()->get_connection();
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $res = $db->execute_query(
            "SELECT attempts, blocked_until FROM auth_rate_limits WHERE rate_key IN ($placeholders)",
            $keys
        );

        $now = time();
        $has_entries = false;
        $max_wait = 0;
        $max_attempts = 0;

        while ($r = $res->fetch_assoc()) {
            $has_entries = true;
            $max_attempts = max($max_attempts, (int)$r["attempts"]);
            $max_wait = max($max_wait, 0, (int)$r["blocked_until"] - $now);
        }

        return [
            "has_entries" => $has_entries,
            "max_wait" => $max_wait,
            "max_attempts" => $max_attempts
        ];
    }

    public static function clear_user_limits(string $ip, string $username, string $email, ?int $user_id = null,
                                             string $device_id = ""
    ): int
    {
        $keys = self::get_user_keys($ip, $username, $email, $user_id, $device_id);
        if (empty($keys)) {
            return 0;
        }

        $db = Database::get_instance()->get_connection();
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $db->execute_query("DELETE FROM auth_rate_limits WHERE rate_key IN ($placeholders)", $keys);
        return $db->affected_rows;
    }
}
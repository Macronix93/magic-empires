<?php

use JetBrains\PhpStorm\NoReturn;

class PhpBBBridge
{
    private static ?mysqli $forum_db = null;

    private static function is_enabled(): bool
    {
        if (defined("IS_DEV") && IS_DEV) {
            return false;
        }
        return !empty(getenv("FORUM_DB_NAME"));
    }

    private static function get_connection(): ?mysqli
    {
        if (self::$forum_db !== null) {
            return self::$forum_db;
        }

        $forum_db_name = getenv("FORUM_DB_NAME");

        if (!empty($forum_db_name)) {
            $host = getenv("FORUM_DB_HOST") ?: "127.0.0.1";
            $port = (int)(getenv("FORUM_DB_PORT") ?: 3306);
            $user = getenv("FORUM_DB_USER") ?: "";
            $pass = getenv("FORUM_DB_PASS") ?: "";

            try {
                self::$forum_db = new mysqli($host, $user, $pass, $forum_db_name, $port);
                self::$forum_db->set_charset("utf8mb4");
            } catch (mysqli_sql_exception $e) {
                Logger::get_instance()->error("phpBB Bridge DB-Verbindungsfehler: " . $e->getMessage());
                return null;
            }
        } else {
            self::$forum_db = Database::get_instance()->get_connection();
        }

        return self::$forum_db;
    }

    private static function get_prefix(): string
    {
        return getenv("FORUM_TABLE_PREFIX") ?: "phpbb_";
    }

    private static function get_registered_group_id(): int
    {
        return (int)(getenv("FORUM_REGISTERED_GROUP_ID") ?: 2);
    }

    /**
     * Create an account for phpBB 3.3 Forum
     */
    public static function create_forum_user(string $username, string $email, string $password_hash): bool
    {
        if (!self::is_enabled()) return false;

        $db = self::get_connection();
        if (!$db) return false;

        $prefix = self::get_prefix();
        $group_id = self::get_registered_group_id();

        $check = $db->execute_query(
            "SELECT user_id FROM {$prefix}users WHERE username_clean = ?",
            [self::clean_username($username)]
        );

        if ($check && $check->num_rows > 0) {
            return false;
        }

        static $user_columns = null;
        if ($user_columns === null) {
            $col_res = $db->query("SHOW COLUMNS FROM {$prefix}users");
            $user_columns = [];

            while ($col = $col_res->fetch_assoc()) {
                $user_columns[$col['Field']] = $col;
            }
        }

        $now = time();
        $username_clean = self::clean_username($username);
        $email_clean = strtolower(trim($email));

        $insert_data = [
            'user_type' => 0,
            'group_id' => $group_id,
            'username' => $username,
            'username_clean' => $username_clean,
            'user_password' => $password_hash,
            'user_passchg' => $now,
            'user_email' => $email_clean,
            'user_regdate' => $now,
            'user_lang' => 'de',
            'user_timezone' => 'Europe/Berlin',
            'user_dateformat' => 'd.m.Y, H:i',
            'user_style' => 1,
            'user_options' => 230271,
            'user_new' => 1,
            'user_ip' => $_SERVER["REMOTE_ADDR"] ?? '127.0.0.1'
        ];

        foreach ($user_columns as $field => $meta) {
            if ($meta['Extra'] === 'auto_increment') {
                continue;
            }
            if (!array_key_exists($field, $insert_data)) {
                if ($meta['Null'] === 'NO' && $meta['Default'] === null) {
                    $is_num = preg_match('/int|decimal|float|double|bit/i', $meta['Type']);
                    $insert_data[$field] = $is_num ? 0 : '';
                }
            }
        }

        $final_cols = [];
        $final_vals = [];
        foreach ($insert_data as $col => $val) {
            if (isset($user_columns[$col])) {
                $final_cols[] = $col;
                $final_vals[] = $val;
            }
        }

        $cols_sql = implode(', ', $final_cols);
        $placeholders = implode(', ', array_fill(0, count($final_vals), '?'));
        $sql = "INSERT INTO {$prefix}users ($cols_sql) VALUES ($placeholders)";

        try {
            $db->execute_query($sql, $final_vals);
            $forum_user_id = $db->insert_id;

            if ($forum_user_id > 0) {
                $db->execute_query(
                    "INSERT INTO {$prefix}user_group (group_id, user_id, group_leader, user_pending) VALUES (?, ?, 0, 0)",
                    [$group_id, $forum_user_id]
                );

                $db->execute_query("UPDATE {$prefix}config SET config_value = config_value + 1 WHERE config_name = 'num_users'");
                $db->execute_query("UPDATE {$prefix}config SET config_value = ? WHERE config_name = 'newest_user_id'", [(string)$forum_user_id]);
                $db->execute_query("UPDATE {$prefix}config SET config_value = ? WHERE config_name = 'newest_username'", [$username]);
                $db->execute_query("UPDATE {$prefix}config SET config_value = '' WHERE config_name = 'newest_user_colour'");

                return true;
            }
        } catch (Throwable $e) {
            Logger::get_instance()->error("Fehler beim Anlegen des Forum-Users ($username): " . $e->getMessage());
        }

        return false;
    }

    /**
     * Synchronize password with forum
     */
    public static function update_password(string $username, string $new_password_hash): void
    {
        if (!self::is_enabled()) return;

        $db = self::get_connection();
        if (!$db) return;

        $prefix = self::get_prefix();
        $now = time();

        $db->execute_query(
            "UPDATE {$prefix}users SET user_password = ?, user_passchg = ? WHERE username_clean = ?",
            [$new_password_hash, $now, self::clean_username($username)]
        );
    }

    /**
     * Synchronize e-mail with forum
     */
    public static function update_email(string $username, string $new_email): void
    {
        if (!self::is_enabled()) return;

        $db = self::get_connection();
        if (!$db) return;

        $prefix = self::get_prefix();
        $email_clean = strtolower(trim($new_email));

        $db->execute_query(
            "UPDATE {$prefix}users SET user_email = ? WHERE username_clean = ?",
            [$email_clean, self::clean_username($username)]
        );
    }

    /**
     * Synchronize user name
     */
    public static function update_username(string $old_username, string $new_username): void
    {
        if (!self::is_enabled()) return;

        $db = self::get_connection();
        if (!$db) return;

        $prefix = self::get_prefix();

        $db->execute_query(
            "UPDATE {$prefix}users SET username = ?, username_clean = ? WHERE username_clean = ?",
            [$new_username, self::clean_username($new_username), self::clean_username($old_username)]
        );
    }

    /**
     * Deletes account and keeps posts as Guest
     */
    public static function delete_forum_user(string $username): bool
    {
        if (!self::is_enabled()) return false;

        $db = self::get_connection();
        if (!$db) return false;

        $prefix = self::get_prefix();
        $username_clean = self::clean_username($username);

        $res = $db->execute_query(
            "SELECT user_id FROM {$prefix}users WHERE username_clean = ?",
            [$username_clean]
        );
        $forum_user = $res->fetch_assoc();

        if (!$forum_user) {
            return false;
        }

        $f_uid = (int)$forum_user["user_id"];

        if ($f_uid <= 1) {
            return false;
        }

        try {
            $db->execute_query("UPDATE {$prefix}posts SET poster_id = 1, post_username = ? WHERE poster_id = ?", [$username, $f_uid]);
            $db->execute_query("UPDATE {$prefix}topics SET topic_poster = 1, topic_first_poster_name = ? WHERE topic_poster = ?", [$username, $f_uid]);
            $db->execute_query("UPDATE {$prefix}topics SET topic_last_poster_id = 1, topic_last_poster_name = ? WHERE topic_last_poster_id = ?", [$username, $f_uid]);
            $db->execute_query("DELETE FROM {$prefix}user_group WHERE user_id = ?", [$f_uid]);
            $db->execute_query("DELETE FROM {$prefix}privmsgs_to WHERE user_id = ?", [$f_uid]);
            $db->execute_query("DELETE FROM {$prefix}users WHERE user_id = ?", [$f_uid]);
            $db->execute_query("UPDATE {$prefix}config SET config_value = config_value - 1 WHERE config_name = 'num_users'");
            $db->execute_query("
                UPDATE {$prefix}config 
                SET config_value = (SELECT user_id FROM {$prefix}users WHERE user_type IN (0, 3) ORDER BY user_id DESC LIMIT 1)
                WHERE config_name = 'newest_user_id' AND config_value = ?
            ", [(string)$f_uid]);

            return true;
        } catch (Throwable $e) {
            Logger::get_instance()->error("Fehler beim Löschen des Forum-Users ($username): " . $e->getMessage());
            return false;
        }
    }

    private static function clean_username(string $username): string
    {
        return mb_strtolower(trim($username), "UTF-8");
    }

    #[NoReturn]
    public static function redirect_to_forum(User $user): void
    {
        $user->check_user_login();

        if (!self::is_enabled()) {
            $_SESSION["game_error"] = "Das Forum ist in der Entwicklungsumgebung deaktiviert.";

            change_location("overview.php");
            exit;
        }

        $uid = $user->get_user_id();
        $username = $user->get_user_name();

        Achievement::unlock($uid, AchievementTypes::ACHIEVEMENT_SECRET_FORUM);

        $token = bin2hex(random_bytes(32));
        $expires = time() + 60;

        $db = Database::get_instance()->get_connection();
        $db->execute_query(
            "UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?",
            [$token, $expires, $uid]
        );

        $forum_base = getenv("FORUM_URL") ?: "https://board.magic-empires.de";
        $target_url = rtrim($forum_base, "/") . "/sso.php?token=" . $token . "&user=" . urlencode($username);

        header("Location: " . $target_url);
        exit;
    }
}
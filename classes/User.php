<?php

use Random\RandomException;

class User
{
    private object $mysqli;
    private string $reg_status = "";
    private int $user_id;
    private string $user_name;
    private int $current_kingdom;


    public function __construct(int $user_id, string $user_name, int $current_kingdom = -1)
    {
        $this->mysqli = Database::get_instance()->get_connection();
        $this->user_id = $user_id;
        $this->user_name = $user_name;
        $this->current_kingdom = $current_kingdom;
    }

    /**
     * @throws RandomException
     */
    public function register_user(string $name, string $email, string $pass): void
    {
        $password = password_hash($pass, PASSWORD_BCRYPT);
        $activation_key = md5($email . $name);

        $ip = $_SERVER["REMOTE_ADDR"];
        $device_id = $_COOKIE["me_device_id"] ?? bin2hex(random_bytes(16));

        if (!isset($_COOKIE["me_device_id"])) {
            setcookie("me_device_id", $device_id, time() + (86400 * 365 * 2), "/", "", false, true);
        }

        // Create activation link to activate account
        $actual_link = "https://$_SERVER[HTTP_HOST]" . BASE_URL . "index.php?key=" . $activation_key;

        $subject = 'Magic-Empires - Registrierung';
        $message = "<h2>Willkommen bei Magic-Empires!</h2>
                    <p>Hallo " . htmlspecialchars($name) . ",</p>
                    <p>vielen Dank für deine Registrierung. Bitte klicke auf den folgenden Button, um deinen Account freizuschalten:</p>
                    <p><a href='" . $actual_link . "' style='display:inline-block; background:#781e14; color:#ffffff; padding:10px 20px; text-decoration:none; border-radius:5px;'>Account aktivieren</a></p>
                    <p>Sollte der Button nicht funktionieren, kopiere diesen Link in deinen Browser:<br>" . $actual_link . "</p>
        ";

        if (send_mail($email, $subject, $message)) {
            $this->mysqli->execute_query("INSERT INTO users (username, password, activationkey, email, registerdate, sessionid, ip, device_id) 
                                                VALUES (?, ?, ?, ?, UNIX_TIMESTAMP(NOW()), ?, ?, ?)",
                [$name, $password, $activation_key, $email, session_id(), $ip, $device_id]);

            unset($_POST);
            unset($_SESSION["captcha_passed"]);

            $this->reg_status = show_passed_box("Du hast dich erfolgreich registriert!<br>Ein Aktivierungslink wurde an deine E-Mail gesendet.");
        } else {
            $this->reg_status = show_error_box("Mail konnte nicht gesendet werden!");
        }
    }

    public function login_user(int $user_id): void
    {
        $timestamp = time();

        $device_id = $_COOKIE["me_device_id"] ?? bin2hex(random_bytes(16));
        setcookie("me_device_id", $device_id, time() + (86400 * 365 * 2), "/", "", false, true);

        // Fetch users data
        $result = $this->mysqli->execute_query("SELECT username, lastlogin, score, mainkingdom, msgcount, lastsentmsgend, adminlevel, device_id FROM users WHERE id = ?", [$user_id]);
        $row = $result->fetch_assoc();
        $_SESSION["currlogin"] = $timestamp;
        $_SESSION["userid"] = $user_id;
        $_SESSION["lastlogin"] = $row["lastlogin"];
        $_SESSION["username"] = $row["username"];
        $_SESSION["kingdomid"] = $row["mainkingdom"];
        $_SESSION["adminlevel"] = $row["adminlevel"];
        $_SESSION["score"] = $row["score"];
        $_SESSION["message_count"] = $row["msgcount"];
        $_SESSION["message_timeframe_end"] = $row["lastsentmsgend"];
        $_SESSION["device_id"] = $device_id;

        // Update login time and session id
        $this->mysqli->execute_query("UPDATE users SET sessionid = ?, ip = ?, lastlogin = ?, lastactivity = ?, device_id = ? WHERE id = ?",
            [session_id(), $_SERVER["REMOTE_ADDR"], $timestamp, $timestamp, $device_id, $user_id]);

        Logger::get_instance()->log_game("ACCOUNT", "LOGIN_SUCCESS");

        change_location("overview.php");
    }

    public function process_user_events(): void
    {
        new EventManager($this)->process_all();
    }

    public function check_session_id(): void
    {
        $result = $this->mysqli->execute_query("SELECT sessionid FROM users WHERE id = ?", [$this->get_user_id()]);

        if ($result->fetch_assoc()["sessionid"] !== session_id()) {
            session_destroy();
            change_location("index.php?logout");
            exit;
        }
    }

    public function get_user_id(): int
    {
        return $this->user_id;
    }

    public function set_user_id(int $user_id): void
    {
        $this->user_id = $user_id;
    }

    public function get_avatar(): string
    {
        $hashedName = substr(hash("sha256", $this->user_id . AVATAR_SALT), 0, 12);

        $directory = __DIR__ . "/../" . UPLOADS_FILE_PATH;
        $files = glob($directory . $hashedName . ".*");

        if (!empty($files)) {
            $info = pathinfo($files[0]);
            return UPLOADS_FILE_PATH . $hashedName . "." . $info["extension"] . "?t=" . filemtime($files[0]);
        }

        return DEFAULT_AVATAR;
    }

    public function get_user_database_id(string $activation_key)
    {
        $result = $this->mysqli->execute_query("SELECT id FROM users WHERE activationkey = ?", [$activation_key]);
        return $result->fetch_assoc()["id"] ?? -1;
    }

    public function is_logged_in(): bool
    {
        return isset($_SESSION["userid"]);
    }

    public function get_user_admin_level(): int
    {
        $result = $this->mysqli->execute_query("SELECT adminlevel FROM users WHERE id = ?", [$this->user_id]);
        return $result->fetch_assoc()["adminlevel"] ?? 0;
    }

    public function clear_last_built_building(int $kingdom_id): void
    {
        if (isset($_SESSION["last_built_building"][$kingdom_id])) {
            unset($_SESSION["last_built_building"][$kingdom_id]);
        }
    }

    public function get_last_built_building(int $kingdom_id): ?array
    {
        return $_SESSION["last_built_building"][$kingdom_id] ?? null;
    }

    public function set_last_built_building(int $kingdom_id, string $building_name, int $building_level): void
    {
        if (!isset($_SESSION["last_built_building"])) {
            $_SESSION["last_built_building"] = array();
        }
        $_SESSION["last_built_building"][$kingdom_id] = [
            "buildingname" => $building_name,
            "buildinglevel" => $building_level
        ];
    }

    public function clear_last_researched_tech(int $kingdom_id): void
    {
        if (isset($_SESSION["last_researched_tech"][$kingdom_id])) {
            unset($_SESSION["last_researched_tech"][$kingdom_id]);
        }
    }

    public function get_last_researched_tech(int $kingdom_id): ?array
    {
        return $_SESSION["last_researched_tech"][$kingdom_id] ?? null;
    }

    public function set_last_researched_tech(int $kingdom_id, string $tech_name, int $tech_level): void
    {
        if (!isset($_SESSION["last_researched_tech"])) {
            $_SESSION["last_researched_tech"] = array();
        }
        $_SESSION["last_researched_tech"][$kingdom_id] = [
            "techname" => $tech_name,
            "techlevel" => $tech_level
        ];
    }

    public function clear_last_recruited_soldier(int $kingdom_id): void
    {
        if (isset($_SESSION["last_recruited_soldier"][$kingdom_id])) {
            unset($_SESSION["last_recruited_soldier"][$kingdom_id]);
        }
    }

    public function get_last_recruited_soldier(int $kingdom_id): ?array
    {
        return $_SESSION["last_recruited_soldier"][$kingdom_id] ?? null;
    }

    public function set_last_recruited_soldier(int $kingdom_id, $soldier_name, $soldier_count): void
    {
        if (!isset($_SESSION["last_recruited_soldier"])) {
            $_SESSION["last_recruited_soldier"] = array();
        }
        $_SESSION["last_recruited_soldier"][$kingdom_id] = [
            "soldiername" => $soldier_name,
            "soldiercount" => $soldier_count
        ];
    }

    public function set_last_upgraded_soldier(int $kingdom_id, string $name, int $count): void
    {
        if (!isset($_SESSION["last_upgraded"][$kingdom_id])) {
            $_SESSION["last_upgraded"] = array();
        }

        $_SESSION["last_upgraded"][$kingdom_id] = ["name" => $name, "count" => $count];
    }

    public function get_last_upgraded_soldier(int $kingdom_id): ?array
    {
        return $_SESSION["last_upgraded"][$kingdom_id] ?? null;
    }

    public function clear_last_upgraded_soldier(int $kingdom_id): void
    {
        unset($_SESSION["last_upgraded"][$kingdom_id]);
    }

    public function get_unread_messages(): int
    {
        $query = "
            SELECT COUNT(*) AS unread_count FROM (
                SELECT id FROM messages 
                WHERE receiverid = ? AND hasread = 0 AND deleted = 0
                UNION ALL
                SELECT id FROM servermessages 
                WHERE receiverid = ? AND hasread = 0
            ) AS combined_messages
        ";

        $result = $this->mysqli->execute_query($query, [$this->get_user_id(), $this->get_user_id()]);
        return $result->fetch_assoc()["unread_count"];
    }

    public function set_user_score(int $score): void
    {
        $this->mysqli->execute_query("UPDATE users SET score = ? WHERE id = ?", [$score, $this->get_user_id()]);
    }

    public function get_user_score(): int
    {
        $result = $this->mysqli->execute_query("SELECT score FROM users WHERE id = ?", [$this->user_id]);
        return $result->fetch_assoc()["score"];
    }

    public function get_current_kingdom(): int
    {
        return $this->current_kingdom;
    }

    public function set_current_kingdom(int $kingdom_id): void
    {
        $this->current_kingdom = $kingdom_id;
    }

    public function get_user_name(): string
    {
        return $this->user_name;
    }

    public function set_user_name(string $user_name): void
    {
        $this->user_name = $user_name;
    }

    public function get_main_kingdom(): int
    {
        $result = $this->mysqli->execute_query("SELECT mainkingdom FROM users WHERE id = ?", [$this->user_id]);
        return $result->fetch_assoc()["mainkingdom"];
    }

    public function give_user_coins(int $coins): void
    {
        $this->mysqli->execute_query("UPDATE users SET coins = coins + ? WHERE id = ?", [$coins, $this->user_id]);
    }

    public function get_user_coins(): int
    {
        $result = $this->mysqli->execute_query("SELECT coins FROM users WHERE id = ?", [$this->user_id]);
        return $result->fetch_assoc()["coins"];
    }

    public function get_reg_status(): string
    {
        return $this->reg_status;
    }
}
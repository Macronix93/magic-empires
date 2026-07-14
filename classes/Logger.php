<?php

class Logger
{
    private static ?Logger $instance = null;
    private mysqli $db;
    private string $log_path;

    private function __construct()
    {
        $this->db = Database::get_instance()->get_connection();
        $this->log_path = __DIR__ . "/../logs/";
    }

    public static function get_instance(): Logger
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    // Technical Logs (File-based)
    public function log_file(string $file, string $message, string $level = "INFO"): void
    {
        $timestamp = date("Y-m-d H:i:s");
        $user_id = $_SESSION["userid"] ?? "GUEST";
        $ip = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

        $formatted_msg = "[$timestamp] [$level] [User: $user_id] [IP: $ip] $message" . PHP_EOL;
        file_put_contents($this->log_path . $file . ".log", $formatted_msg, FILE_APPEND);
    }

    // Game Activities (DB-based)
    public function log_game(string $category, string $action, array $details = [], ?int $kid = null): void
    {
        $user_id = $_SESSION["userid"] ?? null;
        $details_json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ip = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

        $query = "INSERT INTO game_logs (userid, kingdomid, category, action, details, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $this->db->execute_query($query, [$user_id, $kid, $category, $action, $details_json, $ip, time()]);
    }

    // Helper methods
    public function security(string $msg): void
    {
        $this->log_file("security", $msg, "CRITICAL");
    }

    public function admin(string $msg): void
    {
        $this->log_file("admin", $msg, "ADMIN_ACTION");
    }

    public function error(string $msg): void
    {
        $this->log_file("error", $msg, "ERROR");
    }
}
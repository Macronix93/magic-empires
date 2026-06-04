<?php
require_once(__DIR__ . "/../includes/core.php");

// Cleanup DB Logs (>= 30 days)
$db = Database::get_instance()->get_connection();
$db->execute_query("DELETE FROM gamelogs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
$logger->log_file("system", "DB Game Logs pruned.");

// Rotate File Logs
$files = ["error", "security", "admin"];
foreach ($files as $file) {
    $path = __DIR__ . "/../logs/$file.log";

    // Bigger than 5 MB
    if (file_exists($path) && filesize($path) > 1024 * 1024 * 5) {
        rename($path, $path . '.' . date("Y-m-d") . ".bak");
        touch($path);
    }
}
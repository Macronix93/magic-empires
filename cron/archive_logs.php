<?php
require_once(__DIR__ . "/../includes/core.php");

$log_dir = __DIR__ . "/../logs/";
$backup_dir = __DIR__ . "/../backups/";
$log_files = ["error", "security", "admin"];
$max_size = 1024 * 1024 * 5; // 5 MB

if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

foreach ($log_files as $file) {
    $path = $log_dir . $file . ".log";

    if (file_exists($path) && filesize($path) > $max_size) {
        $backup_filename = $file . "_" . date("Y-m-d") . ".bak";
        $target_path = $backup_dir . $backup_filename;

        if (rename($path, $target_path)) {
            touch($path);

            echo "[" . date("H:i:s") . "] Rotiert: $file.log -> backups/$backup_filename\n";
        } else {
            echo "[" . date("H:i:s") . "] FEHLER: Konnte $file.log nicht verschieben.\n";
        }
    }
}
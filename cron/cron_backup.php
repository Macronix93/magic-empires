<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once(__DIR__ . "/../includes/core.php");

// Config
$backup_folder = __DIR__ . "/../backups/";
if (!is_dir($backup_folder)) mkdir($backup_folder, 0755, true);

$date = date("Y-m-d_H-i-s");
$filename = "backup_me_" . $date . ".sql";
$full_path = $backup_folder . $filename;

$mysqldump_path = "D:/xampp/mysql/bin/mysqldump.exe";

$command = sprintf(
    '%s --host=%s --port=%s --user=%s --password=%s %s > %s',
    $mysqldump_path,
    getenv("HOST"),
    getenv("PORT"),
    getenv("USER"),
    getenv("PASSWORD"),
    getenv("DATABASE"),
    $full_path
);

exec($command, $output, $return_var);

if ($return_var === 0) {
    echo "Backup erfolgreich: $filename\n";

    // Delete Old Backups (older than 14 days)
    foreach (glob($backup_folder . "*.sql") as $file) {
        if (time() - filemtime($file) > (86400 * 14)) unlink($file);
    }
} else {
    echo "Fehler beim Backup! Code: $return_var\n";
}
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
    // Get all SQL backups and sort by creation date
    $files = glob($backup_folder . "*.sql");
    if ($files) {
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        // Only keep the last 7 backups
        $max_backups = MAX_SQL_BACKUPS;
        if (count($files) > $max_backups) {
            $files_to_delete = array_slice($files, $max_backups);
            foreach ($files_to_delete as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
} else {
    echo "Fehler beim Backup! Code: $return_var\n";
}
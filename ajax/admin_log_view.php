<?php
require_once("../includes/ajax_core.php");

if (!$user->is_admin()) {
    die("Zugriff verweigert.");
}

$log_dir = __DIR__ . "/../logs/";
$sub_dir = get_str("sub");

$allowed_system_files = ["admin.log", "error.log", "security.log"];
$file = get_str("file");
if ($file !== basename($file)) die("Ungültige Datei.");

$is_system_log = ($sub_dir === "" && in_array($file, $allowed_system_files, true));
$is_chat_backup = ($sub_dir === "chat_backups" && preg_match('/^chat_log_\d+_vs_\d+_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.log$/', $file));
if (!$is_system_log && !$is_chat_backup) die("Ungültige Datei.");

$file_path = $log_dir . ($sub_dir ? $sub_dir . "/" : "") . $file;
if (!file_exists($file_path)) {
    send_json(["success" => false, "error" => "Diese Log-Datei existiert noch nicht."]);
}

$all_lines = file($file_path);
$total_lines = count($all_lines);
if ($total_lines === 0) {
    send_json(["success" => false, "error" => "Diese Log-Datei ist leer."]);
}

if ($sub_dir !== "chat_backups") {
    $all_lines = array_reverse($all_lines);
}

$per_page = 50;
$total_pages = max(1, (int)ceil($total_lines / $per_page));
$page = max(1, min($total_pages, get_int("page", 1)));
$offset = ($page - 1) * $per_page;

$lines_to_show = array_slice($all_lines, $offset, $per_page);

$current_page_display = ($total_lines > 0) ? $page : 0;

echo "<h3>$file - Seite $current_page_display von $total_pages</h3>";

if ($total_pages > 1) {
    echo '<div class="pagination-container" style="margin-bottom: 10px;"><div class="pagination-bar">';
    for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++) {
        $active = ($i == $page) ? "active" : "";
        $url = "ajax/admin_log_view.php?file=$file&page=$i";

        echo "<a href='#' data-on-click='openOverlay' data-url='$url' data-title='Log-Viewer' data-width='1050px' class='page-link $active'>$i</a>";
    }
    echo '</div></div>';
}

echo "<pre style='background: #000; padding: 15px; overflow: auto; max-height: 500px; font-family: monospace; font-size: 13px; text-align: left; white-space: pre-wrap; border: 1px solid var(--border-gold);'>";
foreach ($lines_to_show as $line) {
    echo htmlspecialchars($line);
}
echo "</pre>";

// Footer Info
echo "<div style='margin-top: 10px; font-size: 12px; opacity: 0.7;'>Gesamtzeilen: $total_lines</div>";
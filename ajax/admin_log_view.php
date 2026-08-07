<?php
require_once("../includes/core.php");

if (!$user->is_admin()) {
    die("Zugriff verweigert.");
}

$log_dir = __DIR__ . "/../logs/";
$sub_dir = $_GET["sub"] ?? "";

$allowed_system_files = ["admin.log", "error.log", "security.log"];
$file = $_GET["file"] ?? '';
$page = max(1, (int)($_GET["page"] ?? 1));
$per_page = 50;

$is_system_log = in_array($file, $allowed_system_files);
$is_chat_backup = ($sub_dir === "chat_backups" && str_starts_with($file, "chat_log_"));

if (!$is_system_log && !$is_chat_backup) {
    die("Ungültige Datei.");
}

$file_path = $log_dir . ($sub_dir ? $sub_dir . "/" : "") . $file;
if (!file_exists($file_path)) {
    echo show_error_box("Datei existiert noch nicht.");
    return;
}

$all_lines = file($file_path);
if ($sub_dir !== "chat_backups") {
    $all_lines = array_reverse($all_lines);
}

$total_lines = count($all_lines);
$total_pages = ceil($total_lines / $per_page);

$offset = ($page - 1) * $per_page;
$lines_to_show = array_slice($all_lines, $offset, $per_page);

$current_page_display = ($total_lines > 0) ? $page : 0;

echo "<h3>$file - Seite $current_page_display von $total_pages</h3>";

if ($total_pages > 1) {
    echo '<div class="pagination-container" style="margin-bottom: 10px;"><div class="pagination-bar">';
    for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++) {
        $active = ($i == $page) ? "active" : "";
        $url = "ajax/admin_log_view.php?file=$file&page=$i";

        echo "<a href='#' data-on-click='openOverlay' data-url='$url' data-title='Log-Viewer' class='page-link $active'>$i</a>";
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
echo '<div style="text-align:center">
            <button data-on-click="closeOverlay">
                Schließen
            </button>
        </div>';
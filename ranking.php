<?php
require_once("includes/core.php");

check_user_login($user);

$rows_per_page = MAX_ROWS_PER_RANKING_PAGE;
// Get the current page or set a default
$current_page = isset($_GET["currentpage"]) ? max(1, $_GET["currentpage"]) : 1;
if ($current_page < 1) $current_page = 1;

// Get the total number of rows
$num_rows = $db_instance->execute_query("SELECT COUNT(*) FROM users WHERE status = 1")->fetch_row()[0];
$total_pages = ceil($num_rows / $rows_per_page);

if ($current_page > $total_pages && $total_pages > 0) $current_page = $total_pages;

// Calculate the offset
$offset = ($current_page - 1) * $rows_per_page;

// Get the data for the current page
$result = $db_instance->execute_query("SELECT id, username, lastactivity, lastrank, score FROM users WHERE status = 1 ORDER BY score DESC LIMIT ?, ?",
    [$offset, $rows_per_page]);
$view .= '<table class="table">
            <tr>
                <td class="td-center td-gradient">
                    <b>#</b>
                </td>
                <td class="td-center td-gradient">
                    <b>Spieler</b>
                </td>
                <td class="td-center td-gradient">
                    <b>Punkte</b>
                </td>
            </tr>';
$position = ($current_page - 1) * $rows_per_page + 1;

foreach ($result as $row) {
    $user_id = $row["id"];
    $user_name = $row["username"];
    $last_active = $row["lastactivity"];
    $inactive = (time() - $last_active > INACTIVITY_DELAY && $last_active != 0);
    $icon = "";
    $change = "";
    $color = (time() - $last_active > TIMEOUT_MAX_SECONDS) ? "#F55353" : (time() - $last_active > AFK_SECONDS ? "#FEDC56" : "#0BDA51");
    $last_activity = ($last_active == 0) ? "Nicht verfügbar" : (date("d.m.Y", $last_active) . " um " . date("H:i:s", $last_active) . " " . ($inactive ? "(Inaktiv)" : ""));
    $diff = $row["lastrank"] - $position; // Check last rank (since 00:00) and compare with current rank

    // Get user image
    if ($user_id !== $user->get_user_id()) {
        $player = new User($user_id, $user_name);
        $image_path = $player->get_avatar();
    } else {
        $image_path = $user->get_avatar();
    }

    $user_name = $inactive ? "<i>$user_name</i>" : $user_name;

    // Check if user rank went up or down since last update
    if ($position < $row["lastrank"]) {
        $icon = "<img src='images/icons/icon_arrow_up.png' class='ressource-icons' alt='' style='width: 16px; height: 16px; position: absolute; top: -5px; right: -15px;'/>";
        $change = "+" . $diff;
    } else if ($position > $row["lastrank"]) {
        $icon = "<img src='images/icons/icon_arrow_down.png' class='ressource-icons' alt='' style='width: 16px; height: 16px; position: absolute; top: -5px; right: -15px;'/>";
        $change = $diff;
    }

    $view .= "<tr>
                <td class='td-shrink' style='text-align: right; border-right: none;'>
                    <div style='position: relative; display: inline-block;'>$position
                        <div class='popup' id='description" . $position . "'>$icon</div>
                    </div>
                    <div id='description" . $position . "_box' class='popupbox'>Rang um 0 Uhr: {$row["lastrank"]} ($change)</div>
                </td>
                <td class='td-expand'>
                    <div class='image-and-user'>
                        <div class='avatar-container'>
                            <img class='user-image' src='" . $image_path . "' alt='Nutzerbild'>
                            <span class='status-indicator' style='background-color: " . $color . ";'></span>
                        </div>
                        <a href='#' 
                           data-on-click='openOverlay' 
                           data-url='userinfo.php?userid=" . e($row["id"]) . "' 
                           data-title='Spieler-Info'
                           class='popup' 
                           id='activity" . e($position) . "' 
                           style='cursor: pointer;'>$user_name</a>
                    </div>
                    <div id='activity" . $position . "_box' class='popupbox'>Letzte Aktivität: $last_activity</div>
                </td>
                <td class='td-score'>" . fnum($row["score"]) . "</td>
            </tr>";

    $position++;
}
$view .= "</table>";

// Pagination Styling
$view .= '<div class="pagination-container">';

if ($total_pages > 1) {
    $view .= '<div class="pagination-bar">';

    // First and Back
    if ($current_page > 1) {
        $view .= "<a href='ranking.php?currentpage=1' class='page-link' title='Erste Seite'>&laquo;</a>";
        $prev = $current_page - 1;
        $view .= "<a href='ranking.php?currentpage=$prev' class='page-link'>&lsaquo;</a>";
    }

    // Numbers
    $range = 2;
    for ($x = ($current_page - $range); $x < (($current_page + $range) + 1); $x++) {
        if ($x > 0 && $x <= $total_pages) {
            if ($x == $current_page) {
                $view .= "<span class='page-link active'>$x</span>";
            } else {
                $view .= "<a href='ranking.php?currentpage=$x' class='page-link'>$x</a>";
            }
        }
    }

    // Forward and Last
    if ($current_page < $total_pages) {
        $next = $current_page + 1;
        $view .= "<a href='ranking.php?currentpage=$next' class='page-link'>&rsaquo;</a>";
        $view .= "<a href='ranking.php?currentpage=$total_pages' class='page-link' title='Letzte Seite'>&raquo;</a>";
    }

    $view .= "</div>";
}
$view .= "</div>";

/*
 * HTML Section
 */
$title = "Rangliste";
$header = "Rangliste";
$script_files = ["userinfo"];

include("layout/base.php");
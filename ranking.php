<?php
require_once("includes/core.php");

check_user_login($user);

$rows_per_page = 10;
// Get the current page or set a default
$current_page = isset($_GET["currentpage"]) ? max(1, $_GET["currentpage"]) : 1;

// Get the total number of rows
$result = $db_instance->execute_query("SELECT COUNT(*) FROM users");
$num_rows = $result->fetch_row()[0];
$total_pages = ceil($num_rows / $rows_per_page);

// Calculate the offset
$offset = ($current_page - 1) * $rows_per_page;

// Get the data for the current page
$result = $db_instance->execute_query("SELECT id, username, status, lastactivity, lastrank, score FROM users ORDER BY score DESC LIMIT ?, ?", [$offset, $rows_per_page]);
$view .= '<table class="table">
            <tr>
                <td class="td-center td-gradient"
                    style="width: 20%;" colspan="2">
                    <b>Rang</b>
                </td>
                <td class="td-center td-gradient"
                    style="width: 50%;">
                    <b>Spieler</b>
                </td>
                <td class="td-center td-gradient"
                    style="width: 20%;">
                    <b>Punkte</b>
                </td>
            </tr>';
$position = ($current_page - 1) * $rows_per_page + 1;

foreach ($result as $row) {
    if (!$row["status"]) {
        continue;
    }

    $user_id = $row["id"];
    $user_name = $row["username"];
    $icon = "";
    $change = "";
    $color = (time() - $row["lastactivity"] > TIMEOUT_MAX_SECONDS) ? "#F55353" : (time() - $row["lastactivity"] > AFK_SECONDS ? "#FEDC56" : "#0BDA51");
    $last_activity = ($row["lastactivity"] == 0) ? "Nicht verfügbar" : (date('d.m.Y', $row['lastactivity']) . " um " . date('H:i:s', $row['lastactivity']));
    $diff = $row['lastrank'] - $position; // Check last rank (since 00:00) and compare with current rank

    // Get user image
    if ($user_id !== $user->get_user_id()) {
        $player = new User($user_id, $user_name);
        $image_path = $player->get_avatar();
    } else {
        $image_path = $user->get_avatar();
    }

    // Check if user rank went up or down since last update
    if ($position < $row["lastrank"]) {
        $icon = "<img src='images/icons/icon_arrow_up.png' class='ressource-icons' alt=''>";
        $change = "+" . $diff;
    } else if ($position > $row["lastrank"]) {
        $icon = "<img src='images/icons/icon_arrow_down.png' class='ressource-icons' alt=''>";
        $change = $diff;
    }

    $view .= "<tr>
                <td class='td-center' style='min-width: 13%; text-align: right; border-right: none;'>$position</td>
                <td style='border-left: none; padding: 0; margin:0;'>
                    <div class='popup' id='description" . $position . "'>$icon</div>
                    <div id='description" . $position . "_box' class='popupbox'>Rang um 0 Uhr: {$row['lastrank']} ($change)</div>
                </td>
                <td>
                    <div class='image-and-user'>
                        <img class='user-image' src='" . $image_path . "' alt='Nutzerbild'>
                        <a href='javascript:void(0);' onclick='openPopup(\"userinfo.php?userid=" . $row["id"] . "\");' class='popup' id='activity" . $position . "' style='color: $color; cursor: pointer;'>$user_name</a>
                    </div>
                    <div id='activity" . $position . "_box' class='popupbox'>Letzte Aktivität: $last_activity</div>
                </td>
                <td class='td-center'>" . fnum($row["score"]) . "</td>
            </tr>";

    $position++;
}
$view .= '</table>
<br>
<!-- Build the pagination links -->';
if ($current_page > 1) {
    $view .= "<a href='ranking.php?currentpage=1'> Erste </a>";
    $previous_page = $current_page - 1;
    $view .= "<a href='ranking.php?currentpage=$previous_page'> Zurück </a>";
}

for ($x = max(1, $current_page - 3); $x <= min($total_pages, $current_page + 3); $x++) {
    if ($x == $current_page) {
        $view .= "<span class='current'> [$x] </span>";
    } else {
        $view .= "<a href='ranking.php?currentpage=$x'> $x </a>";
    }
}

if ($current_page < $total_pages) {
    $next_page = $current_page + 1;
    $view .= "<a href='ranking.php?currentpage=$next_page'> Vor </a>";
    $view .= "<a href='ranking.php?currentpage=$total_pages'> Letzte </a>";
}

/*
 * HTML Section
 */
$title = "Rangliste";
$header = "Rangliste";
$script_files = ["userinfo"];

include('layout/base.php');
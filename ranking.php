<?php
require_once("includes/core.php");
check_user_login($user);

$rows_per_page = MAX_ROWS_PER_RANKING_PAGE;
$current_page = max(1, (int)($_GET["currentpage"] ?? 1));
$offset = ($current_page - 1) * $rows_per_page;
$now = time();

// Load Data
$player_count = $db_instance->execute_query("SELECT COUNT(*) FROM users WHERE status = 1")->fetch_row()[0];
$player_pages = ceil($player_count / $rows_per_page);

$players_res = $db_instance->execute_query("
    SELECT u.id, u.username, u.lastactivity, u.lastrank, u.ranking_points AS score,
           g.tag as guild_tag, g.id as guildid, r.rank_color, r.id as rank_id
    FROM users u 
    LEFT JOIN guilds g ON u.guildid = g.id
    LEFT JOIN guild_rank_list r ON u.guild_rank_id = r.id
    WHERE u.status = 1 
    ORDER BY u.ranking_points DESC, u.id 
    LIMIT ?, ?", [$offset, $rows_per_page]);

$guild_count = $db_instance->query("SELECT COUNT(*) FROM guilds")->fetch_row()[0];
$guild_pages = ceil($guild_count / $rows_per_page);

$guilds_res = $db_instance->execute_query("
    SELECT g.id, g.name, g.tag, COUNT(u.id) as member_count, SUM(u.ranking_points) as total_score
    FROM guilds g
    JOIN users u ON g.id = u.guildid
    WHERE u.status = 1
    GROUP BY g.id
    ORDER BY total_score DESC
    LIMIT ?, ?", [$offset, $rows_per_page]);

/* --- VIEW --- */
$view .= "<div class='tab' style='margin: 0 auto 10px auto; max-width: 340px;'>
    <div class='tablinks active' data-on-click='switchRankingTab' data-tab='players'>Spieler</div>
    <div class='tablinks' data-on-click='switchRankingTab' data-tab='guilds'>Gilden</div>
</div>";

// --- CONTAINER PLAYERS ---
$view .= "<div id='ranking_players' class='js-ranking-tab'>";
$view .= '<table class="table">
            <tr>
                <td class="td-center td-gradient"><b>#</b></td>
                <td class="td-center td-gradient"><b>Spieler</b></td>
                <td class="td-center td-gradient"><b>Punkte</b></td>
            </tr>';

$pos = $offset + 1;

foreach ($players_res as $row) {
    $user_id = $row["id"];
    $user_name = $row["username"];
    $last_active = $row["lastactivity"];

    $inactive = ($now - $last_active > INACTIVITY_DELAY && $last_active != 0);
    $last_activity_text = ($last_active == 0) ? "Nicht verfügbar" : (date("d.m.Y", $last_active) . " um " . date("H:i:s", $last_active) . " Uhr " . ($inactive ? "(Inaktiv)" : ""));
    $display_name = $inactive ? "<i>$user_name</i>" : $user_name;

    $color = ($now - $last_active > ONLINE_MAX_SECONDS) ? "#F55353" : ($now - $last_active > AFK_SECONDS ? "#FEDC56" : "#0BDA51");

    $guild_display = $row["guild_tag"] ? " <b style='cursor: pointer;' data-on-click='openGuildInfo' data-id='{$row["guildid"]}'>[" . e($row["guild_tag"]) . "]</b>" : "";
    $name_style = $row["rank_color"] && $row["rank_id"] != GuildRanks::GUILD_MEMBER ? " color: {$row["rank_color"]};" : "";

    $image_path = new User($user_id, $user_name)->get_avatar();

    $user_link = "<a href='#' 
                    data-on-click='openOverlay' 
                    data-url='userinfo.php?userid=$user_id' 
                    data-title='Spieler-Info'
                    class='popup' 
                    id='activity_$pos' 
                    style='cursor: pointer; $name_style'>$display_name
                    <div id='activity_{$pos}_box' class='popupbox'>Letzte Aktivität: $last_activity_text</div>
                  </a>";

    $view .= "<tr>
                <td class='td-shrink' style='text-align: right;'>$pos</td>
                <td class='td-expand'>
                    <div class='image-and-user'>
                        <div class='avatar-container popup' id='av_pop_$user_id'>
                            <img class='user-image' src='$image_path' alt=''>
                            <span class='status-indicator' style='background-color: $color;'></span>
                            <div id='av_pop_{$user_id}_box' class='popupbox avatar-popup'>
                                <img src='$image_path' style='width: 80px; height: 80px; border-radius: 5px;' alt='Avatar'>
                            </div>
                        </div>
                        $user_link $guild_display
                    </div>
                </td>
                <td class='td-score'>" . fnum($row["score"], true) . "</td>
            </tr>";
    $pos++;
}
$view .= "</table>";

$view .= "<div style='margin-top: 10px; opacity: 0.7;'><small>Hinweis: Punkte-Updates finden alle <b>5</b> Minuten statt.</small></div>";
$view .= render_ranking_pagination($current_page, $player_pages);
$view .= "</div>";

// --- CONTAINER GUILDS ---
$view .= "<div id='ranking_guilds' class='js-ranking-tab' style='display:none;'>";
$view .= '<table class="table">
            <tr>
                <td class="td-center td-gradient"><b>#</b></td>
                <td class="td-center td-gradient"><b>Gilde</b></td>
                <td class="td-center td-gradient"><b>Punkte</b></td>
            </tr>';

$pos = $offset + 1;

if ($guilds_res->num_rows > 0) {
    foreach ($guilds_res as $row) {
        $guild_logic = new Guild($db_instance, $user);
        $badge = $guild_logic->render_badge($row["id"], $row["tag"], $row["name"]);

        $view .= "<tr>
            <td class='td-shrink' style='text-align: right;'>$pos</td>
            <td class='td-expand' style='cursor: pointer;' data-on-click='openGuildInfo' data-id='{$row["id"]}'>
                <div style='display: flex; justify-content: space-between; align-items: center;'>
                    <div>$badge</div>
                    <small style='opacity: 0.6;'>{$row["member_count"]} Mitglieder</small>
                </div>
            </td>
            <td class='td-score'>" . fnum($row["total_score"], true) . "</td>
        </tr>";

        $pos++;
    }
} else {
    $view .= "<tr><td colspan='3' class='td-center'>Noch keine Gilden gegründet.</td></tr>";
}
$view .= "</table>";
$view .= render_ranking_pagination($current_page, $guild_pages);
$view .= "</div>";

function render_ranking_pagination($current, $total): string
{
    if ($total <= 1) return "";

    $html = '<div class="pagination-container"><div class="pagination-bar">';

    if ($current > 1) {
        $html .= "<a href='ranking.php?currentpage=1' class='page-link'>&laquo;</a>";
        $prev = $current - 1;
        $html .= "<a href='ranking.php?currentpage=$prev' class='page-link'>&lsaquo;</a>";
    }
    for ($x = max(1, $current - 2); $x <= min($total, $current + 2); $x++) {
        $active = ($x == $current) ? "active" : "";
        $html .= "<a href='ranking.php?currentpage=$x' class='page-link $active'>$x</a>";
    }
    if ($current < $total) {
        $next = $current + 1;
        $html .= "<a href='ranking.php?currentpage=$next' class='page-link'>&rsaquo;</a>";
        $html .= "<a href='ranking.php?currentpage=$total' class='page-link'>&raquo;</a>";
    }

    $html .= "</div></div>";

    return $html;
}

$title = "Rangliste";
$header = "Rangliste";
$script_files = ["userinfo", "guild", "ranking"];

include("layout/base.php");
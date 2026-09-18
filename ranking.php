<?php
require_once("includes/core.php");
check_user_login($user);

$rows_per_page = MAX_ROWS_PER_RANKING_PAGE;
$current_page = max(1, (int)($_GET["currentpage"] ?? 1));
$offset = ($current_page - 1) * $rows_per_page;
$now = time();

// Load Data
$active_tab = $_GET["tab"] ?? "players";
if (!in_array($active_tab, ["players", "guilds"])) {
    $active_tab = "players";
}

$current_page_players = ($active_tab === "players") ? max(1, (int)($_GET["currentpage"] ?? 1)) : 1;
$current_page_guilds = ($active_tab === "guilds") ? max(1, (int)($_GET["currentpage"] ?? 1)) : 1;

$offset_players = ($current_page_players - 1) * $rows_per_page;
$offset_guilds = ($current_page_guilds - 1) * $rows_per_page;

$player_count = $db_instance->execute_query("SELECT COUNT(*) FROM users WHERE status = 1")->fetch_row()[0];
$player_pages = ceil($player_count / $rows_per_page);

$players_res = $db_instance->execute_query("
    SELECT u.id, u.username, u.lastactivity, u.lastrank, u.ranking_points AS score,
           u.is_vacation, u.vacation_until,
           g.tag as guild_tag, g.id as guildid, r.rank_color, r.id as rank_id
    FROM users u 
    LEFT JOIN guilds g ON u.guildid = g.id
    LEFT JOIN guild_rank_list r ON u.guild_rank_id = r.id
    WHERE u.status = 1 
    ORDER BY u.ranking_points DESC, u.id 
    LIMIT ?, ?", [$offset_players, $rows_per_page]);

$guild_count = $db_instance->query("SELECT COUNT(*) FROM guilds")->fetch_row()[0];
$guild_pages = ceil($guild_count / $rows_per_page);

$guilds_res = $db_instance->execute_query("
    SELECT g.id, g.name, g.tag, COUNT(u.id) as member_count, SUM(u.ranking_points) as total_score
    FROM guilds g
    JOIN users u ON g.id = u.guildid
    WHERE u.status = 1
    GROUP BY g.id
    ORDER BY total_score DESC
    LIMIT ?, ?", [$offset_guilds, $rows_per_page]);

/* --- VIEW --- */
$view .= "<div class='tab' style='margin: 0 auto 10px auto; max-width: 340px;'>
    <div class='tablinks " . ($active_tab === "players" ? "active" : '') . "' data-on-click='switchRankingTab' data-tab='players' style='padding: 6px;'>Spieler</div>
    <div class='tablinks " . ($active_tab === "guilds" ? "active" : '') . "' data-on-click='switchRankingTab' data-tab='guilds' style='padding: 6px;'>Gilden</div>
</div>";

$ranking_colgroup = '
    <colgroup>
        <col style="width: 12%;">
        <col style="width: 63%;">
        <col style="width: 25%;">
    </colgroup>';

// --- CONTAINER PLAYERS ---
$view .= "<div id='ranking_players' class='js-ranking-tab' style='display: " . ($active_tab === "players" ? "block" : "none") . ";'>";
$view .= '<table class="table ranking-table">
            ' . $ranking_colgroup . '
            <tr>
                <td class="td-center td-gradient"><b>#</b></td>
                <td class="td-center td-gradient"><b>Spieler</b></td>
                <td class="td-center td-gradient"><b>Punkte</b></td>
            </tr>';

$pos = $offset_players + 1;

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

    $is_on_vacation = (!empty($row["is_vacation"]) && (int)$row["vacation_until"] > $now);
    $vacation_badge = "";

    if ($is_on_vacation) {
        $vac_end_str = date("d.m.Y H:i", $row["vacation_until"]);
        $vacation_badge = " <span class='popup' id='vac_{$row["id"]}' style='cursor: help;'>🏖️<div id='vac_{$row["id"]}_box' class='popupbox'><b>Im Urlaubsmodus</b></div>";
    }

    $player = new User($user_id, $user_name);

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
                <td class='td-expand'>" . $player->render_user("$user_link $guild_display $vacation_badge", $color, $pos) . "</td>
                <td class='td-score'>" . fnum($row["score"], true) . "</td>
            </tr>";
    $pos++;
}
$view .= "</table>";

$view .= "<div style='margin-top: 10px; opacity: 0.7;'><small>Hinweis: Punkte-Updates finden alle <b>5</b> Minuten statt.</small></div>";
$view .= render_ranking_pagination($current_page_players, $player_pages);
$view .= "</div>";

// --- CONTAINER GUILDS ---
$view .= "<div id='ranking_guilds' class='js-ranking-tab' style='display: " . ($active_tab === "guilds" ? "block" : "none") . ";'>";
$view .= '<table class="table ranking-table">
            ' . $ranking_colgroup . '
            <tr>
                <td class="td-center td-gradient"><b>#</b></td>
                <td class="td-center td-gradient"><b>Gilde</b></td>
                <td class="td-center td-gradient"><b>Punkte</b></td>
            </tr>';

$pos = $offset_guilds + 1;

if ($guilds_res->num_rows > 0) {
    foreach ($guilds_res as $row) {
        $guild_logic = new Guild($user);
        $badge = $guild_logic->render_badge($row["id"], $row["tag"], $row["name"]);

        $view .= "<tr>
            <td class='td-shrink td-center' style='text-align: right;'>$pos</td>
            <td class='td-expand' style='cursor: pointer;' data-on-click='openGuildInfo' data-id='{$row["id"]}'>
                <div style='display: flex; justify-content: space-between; align-items: center; gap: 8px;'>
                    <div style='word-break: break-word;'>$badge</div>
                    <small style='opacity: 0.6; white-space: nowrap; flex-shrink: 0;'>{$row["member_count"]} <span class='badge-hide-mobile'>" . ($row["member_count"] == 1 ? "Mitglied" : "Mitglieder") . "</span>
                    <span class='badge-hide-desktop'>Mitgl.</span></small>
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
$view .= render_ranking_pagination($current_page_guilds, $guild_pages, "guilds");
$view .= "</div>";

function render_ranking_pagination(int $current, int $total, string $tab = "players"): string
{
    if ($total <= 1) return "";

    $html = '<div class="pagination-container"><div class="pagination-bar">';

    if ($current > 1) {
        $html .= "<a href='ranking.php?tab=$tab&currentpage=1' class='page-link'>&laquo;</a>";
        $prev = $current - 1;
        $html .= "<a href='ranking.php?tab=$tab&currentpage=$prev' class='page-link'>&lsaquo;</a>";
    }
    for ($x = max(1, $current - 2); $x <= min($total, $current + 2); $x++) {
        $active = ($x == $current) ? "active" : "";
        $html .= "<a href='ranking.php?tab=$tab&currentpage=$x' class='page-link $active'>$x</a>";
    }
    if ($current < $total) {
        $next = $current + 1;
        $html .= "<a href='ranking.php?tab=$tab&currentpage=$next' class='page-link'>&rsaquo;</a>";
        $html .= "<a href='ranking.php?tab=$tab&currentpage=$total' class='page-link'>&raquo;</a>";
    }

    $html .= "</div></div>";

    return $html;
}

$title = "Rangliste";
$header = "Rangliste";
$script_files = ["userinfo", "guild", "ranking"];

include("layout/base.php");
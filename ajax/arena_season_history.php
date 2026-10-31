<?php
require_once("../includes/core.php");
$user->check_user_login();

$db = Database::get_instance()->get_connection();

$german_months = [
        1 => "Januar", 2 => "Februar", 3 => "März", 4 => "April",
        5 => "Mai", 6 => "Juni", 7 => "Juli", 8 => "August",
        9 => "September", 10 => "Oktober", 11 => "November", 12 => "Dezember"
];

// Alle vorhandenen Monate ermitteln
$months_res = $db->query("SELECT DISTINCT season_month FROM arena_season_history ORDER BY season_month DESC");
$available_months = array_column($months_res->fetch_all(MYSQLI_ASSOC), "season_month");

$selected_month = $_GET["month"] ?? ($available_months[0] ?? date("Y-m"));

$entries = [];
if (!empty($available_months)) {
    $res = $db->execute_query("
        SELECT h.*, u.username, u.guildid, g.tag AS guild_tag
        FROM arena_season_history h
        JOIN users u ON h.user_id = u.id
        LEFT JOIN guilds g ON u.guildid = g.id
        WHERE h.season_month = ?
        ORDER BY h.rank_pos
        LIMIT 50
    ", [$selected_month]);
    $entries = $res->fetch_all(MYSQLI_ASSOC);
}
?>

<div id="modal-title-data" data-title="Arena Saison-Archiv" data-width="650px" style="display: none;"></div>

<div style="padding: 10px; max-width: 650px; margin: 0 auto; text-align: left;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 8px;">
        <h3 class="title-border" style="margin: 0 auto;">Vergangene Saisons</h3>
        <?php if (!empty($available_months)): ?>
            <label>
                <select data-on-change="changeSeasonHistoryMonth">
                    <?php foreach ($available_months as $m):
                        $parts = explode('-', $m);
                        $year = $parts[0] ?? '';
                        $monthNum = (int)($parts[1] ?? 0);
                        $monthLabel = ($german_months[$monthNum] ?? $m) . " " . $year;
                        $sel = ($m === $selected_month) ? "selected" : "";
                        ?>
                        <option value="<?= $m ?>" <?= $sel ?>><?= e($monthLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
    </div>

    <?php if (empty($entries)): ?>
        <p style="text-align: center; opacity: 0.6;">Es wurden noch keine abgeschlossenen Saisons
            archiviert.</p>
    <?php else: ?>
        <table class="table" style="width: 100%;">
            <colgroup>
                <col style="width: 15%;">
                <col style="width: 55%;">
                <col style="width: 30%;">
            </colgroup>
            <tr>
                <td class="td-center td-gradient"><b>#</b></td>
                <td class="td-gradient"><b>Herrscher</b></td>
                <td class="td-center td-gradient"><b>Punkte</b></td>
            </tr>
            <?php foreach ($entries as $e):
                $rank_class = match ((int)$e["rank_pos"]) {
                    1 => "rank-gold",
                    2 => "rank-silver",
                    3 => "rank-bronze",
                    default => ""
                };
                $u_obj = new User((int)$e["user_id"], $e["username"]);
                $arena_user_link = "<a href='#' data-on-click='openArenaUserModal' 
                                                data-userid='{$e["user_id"]}' 
                                                data-username='" . e($e["username"]) . "' 
                                                class='user-name-link'>" . e($e["username"]) . "</a>";
                ?>
                <tr>
                    <td class="td-center <?= $rank_class ?>"><?= $e["rank_pos"] ?></td>
                    <td>
                        <?= $u_obj->render_user(
                                content: $arena_user_link,
                                show_guild: true,
                                guild_tag: $e["guild_tag"] ?? null,
                                guild_id: (int)($e["guildid"] ?? 0)
                        ) ?>
                    </td>
                    <td class="td-center <?= $rank_class ?>">
                        <b><?= fnum($e["elo"]) ?> ELO</b><br>
                        <small style="opacity: 0.8; font-weight: normal;">
                            <span class="passed"><?= $e["wins"] ?></span> / <span
                                    class="error"><?= $e["losses"] ?></span>
                        </small>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>
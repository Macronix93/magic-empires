<?php
require_once("../includes/core.php");
$user->check_user_login();

$target_uid = (int)($_GET["userid"] ?? 0);
if ($target_uid <= 0) {
    echo show_error_box("Ungültige Spieler-ID.");
    exit;
}

$db = Database::get_instance()->get_connection();

$u_res = $db->execute_query("SELECT id, username, guildid FROM users WHERE id = ?", [$target_uid]);
$u_data = $u_res->fetch_assoc();
if (!$u_data) {
    echo show_error_box("Spieler nicht gefunden.");
    exit;
}

$arena = new Arena($target_uid);
$p_data = $arena->get_player_data();
$hero_info = $arena->get_hero_full_data();
$h_data = $hero_info["hero"];
$active_skill = $hero_info["active_skill"];

$target_user_obj = new User($target_uid, $u_data["username"]);

$res_history = $db->execute_query("
    SELECT season_month, rank_pos, elo, wins, losses, draws, battles
    FROM arena_season_history
    WHERE user_id = ?
    ORDER BY season_month DESC
    LIMIT 3
", [$target_uid])->fetch_all(MYSQLI_ASSOC);

$elo = (int)($p_data["elo"] ?? ARENA_DEFAULT_ELO);
$wins = (int)($p_data["wins"] ?? 0);
$losses = (int)($p_data["losses"] ?? 0);
$draws = (int)($p_data["draws"] ?? 0);
$total_fights = $wins + $losses + $draws;
$winrate = $total_fights > 0 ? round(($wins / $total_fights) * 100, 1) : 0;
?>

<div id="modal-title-data" data-title="Arena-Profil: <?= e($u_data["username"]) ?>" data-width="520px"
     style="display: none;"></div>

<div style="padding: 10px; max-width: 480px; margin: 0 auto; text-align: left;">
    <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-gold); padding-bottom: 12px; margin-bottom: 15px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <img src="<?= $target_user_obj->get_avatar() ?>" class="user-image" style="width: 44px; height: 44px;"
                 alt="">
            <div>
                <b style="font-size: 18px;"><?= e($u_data["username"]) ?></b>
                <?php if ($u_data["guildid"] > 0):
                    $gtag = $db->execute_query("SELECT tag FROM guilds WHERE id = ?", [$u_data["guildid"]])->fetch_column();
                    ?>
                    <small style="color: var(--link-color);">[<?= e($gtag) ?>]</small>
                <?php endif; ?>
            </div>
        </div>
        <button type="button" data-on-click="openOverlay" data-url="userinfo.php?userid=<?= $target_uid ?>"
                data-title="Spieler-Info" style="padding: 5px 12px;">
            Spieler-Info
        </button>
    </div>
    <div class="tech-info-box" style="margin-bottom: 15px;">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <span>Held: <b class="passed">Stufe <?= $h_data["level"] ?></b></span>
            <span>Spezialisierung: <b
                        style="color: var(--link-color);"><?= e($active_skill["name"]) ?></b> (+<?= round($active_skill["rps_bonus"] * 100, 1) ?>%)</span>
        </div>
    </div>
    <div class="box-container" style="margin-bottom: 20px;">
        <div class="box-header">Aktuelle Saison</div>
        <div class="box-content box-content-bg" style="padding: 12px;">
            <div class="split-content" style="margin-bottom: 6px;">
                <span>Wertung:</span>
                <b style="color: var(--link-color); font-size: 18px;"><?= fnum($elo) ?> ELO</b>
            </div>
            <div class="split-content" style="margin-bottom: 6px;">
                <span>Bilanz (S / N / U):</span>
                <b><span class="passed"><?= $wins ?></span> / <span class="error"><?= $losses ?></span> /
                    <span><?= $draws ?></span></b>
            </div>
            <div class="split-content">
                <span>Siegquote:</span>
                <b><?= $winrate ?>% <small style="opacity: 0.7;">(<?= $total_fights ?> Duelle)</small></b>
            </div>
        </div>
    </div>
    <div class="title-border" style="font-size: 14px; margin-top: 15px;">Platzierungen der letzten 3 Monate</div>
    <?php if (empty($res_history)): ?>
        <p style="text-align: center; opacity: 0.6; font-size: 13px;">Noch keine vergangenen Saisons aufgezeichnet.</p>
    <?php else: ?>
        <table class="table" style="width: 100%; font-size: 13px; margin-bottom: 10px;">
            <tr>
                <td class="td-gradient"><b>Saison</b></td>
                <td class="td-gradient td-center"><b>Platz</b></td>
                <td class="td-gradient td-center"><b>ELO</b></td>
                <td class="td-gradient td-center"><b>Bilanz (S/N)</b></td>
            </tr>
            <?php foreach ($res_history as $h):
                $date_obj = DateTime::createFromFormat('Y-m', $h["season_month"]);
                $month_label = $date_obj ? $date_obj->format('m/Y') : $h["season_month"];
                $r_class = match ((int)$h["rank_pos"]) {
                    1 => "rank-gold",
                    2 => "rank-silver",
                    3 => "rank-bronze",
                    default => ""
                };
                ?>
                <tr>
                    <td><b><?= e($month_label) ?></b></td>
                    <td class="td-center <?= $r_class ?>">#<?= $h["rank_pos"] ?></td>
                    <td class="td-center"><b><?= fnum($h["elo"]) ?></b></td>
                    <td class="td-center">
                        <span class="passed"><?= $h["wins"] ?></span> / <span class="error"><?= $h["losses"] ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>
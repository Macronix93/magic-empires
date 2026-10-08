<?php
require_once("includes/core.php");

$user->check_user_login();
$uid = $user->get_user_id();
$current_coins = $user->get_user_coins();
$coin_limit = $user->get_coin_limit();
$user_gender = $user->get_gender();

Achievement::check($uid);
$all_achs = Achievement::get_all_with_status($uid);

$total_count = count($all_achs);
$unlocked_count = count(array_filter($all_achs, fn($a) => (bool)$a["is_unlocked"]));
$percentage = ($total_count > 0) ? round(($unlocked_count / $total_count) * 100, 1) : 0;

$categories = AchievementCategories::get_labels();
$player_metrics = Achievement::get_player_metrics($uid);

$grouped = [];
foreach ($all_achs as $a) {
    $cat = $a["category"];
    $grouped[$cat][] = $a;
}

/*
 * VIEW
 */
$view = "<div id='achievement-coin-meta' data-coins='$current_coins' data-limit='$coin_limit' style='display: none;'></div>";

$view .= "
<div class='box-container' style='max-width: 600px; margin: 0 auto 20px auto;'>
    <div class='box-header'>Gesamt-Fortschritt</div>
    <div class='box-content box-content-bg' style='padding: 15px;'>
        <div class='split-content' style='font-size: 16px; margin-bottom: 5px;'>
            <span>Freigeschaltet:</span>
            <b>$unlocked_count / $total_count <span style='opacity: 0.7;'>($percentage %)</span></b>
        </div>
        <div class='tick-progress-bg' style='height: 10px;'>
            <div class='tick-progress-fill' style='width: $percentage%;'></div>
        </div>
    </div>
</div>
";

foreach ($grouped as $catKey => $items) {
    $catLabel = $categories[$catKey] ?? "Allgemein";
    $view .= "<div class='title-border'>$catLabel</div>";
    $view .= "<table class='table' style='width: 100%; max-width: 650px; margin-bottom: 10px;'>";
    $view .= "
        <colgroup>
            <col class='ach-status'>
            <col class='ach-title'>
            <col class='ach-reward'>
        </colgroup>
        <tr>
            <td class='td-gradient' colspan='2'><b>Errungenschaft & Titel</b></td>
            <td class='td-center td-gradient'><b>Belohnung</b></td>
        </tr>";

    foreach ($items as $ach) {
        $is_unlocked = (bool)$ach["is_unlocked"];
        $is_secret = ((int)$ach["is_secret"] === 1 && !$is_unlocked);
        $is_claimed = (bool)$ach["is_claimed"];
        $reward = (int)$ach["reward_coins"];

        $status_icon = $is_unlocked
            ? "<img src='images/icons/icon_checked.png' class='ressource-icons' alt='Erreicht' title='Freigeschaltet am " . date("d.m.Y H:i:s", $ach["unlocked_at"]) . "'>"
            : "<img src='images/icons/icon_lock.png' class='ressource-icons' alt='Gesperrt'>";

        $name = $is_secret ? "???" : "<i>" . e($ach["name"]) . "</i>";
        $desc = $is_secret ? "<i>Diese Errungenschaft ist geheim.</i>" : e($ach["description"]);
        $ach_title = ($user_gender === 'f' && !empty($ach["title_f"])) ? $ach["title_f"] : $ach["title"];
        $title_tag = $is_secret
            ? "<small style='opacity: 0.6;'>Titel: ???</small>"
            : "<small " . ($is_unlocked ? "class='passed'" : "") . " style='font-weight: bold;'>Titel: &bdquo;" . e($ach_title) . "&ldquo;</small>";

        $reward = (int)$ach["reward_coins"];
        $reward_display = ($reward > 0)
            ? get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " <b>+$reward</b>"
            : "<span style='opacity: 0.5;'>-</span>";


        $reward_text_and_coin =
            "<span style='display: flex; justify-content: center; align-items: center; gap: 5px;'>" .
            get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) .
            "<b>" . (($is_unlocked && $is_claimed) ? $reward : "+$reward") . "</b>" .
            "</span>";

        if ($is_secret) {
            $reward_display = "???";
        } else if ($reward > 0) {
            if ($is_unlocked && !$is_claimed) {
                $reward_display = "
                <div id='claim-wrap-{$ach["id"]}' style='display: flex; flex-direction: column; align-items: center; gap: 5px;'>
                    $reward_text_and_coin
                    <button type='button' 
                            class='btn-accept' 
                            data-on-click='claimAchievement' 
                            data-id='{$ach["id"]}' 
                            data-reward='$reward'>
                        Abholen
                    </button>
                </div>";
            } else {
                $reward_display = $reward_text_and_coin;
            }
        } else {
            $reward_display = "<span style='opacity: 0.5;'>-</span>";
        }

        $progress_html = "";
        if (!$is_secret) {
            $req_type = (int)$ach["req_type"];
            $req_val = (int)$ach["req_value"];
            $current_val = $player_metrics[$req_type] ?? 0;

            if ($req_val > 1) {
                $cur_capped = min($current_val, $req_val);
                $prog_percent = min(100, round(($cur_capped / $req_val) * 100, 1));
                $bar_text = fnum($cur_capped) . " / " . fnum($req_val) . " <span style='opacity: 0.7;'>(" . fdec($prog_percent) . "%)</span>";

                $progress_html = "
                <div style='margin-top: 8px;'>
                    <div style='display: flex; justify-content: space-between; align-items: baseline; font-size: 11px; margin-bottom: 3px;'>
                        <span style='opacity: 0.7;'>Fortschritt:</span>
                        <b>$bar_text</b>
                    </div>
                    <div class='tick-progress-bg' style='height: 6px;'>
                        <div class='tick-progress-fill' style='width: $prog_percent%;'></div>
                    </div>
                </div>";
            }
        }

        $unlocked_at = "<div class='unlocked-at' style='font-style: italic; opacity: 0.7; margin-top: 15px;'>Freigeschaltet am " . date("d.m.Y H:i:s", $ach["unlocked_at"]) . "</div>";

        $view .= "
        <tr>
            <td class='td-center' style='vertical-align: middle;'>$status_icon</td>
            <td style='padding: 10px;'>
                <div style='margin-bottom: 10px;'><b class='ach-name'>$name</b></div>
                <span class='ach-desc'>$desc</span><br>
                $title_tag
                " . (!$is_unlocked ? $progress_html : $unlocked_at) . "
            </td>
            <td class='td-center' style='vertical-align: middle;'>$reward_display</td>
        </tr>";
    }

    $view .= "</table>";
}

/*
 * HTML Section
 */
$title = "Errungenschaften";
$header = "Errungenschaften";

$script_files = ["achievements"];

include("layout/base.php");
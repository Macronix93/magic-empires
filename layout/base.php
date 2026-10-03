<?php
if (IS_DEV) {
    $js_folder = "js_src/";
} else {
    $js_folder = "js/";
}

$js_suffix = ".js";
$js_main_file = "main.js";

$kingdom_count = 0;
$all_user_kingdoms = [];
$sidebar_data = [
        "has_world_event" => false,
        "market_offers" => 0,
        "guild_status" => '',
        "alchemy_status" => ''
];

if (empty($header_icon)) {
    if (isset($building) && is_object($building) && method_exists($building, 'get_building_id')) {
        $header_icon = "images/icons/icon_building" . $building->get_building_id() . ".png";
    } else {
        $current_file = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $header_icon = match ($current_file) {
            'overview.php' => 'images/icons/icon_buildings.png',
            'map.php' => 'images/icons/icon_tech6.png',
            'messages.php' => isset($_GET["worldchat"]) ? 'images/icons/icon_worldchat.png' : 'images/icons/icon_messages.png',
            'guild.php' => 'images/icons/icon_guild.png',
            'ranking.php' => 'images/icons/icon_ranking.png',
            'techtree.php' => 'images/icons/icon_techtree.png',
            'warsim.php' => 'images/icons/icon_warsim.png',
            'halloffame.php' => 'images/icons/icon_score.png',
            'events.php' => 'images/icons/icon_lich.png',
            'stats.php' => 'images/icons/icon_statistics.png',
            'adminpanel.php' => 'images/icons/icon_adminpanel.png',
            'news.php' => 'images/icons/icon_news.png',
            'settings.php' => 'images/icons/icon_settings.png',
            'rules.php' => 'images/icons/icon_rules.png',
            'faq.php' => 'images/icons/icon_faq.png',
            'donations.php' => 'images/icons/icon_guildtech1.png',
            'suggestions.php' => 'images/icons/icon_feedback.png',
            'support.php' => 'images/icons/icon_question.png',
            'disclaimer.php' => 'images/icons/icon_disclaimer.png',
            'imprint.php' => 'images/icons/icon_imprint.png',
            'privacy.php' => 'images/icons/icon_privacy.png',
            default => null,
        };
    }
}

if ($user->is_logged_in()) {
    if (in_array("userinfo", $script_files ?? [])
            && !in_array("sendtroops", $script_files ?? [])) {
        $script_files[] = "sendtroops";
    }

    $show_attack_alert = false;
    $show_support_alert = false;
    $uid = $user->get_user_id();
    $gid = $user->get_user_guild_id();

    $all_user_kingdoms = $db_instance->execute_query(
            "SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ? ORDER BY created_at",
            [$uid]
    )->fetch_all(MYSQLI_ASSOC);

    $kingdom_count = count($all_user_kingdoms);

    $res_sidebar = $db_instance->execute_query("
        SELECT 
            (SELECT 1 FROM world_events WHERE is_active = 1 AND end_time > UNIX_TIMESTAMP() LIMIT 1) AS has_event,
            (SELECT COUNT(*) FROM marketplace) AS market_count,
            (SELECT 1 FROM events WHERE guild_id = ? AND actionid = " . ActionTypes::ACTION_RESEARCH_TECH . " LIMIT 1) AS guild_research_active,
            (SELECT gtl.name FROM guild_projects gp JOIN guild_tech_list gtl ON gp.tech_id = gtl.id WHERE gp.guild_id = ? LIMIT 1) AS guild_project_name,
            (SELECT CASE 
                WHEN input_amount = 0 AND output_amount >= 1 THEN 'ready'
                WHEN input_amount > 0 THEN 'running'
                ELSE ''
             END FROM kingdom_alchemy WHERE kingdom_id = ? LIMIT 1) AS alchemy_status
    ", [$gid, $gid, $user->get_current_kingdom()])->fetch_assoc();

    $sidebar_data["has_world_event"] = !empty($res_sidebar["has_event"]);
    $sidebar_data["market_offers"] = (int)($res_sidebar["market_count"] ?? 0);
    $sidebar_data["alchemy_status"] = $res_sidebar["alchemy_status"] ?? '';

    if ($gid > 0) {
        if (!empty($res_sidebar["guild_research_active"])) {
            $sidebar_data["guild_status"] = '<img src="images/icons/icon_time.png" class="ressource-icons" title="Gildenforschung läuft..." alt="Forschung">';
        } elseif (!empty($res_sidebar["guild_project_name"])) {
            $sidebar_data["guild_status"] = '<img src="images/icons/icon_hammer.png" class="ressource-icons" title="Projekt aktiv: ' . e($res_sidebar["guild_project_name"]) . '" alt="Projekt">';
        }
    }

    $ack_ids = $_SESSION["acknowledged_attacks"] ?? [];
    $ack_sup_ids = $_SESSION["acknowledged_supports"] ?? [];

    $unacknowledged_attacks = !empty($_SESSION["active_attacks"])
            ? count(array_filter($_SESSION["active_attacks"], fn($atk) => $atk["is_new"] && !in_array($atk["eventid"], $ack_ids)))
            : 0;

    $unacknowledged_supports = !empty($_SESSION["active_supports"])
            ? count(array_filter($_SESSION["active_supports"], fn($sup) => !in_array($sup["eventid"], $ack_sup_ids)))
            : 0;

    $show_attack_alert = ($unacknowledged_attacks > 0);
    $show_support_alert = ($unacknowledged_supports > 0);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE-edge">
    <meta name="viewport" content="width=device-width, initial-scale:1.0">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico" id="icon">
    <link rel="stylesheet" type="text/css"
          href="styles.css?v=<?= file_exists("styles.css") ? filemtime("styles.css") : 1 ?>">
    <title>
        <?php
        if (IS_DEV) {
            echo "[LOKAL]" . (!empty($title) ? " - $title" : "");
        } else {
            echo((!empty($title)) ? "Magic Empires - $title" : "Magic Empires");
        }
        ?>
    </title>
    <noscript>
        <meta http-equiv="refresh" content="0;url=nojs.php">
    </noscript>
    <?php
    $main_js_path = $js_folder . $js_main_file;
    $main_v = file_exists($main_js_path) ? filemtime($main_js_path) : time();
    ?>
    <script type="text/javascript" src="<?= $main_js_path ?>?v=<?= $main_v ?>" defer></script>

    <?php
    if (!empty($script_files)) {
        foreach ($script_files as $script_file) {
            $path = $js_folder . $script_file . $js_suffix;
            $v = file_exists($path) ? filemtime($path) : time();
            echo '<script type="text/javascript" src="' . $path . '?v=' . $v . '" defer></script>';
        }
    }
    echo $head_extra ?? '';
    ?>
</head>
<body class="preload logged-in"
        <?php
        if (!isset($_COOKIE["me_remember"])) {
            echo 'data-timeout="' . TIMEOUT_MAX_SECONDS . '"';
        }
        ?>
      data-server-time="<?= time(); ?>"
      data-flash-limit="<?= TITLE_FLASH_SECONDS ?>"
>
<?php if (isset($show_attack_alert) && $show_attack_alert): ?>
    <?php
    $sync_offset = fmod(microtime(true), 2);
    ?>
    <div class="attack-alert-overlay" style="animation-delay: -<?= $sync_offset; ?>s;"></div>
<?php endif; ?>

<?php if (isset($show_support_alert) && $show_support_alert): ?>
    <?php
    $sync_offset = fmod(microtime(true), 2);
    ?>
    <div class="support-alert-overlay" style="animation-delay: -<?= $sync_offset; ?>s;"></div>
<?php endif; ?>
<div class="header img">
    <img src="images/header.png" alt="Header"/>
</div>
<div class="content-box">
    <div class="left-container">
        <?php include_once("layout/left.php"); ?>
    </div>
    <div class="middle-container">
        <div class="big-box-container">
            <div class="big-box-header">
                <?php if (!empty($header_icon) && file_exists($header_icon)): ?>
                    <img src="<?= e($header_icon) ?>" class="header-icon" alt="">
                <?php endif; ?>
                <?= $header ?? 'Default Header'; ?>
            </div>
            <div class="big-box-content">
                <?php
                $success_msg = $_SESSION["game_success"] ?? $_SESSION["admin_flash_msg"] ?? $_SESSION["guild_success"] ?? $_SESSION["support_success"] ?? null;
                if ($success_msg) {
                    echo show_passed_box($success_msg);
                    unset($_SESSION["game_success"], $_SESSION["admin_flash_msg"], $_SESSION["guild_success"], $_SESSION["support_success"]);
                }

                $error_msg = $_SESSION["game_error"] ?? $_SESSION["guild_error"] ?? null;
                if ($error_msg) {
                    echo show_error_box($error_msg);
                    unset($_SESSION["game_error"], $_SESSION["guild_error"]);
                }
                ?>
                <?= $view ?? "Default Content"; ?>
            </div>
        </div>
    </div>
    <div class="right-container">
        <?php include_once("layout/right.php"); ?>
    </div>
</div>
<div id="nav-left-trigger" class="mobile-trigger">
    <p>&#9776;</p>
    <?php if ($user->is_logged_in()):
        $has_mobile_alert = (!empty($show_attack_alert) || !empty($show_support_alert)); ?>
        <span class="nav-notification-dot"
              id="mobile-nav-dot"
              data-has-alert="<?= $has_mobile_alert ? "true" : "false" ?>"
              style="<?= ($user->get_unread_messages() > 0 || $has_mobile_alert) ? '' : "display: none;" ?>">
        </span>
    <?php endif; ?>
</div>
<div id="nav-left-menu" class="mobile-side-nav">
    <?php include("layout/left.php"); ?>
</div>
<?php if ($kingdom_count > 1): ?>
    <?php
    $cur_k_id = $user->get_current_kingdom();
    $cur_kname = "";
    $cur_pos = 1;

    foreach ($all_user_kingdoms as $idx => $k) {
        if ($k["id"] == $cur_k_id) {
            $cur_pos = $idx + 1;
            $cur_kname = $k["kingdomname"];
            break;
        }
    }
    ?>
    <div class="mobile-nav-arrow" style="left: 60px; top: 1px;" data-on-click="switchKingdomPrev">
        <span class="arrow-nav-symbol" data-on-click="switchKingdomPrev" title="Vorheriges Königreich">❮</span>
    </div>
    <div class="mobile-kingdom-display" style="top: 1px;" data-on-click="toggleMobileKingdomMenu">
        <span class="mobile-kingdom-title"><?= $cur_pos ?> - <?= e($cur_kname) ?> <span
                    style="font-size: 10px; opacity: 0.7;">▾</span></span>
    </div>
    <div id="mobile-kingdom-dropdown" class="mobile-kingdom-dropdown">
        <?php
        foreach ($all_user_kingdoms as $idx => $m_k):
            $pos = $idx + 1;
            $is_active = ($m_k["id"] == $cur_k_id);
            ?>
            <div class="mobile-kingdom-dropdown-item<?= $is_active ? ' active' : '' ?>"
                 data-on-click="selectMobileKingdom"
                 data-id="<?= $m_k["id"] ?>">
                <span class="mobile-dropdown-kname"><?= $pos ?> - <?= e($m_k["kingdomname"]) ?></span>
                <span class="mobile-dropdown-coords"><?= $m_k["mapx"] ?>:<?= $m_k["mapy"] ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="mobile-nav-arrow" style="right: 60px; top: 1px;" data-on-click="switchKingdomNext">
        <span class="arrow-nav-symbol" data-on-click="switchKingdomNext" title="Nächstes Königreich">❯</span>
    </div>
<?php endif; ?>
<div id="nav-right-trigger" class="mobile-trigger">
    <p><?= wrap_emojis("🏰") ?></p>
</div>
<div id="nav-right-menu" class="mobile-side-nav">
    <?php include("layout/right.php"); ?>
</div>
<div id="onpage-overlay" class="overlay-modal" style="display: none;">
    <div id="overlay-handle" class="overlay-header">
        <span id="overlay-title"></span>
        <button data-on-click="closeOverlay" class="overlay-close-btn">&times;</button>
    </div>
    <div id="overlay-content-body" class="overlay-body">
        <div class="spinner"></div>
    </div>
</div>
</body>
</html>
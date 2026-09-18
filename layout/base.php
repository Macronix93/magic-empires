<?php
if (IS_DEV) {
    $js_folder = "js_src/";
} else {
    $js_folder = "js/";
}

$js_suffix = ".js";
$js_main_file = "main.js";

$kingdom_count = 0;
if ($user->is_logged_in()) {
    $show_attack_alert = false;
    $show_support_alert = false;

    $ack_ids = $_SESSION["acknowledged_attacks"] ?? [];
    $ack_sup_ids = $_SESSION["acknowledged_supports"] ?? [];

    if (!empty($_SESSION["active_attacks"])) {
        foreach ($_SESSION["active_attacks"] as $atk) {
            if ($atk["is_new"] && !in_array($atk["eventid"], $ack_ids)) {
                $show_attack_alert = true;
                break;
            }
        }
    }

    if (!empty($_SESSION["active_supports"])) {
        foreach ($_SESSION["active_supports"] as $sup) {
            if (!in_array($sup["eventid"], $ack_sup_ids)) {
                $show_support_alert = true;
                break;
            }
        }
    }

    $kingdom_count = $user->count_user_kingdoms();
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
<body class="preload"
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
                <?= $view ?? 'Default Content'; ?>
            </div>
        </div>
    </div>
    <div class="right-container">
        <?php include_once("layout/right.php"); ?>
    </div>
</div>
<div id="nav-left-trigger" class="mobile-trigger">
    <p>&#9776;</p>
    <?php
    $total_unread = ($user->is_logged_in()) ? $user->get_unread_messages() : 0;
    if ($total_unread > 0): ?>
        <span class="nav-notification-dot" id="mobile-nav-dot"></span>
    <?php endif; ?>
</div>
<div id="nav-left-menu" class="mobile-side-nav">
    <?php include("layout/left.php"); ?>
</div>
<?php if ($kingdom_count > 1): ?>
    <?php
    $all_mobile_kingdoms = $db_instance->execute_query(
            "SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ? ORDER BY created_at",
            [$user->get_user_id()]
    )->fetch_all(MYSQLI_ASSOC);

    $cur_k_id = $user->get_current_kingdom();
    $cur_kname = "";
    $cur_pos = 1;

    foreach ($all_mobile_kingdoms as $idx => $k) {
        if ($k["id"] == $cur_k_id) {
            $cur_pos = $idx + 1;
            $cur_kname = $k["kingdomname"];
            break;
        }
    }
    ?>
    <div class="mobile-nav-arrow" style="left: 60px; top: 1px;" data-on-click="switchKingdomPrev">
        <img src="images/icons/icon_right_slow.png"
             class="arrow-nav arrow-left"
             data-on-click="switchKingdomPrev"
             title="Vorheriges Königreich" alt="">
    </div>
    <div class="mobile-kingdom-display" style="top: 1px;" data-on-click="toggleMobileKingdomMenu">
        <span class="mobile-kingdom-title"><?= $cur_pos ?> - <?= e($cur_kname) ?> <span
                    style="font-size: 10px; opacity: 0.7;">▾</span></span>
    </div>
    <div id="mobile-kingdom-dropdown" class="mobile-kingdom-dropdown">
        <?php
        foreach ($all_mobile_kingdoms as $idx => $m_k):
            $pos = $idx + 1;
            $is_active = ($m_k["id"] == $cur_k_id);
            ?>
            <div class="mobile-kingdom-dropdown-item<?= $is_active ? ' active' : '' ?>"
                 data-on-click="selectMobileKingdom"
                 data-id="<?= $m_k["id"] ?>">
                <span class="mobile-dropdown-kname"><?= $pos ?> - <?= e($m_k["kingdomname"]) ?></span>
                <span class="mobile-dropdown-coords">(<?= $m_k["mapx"] ?>:<?= $m_k["mapy"] ?>)</span>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="mobile-nav-arrow" style="right: 60px; top: 1px;" data-on-click="switchKingdomNext">
        <img src="images/icons/icon_right_slow.png"
             class="arrow-nav"
             data-on-click="switchKingdomNext"
             title="Nächstes Königreich" alt="">
    </div>
<?php endif; ?>
<div id="nav-right-trigger" class="mobile-trigger">
    <p>&#127984;</p>
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
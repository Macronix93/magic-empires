<?php
if (IS_DEV) {
    $js_folder = "js_src/";
} else {
    $js_folder = "js/";
}

$js_suffix = ".js";
$js_main_file = "main.js";

if ($user->is_logged_in()) {
    $show_attack_alert = false;

    $ack_ids = $_SESSION["acknowledged_attacks"] ?? [];

    if (!empty($_SESSION["active_attacks"])) {
        foreach ($_SESSION["active_attacks"] as $atk) {
            if ($atk["is_new"] && !in_array($atk["eventid"], $ack_ids)) {
                $show_attack_alert = true;
                break;
            }
        }
    }
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
      data-server-time="<?php echo time(); ?>"
>
<?php if (isset($show_attack_alert) && $show_attack_alert): ?>
    <?php
    $sync_offset = fmod(microtime(true), 2);
    ?>
    <div class="attack-alert-overlay" style="animation-delay: -<?php echo $sync_offset; ?>s;"></div>
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
                <?php echo $header ?? 'Default Header'; ?>
            </div>
            <div class="big-box-content">
                <?php echo $view ?? 'Default Content'; ?>
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
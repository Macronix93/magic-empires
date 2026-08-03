<?php
$show_attack_alert = false;

if (IS_DEV) {
    $js_folder = "js_src/";
} else {
    $js_folder = "js/";
}

$js_suffix = ".js";
$js_main_file = "main.js";

if ($user->is_logged_in()) {
    $now = time();
    $my_uid = $user->get_user_id();

    $ack_ids = $_SESSION["acknowledged_attacks"] ?? [];
    $not_in_clause = "";

    if (!empty($ack_ids)) {
        $clean_ids = array_map("intval", $ack_ids);
        $not_in_clause = "AND e.eventid NOT IN (" . implode(',', $clean_ids) . ")";
    }

    $q_alert = "
        SELECT e.eventid
        FROM events e
        JOIN kingdoms k ON e.targetid = k.id
        JOIN buildings b ON k.id = b.kingdomid AND b.buildingid = ?
        WHERE k.userid = ? 
          AND e.actionid = ?
          AND e.is_processing = 0
          AND e.arrivaltime > ?
          AND (e.arrivaltime - ?) <= (b.buildinglevel * ?)
          $not_in_clause
        LIMIT 1
    ";

    $res_alert = $db_instance->execute_query($q_alert, [
            BuildingTypes::BUILDING_WATCHTOWER,
            $my_uid,
            ActionTypes::ACTION_SEND_TROOPS,
            $now,
            $now,
            WATCHTOWER_DETECTION_PER_LEVEL
    ]);

    if ($res_alert->num_rows > 0) {
        $show_attack_alert = true;
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
    <link rel="stylesheet" type="text/css" href="styles.css">
    <title><?php echo (!empty($title)) ? "Magic Empires - $title" : "Magic Empires"; ?></title>
    <noscript>
        <meta http-equiv="refresh" content="0;url=nojs.php">
    </noscript>
    <script type="text/javascript" src="<?= $js_folder . $js_main_file ?>" defer></script>
    <?php
    if (!empty($script_files)) {
        foreach ($script_files as $script_file) {
            echo '<script type="text/javascript" src="' . $js_folder . $script_file . $js_suffix . '" defer></script>';
        }
    }
    echo $head_extra ?? '';
    ?>
</head>
<body
        <?php
        if (!isset($_COOKIE["me_remember"])) {
            echo 'data-timeout="' . TIMEOUT_MAX_SECONDS . '"';
        }
        ?>
        data-server-time="<?php echo time(); ?>"
>
<?php if (isset($show_attack_alert) && $show_attack_alert): ?>
    <div class="attack-alert-overlay"></div>
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
        <span class="nav-notification-dot"></span>
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
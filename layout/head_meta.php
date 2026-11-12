<?php
$meta_theme_color = WINTER_THEME_ACTIVE ? "#101a26" : "#2c1f12";
?>
<meta charset="UTF-8">
<meta http-equiv="X-UA-Compatible" content="IE-edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= get_csrf_token() ?>">
<meta name="chat-emojis" content="<?= e(json_encode(Messages::get_chat_emojis(), JSON_UNESCAPED_UNICODE)) ?>">
<meta name="theme-color" content="<?= $meta_theme_color ?>">
<meta name="msapplication-navbutton-color" content="<?= $meta_theme_color ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="icon" type="image/x-icon" href="images/favicon.ico" id="icon">
<link rel="stylesheet" type="text/css"
      href="styles.css?v=<?= file_exists("styles.css") ? filemtime("styles.css") : 1 ?>">

<?php if (WINTER_THEME_ACTIVE): ?>
    <link rel="preload" href="images/winter/background.jpg" as="image">
    <link rel="stylesheet" type="text/css"
          href="themes/winter.css?v=<?= file_exists("themes/winter.css") ? filemtime("themes/winter.css") : 1 ?>">
    <?php if (($_COOKIE["me_show_snow"] ?? "1") === "1"): ?>
        <script type="text/javascript" src="<?= $js_folder ?>snow.js" defer></script>
    <?php endif; ?>
<?php else: ?>
    <link rel="preload" href="images/background.jpg" as="image">
<?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Color+Emoji&display=swap" rel="stylesheet">

<noscript>
    <meta http-equiv="refresh" content="0;url=nojs.php">
</noscript>
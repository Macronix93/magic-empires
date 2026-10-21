<?php
require_once("includes/core.php");

if (!$user->is_logged_in()) {
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>Magic Empires - Login erforderlich</title>
        <link rel="stylesheet" href="styles.css?v=<?= file_exists("styles.css") ? filemtime("styles.css") : 1 ?>">
        <style>
            body {
                background: #1a120b;
                color: #e6dcce;
                font-family: Georgia, serif;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
                text-align: center;
            }

            .login-hint {
                background: rgba(45, 42, 38, 0.95);
                border: 2px solid var(--border-gold);
                border-radius: 6px;
                padding: 15px 25px;
            }
        </style>
    </head>
    <body>
    <div class="login-hint">
        <span>Du musst im Spiel eingeloggt sein, um den Welt-Chat zu nutzen.</span><br><br>
        <a href="index.php" target="_blank"
           style="color: var(--link-color); text-decoration: underline; font-weight: bold;">
            Zum Spiel-Login ↗
        </a>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$messages = new Messages($user);

$js_folder = IS_DEV ? "js_src/" : "js/";

$main_js = $js_folder . "main.js";
$timer_js = $js_folder . "timer.js";
$chat_js = $js_folder . "chat.js";

$main_v = file_exists($main_js) ? filemtime($main_js) : time();
$timer_v = file_exists($timer_js) ? filemtime($timer_js) : time();
$chat_v = file_exists($chat_js) ? filemtime($chat_js) : time();
$css_v = file_exists("styles.css") ? filemtime("styles.css") : 1;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Magic Empires - Welt-Chat</title>
    <link rel="stylesheet" href="styles.css?v=<?= $css_v ?>">
    <script src="<?= $main_js ?>?v=<?= $main_v ?>" defer></script>
    <script src="<?= $timer_js ?>?v=<?= $timer_v ?>" defer></script>
    <script src="<?= $chat_js ?>?v=<?= $chat_v ?>" defer></script>
    <style>
        html::before {
            display: none !important;
            background: none !important;
        }

        html, body {
            height: 100%;
            margin: 0;
            padding: 8px;
            box-sizing: border-box;
            background: #261a0e !important;
            overflow: hidden;
        }

        #tab_worldchat {
            display: flex !important;
            flex-direction: column;
            height: 100%;
            width: 100%;
            box-sizing: border-box;
        }

        #chat-loading-wrapper {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
            position: relative;
            border: 1px solid var(--border-gold);
            overflow: hidden;
        }

        #messages-section {
            flex: 1 1 auto;
            height: 100% !important;
            min-height: 0 !important;
            max-height: none !important;
            font-size: 18px;
            overflow-y: auto;
        }

        #newmessage-section {
            flex: 0 0 auto;
            margin-top: 8px;
        }

        #newmessage-section form {
            margin: 0;
        }

        #newmessage-section input[type="button"] {
            font-size: 13px !important;
            padding: 5px 12px;
        }

        :not(html):not(body) {
            scrollbar-width: thin;
        }

        .sender-bubble,
        .receiver-bubble {
            max-width: 700px;
        }

        @media screen and (max-width: 600px) {
            #messages-section {
                font-size: 13px;
            }

            #tab_worldchat {
                height: 80%;
            }
        }
    </style>
</head>
<body class="logged-in" data-server-time="<?= time(); ?>" data-flash-limit="120">
<div id="tab_worldchat">
    <?= $messages->show_world_chat(); ?>
</div>
</body>
</html>
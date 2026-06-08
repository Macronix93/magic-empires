<?php
// Wir laden die core.php nicht komplett, um Redirect-Schleifen zu verhindern,
// aber wir brauchen die Konstanten für das Design.
require_once("includes/core.php");
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <title>Magic Empires - JavaScript benötigt</title>
    <style>
        .nojs-container {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .nojs-box {
            max-width: 600px;
            text-align: center;
        }

        .warning-icon {
            font-size: 50px;
            margin-bottom: 20px;
            display: block;
        }

        .retry-button {
            display: inline-block;
            padding: 10px 20px;
            text-decoration: none;
        }
    </style>
</head>
<body>
<div class="header img">
    <img src="images/header.png" alt="Header"/>
</div>

<div class="nojs-container">
    <div class="big-box-container nojs-box">
        <div class="big-box-header">
            Magie blockiert!
        </div>
        <div class="big-box-content">
            <span class="warning-icon">📜</span>
            <p>Seid gegrüßt, Reisender.</p>
            <p>Um die Welt von <strong>Magic Empires</strong> betreten zu können, benötigt dein Browser die Kraft von
                <strong>JavaScript</strong>.</p>

            <div class="info-box event-error" style="margin-top: 20px; text-align: left;">
                    <span>
                        <strong>Warum ist das nötig?</strong><br>
                        - Die Echtzeit-Timer für deine Gebäude.<br>
                        - Die interaktive Weltkarte.<br>
                        - Der Live-Chat mit anderen Herrschern.
                    </span>
            </div>

            <p style="margin-top: 20px;">Bitte aktiviere JavaScript in deinen Browser-Einstellungen und kehre dann in
                dein Königreich zurück.</p>

            <a href="index.php" class="retry-button">
                <button type="button">Erneut versuchen</button>
            </a>

            <p style="font-size: 12px; margin-top: 15px; opacity: 0.6;">
                <a href="https://www.enable-javascript.com/de/" target="_blank" style="color: var(--link-color);">
                    Hilfe zur Aktivierung von JavaScript
                </a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
<?php
require_once("includes/core.php");

if (!MAINTENANCE_MODE) {
    change_location("overview.php");
    exit;
}

$reason = !empty(MAINTENANCE_REASON) ? e(MAINTENANCE_REASON) : "Wartungsarbeiten & Updates";
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="30">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="styles.css?v=<?= file_exists("styles.css") ? filemtime("styles.css") : 1 ?>" rel="stylesheet">
    <title>Magic Empires - Wartungsarbeiten</title>
</head>
<body>
<div class="maintenance-container">
    <div class="maintenance-box">
        <h2 style="color: rgb(212, 175, 55); margin-top: 0;">Wartungsmodus aktiv</h2>
        <p>Die Pforten des Reiches sind vorübergehend geschlossen.</p>
        <div class="info-box event-warning" style="margin: 20px 0; justify-content: center;">
            <span><b>Grund:</b> <?= $reason ?></span>
        </div>
        <p style="font-size: 14px; opacity: 0.8;">
            Dein Account bleibt eingeloggt. Diese Seite aktualisiert sich automatisch alle 30 Sekunden.
        </p>
        <a href="index.php?logout">
            <button type="button" style="padding: 8px 25px;">
                Ausloggen
            </button>
        </a>
    </div>
</div>
</body>
</html>
<?php
if (empty($header_icon)) {
    $current_file = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $header_icon = match ($current_file) {
        'faq.php' => 'images/icons/icon_faq.png',
        'rules.php' => 'images/icons/icon_rules.png',
        'news.php' => 'images/icons/icon_news.png',
        'disclaimer.php' => 'images/icons/icon_disclaimer.png',
        'imprint.php' => 'images/icons/icon_imprint.png',
        'privacy.php' => 'images/icons/icon_privacy.png',
        default => null,
    };
}
?>
<!DOCTYPE html>
<html lang="de">
<?php include_once("layout/head.php"); ?>
<body>
<?php include_once("layout/banner.html"); ?>

<div class="middle-container" style="margin: auto; width: 900px; max-width: 98%;">
    <div class="big-box-container">
        <div class="big-box-header">
            <?php if (!empty($header_icon) && file_exists($header_icon)): ?>
                <img src="<?= e($header_icon) ?>" class="header-icon" alt="">
            <?php endif; ?>
            <?= $header ?? "Information" ?>
        </div>
        <div class="big-box-content">
            <?= str_contains($view, "Credits") || str_contains($view, "Datenschutz") || str_contains($view, "Fragen") || str_contains($view, "Regeln") ?
                    "<a href='index.php'>
                        <button type='button'>Zurück zur Startseite</button>
                    </a><br><br>"
                    :
                    ""
            ?>
            <?= $view ?? "" ?>
            <br>
            <a href="index.php">
                <button type="button">Zurück zur Startseite</button>
            </a>
        </div>
    </div>
</div>

<footer>
    <?php include_once("layout/copyright.php"); ?>
</footer>
</body>
</html>
<!DOCTYPE html>
<html lang="de">
<?php include_once("layout/head.html"); ?>
<body>
<?php include_once("layout/banner.html"); ?>

<div class="middle-container" style="margin: auto;">
    <div class="big-box-container">
        <div class="big-box-header">
            <?= $header ?? "Information" ?>
        </div>
        <div class="big-box-content">
            <?= str_contains($view, "Credits") ?
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
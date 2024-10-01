<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE-edge">
    <meta name="viewport" content="width=device-width, initial-scale:1.0">
    <link rel="stylesheet" href="styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <title><?php echo (!empty($title)) ? "Magic Empires - $title" : "Magic Empires"; ?></title>
    <script type="text/javascript" src="js/main.js"></script>
    <?php
    if (!empty($script_files)) {
        foreach ($script_files as $script_file) {
            echo '<script type="text/javascript" src="js/' . $script_file . '.js"></script>';
        }
    }
    echo $head_extra ?? '';
    ?>
</head>
<body>
<div class="header img">
    <img src="images/header3.png" alt="Header"/>
</div>
<div class="content-box">
    <div class="left-container">
        <?php include_once("layout/left.php"); ?>
    </div>
    <div class="middle-container">
        <div class="big-box-container">
            <div class="big-box-header">
                <p><?php echo $header ?? 'Default Header'; ?></p>
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
<div id="hamburger-icon">
    <p style="font-size: 24px;">&#9776;</p>
</div>
<div id="mobile-nav">
    <div id="menu">
        <?php
        include("left.php");
        include("right.php");
        ?>
    </div>
</div>
<footer>
    <div id="footerwrapper">
        © Magic Empires - 2024
    </div>
</footer>
</body>
</html>
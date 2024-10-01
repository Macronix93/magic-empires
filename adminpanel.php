<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->is_logged_in())) {
    change_location("login.php");
    exit;
}

$view = "";
$error = "";

if ($user->get_user_admin_level() == 0) {
    $error = "Du bist kein Administrator!";
}
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/banner.html");
?>
<div class="content-box">
    <div class="left-container">
        <?php
        include_once("layout/left.php");
        ?>
    </div>
    <div class="middle-container">
        <div class="big-box-container">
            <div class="big-box-header">
                Admin-Bereich
            </div>
            <div class="big-box-content">
                <?php
                if (!empty($error)) {
                    echo $error;
                }

                echo $view;
                ?>
            </div>
        </div>
    </div>
    <div class="right-container">
        <?php
        include_once("layout/right.php");
        ?>
    </div>
</div>
<?php
include_once("layout/footer.php");
?>
</body>
</html>

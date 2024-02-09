<?php
/************************************
 * Functions file
 ************************************/

/*
    Check session and create if non-existent
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
    Constants (defines)
*/
const HOST = "localhost";
const USER = "newme";
const PASSWORD = "q42i7aw8c3";
const DATABASE = "newme";
const PORT = "34156";
const MIN_USERNAME_LENGTH = 4;
const MAX_USERNAME_LENGTH = 16;
const MIN_PASSWORD_LENGTH = 5;
const MAX_PASSWORD_LENGTH = 65;
const MAX_X = 100;
const MAX_Y = 100;
const MAX_MAP_SEARCHES = 3;
const ACTION_BUILD_BUILDING = 1;
const ACTION_BUILD_TROOPS = 2;
const ACTION_SEND_TROOPS = 3;
const BUILDING_COST_WOOD = 1;
const BUILDING_COST_FOOD = 2;
const BUILDING_COST_STONE = 3;
const BUILDING_COST_GOLD = 4;
const MAX_BUILDING_LEVEL = 10;
const DEFAULT_WALL_HP = 200;
const TIMEOUT_MAX_SECONDS = 1800;
const USER_UPDATE_TICK = 30;
const MAX_USER_MESSAGES = 50;
const MAX_GUILD_MESSAGES = 50;
const MAX_MESSAGE_LENGTH = 400;
const MAX_SUBJECT_LENGTH = 16;
const INACTIVITY_DELAY = 864000;

ini_set('max_execution_time', 300);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
    AutoLoad classes
*/
spl_autoload_register(function ($class_name) {
    $class = strtolower($class_name);
    include("classes/$class.php");
});

// Database instance for classes
$db = Database::getInstance();
$db_instance = $db->getConnection();

// Create User instance
$user = new User($db_instance);

// Timeout Check
if (!isset($_SESSION["lastactivity"])) {
    // initiate value
    $_SESSION["lastactivity"] = time();
}

// last activity is more than TIMEOUT_MAX_SECONDS seconds ago
if (time() - $_SESSION["lastactivity"] > TIMEOUT_MAX_SECONDS) {
    //changeLocation("login.php", 0);
    header("Location: login.php");

    session_destroy();
    exit;
} else {
    // update last activity timestamp
    $currentTimestamp = time();

    if ($currentTimestamp - $_SESSION["lastactivity"] > USER_UPDATE_TICK) {
        $stmt = $db_instance->prepare("UPDATE users SET lastactivity = $currentTimestamp WHERE id = ?");
        $userID = $user->getUserID();
        $stmt->bind_param('i', $userID);
        $stmt->execute();
        $stmt->close();
    }

    $_SESSION["lastactivity"] = $currentTimestamp;
}

/*
    Useful functions
*/

// Make Input data secure
function makeSecure($data): string {
    $data = trim($data);
    $data = stripslashes($data);
    return htmlspecialchars($data);
}

// Show messages indicator
function showNewMessagesIndicator($number): void {
    echo ($number == 0) ? "" : "<img src='images/icons/icon_" . ($number > 5 ? "more_than_5" : $number) . ".png' class='menu-icons' style='width: 16px; height: 16px;' alt='' />";
}

// Convert seconds to a string
function convertSecToStr($secs): string {
    if ($secs == 0) {
        return '0s';
    }

    $output = '';

    if ($secs >= 86400) {
        $days = floor($secs / 86400);
        $secs = $secs % 86400;
        $output .= $days . 'd ';
    }

    if ($secs >= 3600) {
        $hours = floor($secs / 3600);
        $secs = $secs % 3600;
        $output .= $hours . 'h ';
    }

    if ($secs >= 60) {
        $minutes = floor($secs / 60);
        $secs = $secs % 60;
        $output .= $minutes . 'm ';
    }

    if ($secs > 0) {
        $output .= $secs . 's';
    }

    return trim($output);
}

function changeLocation($url, $seconds): void {
    $urlJson = json_encode($url);
    $secondsJson = json_encode($seconds);

    echo '<script type="text/javascript">', 'changeLoc(', $secondsJson, ', ', $urlJson, ');', '</script>';
}
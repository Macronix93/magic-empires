<?php
/************************************
 * Functions file
 ************************************/

/*
    Check session and create if non-existent
*/

use JetBrains\PhpStorm\NoReturn;

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/*
    Constants (defines)
*/
const ERROR_PATH = "D:/xampp/htdocs/magic-empires/errors.log";
const ERROR_DATE_FORMAT = "D M d H:i:s";
const MIN_USERNAME_LENGTH = 4;
const MAX_USERNAME_LENGTH = 16;
const MIN_PASSWORD_LENGTH = 5;
const MAX_PASSWORD_LENGTH = 65;
const MAX_X = 100;
const MAX_Y = 100;
const FIELD_TYPE_MOUNTAINS = 1;
const FIELD_TYPE_COAST = 2;
const FIELD_TYPE_FOREST = 3;
const FIELD_TYPE_DESERT = 4;
const FIELD_TYPE_PLAINS = 5;
const MAX_MAP_SEARCHES = 3;
const ACTION_BUILD_BUILDING = 1;
const ACTION_BUILD_TROOPS = 2;
const ACTION_SEND_TROOPS = 3;
const ACTION_TRADING = 4;
const BUILDING_COST_WOOD = 1;
const BUILDING_COST_FOOD = 2;
const BUILDING_COST_STONE = 3;
const BUILDING_COST_GOLD = 4;
const MAX_BUILDING_LEVEL = 10;
const DEFAULT_WALL_HP = 200;
const TIMEOUT_MAX_SECONDS = 1800; // 30 Minutes
const AFK_SECONDS = 300; // 5 Minutes
const USER_UPDATE_TICK = 30; // 30 Seconds
const MAX_USER_MESSAGES = 50;
const MAX_GUILD_MESSAGES = 50;
const MAX_MESSAGE_LENGTH = 400;
const MAX_LINE_BREAK_COUNT = 10;
const MESSAGES_RATE_LIMIT = 60;
const MAX_MESSAGES_PER_RATELIMIT = 10;
const INACTIVITY_DELAY = 864000;
const MAX_SOLDIERS = 4;
const STARTING_FOOD = 1000;
const STARTING_WOOD = 1000;
const STARTING_STONE = 700;
const STARTING_GOLD = 500;
const STORAGE_STARTING_VALUE = 1000;
const MAX_STORAGE_VALUE = 50000;
const BUILDING_MILL = 5;
const BUILDING_SAWMILL = 6;
const BUILDING_STONEMINE = 7;
const BUILDING_GOLDMINE = 8;
const BUILDING_STORAGE = 9;
const BASE_FOOD_GAIN = 20;
const BASE_WOOD_GAIN = 20;
const BASE_STONE_GAIN = 15;
const BASE_GOLD_GAIN = 10;
const STARTING_GAIN = 10;
const CONV_INACTIVITY_TIME = 1209600; // In seconds (currently 1209600 seconds = 14 days)

/*
 * Global exception handlers
 */
#[NoReturn] function globalExceptionHandler($e): void {
    error_log("[" . date("D M d H:i:s") . "] " . $e->getMessage() . " on line " . $e->getLine() . " in file " . $e->getFile() . "\nTrace:" . $e->getTraceAsString() . "\n", 3, ERROR_PATH);
    echo "<body style='
                        display: flex;
                        justify-content: center;
                        background: rgb(0, 0, 0) url(../images/background.png);     
                        color: rgb(240, 240, 240);
                        text-shadow: -1px -1px 0 rgb(0, 0, 0), 1px -1px 0 rgb(0, 0, 0), -1px 1px 0 rgb(0, 0, 0), 1px 1px 0 rgb(0, 0, 0);
                        font-family: Arial, Helvetica, sans-serif;
                        font-size: 24px;'>
                        <p style='background-color: rgba(0,0,0,0.7); padding: 20px; text-align: center'>An unexpected error occurred! Please stand by.</p>
          </body>";
    exit;
}

/**
 * @throws ErrorException
 */
function globalErrorHandler($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

function fatalErrorShutdownHandler(): void {
    $error = error_get_last();
    if ($error !== null) {
        error_log("[" . date("D M d H:i:s") . "] Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line'] . "\n", 3, ERROR_PATH);
        echo "<body style='
                        display: flex;
                        justify-content: center;
                        background: rgb(0, 0, 0) url(../images/background.png);     
                        color: rgb(240, 240, 240);
                        text-shadow: -1px -1px 0 rgb(0, 0, 0), 1px -1px 0 rgb(0, 0, 0), -1px 1px 0 rgb(0, 0, 0), 1px 1px 0 rgb(0, 0, 0);
                        font-family: Arial, Helvetica, sans-serif;
                        font-size: 24px;'>
                        <p style='background-color: rgba(0,0,0,0.7); padding: 20px; text-align: center'>A fatal error occurred! Please stand by.</p>
          </body>";
    }
}

/*
 * PHP Options
 */
/*set_exception_handler('globalExceptionHandler');
set_error_handler('globalErrorHandler');
register_shutdown_function('fatalErrorShutdownHandler');*/
ini_set('max_execution_time', 300);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
 * AutoLoad classes
 */
spl_autoload_register(function ($class_name) {
    include("classes/" . strtolower($class_name) . ".php");
});

// Load .env file
(new DotEnv(__DIR__ . "/.env"))->load();

// Database instance for classes
$db = Database::getInstance();
$db_instance = $db->getConnection();

// Create User instance
$user = new User($db_instance);

// Timeout Check
if ($user->isLoggedIn()) {
    if (!isset($_SESSION["lastactivity"])) {
        // initiate value
        $_SESSION["lastactivity"] = time();
    }

    // last activity is more than TIMEOUT_MAX_SECONDS seconds ago
    if (time() - $_SESSION["lastactivity"] > TIMEOUT_MAX_SECONDS) {
        session_destroy();

        changeLocation("login.php");
        exit;
    } else {
        // update last activity timestamp
        $currentTimestamp = time();

        if ($currentTimestamp - $_SESSION["lastactivity"] > USER_UPDATE_TICK) {
            $stmt = $db_instance->prepare("UPDATE users SET lastactivity = $currentTimestamp WHERE id = ?");
            $userID = $user->getUserID();
            $stmt->bind_param("i", $userID);
            $stmt->execute();
            $stmt->close();
        }

        $_SESSION["lastactivity"] = $currentTimestamp;
    }

    /*$kingdom = new Kingdoms($db_instance);
    $kingdom->getKingdomRessources($_SESSION["kingdomid"]);*/

    // Process user events
    $user->processUserEvents($user->getUserID());
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
function showNewMessagesIndicator($number): string {
    return ($number == 0) ? "" : "<img src='images/icons/icon_" . ($number > 5 ? "more_than_5" : $number) . ".png' class='menu-icons' style='width: 16px; height: 16px;' alt='' />";
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

function changeLocation($url, $seconds = 0): void {
    if ($seconds === 0) {
        header("Location: $url");
    } else {
        header("refresh:$seconds; url=$url");
    }
}

function clampValue($value) {
    if ($value > 91) {
        return 91;
    }
    return max(min($value, 100), 1);
}

// Check for an error in a conversation
function getError(string $text, string $receiverid): string {
    $error = "";
    $lineBreaksCount = substr_count($text, '<br />');
    $textWithoutLineBreaks = preg_replace('/<br\s*\/?>/i', '', $text);

    // Check different errors
    if ($receiverid == $_SESSION["userid"]) {
        $error = "Du kannst keine Nachrichten an dich selbst senden!";
    } else if ($_SESSION["msgreceiver"] != $receiverid) {
        $error = "Bitte nutze nur einen Tab für Konversationen!";
    } else if (strlen(trim(strip_tags($text))) === 0) {
        $error = "Bitte alle Felder ausfüllen!";
    } else if (strlen($textWithoutLineBreaks) > MAX_MESSAGE_LENGTH) {
        $error = "Die Nachricht darf maximal " . MAX_MESSAGE_LENGTH . " Zeichen lang sein!";
    } else if ($lineBreaksCount > MAX_LINE_BREAK_COUNT) {
        $error = "Dein Text darf maximal " . MAX_LINE_BREAK_COUNT . " Zeilenumbrüche beinhalten!";
    }
    return $error;
}

function fnum($number): string {
    return number_format($number, 0, ",", ".");
}
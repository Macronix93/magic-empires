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
const MAX_MESSAGE_LENGTH = 400;
const MAX_LINE_BREAK_COUNT = 10;
const MESSAGES_RATE_LIMIT = 60;
const MAX_MESSAGES_PER_RATELIMIT = 10;
const INACTIVITY_DELAY = 864000;
const STARTING_FOOD = 1000;
const STARTING_WOOD = 1000;
const STARTING_STONE = 1000;
const STARTING_GOLD = 1000;
const STORAGE_STARTING_VALUE = 1000;
const MAX_STORAGE_VALUE = 50000;
const BUILDING_TOWNCENTER = 0;
const BUILDING_UNIVERSITY = 1;
const BUILDING_BARRACKS = 2;
const BUILDING_WALL = 3;
const BUILDING_SMITH = 4;
const BUILDING_MILL = 5;
const BUILDING_SAWMILL = 6;
const BUILDING_STONEMINE = 7;
const BUILDING_GOLDMINE = 8;
const BUILDING_STORAGE = 9;
const BASE_FOOD_GAIN = 20;
const BASE_WOOD_GAIN = 20;
const BASE_STONE_GAIN = 15;
const BASE_GOLD_GAIN = 10;
const CONV_INACTIVITY_TIME = 1209600; // In seconds (currently 1209600 seconds = 14 days)

/*
 * Global exception handlers
 */
#[NoReturn] function global_exception_handler($e): void {
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
function global_error_handler($err_no, $err_str, $err_file, $err_line) {
    throw new ErrorException($err_str, 0, $err_no, $err_file, $err_line);
}

function fatal_error_shutdown_handler(): void {
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
/*set_exception_handler('global_exception_handler');
set_error_handler('global_error_handler');
register_shutdown_function('fatal_error_shutdown_handler');*/
ini_set('max_execution_time', 300);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
 * AutoLoad classes
 */
spl_autoload_register(function ($class_name) {
    include('classes/' . strtolower($class_name) . '.php');
});

// Load .env file
(new DotEnv(__DIR__ . "/.env"))->load();

// Database instance for classes
$db = Database::get_instance();
$db_instance = $db->get_connection();

// Create User instance
$user = new User($db_instance);

// Timeout Check
if ($user->is_logged_in()) {
    $timestamp = time();

    if (!isset($_SESSION["lastactivity"])) {
        // initiate value
        $_SESSION["lastactivity"] = $timestamp;
    }

    // last activity is more than TIMEOUT_MAX_SECONDS seconds ago
    if ($timestamp - $_SESSION["lastactivity"] > TIMEOUT_MAX_SECONDS) {
        session_unset();
        session_destroy();

        change_location("login.php");
        exit;
    } else {
        // update last activity timestamp, if not logging out
        if ($timestamp - $_SESSION["lastactivity"] > USER_UPDATE_TICK && !(isset($_GET["logout"]) && $_GET["logout"] === "inactive")) {
            $db_instance->execute_query("UPDATE users SET lastactivity = $timestamp WHERE id = ?", [$user->get_user_id()]);
        }

        $_SESSION["lastactivity"] = $timestamp;
    }

    // Process all events for the user
    $user->process_user_events($user->get_user_id());

    // Update villager count after events were processed (villager cap)
    apply_villager_cap($_SESSION["kingdomid"]);
}

/*
    Useful functions
*/

// Apply villager cap
function apply_villager_cap($kingdom_id): void {
    $db = Database::get_instance();
    $db_instance = $db->get_connection();
    $result = $db_instance->execute_query("SELECT villager, maxvillager FROM kingdoms WHERE id = ?", [$kingdom_id]);

    // Fetch the villager count from the result and apply the cap if needed
    $row = $result->fetch_assoc();
    $villager_count = $row["villager"];
    $max_villager = $row["maxvillager"];

    if ($villager_count > $max_villager) {
        $villDiff = $villager_count - $max_villager;
        $db_instance->execute_query("UPDATE kingdoms SET villager = villager - $villDiff WHERE id = ?", [$kingdom_id]);
    }
}

function fetch_all_buildings($kingdom_id): array {
    $db = Database::get_instance();
    $db_instance = $db->get_connection();
    $buildings = [];

    // Query to fetch buildings and dependencies
    $query = "
        SELECT b.*, GROUP_CONCAT(d.dependencyid) AS dependency_ids, GROUP_CONCAT(d.dependencylevel) AS dependency_levels, bl.buildinglevel 
        FROM buildinglist b 
        LEFT JOIN buildingdeps d ON b.id = d.buildingid 
        LEFT JOIN buildings bl ON bl.buildingid = b.id AND bl.kingdomid = ?
        GROUP BY b.id
    ";
    $result = $db_instance->execute_query($query, [$kingdom_id]);

    // Process each building and its dependencies
    foreach ($result as $row) {
        $building_id = $row["id"];

        // Check if building object already exists
        if (!isset($buildings[$building_id])) {
            $building = new Building($db_instance);
            $buildings = $building->create_building($building, $row, $buildings, $building_id);
        }

        // Process dependencies if any exist
        if (!empty($row["dependency_ids"])) {
            $dependency_ids = explode(',', $row["dependency_ids"]);
            $dependency_levels = explode(',', $row["dependency_levels"]);

            foreach ($dependency_ids as $index => $dependency_id) {
                $buildings[$building_id]->add_building_dependency($dependency_id, $dependency_levels[$index]);
            }
        }
    }

    return $buildings;
}

// Make Input data secure
function make_secure($data): string {
    $data = trim($data);
    $data = stripslashes($data);
    $data = preg_replace('/\s+/', '', $data);
    return htmlspecialchars($data);
}

// Show messages indicator
function show_messages_indicator($number): string {
    return ($number == 0) ? "" : "<img src='images/icons/icon_" . ($number > 5 ? "more_than_5" : $number) . ".png' class='menu-icons' style='width: 16px; height: 16px;' alt='' />";
}

// Convert seconds to a string
function convert_sec_to_str($secs): string {
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

function change_location(string $url, int $seconds = 0): void {
    if ($seconds === 0) {
        header("Location: $url");
    } else {
        header("refresh:$seconds; url=$url");
    }
}

function clamp_value(int $value) {
    if ($value > 91) {
        return 91;
    }
    return max(min($value, 100), 1);
}

// Check for an error in a conversation
function get_error(string $text, string $receiver_id): string {
    $error = "";
    $line_breaks_count = substr_count($text, '<br />');
    $text_without_line_breaks = preg_replace('/<br\s*\/?>/i', '', $text);

    // Check different errors
    if ($receiver_id == $_SESSION["userid"]) {
        $error = "Du kannst keine Nachrichten an dich selbst senden!";
    } else if ($_SESSION["msgreceiver"] != $receiver_id) {
        $error = "Bitte nutze nur einen Tab für Konversationen!";
    } else if (strlen(trim(strip_tags($text))) === 0) {
        $error = "Bitte alle Felder ausfüllen!";
    } else if (strlen($text_without_line_breaks) > MAX_MESSAGE_LENGTH) {
        $error = "Die Nachricht darf maximal " . MAX_MESSAGE_LENGTH . " Zeichen lang sein!";
    } else if ($line_breaks_count > MAX_LINE_BREAK_COUNT) {
        $error = "Dein Text darf maximal " . MAX_LINE_BREAK_COUNT . " Zeilenumbrüche beinhalten!";
    }
    return $error;
}

function fnum(int $number): string {
    return number_format($number, 0, ",", ".");
}

function regex_pattern(): string {
    return '/\b('
        . '(a(bstract|nd|rray|s))|'
        . '(c(a(llable|se|tch)|l(ass|one)|on(st|tinue)))|'
        . '(d(e(clare|fault)|ie|o))|'
        . '(e(cho|lse(if)?|mpty|nd(declare|for(each)?|if|switch|while)|val|x(it|tends)))|'
        . '(f(inal|or(each)?|unction))|'
        . '(g(lobal|goto))|'
        . '(i(f|mplements|n(clude(_once)?|st(anceof|eadof)|terface)|sset))|'
        . '(n(amespace|new))|'
        . '(p(r(i(nt|vate)|otected)|ublic))|'
        . '(re(quire(_once)?|turn))|'
        . '(s(tatic|witch))|'
        . '(t(hrow|r(ait|y)))|'
        . '(u(nset|se))|'
        . '(__halt_compiler|break|list|(x)?or|var|while)'
        . ')\b/';
}

function get_bad_names(): array {
    $filename = __DIR__ . '/bad_names.txt';

    if (!file_exists($filename)) {
        echo "File for bad names not found!";
        return [];
    }

    // Convert all names to lowercase to ensure case-insensitive comparison
    return file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}
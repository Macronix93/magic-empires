<?php
/************************************
 * Core file
 ************************************/

/*
    Check session and create if non-existent
*/

use JetBrains\PhpStorm\NoReturn;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . "/../vendor/autoload.php"; // Composer Autoloader

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (str_contains($_SERVER["HTTP_HOST"], "localhost") || str_contains($_SERVER["HTTP_HOST"], "127.0.0.1")) {
    define("BASE_URL", "/magic-empires/");
    define("IS_DEV", true);
} else {
    define("BASE_URL", '/');
    define("IS_DEV", false);
}

/*
    Constants (defines)
*/
const MAINTENANCE_MODE = false;
const MAX_ROWS_PER_RANKING_PAGE = 10;
const BASE_CONQUEST_CHANCE = 0.2;
const MAX_CONQUEST_CHANCE = 0.9;
const MIN_CONQUEST_CHANCE = 0.01;
const BACKGROUND_IMAGE = "images/background.png";
const ERROR_LOG_FILE = "logs/errors.log";
const ERROR_DATE_FORMAT = "D M d H:i:s";
const MIN_USERNAME_LENGTH = 4;
const MAX_USERNAME_LENGTH = 16;
const MIN_PASSWORD_LENGTH = 5;
const MAX_PASSWORD_LENGTH = 65;
const MAX_X = 100;
const MAX_Y = 100;
const MAX_BUILDING_LEVEL = 10;
const DEFAULT_WALL_HP = 100;
const MIN_WALL_DEFENSE = 1;
const MAX_WALL_DEFENSE = 5;
const WALL_DEFENSE_FACTOR = 0.6;
const BASE_WALL_REPAIR_COST = 50;
const TIMEOUT_MAX_SECONDS = 1800; // 30 Minutes
const AFK_SECONDS = 300; // 5 Minutes
const USER_UPDATE_TICK = 30; // 30 Seconds
const MAX_MESSAGE_LENGTH = 400;
const MAX_LINE_BREAK_COUNT = 10;
const MESSAGES_RATE_INTERVAL = 60;
const MAX_MESSAGES_RATELIMIT = 10;
const SHOW_MESSAGES_LIMIT = 50;
const INACTIVITY_DELAY = 864000;
const STARTING_FOOD = 2000;
const STARTING_WOOD = 2000;
const STARTING_STONE = 2000;
const STARTING_GOLD = 2000;
const STORAGE_STARTING_VALUE = 2000;
const STORAGE_INC_FACTOR = 1.43;
const BASE_FOOD_GAIN = 40;
const BASE_WOOD_GAIN = 40;
const BASE_STONE_GAIN = 30;
const BASE_GOLD_GAIN = 20;
const CONV_INACTIVITY_TIME = 1209600; // In seconds (currently 1209600 seconds = 14 days)
const UPLOADS_FILE_PATH = "uploads/";
const DEFAULT_AVATAR = UPLOADS_FILE_PATH . "default_avatar.jpg";
const AVATAR_SALT = "Dpf89!jkl#45mAlmDlp";
const MAX_UPLOAD_FILE_SIZE = 64; // In KB
const NOOB_PROTECTION_MULT = 0.5;
const RESEARCH_FOOD_INC = 10;
const RESEARCH_WOOD_INC = 10;
const RESEARCH_STONE_INC = 7;
const RESEARCH_GOLD_INC = 5;
const RESEARCH_STORAGE_INC = 10000;
const RESEARCH_WALL_HP_INC = 100;
const MARKET_BASE_FEE = 1;
const MARKET_FEE_MULTIPLIER_FOOD = 0.001;
const MARKET_FEE_MULTIPLIER_WOOD = 0.002;
const MARKET_FEE_MULTIPLIER_STONE = 0.005;
const MARKET_FEE_MULTIPLIER_GOLD = 0.01;
const MARKET_OFFER_DURATION = 86400; // 24 hours
const MAX_SOLDIERS_RECRUIT_INPUT = 99;


/*
 * Interfaces
 */

interface MessageCategories
{
    const string CATEGORY_DEFAULT = "Default";
    const string CATEGORY_WAR = "Krieg";
    const string CATEGORY_TRADE = "Handel";
}

interface BuildingTypes
{
    const int BUILDING_TOWNCENTER = 0;
    const int BUILDING_UNIVERSITY = 1;
    const int BUILDING_BARRACKS = 2;
    const int BUILDING_WALL = 3;
    const int BUILDING_SMITHY = 4;
    const int BUILDING_MILL = 5;
    const int BUILDING_SAWMILL = 6;
    const int BUILDING_STONEMINE = 7;
    const int BUILDING_GOLDMINE = 8;
    const int BUILDING_STORAGE = 9;
    const int BUILDING_MARKETPLACE = 10;
}

interface ResourceTypes
{
    const int RESOURCE_TYPE_FOOD = 0;
    const int RESOURCE_TYPE_WOOD = 1;
    const int RESOURCE_TYPE_STONE = 2;
    const int RESOURCE_TYPE_GOLD = 3;
    const int RESOURCE_TYPE_TIME = 4;
    const int RESOURCE_TYPE_VILLAGER = 5;
    const int RESOURCE_TYPE_ATTACK = 6;
    const int RESOURCE_TYPE_DEFENSE = 7;
    const int RESOURCE_TYPE_RECRUIT_TIME = 8;
    const int RESOURCE_TYPE_HEALTH = 9;
    const int RESOURCE_TYPE_COINS = 10;
}

interface ActionTypes
{
    const int ACTION_BUILD_BUILDING = 0;
    const int ACTION_BUILD_TROOPS = 1;
    const int ACTION_SEND_TROOPS = 2;
    const int ACTION_RETURN_TROOPS = 3;
    const int ACTION_RESEARCH_TECH = 4;
    const int ACTION_RECEIVE_RESOURCES = 5;
}

interface TechTypes
{
    const int TECH_TYPE_FOOD_INC = 0;
    const int TECH_TYPE_WOOD_INC = 1;
    const int TECH_TYPE_STONE_INC = 2;
    const int TECH_TYPE_GOLD_INC = 3;
    const int TECH_TYPE_WALL_HP_INC = 4;
    const int TECH_TYPE_STORAGE_INC = 5;
}

interface SoldierTypes
{
    const int SOLDIER_TYPE_INFANTRY = 0;
    const int SOLDIER_TYPE_CAVALRY = 1;
    const int SOLDIER_TYPE_ARCHERS = 2;
    const int SOLDIER_TYPE_SPECIAL = 3;
}

/*
 * Interfaces end
 */

function get_building_file(int $building_id): string
{
    return match ($building_id) {
        BuildingTypes::BUILDING_TOWNCENTER => "towncenter",
        BuildingTypes::BUILDING_UNIVERSITY => "university",
        BuildingTypes::BUILDING_BARRACKS => "barracks",
        BuildingTypes::BUILDING_WALL => "wall",
        BuildingTypes::BUILDING_SMITHY => "blacksmith",
        BuildingTypes::BUILDING_MILL => "mill",
        BuildingTypes::BUILDING_SAWMILL => "sawmill",
        BuildingTypes::BUILDING_STONEMINE => "stonemine",
        BuildingTypes::BUILDING_GOLDMINE => "goldmine",
        BuildingTypes::BUILDING_STORAGE => "storage",
        BuildingTypes::BUILDING_MARKETPLACE => "marketplace",
        default => "index",
    };
}

/*
    Useful functions
*/

function get_resource_icon(int $resource_type): string
{
    return match ($resource_type) {
        ResourceTypes::RESOURCE_TYPE_WOOD => "<img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz' title='Holz'/>",
        ResourceTypes::RESOURCE_TYPE_FOOD => "<img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung' title='Nahrung'/>",
        ResourceTypes::RESOURCE_TYPE_STONE => "<img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein' title='Stein'/>",
        ResourceTypes::RESOURCE_TYPE_GOLD => "<img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold' title='Gold'/>",
        ResourceTypes::RESOURCE_TYPE_TIME => "<img src='images/icons/icon_hammer.png' class='ressource-icons' alt='Bauzeit' title='Bauzeit'/>",
        ResourceTypes::RESOURCE_TYPE_VILLAGER => "<img src='images/icons/icon_villager.png' class='ressource-icons' alt='Dorfbewohner' title='Dorfbewohner'/>",
        ResourceTypes::RESOURCE_TYPE_ATTACK => "<img src='images/icons/icon_sword.png' class='ressource-icons' alt='Angriff' title='Angriff'/>",
        ResourceTypes::RESOURCE_TYPE_DEFENSE => "<img src='images/icons/icon_shield.png' class='ressource-icons' alt='Verteidigung' title='Verteidigung'/>",
        ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME => "<img src='images/icons/icon_time.png' class='ressource-icons' alt='Rekrutierzeit' title='Rekrutierzeit'/>",
        ResourceTypes::RESOURCE_TYPE_HEALTH => "<img src='images/icons/icon_health.png' class='ressource-icons' alt='Lebenspunkte' title='Lebenspunkte'/>",
        ResourceTypes::RESOURCE_TYPE_COINS => "<img src='images/icons/icon_coins.png' class='ressource-icons' alt='Münzen' title='Münzen'/>",
        default => 0,
    };
}

// Start inactivity counter
function start_inactivity_check(int $seconds): void
{
    echo '
    <script type="text/javascript">
        (function() {
            const logoutTime = Date.now() + (' . $seconds . ' * 1000)

            const checkTimer = setInterval(function() {
                if (Date.now() >= logoutTime) {
                    clearInterval(checkTimer);
                    window.location.href = "index.php?logout=inactive";
                }
            }, 10000);
        })();
    </script>';
}

// Apply villager cap
function apply_villager_cap(int $kingdom_id): void
{
    $db = Database::get_instance();
    $db_instance = $db->get_connection();
    $result = $db_instance->execute_query("SELECT villager, maxvillager FROM kingdoms WHERE id = ?", [$kingdom_id]);

    // Fetch the villager count from the result and apply the cap if needed
    $row = $result->fetch_assoc();
    $villager_count = $row["villager"];
    $max_villager = $row["maxvillager"];

    if ($villager_count > $max_villager) {
        $villager_difference = $villager_count - $max_villager;
        $db_instance->execute_query("UPDATE kingdoms SET villager = villager - $villager_difference WHERE id = ?",
            [$kingdom_id]);
    }
}

// Make Input data secure
function e($value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, "UTF-8");
}

function js($value): string
{
    $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $json !== false ? $json : "null";
}

function make_secure(string $data): string
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = preg_replace('/\s+/', '', $data);
    return htmlspecialchars($data);
}

// Convert seconds to a string
function convert_sec_to_str(int $secs): string
{
    if ($secs == 0) {
        return "0s";
    }

    $output = "";

    if ($secs >= 86400) {
        $days = floor($secs / 86400);
        $secs = $secs % 86400;
        $output .= $days . "T ";
    }

    if ($secs >= 3600) {
        $hours = floor($secs / 3600);
        $secs = $secs % 3600;
        $output .= $hours . " Std. ";
    }

    if ($secs >= 60) {
        $minutes = floor($secs / 60);
        $secs = $secs % 60;
        $output .= $minutes . " Min. ";
    }

    if ($secs > 0) {
        $output .= $secs . " Sek.";
    }

    return trim($output);
}

function change_location(string $url, int $seconds = 0): void
{
    $full_url = rtrim(BASE_URL, "/") . "/" . ltrim($url, "/");

    if ($seconds === 0) {
        header("Location: $full_url");
    } else {
        header("refresh:$seconds; url=$full_url");
    }
}

function show_passed_box(string $info_text, bool $display = true): string
{
    return "<div class='info-box event-passed' " . ($display ? "" : "style='display: none;'") . "><img src='images/icons/icon_checked.png' alt='Erfolg'><span>$info_text</span></div>";
}

function show_error_box(string $info_text, bool $display = true): string
{
    return "<div class='info-box event-error' " . ($display ? "" : "style='display: none;'") . "><img src='images/icons/icon_error.png' alt='Fehler'><span>$info_text</span></div>";
}

function show_weighted_box(string $info_text, string $weighted_text): string
{
    return "<div class='info-box event-passed'><img src='images/icons/icon_checked.png' alt='Erfolg'><span><span class='weighted'>$weighted_text</span> $info_text</span></div>";
}

// Check for an error in a conversation
function get_error(string $text, string $receiver_id): string
{
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

function fnum(int $number): string
{
    return number_format($number, 0, ",", ".");
}

function regex_pattern(): string
{
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

function get_bad_names(): array
{
    $filename = __DIR__ . '/bad_names.txt';

    if (!file_exists($filename)) {
        echo "File for bad names not found!";
        return [];
    }

    // Convert all names to lowercase to ensure case-insensitive comparison
    return file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
}

/*
 * Global exception handlers
 */
#[NoReturn]
function global_exception_handler($e): void
{
    error_log("[" . date(ERROR_DATE_FORMAT) . "] " . $e->getMessage() . " on line " . $e->getLine() . " in file " . $e->getFile() . "\nTrace:" . $e->getTraceAsString() . "\n", 3, ERROR_LOG_FILE);
    echo "<body style='
                        display: flex;
                        justify-content: center;
                        background: rgb(0, 0, 0) url(" . BACKGROUND_IMAGE . ");     
                        color: rgb(240, 240, 240);
                        text-shadow: -1px -1px 0 rgb(0, 0, 0), 1px -1px 0 rgb(0, 0, 0), -1px 1px 0 rgb(0, 0, 0), 1px 1px 0 rgb(0, 0, 0);
                        font-family: Arial, Helvetica, sans-serif;
                        font-size: 24px;'>
                        <p style='background-color: rgba(0,0,0,0.7); padding: 20px; text-align: center'>Ein unerwarteter Fehler ist aufgetreten!</p>
          </body>";
    exit;
}

/**
 * @throws ErrorException
 */
function global_error_handler($err_no, $err_str, $err_file, $err_line)
{
    throw new ErrorException($err_str, 0, $err_no, $err_file, $err_line);
}

function fatal_error_shutdown_handler(): void
{
    $error = error_get_last();
    if ($error !== null) {
        error_log("[" . date(ERROR_DATE_FORMAT) . "] Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line'] . "\n", 3, ERROR_LOG_FILE);
        echo "<body style='
                        display: flex;
                        justify-content: center;
                        background: rgb(0, 0, 0) url(" . BACKGROUND_IMAGE . ");     
                        color: rgb(240, 240, 240);
                        text-shadow: -1px -1px 0 rgb(0, 0, 0), 1px -1px 0 rgb(0, 0, 0), -1px 1px 0 rgb(0, 0, 0), 1px 1px 0 rgb(0, 0, 0);
                        font-family: Arial, Helvetica, sans-serif;
                        font-size: 24px;'>
                        <p style='background-color: rgba(0,0,0,0.7); padding: 20px; text-align: center'>Ein fataler Fehler ist aufgetreten!</p>
          </body>";
    }
}

/*
 * PHP Options
 */
if (!IS_DEV) {
    set_exception_handler("global_exception_handler");
    set_error_handler("global_error_handler");
    register_shutdown_function("fatal_error_shutdown_handler");
} else {
    ini_set("display_errors", 1);
    ini_set("display_startup_errors", 1);
    error_reporting(E_ALL);
}

ini_set("max_execution_time", 300);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
 * AutoLoad classes
 */
spl_autoload_register(function ($class_name) {
    include(__DIR__ . '/../classes/' . $class_name . '.php');
});

// Load .env file
(new DotEnv(__DIR__ . "/.env"))->load();

function send_mail(string $to, string $subject, string $body): bool
{
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = getenv("MAIL_HOST");
        $mail->SMTPAuth = true;
        $mail->Username = getenv("MAIL_USER");
        $mail->Password = getenv("MAIL_PASS");
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = getenv("MAIL_PORT");
        $mail->SMTPOptions = [
            "ssl" => [
                "verify_peer" => false,
                "verify_peer_name" => false,
                "allow_self_signed" => true
            ]
        ];

        $mail->setFrom(getenv('MAIL_FROM'), getenv('MAIL_NAME'));
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->CharSet = "UTF-8";
        $mail->Subject = $subject;

        $mail->Body = "<html lang='de'><head><meta charset='UTF-8'></head><body>" . $body . "</body></html>";
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br />', '</h1>', '</h2>', '</p>'], ["\n", "\n", "\n\n", "\n\n", "\n\n"], $body));

        $mail->send();
        return true;
    } catch (Exception) {
        error_log("PHPMailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// Database instance for classes
$db = Database::get_instance();
$db_instance = $db->get_connection();

// Create User instance
$user = new User($_SESSION["userid"] ?? -1, $_SESSION["username"] ?? "", $_SESSION["kingdomid"] ?? -1);
$error = "";
$view = "";

// Timeout and session ID check
if ($user->is_logged_in()) {
    $user->check_session_id();

    $timestamp = time();

    if (!isset($_SESSION["lastactivity"])) {
        $_SESSION["lastactivity"] = $timestamp;
    }

    // last activity is more than TIMEOUT_MAX_SECONDS seconds ago
    if ($timestamp - $_SESSION["lastactivity"] > TIMEOUT_MAX_SECONDS) {
        if (basename($_SERVER["PHP_SELF"]) !== "index.php") {
            change_location("index.php?logout=session");
            exit;
        }
    } else {
        if (!isset($_SESSION["last_db_update"])) {
            $_SESSION["last_db_update"] = 0;
        }

        if ($timestamp - $_SESSION["last_db_update"] > USER_UPDATE_TICK) {
            $db_instance->execute_query("UPDATE users SET lastactivity = $timestamp WHERE id = ?", [$user->get_user_id()]);

            $_SESSION["last_db_update"] = $timestamp;
        }

        $is_ajax = (!empty($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest");

        if (!$is_ajax) {
            $_SESSION["lastactivity"] = $timestamp;
        }

        // Process all events for the user
        $user->process_user_events();

        // Update villager count after events were processed (villager cap)
        apply_villager_cap($user->get_current_kingdom());
    }
}

// Login Check
function check_user_login($user): void
{
    if (!($user->is_logged_in())) {
        change_location("index.php");
        exit;
    }
}


/*
 * Check if user is logged in and get kingdom and building relevant infos
 */
function check_user_login_and_kingdom($user, $db_instance, $building_type): array
{
    // Check if user is logged in
    if (!($user->is_logged_in())) {
        change_location("index.php");
        exit;
    }

    // Get the current kingdom
    $current_kingdom = $user->get_current_kingdom();

    // Get kingdom info
    $kingdom = new Kingdom($db_instance, $current_kingdom);

    // Get building info
    $building = $kingdom->fetch_kingdom_building($current_kingdom, $building_type);

    // Check if building is built
    if ($building == null) {
        change_location("towncenter.php");
        exit;
    }

    return [
        "current_kingdom" => $current_kingdom,
        "building" => $building,
        "building_name" => $building->get_building_name(),
        "kingdom" => $kingdom,
        "k_wood" => $kingdom->get_kingdom_wood(),
        "k_food" => $kingdom->get_kingdom_food(),
        "k_stone" => $kingdom->get_kingdom_stone(),
        "k_gold" => $kingdom->get_kingdom_gold(),
        "k_villager" => $kingdom->get_kingdom_villager()
    ];
}

function send_server_message(int $user_id, string $user_name, string $message, string $category = MessageCategories::CATEGORY_DEFAULT): void
{
    $db = Database::get_instance();
    $db->get_connection()->execute_query("INSERT INTO servermessages (receiverid, receiver, date, message, category) VALUES (?, ?, ?, ?, ?)",
        [$user_id, $user_name, time(), $message, $category]);
}

function get_resource_text(int $cost, int $current_val): string
{
    return ($cost > $current_val ? "<b class='error'>" . fnum($cost) . "</b>" : fnum($cost));
}

function calculate_market_fee($supply_type, $supply_value, $demand_type, $demand_value): int
{
    $multipliers = [
        ResourceTypes::RESOURCE_TYPE_FOOD => MARKET_FEE_MULTIPLIER_FOOD,
        ResourceTypes::RESOURCE_TYPE_WOOD => MARKET_FEE_MULTIPLIER_WOOD,
        ResourceTypes::RESOURCE_TYPE_STONE => MARKET_FEE_MULTIPLIER_STONE,
        ResourceTypes::RESOURCE_TYPE_GOLD => MARKET_FEE_MULTIPLIER_GOLD
    ];

    $factor_s = $multipliers[$supply_type] ?? 0.001;
    $variable_fee_s = floor($supply_value * $factor_s);

    $factor_d = $multipliers[$demand_type] ?? 0.001;
    $variable_fee_d = floor($demand_value * $factor_d);

    $max_variable = max($variable_fee_s, $variable_fee_d);

    return (int)(MARKET_BASE_FEE + $max_variable);
}
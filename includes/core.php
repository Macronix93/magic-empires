<?php
/************************************
 * Core file
 ************************************/

/*
    Check session and create if non-existent
*/
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . "/../vendor/autoload.php"; // Composer Autoloader

require_once("config.php");
require_once("functions.php");

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    if (str_contains($_SERVER["HTTP_HOST"] ?? '', "localhost") || str_contains($_SERVER["HTTP_HOST"] ?? '', "127.0.0.1")) {
        define("BASE_URL", "/magic-empires/");
        define("IS_DEV", true);
    } else {
        define("BASE_URL", '/');
        define("IS_DEV", false);
    }
} else {
    define("BASE_URL", '/');
    define("IS_DEV", true);
}

if (!$is_cli) {
    header("Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; " .
        "frame-src https://www.google.com/recaptcha/; " .
        "style-src 'self' 'unsafe-inline'; " .
        "img-src 'self' data:; " .
        "connect-src 'self';");
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
new DotEnv(__DIR__ . "/.env")->load();

// Database instance for classes
$db = Database::get_instance();
$db_instance = $db->get_connection();

// Logger instance
$logger = Logger::get_instance();

// Server Settings
$maintenance_res = $db_instance->execute_query("SELECT value FROM system_settings WHERE name = 'maintenance_mode'");
define("MAINTENANCE_MODE", ($maintenance_res->fetch_assoc()["value"] === "1"));

// Create User instance
$user = new User($_SESSION["userid"] ?? -1, $_SESSION["username"] ?? "", $_SESSION["kingdomid"] ?? -1);

require_once("sessions.php");

$error = "";
$view = "";
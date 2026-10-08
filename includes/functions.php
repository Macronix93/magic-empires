<?php

use GuzzleHttp\Client;
use JetBrains\PhpStorm\NoReturn;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
        ResourceTypes::RESOURCE_TYPE_COAL => "<img src='images/icons/icon_coal.png' class='ressource-icons' alt='Kohle' title='Kohle'/>",
        ResourceTypes::RESOURCE_TYPE_IRON => "<img src='images/icons/icon_iron.png' class='ressource-icons' alt='Eisen' title='Eisen'/>",
        ResourceTypes::RESOURCE_TYPE_SAPPHIRE => "<img src='images/icons/icon_sapphire.png' class='ressource-icons' alt='Saphir' title='Saphir'/>",
        ResourceTypes::RESOURCE_TYPE_DIAMOND => "<img src='images/icons/icon_diamond.png' class='ressource-icons' alt='Diamant' title='Diamant'/>",
        default => "",
    };
}

// Make Input data secure
function e($value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, "UTF-8");
}

function make_secure(string $data): string
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = preg_replace('/\s+/', '', $data);
    return htmlspecialchars($data);
}

function sanitize_input(string $str): string
{
    $str = preg_replace('/\p{C}/u', '', $str);
    $str = preg_replace('/\s+/u', ' ', $str);
    return trim($str);
}

// Convert seconds to a string
function convert_sec_to_str(int $secs, bool $short_format = false, bool $show_seconds = true): string
{
    if ($secs <= 0) return "0s";

    $days = floor($secs / 86400);
    $secs %= 86400;
    $hours = floor($secs / 3600);
    $secs %= 3600;
    $minutes = floor($secs / 60);
    $seconds = $secs % 60;

    if ($short_format) {
        if ($days > 0 && $hours == 0 && $minutes == 0 && $seconds == 0) {
            return $days . "T";
        }

        $out = ($days > 0) ? $days . "T " : "";
        return $out . sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }

    $output = "";
    if ($days > 0) $output .= $days . "T ";
    if ($hours > 0) $output .= $hours . " Std. ";
    if ($minutes > 0) $output .= $minutes . " Min. ";
    if (($show_seconds && $seconds > 0) || empty($output)) {
        $output .= $seconds . " Sek.";
    }

    $trimmed = trim($output);
    return !empty($trimmed) ? $trimmed : "0s";
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

function show_warning_box(string $info_text, bool $display = true): string
{
    return "<div class='info-box event-warning' " . ($display ? "" : "style='display: none;'") . "><img src='images/icons/icon_warning.png' alt='Hinweis'><span>$info_text</span></div>";
}

function show_weighted_box(string $info_text, string $weighted_text): string
{
    return "<div class='info-box event-passed'><img src='images/icons/icon_checked.png' alt='Erfolg'><span><span class='weighted'>$weighted_text</span> $info_text</span></div>";
}

function fdec($number, $decimals = 1): string
{
    if (!is_numeric($number)) return "0";
    $formatted = number_format((float)$number, $decimals, ",", ".");

    if (str_contains($formatted, ",")) {
        $formatted = rtrim($formatted, '0');
        $formatted = rtrim($formatted, ',');
    }

    return $formatted;
}

function format_num($number): string
{
    if (!is_numeric($number)) return "0";
    $n = (int)$number;

    if ($n >= 1000000000) {
        $main = intdiv($n, 1000000000);
        $sub = intdiv($n % 1000000000, 10000000);

        if ($sub === 0) return $main . 'B';

        $subStr = str_pad((string)$sub, 2, '0', STR_PAD_LEFT);
        $subStr = rtrim($subStr, '0');

        return $main . ',' . $subStr . 'B';
    }

    if ($n >= 1000000) {
        $main = intdiv($n, 1000000);
        $sub = intdiv($n % 1000000, 10000);

        if ($sub === 0) return $main . 'M';

        $subStr = str_pad((string)$sub, 2, '0', STR_PAD_LEFT);
        $subStr = rtrim($subStr, '0');

        return $main . ',' . $subStr . 'M';
    }

    if ($n >= 100000) {
        $main = intdiv($n, 1000);
        $sub = intdiv($n % 1000, 100);

        if ($sub === 0) return $main . 'k';

        return $main . ',' . $sub . 'k';
    }

    return number_format($n, 0, ",", ".");
}

function fnum($number, bool $simple_format = false, $is_barracks = false): string
{
    if (!is_numeric($number)) return "0";

    $decimals = (floor($number) == $number) ? 0 : 1;
    $full = number_format((float)$number, $decimals, ",", ".");

    if ($simple_format) {
        return $full;
    }

    $short = format_num($number);

    if ($full === $short) {
        return $full;
    }

    if ($is_barracks) {
        return $short;
    }

    $uid = "val_" . substr(md5(mt_rand()), 0, 6);

    return "<span class='popup' id='$uid'>$short<div id='{$uid}_box' class='popupbox'>$full</div></span>";
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
    static $cache = null;
    if ($cache !== null) return $cache;

    $profanity = file_exists(__DIR__ . "/bad_words.txt") ? file(__DIR__ . "/bad_words.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $reserved = file_exists(__DIR__ . "/reserved_names.txt") ? file(__DIR__ . "/reserved_names.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    $cache = array_merge($profanity, $reserved);
    return $cache;
}

function get_bad_words_only(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = file_exists(__DIR__ . "/bad_words.txt") ? file(__DIR__ . "/bad_words.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    return $cache;
}

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

        $mail_name = trim(getenv("MAIL_NAME"), '"\'');
        $mail->setFrom(getenv("MAIL_FROM"), $mail_name);
        $mail->addAddress($to);

        $mail->isHTML();
        $mail->CharSet = "UTF-8";
        $mail->Subject = $subject;

        $mail->Body = "
        <div style='background-color: #1a120b; padding: 40px 10px; font-family: Georgia, serif; color: #e6dcce; text-align: center;'>
            <table style='max-width: 600px; width: 100%; margin: 0 auto; background-color: #2d2a26; border: 3px double #a57c00; border-collapse: collapse; border-spacing: 0;'>
                <thead>
                    <tr>
                        <th style='padding: 20px; border-bottom: 2px solid #a57c00; font-weight: normal;'>
                            <h1 style='color: #d4af37; margin: 0; font-variant: small-caps; letter-spacing: 2px; font-size: 30px;'>Magic Empires</h1>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style='padding: 30px; line-height: 1.6; font-size: 16px; color: #e6dcce; text-align: left;'>
                            $body
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td style='padding: 15px; background-color: #23201c; font-size: 12px; color: rgba(230, 220, 200, 0.5); border-top: 1px solid rgba(165, 124, 0, 0.3); text-align: center;'>
                            &copy; " . date("Y") . " Magic Empires
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>";

        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body));

        $mail->send();
        return true;
    } catch (Exception) {
        error_log("PHPMailer Error: $mail->ErrorInfo");
        return false;
    }
}

function check_image_content($temp_file_path): string
{
    $api_user = getenv("SIGHTENGINE_API_USER");
    $api_secret = getenv("SIGHTENGINE_API_SECRET");

    if (empty($api_user) || empty($api_secret)) {
        return "error: Sightengine API-Zugangsdaten fehlen in der .env";
    }

    $cfile = new CURLFile($temp_file_path);

    $params = [
            "media" => $cfile,
            "models" => "nudity-2.0,wad,gore",
            "api_user" => $api_user,
            "api_secret" => $api_secret
    ];

    $ch = curl_init("https://api.sightengine.com/1.0/check.json");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);

    if ($response === false) {
        return "error: Verbindung zu Moderations-API fehlgeschlagen";
    }

    $data = json_decode($response, true);

    if (!isset($data["status"]) || $data["status"] !== "success") {
        return "error: " . ($data["error"]["message"] ?? "Unbekannter API-Fehler");
    }

    $firearm_score = (float)($data["weapon_firearm"] ?? $data["weapon"]["firearm"] ?? 0);
    if ($firearm_score > 0.50) {
        return "blocked";
    }

    $nudity_score = (float)($data["nudity"]["sexual_activity"] ?? 0)
            + (float)($data["nudity"]["sexual_display"] ?? 0)
            + (float)($data["nudity"]["erotica"] ?? 0);
    if ($nudity_score > 0.50) {
        return "blocked";
    }

    $gore_score = (float)($data["gore"]["prob"] ?? 0);
    if ($gore_score > 0.50) {
        return "blocked";
    }

    return "ok";
}

function get_include_contents($filename, $variables = []): false|string
{
    if (is_file($filename)) {
        extract($variables);
        ob_start();
        include $filename;
        return ob_get_clean();
    }
    return "Inhalt nicht gefunden.";
}

function generate_safe_password($length = 12): string
{
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&?";
    return substr(str_shuffle(str_repeat($chars, 5)), 0, $length);
}


/*
 * Global exception handlers
 */
#[NoReturn]
function render_error_page(string $title, string $message): void
{
    if (!empty($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest") {
        http_response_code(500);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(["error" => $title . ": " . $message]);
        exit;
    }

    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="icon" type="image/x-icon" href="<?= defined("BASE_URL") ? BASE_URL : '' ?>images/favicon.ico"
              id="icon">
        <title>Magic Empires - <?= $title ?></title>
        <style>
            body {
                background: #1a120b url("images/background.jpg") no-repeat center center fixed;
                background-size: cover;
                color: #e6dcce;
                font-family: 'Georgia', 'Times New Roman', serif;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 15px;
                box-sizing: border-box;
                text-align: center;
            }

            .error-box {
                background: rgba(45, 42, 38, 0.96);
                border: 3px double rgb(165, 124, 0);
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.9);
                border-radius: 8px;
                padding: 0 30px;
                max-width: 520px;
                width: 100%;
            }

            h2 {
                color: rgb(212, 175, 55);
                margin-top: 0;
                font-variant: small-caps;
                letter-spacing: 1px;
                font-size: 26px;
            }

            .info-wrapper {
                background: rgba(236, 16, 16, 0.2);
                border: 1px solid rgba(225, 53, 53, 0.4);
                border-radius: 6px;
                padding: 12px;
                margin: 20px 0;
                color: #ff9999;
                font-size: 15px;
                line-height: 1.4;
            }

            p {
                font-size: 15px;
                line-height: 1.6;
                opacity: 0.9;
            }

            button {
                cursor: pointer;
                background: linear-gradient(0deg, #4b140a 0%, #781e14 39%);
                border: 2px solid rgb(165, 124, 0);
                border-radius: 4px;
                padding: 10px 25px;
                color: #dedede;
                font-weight: bold;
                font-size: 15px;
                margin: 10px;
                transition: 0.2s;
            }
        </style>
    </head>
    <body>
    <div class="error-box">
        <div class="info-wrapper">
            <b><?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") . "<br>Bitte versuche es in ein paar Minuten erneut." ?></b>
        </div>
        <a href="index.php">
            <button type="button">Zur Startseite</button>
        </a>
    </div>
    </body>
    </html>
    <?php
    exit;
}

#[NoReturn]
function global_exception_handler($e): void
{
    error_log("[" . date(ERROR_DATE_FORMAT) . "] " . $e->getMessage() . " on line " . $e->getLine() . " in file " . $e->getFile() . "\nTrace:" . $e->getTraceAsString() . "\n", 3, ERROR_LOG_FILE);
    Logger::get_instance()->error($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());

    render_error_page("Ausnahmefehler", "Ein unerwarteter Fehler ist aufgetreten!");
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
        Logger::get_instance()->error("FATAL: " . $error['message'] . " in " . $error['file']);

        render_error_page("Kritischer Fehler", "Ein fataler Fehler ist aufgetreten!");
    }
}

// Bad words checker
function get_bad_word_pattern(string $bad_word, bool $bound_left = true, bool $bound_right = true): string
{
    $leet_map = [
            'a' => '[a4@ä]',
            'e' => '[e3]',
            'i' => '[i1!|]',
            'l' => '[l1|]',
            'o' => '[o0ö]',
            's' => '[s5$]',
            't' => '[t7+]',
            'b' => '[b8]',
            'u' => '[uü]'
    ];

    $bad_word = mb_strtolower($bad_word, 'UTF-8');
    $chars = preg_split('//u', $bad_word, -1, PREG_SPLIT_NO_EMPTY);
    $regex_parts = [];

    foreach ($chars as $char) {
        $pattern = $leet_map[$char] ?? preg_quote($char, '/');
        $regex_parts[] = $pattern . '+';
    }

    $stretchy_pattern = implode('[.\s_\-\d]*', $regex_parts);

    $left = $bound_left ? '(?<![a-zäöüß])' : '';
    $right = $bound_right ? '(?![a-zäöüß])' : '';

    return '/' . $left . $stretchy_pattern . $right . '/iu';
}

function get_prepared_chat_patterns(): array
{
    static $cached_patterns = null;
    if ($cached_patterns !== null) {
        return $cached_patterns;
    }

    $bad_words = get_bad_words_only();
    usort($bad_words, function ($a, $b) {
        return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
    });

    $cached_patterns = [];

    foreach ($bad_words as $bad) {
        $bad = trim($bad);
        $len = mb_strlen($bad, 'UTF-8');
        if (empty($bad) || $len < 3) continue;

        $bad_lower = mb_strtolower($bad, 'UTF-8');
        if (in_array($bad_lower, ['arsch', 'anal'])) continue;

        $is_strict = ($len < 4 || in_array($bad_lower, ['bad', 'dick', 'ass']));

        $cached_patterns[] = get_bad_word_pattern($bad, $is_strict, $is_strict);
    }

    return $cached_patterns;
}

function contains_bad_words($name, ?array $list = null): bool
{
    if (empty($name)) return false;

    if (preg_match('/(?<![bmhwBMHW])(?<!kl)(?<!ha)(?<!nachb)arsch/iu', $name)
            || preg_match('/(?<!k)anal/iu', $name)) {
        return true;
    }

    $bad_words = $list ?? get_bad_names();
    $split_name = preg_replace('/([a-zäöüß])([A-ZÄÖÜ])/u', '$1 $2', $name);

    foreach ($bad_words as $bad) {
        $bad = trim($bad);
        $len = mb_strlen($bad, 'UTF-8');
        if (empty($bad) || $len < 3) continue;

        $bad_lower = mb_strtolower($bad, 'UTF-8');
        if (in_array($bad_lower, ['arsch', 'anal'])) continue;

        $is_strict = ($len < 4 || in_array($bad_lower, ['bad', 'dick', 'ass']));
        $pattern = get_bad_word_pattern($bad, $is_strict, $is_strict);

        if (preg_match($pattern, $name)) {
            return true;
        }
        if ($name !== $split_name && preg_match($pattern, $split_name)) {
            return true;
        }
    }

    return false;
}

function send_user_push(int $user_id, string $title, string $message, string $category = "combat", string $target_url = "/overview.php"): bool
{
    global $db_instance;

    $valid_categories = ["combat", "troops", "building", "storage", "messages", "events"];
    if (!in_array($category, $valid_categories)) {
        $category = "combat";
    }

    $vapid_public = getenv("VAPID_PUBLIC_KEY") ?: (defined('VAPID_PUBLIC_KEY') ? VAPID_PUBLIC_KEY : '');
    $vapid_private = getenv("VAPID_PRIVATE_KEY") ?: (defined('VAPID_PRIVATE_KEY') ? VAPID_PRIVATE_KEY : '');
    $vapid_subject = getenv("VAPID_SUBJECT") ?: (defined('VAPID_SUBJECT') ? VAPID_SUBJECT : "mailto:webmaster@magic-empires.de");

    if (empty($vapid_public) || empty($vapid_private)) {
        return false;
    }

    try {
        $res_pref = $db_instance->execute_query(
                "SELECT `$category` FROM user_push_settings WHERE user_id = ?",
                [$user_id]
        );
        $is_allowed = !($res_pref->num_rows > 0) || $res_pref->fetch_column();

        if (!$is_allowed) {
            return false;
        }

        $subscriptions = $db_instance->execute_query(
                "SELECT id, endpoint, public_key, auth_token FROM user_push_subscriptions WHERE user_id = ?",
                [$user_id]
        )->fetch_all(MYSQLI_ASSOC);

        if (empty($subscriptions)) {
            return false;
        }

        $auth = [
                "VAPID" => [
                        "subject" => $vapid_subject,
                        "publicKey" => $vapid_public,
                        "privateKey" => $vapid_private,
                ],
        ];

        $http_client = new Client([
                "timeout" => 5,
                "verify" => !((defined("IS_DEV") && IS_DEV)),
        ]);

        $default_options = [
                "timeout" => 5,
        ];

        $web_push = new Minishlink\WebPush\WebPush($auth, $default_options, $http_client);

        $payload = json_encode([
                "title" => $title,
                "body" => $message,
                "icon" => 'images/icons/icon_castle.png',
                "url" => $target_url
        ], JSON_UNESCAPED_UNICODE);

        foreach ($subscriptions as $sub) {
            $push_sub = Minishlink\WebPush\Subscription::create([
                    "endpoint" => $sub["endpoint"],
                    "publicKey" => $sub["public_key"],
                    "authToken" => $sub["auth_token"],
            ]);
            $web_push->queueNotification($push_sub, $payload);
        }

        foreach ($web_push->flush() as $report) {
            if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                Logger::get_instance()->error("WebPush Fehler für User $user_id: " . $report->getReason());

                $db_instance->execute_query(
                        "DELETE FROM user_push_subscriptions WHERE endpoint = ?",
                        [$report->getEndpoint()]
                );
            }
        }

        return true;
    } catch (Throwable $e) {
        Logger::get_instance()->error("WebPush Exception bei User ID $user_id: " . $e->getMessage());
        return false;
    }
}

function get_resource_text(int $cost, int $current_val): string
{
    return ($cost > $current_val ? "<b class='error'>" . fnum($cost) . "</b>" : fnum($cost));
}

function is_name_monotonous($name): bool
{
    $name = mb_strtolower($name, "UTF-8");
    $len = mb_strlen($name);

    // Check: Too many identical characters in succession
    if (preg_match('/(.)\1{3,}/u', $name)) {
        return true;
    }

    // Check: No variety in name
    $unique_chars = count(array_unique(preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY)));

    if ($len > NUM_NAME_LENGTH_CHECK && $unique_chars < NUM_UNIQUE_CHARS) {
        return true;
    }

    return false;
}

function check_ip_proxy($ip): array|null
{
    $api_url = "https://proxycheck.io/v2/" . $ip . "?vpn=1&asn=1";
    $context = stream_context_create(["http" => ["timeout" => 2]]);
    $response = @file_get_contents($api_url, false, $context);

    if ($response === false) {
        return null;
    }

    $details = json_decode($response);

    if (isset($details->$ip)) {
        $ip_info = $details->$ip;

        return [
                "proxy" => $ip_info->proxy ?? "no",
                "type" => $ip_info->type ?? "none",
                "isp" => $ip_info->is ?? $ip_info->asn ?? "Unbekannt"
        ];
    }
    return null;
}

function format_time_for_js(int $totalSeconds): string
{
    if ($totalSeconds <= 0) return "00:00";

    $days = floor($totalSeconds / 86400);
    $hours = floor(($totalSeconds % 86400) / 3600);
    $minutes = floor(($totalSeconds % 3600) / 60);
    $seconds = $totalSeconds % 60;

    $h_display = str_pad($hours, 2, '0', STR_PAD_LEFT);
    $m_display = str_pad($minutes, 2, '0', STR_PAD_LEFT);
    $s_display = str_pad($seconds, 2, '0', STR_PAD_LEFT);

    if ($days > 0) {
        return $days . "T " . $h_display . ":" . $m_display . ":" . $s_display;
    } else if ($hours > 0) {
        return $h_display . ":" . $m_display . ":" . $s_display;
    } else {
        return $m_display . ":" . $s_display;
    }
}

function format_relative_activity(int $timestamp, int $current_time): string
{
    if ($timestamp <= 0) {
        return "Nicht verfügbar";
    }

    $diff = max(0, $current_time - $timestamp);

    if ($diff < 3600) {
        return "in der letzten Stunde";
    }

    if ($diff < 86400) {
        $hours = intdiv($diff, 3600);
        return "vor $hours " . ($hours === 1 ? "Stunde" : "Stunden");
    }

    $days = intdiv($diff, 86400);

    if ($days < 7) {
        return "vor $days " . ($days === 1 ? "Tag" : "Tagen");
    }

    if ($days < 28) {
        $weeks = intdiv($days, 7);
        return "vor $weeks " . ($weeks === 1 ? "Woche" : "Wochen");
    }

    if ($days < 365) {
        $months = max(1, intdiv($days, 30));
        return "vor $months " . ($months === 1 ? "Monat" : "Monaten");
    }

    $years = intdiv($days, 365);
    return "vor $years " . ($years === 1 ? "Jahr" : "Jahren");
}

function render_tutorial_modal(?User $user = null, bool $is_replay = false): string
{
    $db_instance = Database::get_instance()->get_connection();
    $kid = ($user && $user->is_logged_in()) ? $user->get_current_kingdom() : -1;
    $k_info = null;

    if ($kid > 0) {
        $uid = $user->get_user_id();
        $main_kid = $user->get_main_kingdom();

        $query = "
            SELECT ft.fieldname, ft.foodrate, ft.woodrate, ft.stonerate, ft.goldrate
            FROM kingdoms k
            JOIN map m ON k.id = m.kingdomid
            JOIN field_types ft ON m.fieldtype = ft.fieldid
            WHERE k.userid = ?
            ORDER BY 
                (k.creation_method = " . KingdomCreationTypes::KINGDOM_CREATION_INITIAL . ") DESC,
                (k.id = ?) DESC,
                k.created_at
            LIMIT 1
        ";
        $res = $db_instance->execute_query($query, [$uid, $main_kid]);
        $k_info = $res->fetch_assoc();
    }

    $fieldname = $k_info["fieldname"] ?? null;
    $good_res = "";
    if ($k_info) {
        if ($k_info["foodrate"] > 1) $good_res .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . " Nahrung ";
        if ($k_info["woodrate"] > 1) $good_res .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . " Holz ";
        if ($k_info["stonerate"] > 1) $good_res .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . " Stein ";
        if ($k_info["goldrate"] > 1) $good_res .= get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " Gold ";
    }

    if (empty($good_res)) {
        $good_res = "<i>Dieses Land ist ein Allrounder (ausgeglichene Erträge).</i>";
    }

    $intro_text = $fieldname
            ? "Eure Siedlung im <b class='passed'>" . e($fieldname) . "</b> ist bereit."
            : "Eure Siedlung ist bereit.";

    $action_close = $is_replay ? "closeTutorial" : "finishTutorial";
    $btn_label = "Alles klar!";
    $header_title = "Willkommen, Eure Hoheit!";

    return "<div id='tutorial-overlay' class='info-box-bg' style='display:flex;' data-good-res='" . e($good_res) . "'> 
        <div class='big-box-container tutorial-box'> 
            <div class='big-box-header'>$header_title</div> 
            <div class='big-box-content' style='text-align: justify;'> 
                <p style='margin-top: 0;'>$intro_text Die Welt besteht aus 5 Geländetypen 
                (<b>Hochland, Wald, Wüste, Küste und Gebirge</b>) mit jeweils eigenen Erträgen und Marschgeschwindigkeiten. 
                Erkundet euer Umland auf der <a href='map.php' style='color: var(--link-color); text-decoration: underline;' data-on-click='$action_close'><b>Karte</b></a>!</p> 
                <div style='margin-bottom: 15px;'> 
                    <b style='color: var(--link-color);'>1. Ressourcen sichern</b><br> 
                    <p>Baut zuerst <b>Mühle, Sägewerk</b> oder <b>Steinmine</b>. Euer Land liefert extra viel:</p> 
                    <p>$good_res</p> 
                </div> 
                <div style='margin-bottom: 15px;'> 
                    <b style='color: var(--link-color);'>2. Das Dorfzentrum</b><br> 
                    <p>Das Herz eures Reiches. Seine Stufe begrenzt das Level <b>aller</b> anderen Gebäude (außer <b>Lager</b>. Dieses kann eine Stufe höher als das aktuelle Dorfzentrum gebaut werden).</p> 
                </div> 
                <div style='margin-bottom: 15px;'> 
                    <b style='color: var(--link-color);'>3. Schutz & Reparatur</b><br> 
                    <p>Eure <b>Mauer</b> gibt einen Verteidigungsbonus, um Angreifer abzuschrecken. Haltet sie stets repariert!</p> 
                </div> 
                <div class='tutorial-tip'>
                    💡 <b>Tipp:</b> Viele Überschriften, Texte, Symbole und Zahlen verbergen Detailwissen. Fahrt mit der Maus darüber oder <b>tippt sie an</b>, um nützliche Infos zu öffnen!
                </div>
                <div style='text-align: center; margin-top: 20px;'> 
                    <button id='close-tutorial' type='button' data-on-click='$action_close' style='padding: 10px 40px;'>$btn_label</button> 
                </div> 
            </div> 
        </div> 
    </div>";
}
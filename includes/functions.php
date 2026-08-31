<?php

use JetBrains\PhpStorm\NoReturn;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
        BuildingTypes::BUILDING_ESTATE => "manor",
        BuildingTypes::BUILDING_WATCHTOWER => "watchtower",
        BuildingTypes::BUILDING_SHRINE => "shrine",
        BuildingTypes::BUILDING_EMBASSY => "embassy",
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
    if ($show_seconds && ($seconds > 0 || empty($output))) {
        $output .= $seconds . " Sek.";
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

    if ($number >= 1000000) {
        $val = $number / 1000000;
        $truncated = floor(($val + 0.000001) * 100) / 100;
        return number_format($truncated, ($truncated == floor($truncated) ? 0 : 2), ",", ".") . 'M';
    }

    if ($number >= 100000) {
        $val = $number / 1000;
        $truncated = floor(($val + 0.000001) * 10) / 10;
        return number_format($truncated, ($truncated == floor($truncated) ? 0 : 1), ",", ".") . 'k';
    }

    return number_format($number, (floor($number) == $number ? 0 : 1), ",", ".");
}

function fnum($number, bool $simple_format = false): string
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

    $uid = "val_" . substr(md5(mt_rand()), 0, 6);

    return "<span class='popup' id='$uid'>
                $short
                <div id='{$uid}_box' class='popupbox' style=''>
                    $full
                </div>
            </span>";
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

function calculate_listing_fee(int $supply_value): int
{
    return (int)max(1, ceil($supply_value / 20000));
}

function check_image_content($tempFilePath)
{
    $api_url = getenv("CHECK_NSFW_API_URL");
    $api_token = getenv("CHECK_NSFW_API_KEY");

    $imageData = file_get_contents($tempFilePath);
    if ($imageData === false) return "Datei nicht lesbar";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $api_token,
        "Content-Type: application/octet-stream"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        return "Verbindung fehlgeschlagen";
    }

    $result = json_decode($response, true);

    if ($httpCode === 503) return "loading";
    if (isset($result["error"])) return "error: " . $result["error"];

    $nsfw_score = 0;
    $normal_score = 0;

    if (is_array($result)) {
        $data = isset($result[0][0]) ? $result[0] : $result;

        foreach ($data as $prediction) {
            if (isset($prediction["label"]) && isset($prediction["score"])) {
                $label = strtolower($prediction["label"]);

                if ($label === "nsfw") $nsfw_score = $prediction["score"];
                if ($label === "normal") $normal_score = $prediction["score"];
            }
        }
    }

    if ($nsfw_score > $normal_score) {
        return $nsfw_score;
    }

    return 0;
}

function wrap_emojis($text): array|string|null
{
    $emoji_pattern = '/[\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

    return preg_replace($emoji_pattern, '<span class="emoji-fix">$0</span>', $text);
}

function get_chat_emojis(): array
{
    return [
        '😀', '😃', '😄', '😁', '😅', '😂', '🤣', '😊', '😇', '🙂', '😉', '😌', '😍', '🥰', '😘',
        '😎', '🤓', '🧐', '🤨', '🤔', '😐', '😑', '😶', '🙄', '😏', '😣', '😥', '😮', '🤐', '😯',
        '😴', '🥱', '😫', '🤤', '😒', '😓', '😔', '😕', '🙃', '🤑', '😲', '☹️', '🙁', '😖', '😞',
        '😟', '😤', '😡', '😠', '🤬', '😈', '👿', '💀', '☠️', '💩', '🤡', '👻', '😱', '😰', '😢', '😭', '❤️',
        '👍', '👎', '👌', '🤌', '✌️', '🤞', '🤟', '🤘', '🤙', '👊', '👋', '👏', '🙏', '💪', '👃', '🫡', '❓', '❗',
        '⚔️', '🛡️', '🏰', '🏯', '🏹', '🐎', '🔥', '💣', '🧱', '⚒️', '📜', '🗺️', '👑', '🏆', '💎',
        '💰', '🪙', '🍞', '🥩', '🌲', '🪵', '🪨', '⛏️', '🤝', '⚖️', '📦', '🛒', '📈', '📉', '👀', '🦆',
        '✨', '⭐', '🌟', '💥', '🎈', '🎉', '🎊', '🎁', '✅', '❌', '⚠️', '🚩', '🏴', '🍺', '🍻'
    ];
}

function get_unread_news_count($user, $db_instance): int
{
    $uid = $user->get_user_id();
    if ($uid <= 0) return 0;

    $result = $db_instance->execute_query(
        "SELECT COUNT(*) FROM news WHERE id > (SELECT last_news_read FROM users WHERE id = ?)",
        [$uid]
    );
    return (int)$result->fetch_row()[0];
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

// Apply villager cap
function apply_villager_cap(int $kingdom_id): void
{
    if ($kingdom_id <= 0) {
        return;
    }

    $db = Database::get_instance();
    $db_instance = $db->get_connection();
    $result = $db_instance->execute_query("SELECT villager, maxvillager FROM kingdoms WHERE id = ?", [$kingdom_id]);

    // Fetch the villager count from the result and apply the cap if needed
    $row = $result->fetch_assoc();
    if (!$row) {
        return;
    }

    $villager_count = $row["villager"];
    $max_villager = $row["maxvillager"];

    if ($villager_count > $max_villager) {
        $villager_difference = $villager_count - $max_villager;
        $db_instance->execute_query("UPDATE kingdoms SET villager = villager - $villager_difference WHERE id = ?",
            [$kingdom_id]);
    }
}


// Check for an error in a conversation
function get_error(string $text, string $receiver_id): string
{
    $error = "";
    $line_breaks_count = substr_count($text, '<br />');
    $text_without_line_breaks = preg_replace('/<br\s*\/?>/i', '', $text);
    $text_length = mb_strlen(strip_tags($text_without_line_breaks), 'UTF-8');

    // Check different errors
    if ($receiver_id == $_SESSION["userid"]) {
        $error = "Du kannst keine Nachrichten an dich selbst senden!";
    } else if ($_SESSION["msgreceiver"] != $receiver_id) {
        $error = "Bitte nutze nur einen Tab für Konversationen!";
    } else if (strlen(trim(strip_tags($text))) === 0) {
        $error = "Bitte alle Felder ausfüllen!";
    } else if ($text_length > MAX_MESSAGE_LENGTH) {
        $error = "Die Nachricht darf maximal " . MAX_MESSAGE_LENGTH . " Zeichen lang sein!";
    } else if ($line_breaks_count > MAX_LINE_BREAK_COUNT) {
        $error = "Dein Text darf maximal " . MAX_LINE_BREAK_COUNT . " Zeilenumbrüche beinhalten!";
    }
    return $error;
}


/*
 * Global exception handlers
 */
#[NoReturn]
function global_exception_handler($e): void
{
    error_log("[" . date(ERROR_DATE_FORMAT) . "] " . $e->getMessage() . " on line " . $e->getLine() . " in file " . $e->getFile() . "\nTrace:" . $e->getTraceAsString() . "\n", 3, ERROR_LOG_FILE);
    Logger::get_instance()->error($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());

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
        Logger::get_instance()->error("FATAL: " . $error['message'] . " in " . $error['file']);

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

// Login Check
function check_user_login($user): void
{
    if (!($user->is_logged_in())) {
        change_location("index.php");
        exit;
    }
}

// Bad words checker
function contains_bad_words($name, ?array $list = null): bool
{
    $bad_words = $list ?? get_bad_names();

    foreach ($bad_words as $bad) {
        $bad = trim($bad);
        if (empty($bad) || mb_strlen($bad) < 3) continue;

        $pattern = get_bad_word_pattern($bad);

        if (preg_match($pattern, $name)) {
            return true;
        }
    }
    return false;
}

function get_bad_word_pattern($bad_word): string
{
    $leet_map = [
        'a' => '[a4@ä]',
        'e' => '[e3]',
        'i' => '[i1!|]',
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

    $stretchy_pattern = implode('[.\s_\-\d\p{P}\p{C}]*', $regex_parts);

    return "/(?<![a-zäöüß])" . $stretchy_pattern . "/iu";
}

function filter_chat_message($text)
{
    $bad_words = get_bad_words_only();

    static $sorted_bad_words = null;

    if ($sorted_bad_words === null) {
        $sorted_bad_words = $bad_words;
        usort($sorted_bad_words, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });
    }

    $filtered_text = $text;

    foreach ($bad_words as $bad) {
        $bad = trim($bad);
        if (empty($bad) || mb_strlen($bad) < 3) continue;

        $pattern = get_bad_word_pattern($bad);

        $filtered_text = preg_replace_callback($pattern, function ($matches) {
            return str_repeat('*', mb_strlen($matches[0]));
        }, $filtered_text);
    }

    return $filtered_text;
}

function is_message_blocked($text): bool
{
    $bad_words = get_bad_names();

    foreach ($bad_words as $bad) {
        $bad = trim($bad);
        if (empty($bad) || mb_strlen($bad) < 3) continue;

        $pattern = get_bad_word_pattern($bad);

        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    return false;
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

function send_server_message(int $user_id, string $user_name, string $message, string $category = MessageCategories::CATEGORY_DEFAULT,
                                 $data = null): void
{
    $db = Database::get_instance();
    $json = null;

    if (is_array($data)) {
        $json = json_encode($data);
    }

    $db->get_connection()->execute_query("INSERT INTO server_messages (receiverid, receiver, date, message, category, data_json) VALUES (?, ?, ?, ?, ?, ?)",
        [$user_id, $user_name, time(), $message, $category, $json]);
}

function get_resource_text(int $cost, int $current_val): string
{
    return ($cost > $current_val ? "<b class='error'>" . fnum($cost) . "</b>" : fnum($cost));
}

function delete_user_avatar_files(int $user_id): void
{
    $hashedName = substr(hash("sha256", $user_id . AVATAR_SALT), 0, 12);
    $directory = __DIR__ . "/../" . UPLOADS_FILE_PATH;
    $files = glob($directory . $hashedName . ".*");

    if (!empty($files)) {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
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

function update_global_stat(string $name, int $increment = 1): void
{
    global $db_instance;

    $query = "UPDATE system_settings SET value = value + ? WHERE name = ?";
    $db_instance->execute_query($query, [$increment, $name]);
}

function update_player_stat(int $user_id, string $column, $increment = 1): void
{
    global $db_instance;

    $query = "INSERT INTO player_stats (userid, `$column`) VALUES (?, ?) 
              ON DUPLICATE KEY UPDATE `$column` = `$column` + VALUES(`$column`)";

    $db_instance->execute_query($query, [$user_id, $increment]);
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

function check_for_incoming_attacks(int $uid, mysqli $db): array
{
    $now = time();

    $query = "
        SELECT e.eventid, e.arrivaltime, k.kingdomname, e.targetx, e.targety
        FROM events e
        JOIN kingdoms k ON e.targetid = k.id
        JOIN buildings b ON k.id = b.kingdomid AND b.buildingid = " . BuildingTypes::BUILDING_WATCHTOWER . "
        WHERE k.userid = ? 
          AND e.userid != k.userid
          AND e.actionid = " . ActionTypes::ACTION_SEND_TROOPS . "
          AND e.is_processing = 0
          AND e.arrivaltime > ?
          AND (e.arrivaltime - ?) <= (b.buildinglevel * " . WATCHTOWER_DETECTION_PER_LEVEL . ")
        ORDER BY e.arrivaltime
    ";

    $result = $db->execute_query($query, [$uid, $now, $now]);
    $attacks = $result->fetch_all(MYSQLI_ASSOC);

    $ack_ids = $_SESSION["acknowledged_attacks"] ?? [];
    foreach ($attacks as &$attack) {
        $attack["is_new"] = !in_array($attack["eventid"], $ack_ids);
    }

    return $attacks;
}

function check_for_incoming_support(int $uid, mysqli $db): array
{
    $now = time();
    
    $query = "
        SELECT e.eventid, e.arrivaltime, k.kingdomname, e.targetx, e.targety, u.username AS sender_name
        FROM events e
        JOIN kingdoms k ON e.targetid = k.id
        JOIN users u ON e.userid = u.id
        WHERE k.userid = ? 
          AND e.actionid = ? 
          AND e.arrivaltime > ?
        ORDER BY e.arrivaltime
    ";
    $result = $db->execute_query($query, [$uid, ActionTypes::ACTION_STATION_TROOPS, $now]);
    $supports = $result->fetch_all(MYSQLI_ASSOC);

    $ack_ids = $_SESSION["acknowledged_supports"] ?? [];
    foreach ($supports as &$sup) {
        $sup["is_new"] = !in_array($sup["eventid"], $ack_ids);
    }

    return $supports;
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

function parse_chat_quotes(string $text): string
{
    $max_depth = 5;
    $depth = 0;
    $open_divs = 0;
    $output = '';

    $pattern = '#(\[quote=[^\[\]]+]|\[/quote])#iu';
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

    foreach ($parts as $part) {
        if ($part === '' || $part === null) continue;

        if (preg_match('#^\[quote=([^]]+)]$#i', $part, $m)) {
            $depth++;

            if ($depth <= $max_depth) {
                $name = trim(strip_tags(preg_replace('/\p{C}/u', '', $m[1])));

                if (preg_match('/^[a-zA-Z0-9äöüÄÖÜß \-_]{1,24}$/u', $name)) {
                    $output .= '<div class="chat-quote"><span class="chat-quote-author">' . e($name) . ' schrieb:</span>';
                    $open_divs++;
                } else {
                    $output .= e($part);
                    $depth--;
                }
            }
        } elseif ($part === '[/quote]') {
            if ($depth > 0) {
                if ($depth <= $max_depth && $open_divs > 0) {
                    $output .= '</div>';
                    $open_divs--;
                }
                $depth--;
            }
        } else {
            $output .= $part;
        }
    }

    while ($open_divs > 0) {
        $output .= '</div>';
        $open_divs--;
    }

    return $output;
}

function render_reactions_bar(string $type, int $id, User $user, string $mode = 'full'): string
{
    $my_id = $user->get_user_id();
    $db = Database::get_instance()->get_connection();

    $badges_html = '';
    if ($mode !== "btn_only") {
        $query = "SELECT r.emoji, COUNT(*) as total, 
                         MAX(IF(r.user_id = ?, 1, 0)) as self_reacted,
                         GROUP_CONCAT(u.username ORDER BY r.id ASC SEPARATOR ', ') as names
                  FROM reactions r
                  JOIN users u ON r.user_id = u.id
                  WHERE r.entity_type = ? AND r.entity_id = ? 
                  GROUP BY r.emoji";
        $res = $db->execute_query($query, [$my_id, $type, $id]);

        while ($row = $res->fetch_assoc()) {
            $active_class = ($row["self_reacted"] == 1) ? " active" : '';

            $user_list = e($row["names"]);
            $popup_id = "reac_" . $type . "_" . $id . "_" . md5($row["emoji"]);

            $badges_html .= '
                <span class="reaction-badge' . $active_class . ' popup" id="' . $popup_id . '"
                      data-on-click="toggleReaction" 
                      data-type="' . $type . '" 
                      data-id="' . $id . '" 
                      data-emoji="' . e($row["emoji"]) . '">
                    ' . e($row["emoji"]) . ' <small>' . $row["total"] . '</small>
                    <div id="' . $popup_id . '_box" class="popupbox">
                        <b>Reaktionen:</b><br>' . $user_list . '
                    </div>
                </span>';
        }
    }

    $picker_html = '';
    if ($mode === "full" || $mode === "btn_only") {
        $picker_html = '<div class="reaction-add-wrapper" style="position:relative; display:inline-block;">
                            <span class="reaction-add" data-on-click="toggleReactionPicker">🙂</span>
                            <div class="reaction-picker" style="display:none;">';
        foreach (get_chat_emojis() as $emoji) {
            $picker_html .= '<span class="picker-emoji" data-on-click="toggleReaction" 
                            data-type="' . $type . '" data-id="' . $id . '" 
                            data-emoji="' . e($emoji) . '">' . e($emoji) . '</span>';
        }
        $picker_html .= '</div></div>';
    }

    $html = '<div class="reaction-container mode-' . $mode . '" data-type="' . $type . '" data-id="' . $id . '">';

    $res_count = $db->execute_query("SELECT COUNT(*) FROM reactions WHERE entity_type = ? AND entity_id = ?", [$type, $id]);
    $has_reactions = ($res_count->fetch_row()[0] > 0);

    $info_btn = "";
    if ($has_reactions) {
        $info_btn = "<img src='images/icons/icon_feedback.png' 
                      class='ressource-icons' 
                      style='cursor: pointer; opacity: 0.7;' 
                      data-on-click='openReactorList' 
                      data-type='$type' 
                      data-id='$id' 
                      title='Wer hat reagiert?' alt=''>";
    }

    if ($mode === "full") {
        $html .= '<div class="reaction-bar-btn-row">' . $picker_html . '</div>';
        $html .= '<div class="reaction-bar-badges-row">' . $badges_html . '</div>';
    } else if ($mode === "btn_only") {
        $html .= $picker_html . $info_btn;
    } else if ($mode === "badges_only") {
        $html .= $badges_html;
    }

    $html .= '</div>';
    return $html;
}
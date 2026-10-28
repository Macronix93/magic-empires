<?php

class Database
{
    private static ?Database $_instance = null;
    private ?mysqli $_connection;

    /**
     * Database constructor.
     * @throws Exception
     */
    private function __construct()
    {
        $max_retries = 2;
        $attempt = 0;
        $connected = false;

        while ($attempt < $max_retries && !$connected) {
            $attempt++;
            try {
                $this->_connection = new mysqli(
                    getenv("HOST"),
                    getenv("USER"),
                    getenv("PASSWORD"),
                    getenv("DATABASE"),
                    getenv("PORT")
                );

                $connected = true;
            } catch (mysqli_sql_exception $e) {
                if ($attempt < $max_retries) {
                    usleep(250000);
                    continue;
                }

                if (class_exists("Logger")) {
                    $url = $_SERVER["REQUEST_URI"] ?? "CLI";
                    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
                    $is_ajax = (!empty($_SERVER["HTTP_X_REQUESTED_WITH"]) && strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest") ? " [AJAX]" : "";

                    $post_info = "";
                    if ($method === "POST" && !empty($_POST)) {
                        $keys = implode(", ", array_keys($_POST));
                        $post_info = " | POST-Keys: ($keys)";
                    }

                    $target = (getenv("HOST") ?: "127.0.0.1") . ":" . (getenv("PORT") ?: 3306);

                    $details = sprintf(
                        "DB-Verbindungsfehler nach %d Versuchen zu [%s]! Aufruf: [%s %s%s]%s | Fehler (%d): %s",
                        $attempt,
                        $target,
                        $method,
                        $url,
                        $is_ajax,
                        $post_info,
                        $e->getCode(),
                        $e->getMessage()
                    );

                    Logger::get_instance()->error($details);
                }

                // CLI (Cronjobs) clean errorcode
                if (php_sapi_name() === "cli") {
                    fwrite(STDERR, "[" . date("Y-m-d H:i:s") . "] DB Connection Error: " . $e->getMessage() . PHP_EOL);
                    exit(1);
                }

                $favicon_url = (defined("BASE_URL") ? BASE_URL : '') . "images/favicon.ico";

                http_response_code(503);
                die("
                <!DOCTYPE html>
                <html lang='de'>
                <head>
                    <meta charset='UTF-8'>
                    <link rel='icon' type='image/x-icon' href='$favicon_url' id='icon'>
                    <title>Magic Empires - Wartung</title>
                    <style>
                        body {
                            background: #1a120b;
                            color: #e6dcce;
                            font-family: Georgia, serif;
                            display: flex;
                            justify-content: center;
                            align-items: center;
                            height: 100vh;
                            margin: 0;
                            text-align: center;
                        }
                        .box {
                            background: rgba(45, 42, 38, 0.95);
                            border: 3px double rgb(165, 124, 0);
                            border-radius: 8px;
                            padding: 30px 40px;
                            max-width: 500px;
                            box-shadow: 0 10px 30px rgba(0,0,0,0.8);
                        }
                        h2 { color: rgb(212, 175, 55); margin-top: 0; }
                    </style>
                </head>
                <body>
                    <div class='box'>
                        <h2>Das Reich formiert sich...</h2>
                        <p>Die Schatzkammern und Archive werden im Moment gewartet.</p>
                        <p style='font-size: 14px; opacity: 0.8;'>Bitte lade die Seite in wenigen Sekunden erneut.</p>
                    </div>
                </body>
                </html>");
            }
        }
    }

    public static function get_instance(): Database
    {
        // If no instance then make one
        if (!self::$_instance) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __destruct()
    {
        $this->_connection?->close();
    }

    public function get_connection(): mysqli
    {
        return $this->_connection;
    }

    /**
     * @throws Exception
     */
    private function __clone()
    {
        throw new Exception("Singleton kann nicht geklont werden.");
    }
}
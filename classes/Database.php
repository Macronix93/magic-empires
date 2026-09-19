<?php

class Database
{
    private static ?Database $_instance = null;
    private ?mysqli $_connection; // The single instance

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

                if (class_exists('Logger')) {
                    Logger::get_instance()->error("Datenbank-Verbindung fehlgeschlagen: " . $e->getMessage());
                }

                // CLI (Cronjobs) clean errorcode
                if (php_sapi_name() === "cli") {
                    fwrite(STDERR, "[" . date("Y-m-d H:i:s") . "] DB Connection Error: " . $e->getMessage() . PHP_EOL);
                    exit(1);
                }

                http_response_code(503);
                die("
                <!DOCTYPE html>
                <html lang='de'>
                <head>
                    <meta charset='UTF-8'>
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

    // Constructor
    public static function get_instance(): Database
    {
        // If no instance then make one
        if (!self::$_instance) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    // Magic method clone is empty to prevent duplication of connection
    public function get_connection(): mysqli
    {
        return $this->_connection;
    }

    // Get mysqli connection

    /**
     * @throws Exception
     */
    private function __clone()
    {
        throw new Exception("Singleton kann nicht geklont werden.");
    }
}
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
        $this->_connection = @new mysqli(
            getenv("HOST"),
            getenv("USER"),
            getenv("PASSWORD"),
            getenv("DATABASE"),
            getenv("PORT")
        );

        if ($this->_connection->connect_error) {
            throw new Exception("Fehler bei der MYSQL-Verbindung: " . $this->_connection->connect_error);
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
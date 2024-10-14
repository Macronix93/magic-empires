<?php

class Database
{
    private static $_instance;
    private object $_connection; // The single instance

    /*
    Get an instance of the Database
    @return Instance
    */
    private function __construct()
    {
        try {
            $this->_connection = new mysqli(getenv("HOST"), getenv("USER"), getenv("PASSWORD"), getenv("DATABASE"), getenv("PORT"));
        } catch (Exception $e) {
            trigger_error("Fehler bei der MYSQL-Verbindung: " . mysqli_connect_error() . $e, E_USER_ERROR);
        }
    }

    // Constructor
    public static function get_instance(): Database
    {
        if (!self::$_instance) // If no instance then make one
        {
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
        throw new Exception("Can't clone a singleton");
    }
}
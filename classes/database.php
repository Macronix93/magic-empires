<?php

class Database {
    private object $_connection;
    private static $_instance; //The single instance

    /*
    Get an instance of the Database
    @return Instance
    */
    public static function get_instance(): Database {
        if (!self::$_instance) // If no instance then make one
        {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    // Constructor
    private function __construct() {
        try {
            $this->_connection = new mysqli(getenv("HOST"), getenv("USER"), getenv("PASSWORD"), getenv("DATABASE"), getenv("PORT"));
        } catch (Exception $e) {
            trigger_error("Fehler bei der MYSQL-Verbindung: " . mysqli_connect_error() . $e, E_USER_ERROR);
        }
    }

    // Magic method clone is empty to prevent duplication of connection
    private function __clone() {
    }

    // Get mysqli connection
    public function get_connection(): mysqli {
        return $this->_connection;
    }
}
<?php

class Database
{
	private $_connection;
	private static $_instance; //The single instance
	private $_host = HOST;
	private $_username = USER;
	private $_password = PASSWORD;
	private $_database = DATABASE;
	private $_port = PORT;

	/*
	Get an instance of the Database
	@return Instance
	*/
	public static function getInstance()
	{
		if(!self::$_instance) // If no instance then make one
		{
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	// Constructor
	private function __construct()
	{
		try
		{
			$this->_connection = new mysqli($this->_host, $this->_username, $this->_password, $this->_database, $this->_port);
		}
		catch(Exception $e)
		{
			trigger_error("Fehler bei der MYSQL-Verbindung: " . mysqli_connect_error(), E_USER_ERROR);
		}
	}

	// Magic method clone is empty to prevent duplication of connection
	private function __clone()
	{

	}

	// Get mysqli connection
	public function getConnection()
	{
		return $this->_connection;
	}
}
?>
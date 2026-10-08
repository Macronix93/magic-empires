<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once(__DIR__ . "/../includes/core.php");

$spawner = new MapSpawner($db_instance);

// Spawn Mines
$mines = $spawner->spawn_mines();
echo "[" . date("H:i:s") . "] $mines Minen balance-optimiert platziert.\n";

// Spawn Resource Camps
$res = $spawner->spawn_resources();
echo "[" . date("H:i:s") . "] $res neue Rohstofffelder per Batch generiert.\n";
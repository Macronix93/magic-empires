<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once(__DIR__ . "/../includes/core.php");

$we_logic = new WorldEvent($db_instance);
$today_day = (int)date('w'); // 0 (Sunday) to 6 (Saturday)
$today_start = strtotime("today midnight");

if ($today_day !== 2 && $today_day !== 5) {
    die("[" . date("H:i:s") . "] Abbruch: Heute ist kein geplanter Event-Tag (Di/Fr).\n");
}

$check = $db_instance->execute_query("SELECT id FROM world_events WHERE start_time >= ?", [$today_start]);
if ($check->num_rows > 0) {
    die("[" . date("H:i:s") . "] Abbruch: Heute wurde bereits ein Event gestartet.\n");
}

// Boss Rotation
$last_type = $we_logic->get_last_event_type();
$new_type = ($last_type === "BOSS_HP") ? "DAMAGE" : "BOSS_HP";

// Spawn Event and notify users
$we_logic->spawn_event($new_type);
$we_logic->broadcast_spawn_notification($new_type);

echo "[" . date("H:i:s") . "] Erfolg: Welt-Event [$new_type] wurde gestartet und Spieler benachrichtigt.\n";
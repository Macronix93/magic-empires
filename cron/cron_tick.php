<?php
// Simulation für CLI
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once("../includes/core.php");

$db = Database::get_instance()->get_connection();
$now = time();

echo "[" . date("Y-m-d H:i:s") . "] Starte Cron-Tick...\n";

$query = "SELECT e.*, u.username 
          FROM events e
          JOIN users u ON e.userid = u.id
          WHERE e.is_processing = 0 
          AND (
              (e.actionid IN (0, 4, 8) AND e.buildingtime > 0 AND e.buildingtime <= ?) 
              OR
              (e.actionid IN (2, 3, 5, 6) AND e.arrivaltime > 0 AND e.arrivaltime <= ?)
              OR
              (e.actionid = 1 AND e.buildingtime <= ?)
              OR
              (e.actionid = 7 AND e.recruittime > 0 AND e.recruittime <= ?)
          )";

$result = $db_instance->execute_query($query, [$now, $now, $now, $now]);

$count = 0;
foreach ($result as $row) {
    // Locking
    $db->execute_query("UPDATE events SET is_processing = ? WHERE eventid = ?", [$now, $row["eventid"]]);

    if ($db->affected_rows > 0) {
        try {
            $event_owner = new User((int)$row["userid"], $row["username"]);
            $GLOBALS['user'] = $event_owner;

            $em = new EventManager($event_owner);
            $em->handle_event($row);

            $count++;
        } catch (Throwable $t) {
            $db->execute_query("UPDATE events SET is_processing = 0 WHERE eventid = ?", [$row["eventid"]]);
            error_log("Cronjob Error in Event " . $row["eventid"] . ": " . $t->getMessage());
        }
    }
}

if ($count > 0) {
    echo "[" . date("H:i:s") . "] $count Events processed.\n";
}
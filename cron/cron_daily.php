<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once("../includes/core.php");

$db = Database::get_instance()->get_connection();

$now = time();
$activity_threshold = $now - INACTIVITY_DELAY;

$res = $db->execute_query(
    "SELECT id, username FROM users WHERE status = 1 AND is_banned = 0 AND lastactivity > ?",
    [$activity_threshold]
);
$eligible_players = $res->fetch_all(MYSQLI_ASSOC);

if (empty($eligible_players)) {
    $res_fallback = $db->query("SELECT id, username FROM users WHERE status = 1 AND is_banned = 0");
    $eligible_players = $res_fallback->fetch_all(MYSQLI_ASSOC);
}

$player_count = count($eligible_players);

if ($player_count > 0) {
    $step = defined("HERO_DISTRIBUTION_PLAYER_STEP") ? HERO_DISTRIBUTION_PLAYER_STEP : 6;
    $heroes_to_give = min($player_count, max(1, (int)floor($player_count / $step)));

    // Every player can only get one hero
    shuffle($eligible_players);
    $winners = array_slice($eligible_players, 0, $heroes_to_give);

    $res_hero_name = $db->execute_query("SELECT soldiername FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_HERO]);
    $hero_db_name = $res_hero_name->fetch_column() ?: "Held";

    $given_count = 0;
    foreach ($winners as $winner) {
        $uid = (int)$winner["id"];
        $uname = $winner["username"];

        // Choose random kingdom of the user
        $res_k = $db->execute_query("SELECT id, kingdomname FROM kingdoms WHERE userid = ? ORDER BY RAND() LIMIT 1", [$uid]);
        $k_data = $res_k->fetch_assoc();
        if ($k_data) {
            $kid = (int)$k_data["id"];
            $kname = $k_data["kingdomname"];

            $db->execute_query(
                "INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
                 VALUES (?, ?, ?, 1) 
                 ON DUPLICATE KEY UPDATE soldiercount = soldiercount + 1",
                [$kid, Soldiers::SOLDIER_HERO, $hero_db_name]
            );

            $hero_json = [
                "template" => "daily_hero_received",
                "kingdom_name" => $kname
            ];
            Messages::send_server_message($uid, $uname, MessageCategories::CATEGORY_DEFAULT, $hero_json);

            echo "[" . date("H:i:s") . "] Held vergeben an $uname im Königreich $kname (ID: $kid)\n";
            $given_count++;
        }
    }
    echo "[" . date("H:i:s") . "] Insgesamt $given_count Helden an $player_count berechtigte Spieler verteilt.\n";
} else {
    echo "[" . date("H:i:s") . "] Abbruch: Kein einziger berechtigter Spieler mit Königreich in der DB.\n";
}

// Support Cleanup
$delete_limit = $now - (SUPPORT_TICKET_AUTO_DELETE_DAYS * 86400);
$db->execute_query("DELETE FROM support_tickets WHERE status = 0 AND closed_at < ?", [$delete_limit]);

$deleted_count = $db->affected_rows;
if ($deleted_count > 0) {
    echo "[" . date("H:i:s") . "] Support-Cleanup: $deleted_count alte Tickets gelöscht.\n";
}

// Abandoned Kingdoms Cleanup
$db->execute_query("DELETE FROM abandoned_kingdoms WHERE expires_at < ?", [$now]);
$db->execute_query("UPDATE map SET kingdomid = -1 WHERE kingdomid = -4 AND (mapx, mapy) NOT IN (SELECT mapx, mapy FROM abandoned_kingdoms)");

// Spawn Monster Camps
$camps = new MapSpawner($db)->spawn_monster_camps();
echo "[" . date("H:i:s") . "] $camps Monstercamps balance-optimiert generiert.\n";

// Cleanup old events
$we_logic = new WorldEvent();
$deleted_events = $we_logic->cleanup_old_events();
if ($deleted_events > 0) {
    echo "[" . date("H:i:s") . "] Cleanup: $deleted_events alte Welt-Events aus der Datenbank entfernt.\n";
}
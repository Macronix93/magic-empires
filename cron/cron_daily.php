<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once("../includes/core.php");

$db = Database::get_instance()->get_connection();

$activity_threshold = time() - INACTIVITY_DELAY;

$query_active = "SELECT u.id, u.username, u.mainkingdom, k.kingdomname 
          FROM users u 
          JOIN kingdoms k ON u.mainkingdom = k.id 
          WHERE u.status = 1 
            AND u.is_banned = 0 
            AND u.lastactivity > ? 
          ORDER BY RAND() LIMIT 1";

$res = $db->execute_query($query_active, [$activity_threshold]);
$winner = $res->fetch_assoc();

if (!$winner) {
    echo "Kein aktiver Spieler gefunden. Wähle aus allen berechtigten Spielern...\n";

    $query_fallback = "SELECT u.id, u.username, u.mainkingdom, k.kingdomname 
          FROM users u 
          JOIN kingdoms k ON u.mainkingdom = k.id 
          WHERE u.status = 1 
            AND u.is_banned = 0 
          ORDER BY RAND() LIMIT 1";

    $res = $db->execute_query($query_fallback);
    $winner = $res->fetch_assoc();
}

if ($winner) {
    $uid = $winner["id"];
    $kid = $winner["mainkingdom"];
    $kname = $winner["kingdomname"];
    $uname = $winner["username"];

    $res_hero_name = $db->execute_query("SELECT soldiername FROM soldier_list WHERE id = ?", [Soldiers::SOLDIER_HERO]);
    $hero_db_name = $res_hero_name->fetch_column() ?: 'Held';

    $db->execute_query("INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
                        VALUES (?, ?, ?, 1) 
                        ON DUPLICATE KEY UPDATE soldiercount = soldiercount + 1",
        [$kid, Soldiers::SOLDIER_HERO, $hero_db_name]);

    $msg = "✨ <b>Göttliche Fügung!</b> ✨<br>Ein legendärer Held hat von deinen Taten gehört und sich entschlossen, deinem Königreich <b>" . e($kname) . "</b> beizutreten!";
    send_server_message($uid, $uname, $msg);

    echo "[" . date("H:i:s") . "] Held vergeben an $uname im Königreich $kname (ID: $kid)\n";
} else {
    echo "[" . date("H:i:s") . "] Abbruch: Kein einziger berechtigter Spieler mit Königreich in der DB.\n";
}

// Support Cleanup
$delete_limit = time() - (SUPPORT_TICKET_AUTO_DELETE_DAYS * 86400);
$db->execute_query("DELETE FROM support_tickets WHERE status = 0 AND closed_at < ?", [$delete_limit]);

$deleted_count = $db->affected_rows;
if ($deleted_count > 0) {
    echo "[" . date("H:i:s") . "] Support-Cleanup: $deleted_count alte Tickets gelöscht.\n";
}
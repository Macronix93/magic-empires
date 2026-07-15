<?php
$_SERVER["HTTP_HOST"] = "localhost";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["REQUEST_METHOD"] = "GET";

require_once("../includes/core.php");

$db = Database::get_instance()->get_connection();

$query = "SELECT u.id, u.username, u.mainkingdom, k.kingdomname 
          FROM users u 
          JOIN kingdoms k ON u.mainkingdom = k.id 
          WHERE u.status = 1 AND u.is_banned = 0 
          ORDER BY RAND() LIMIT 1";

$res = $db->execute_query($query);
$winner = $res->fetch_assoc();

if ($winner) {
    $uid = $winner["id"];
    $kid = $winner["mainkingdom"];
    $kname = $winner["kingdomname"];
    $uname = $winner["username"];

    $db->execute_query("INSERT INTO soldiers (kingdomid, soldierid, soldiername, soldiercount) 
                        VALUES (?, ?, 'Held', 1) 
                        ON DUPLICATE KEY UPDATE soldiercount = soldiercount + 1", [$kid, Soldiers::SOLDIER_HERO]);

    $msg = "✨ <b>Göttliche Fügung!</b> ✨<br>Ein legendärer Held hat von deinen Taten gehört und sich entschlossen, deinem Königreich <b>" . e($kname) . "</b> beizutreten!";
    send_server_message($uid, $uname, $msg);

    echo "Held vergeben an $uname im Königreich $kname";
}

// Delete closed support tickets
$delete_limit = time() - (SUPPORT_TICKET_AUTO_DELETE_DAYS * 86400);
$db->execute_query("DELETE FROM support_tickets WHERE status = 0 AND closed_at < ?", [$delete_limit]);

$deleted_count = $db->affected_rows;

if ($deleted_count > 0) {
    echo "[" . date("H:i:s") . "] Support-Cleanup: $deleted_count alte Tickets gelöscht.\n";
}
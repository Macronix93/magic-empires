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
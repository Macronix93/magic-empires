<?php
require_once("includes/core.php");
check_user_login($user);

// Get user data
$uid = $user->get_user_id();
$query = "SELECT u.*, k.kingdomname, k.mapx, k.mapy 
          FROM users u 
          JOIN kingdoms k ON u.mainkingdom = k.id 
          WHERE u.id = ?";
$res = $db_instance->execute_query($query, [$uid]);
$data = $res->fetch_assoc();

// Calculate rank
$rank_res = $db_instance->execute_query("SELECT COUNT(*) + 1 AS rank FROM users WHERE score > ?", [$data["score"]]);
$user_rank = $rank_res->fetch_column();

$time_diff = time() - $_SESSION["currlogin"];

$view = "
<div style='text-align: center; margin-bottom: 20px;'>
    <img src='{$user->get_avatar()}' class='user-image' style='width: 80px; height: 80px; border-radius: 10px; border: 2px solid var(--border-gold);' alt='Avatar'>
    <h2 style='margin: 10px 0;'>{$data["username"]}</h2>
</div>

<div class='title-border'>Account-Informationen</div>
<table class='table' style='max-width: 600px;'>
    <tr><td><b>Spieler-ID:</b></td><td>#{$data["id"]}</td></tr>
    <tr><td><b>E-Mail Adresse:</b></td><td>{$data["email"]}</td></tr>
    <tr><td><b>Registriert seit:</b></td><td>" . date("d.m.Y H:i", $data["registerdate"]) . " Uhr</td></tr>
    <tr><td><b>Letzter Login:</b></td><td>" . date("d.m.Y H:i", $data["lastlogin"]) . " Uhr</td></tr>
    <tr><td><b>Login-Zeit:</td><td><span id='login-counter' data-start='$time_diff'></span></td></tr>
</table>

<br>

<div class='title-border'>Statistiken & Fortschritt</div>
<table class='table' style='max-width: 600px;'>
    <tr><td><b>Gesamtpunkte:</b></td><td>" . fnum($data["score"]) . "</td></tr>
    <tr><td><b>Globaler Rang:</b></td><td>$user_rank</td></tr>
    <tr><td><b>Haupt-Königreich:</b></td><td>{$data["kingdomname"]} ({$data["mapx"]}:{$data["mapy"]})</td></tr>
    <tr><td><b>Gilde:</b></td><td>" . ($data["guildid"] == -1 ? "Keine Gilde" : $data["guildid"]) . "</td></tr>
    <tr><td><b>Admin-Level:</b></td><td>{$data["adminlevel"]}</td></tr>
</table>
";


$title = "Account-Info";
$header = "Account-Info";
$script_files = ["counter"];

include("layout/base.php");
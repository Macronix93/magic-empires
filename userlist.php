<?php
require_once("includes/core.php");

check_user_login($user);

// Get the complete userlist
$result = $db_instance->execute_query("SELECT username FROM users WHERE username != ? ORDER BY username", [$user->get_user_name()]);
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<table class="table" style="margin-top: 20px; min-width: 300px;">
    <tr>
        <td class="td-center td-gradient">
            <b>Spielerliste</b>
        </td>
    </tr>
    <?php
    foreach ($result as $row) {
        $username = htmlspecialchars($row["username"], ENT_QUOTES);
        
        echo "<tr><td><a href=\"#\" onclick=\"selectUser('$username'); return false;\">$username</a></td></tr>";
    }
    ?>
</table>
<br>
<div style="text-align:center">
    <a href="#" onclick="closeOverlay()"
       style="background-color: rgba(0, 0, 0, 0.7); display: inline-block; padding: 10px;">[Schließen]</a>
</div>
</body>
</html>
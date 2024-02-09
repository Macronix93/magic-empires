<?php
global $db_instance, $user;
require_once("functions.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php", 0);
    exit;
}

$stmt = $db_instance->prepare("SELECT username FROM users ORDER BY username");
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
?>
<script type="text/javascript">
    function selectUser(id) {
        opener.newmessage.receiver.value = id;
    }
</script>
<table class="table" style="margin-top: 20px; min-width: 300px;">
    <tr>
        <td class="td-center td-gradient"><b>Benutzerliste</b></td>
    </tr>
    <?php
    while ($row = $result->fetch_assoc()) {
        if ($row["username"] == $user->getUserName()) {
            continue;
        }
        echo "<tr><td><a href='javascript:selectUser(\"" . $row["username"] . "\")'>" . $row["username"] . "</a></td></tr>";
    }
    ?>
</table>
<br>
<div style="text-align:center">
    <a href="javascript:window.close()"
       style="background-color: rgba(0, 0, 0, 0.7); display: inline-block; padding: 10px;">[Schließen]</a>
</div>
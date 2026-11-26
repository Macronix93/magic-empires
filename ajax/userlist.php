<?php
require_once("../includes/ajax_core.php");

$result = $db_instance->execute_query("SELECT username FROM users WHERE status = 1 AND username != ? ORDER BY username",
        [$user->get_user_name()]
);
?>
<div id="modal-title-data" data-title="Spielerliste" data-width="300px" style="display: none;"></div>
<table class="table">
    <?php foreach ($result as $row): $username = e($row["username"]); ?>
        <tr>
            <td><a href="#" data-on-click="pickUser" data-username="<?= $username ?>"><?= $username ?></a></td>
        </tr>
    <?php endforeach; ?>
</table>
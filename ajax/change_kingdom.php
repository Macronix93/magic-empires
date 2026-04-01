<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $chosen = $_POST["choosekingdom"] ?? null;

    if (isset($chosen)) {
        if (!is_numeric($chosen)) {
            return;
        }

        // Check if kingdom belongs to the player
        $result = $db_instance->execute_query("SELECT userid FROM kingdoms WHERE id = ?", [$chosen]);
        $user_id = $result->fetch_assoc()["userid"];

        // Change current kingdom for the user
        if ($user_id == $user->get_user_id()) {
            $_SESSION["kingdomid"] = (int)$chosen;
            $user->set_current_kingdom((int)$chosen);
        }
    }
} else {
    change_location("index.php");
}
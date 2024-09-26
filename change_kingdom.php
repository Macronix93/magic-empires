<?php
global $user, $db_instance;
require_once("includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    if (isset($_POST['choosekingdom'])) {
        if (!is_numeric($_POST['choosekingdom'])) {
            return;
        }

        // Check if kingdom belongs to the player
        $result = $db_instance->execute_query("SELECT userid FROM kingdoms WHERE id = ?", [$_POST['choosekingdom']]);
        $row = $result->fetch_assoc();

        if ($row['userid'] == $user->get_user_id()) {
            // Change current kingdom for the user
            $_SESSION["kingdomid"] = $_POST["choosekingdom"];
        }

        exit();
    }
} else {
    change_location("index.php");
}
<?php
global $user, $db_instance;
require_once("includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    if (isset($_POST['chooseKingdom'])) {
        if (!is_numeric($_POST['chooseKingdom'])) {
            return;
        }

        // Check if kingdom belongs to the player
        $result = $db_instance->execute_query("SELECT userid FROM kingdoms WHERE id = ?", [$_POST['chooseKingdom']]);
        $row = $result->fetch_assoc();

        if ($row['userid'] == $user->getUserID()) {
            // Create kingdom object
            $_SESSION["kingdomid"] = $_POST["chooseKingdom"];
        }

        exit();
    }
} else {
    changeLocation("index.php");
}
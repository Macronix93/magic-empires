<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    if (!$user->is_logged_in()) {
        echo json_encode(["success" => false, "error" => "Nicht autorisiert"]);
        exit;
    }

    $action = $_GET["action"] ?? '';
    $guild_logic = new Guild($db_instance, $user);
    $response = ["success" => false];

    switch ($action) {
        case "invite":
            $target_id = (int)($_GET["target_id"] ?? 0);
            $res = $guild_logic->invite_user($target_id);

            if ($res === null) {
                $response["success"] = true;

                $response["html"] = show_passed_box("Einladung wurde erfolgreich verschickt!");
            } else {
                $response["error"] = $res;
            }
            break;

        case "accept_invite":
            $invite_id = (int)($_GET["invite_id"] ?? 0);
            $res = $guild_logic->join_guild($invite_id, true);

            if ($res === null) {
                $_SESSION["guild_success"] = "Willkommen in der Gilde!";

                $guild_logic->delete_invite_msg($db_instance, $user->get_user_id());
            } else {
                $_SESSION["guild_error"] = $res;
            }
            $response["success"] = true;
            break;

        case "decline_invite":
            $invite_id = (int)($_GET["invite_id"] ?? 0);
            $res = $guild_logic->decline_invite($invite_id);

            if ($res === null) {
                $_SESSION["guild_success"] = "Du hast die Einladung abgelehnt.";

                $guild_logic->delete_invite_msg($db_instance, $user->get_user_id());
            } else {
                $_SESSION["guild_error"] = $res;
            }
            $response["success"] = true;
            break;

        case "leave":
            $res = $guild_logic->leave_guild();
            if ($res === null) {
                $_SESSION["guild_success"] = "Du hast die Gilde erfolgreich verlassen.";
            } else {
                $_SESSION["guild_error"] = $res;
            }
            $response["success"] = true;
            break;

        case "kick":
            $target_id = (int)($_GET["target_id"] ?? 0);
            $res = $guild_logic->kick_member($target_id);

            if ($res === null) {
                $_SESSION["guild_success"] = "Das Mitglied wurde aus der Gilde entfernt.";
            } else {
                $_SESSION["guild_error"] = $res;
            }
            $response["success"] = true;
            break;

        case "set_rank":
            $target_id = (int)($_GET["target_id"] ?? 0);
            $rank_id = (int)($_GET["rank_id"] ?? 0);
            $res = $guild_logic->set_member_rank($target_id, $rank_id);

            if ($res === null) {
                $_SESSION["guild_success"] = "Der Rang wurde erfolgreich angepasst.";
            } else {
                $_SESSION["guild_error"] = $res;
            }
            $response["success"] = true;
            break;

        case "join":
            $guild_id = (int)($_GET["guild_id"] ?? 0);
            $res = $guild_logic->join_guild($guild_id);

            if ($res === null) {
                $_SESSION["guild_success"] = "Willkommen in der Gilde!";
            } else {
                $_SESSION["guild_error"] = $res;
            }
            $response["success"] = true;
            break;

        case "cancel_invite":
            $invite_id = (int)($_GET["invite_id"] ?? 0);
            $res = $guild_logic->cancel_invite($invite_id);

            if ($res === null) {
                $_SESSION["guild_success"] = "Die Einladung wurde erfolgreich zurückgezogen.";
            } else {
                $_SESSION["guild_error"] = $res;
            }
            $response["success"] = true;
            break;

        default:
            $response["error"] = "Unbekannte Aktion";
            break;
    }

    session_write_close();
    header('Content-Type: application/json');
    echo json_encode($response);
} else {
    change_location("guilds.php");
}
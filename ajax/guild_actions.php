<?php
require_once("../includes/ajax_core.php");

$action = $_POST["action"] ?? '';
$guild_logic = new Guild($user);
$response = ["success" => false];

switch ($action) {
    case "invite":
        $target_id = (int)($_POST["target_id"] ?? 0);
        $res = $guild_logic->invite_user($target_id);

        if ($res === null) {
            $response["success"] = true;

            $response["html"] = show_passed_box("Einladung wurde erfolgreich verschickt!");
        } else {
            $response["error"] = $res;
        }
        break;

    case "accept_invite":
        $invite_id = (int)($_POST["invite_id"] ?? 0);
        $res = $guild_logic->join_guild($invite_id, true);

        if ($res === null) {
            $_SESSION["game_success"] = "Willkommen in der Gilde!";

            $guild_logic->delete_invite_msg($db_instance, $user->get_user_id());
        } else {
            $_SESSION["game_error"] = $res;
        }
        $response["success"] = true;
        break;

    case "decline_invite":
        $invite_id = (int)($_POST["invite_id"] ?? 0);
        $res = $guild_logic->decline_invite($invite_id);

        if ($res === null) {
            $_SESSION["game_success"] = "Du hast die Einladung abgelehnt.";

            $guild_logic->delete_invite_msg($db_instance, $user->get_user_id());
        } else {
            $_SESSION["game_error"] = $res;
        }
        $response["success"] = true;
        break;

    case "leave":
        $res = $guild_logic->leave_guild();
        if ($res === null) {
            $_SESSION["game_success"] = "Du hast die Gilde erfolgreich verlassen.";
        } else {
            $_SESSION["game_error"] = $res;
        }
        $response["success"] = true;
        break;

    case "kick":
        $target_id = (int)($_POST["target_id"] ?? 0);
        $res = $guild_logic->kick_member($target_id);

        if ($res === null) {
            $_SESSION["game_success"] = "Das Mitglied wurde aus der Gilde entfernt.";
        } else {
            $_SESSION["game_error"] = $res;
        }
        $response["success"] = true;
        break;

    case "set_rank":
        $target_id = (int)($_POST["target_id"] ?? 0);
        $rank_id = (int)($_POST["rank_id"] ?? 0);
        $res = $guild_logic->set_member_rank($target_id, $rank_id);

        if ($res === null) {
            $_SESSION["game_success"] = "Der Rang wurde erfolgreich angepasst.";
        } else {
            $_SESSION["game_error"] = $res;
        }
        $response["success"] = true;
        break;

    case "join":
        $guild_id = (int)($_POST["guild_id"] ?? 0);
        $res = $guild_logic->join_guild($guild_id);

        if ($res === null) {
            $_SESSION["game_success"] = "Willkommen in der Gilde!";
        } else {
            $_SESSION["game_error"] = $res;
        }
        $response["success"] = true;
        break;

    case "cancel_invite":
        $invite_id = (int)($_POST["invite_id"] ?? 0);
        $res = $guild_logic->cancel_invite($invite_id);

        if ($res === null) {
            $_SESSION["game_success"] = "Die Einladung wurde erfolgreich zurückgezogen.";
        } else {
            $_SESSION["game_error"] = $res;
        }
        $response["success"] = true;
        break;

    default:
        $response["error"] = "Unbekannte Aktion";
        break;
}

session_write_close();
send_json($response);
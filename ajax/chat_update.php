<?php
global $db_instance, $user;
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    // Get chat partner
    $chat_partner = htmlspecialchars($_GET["s"]);
    $has_new_messages = false;

    // Render the conversation HTML
    ob_start();

    if ($_SESSION["msgreceiver"] != $chat_partner) {
        echo "<div style='text-align: center;'>Bitte nutze nur einen Tab für Konversationen!<br>Gesendete Nachrichten gehen an " . $_SESSION["msgreceiver"] . "</div>";
    } else {
        $result = $db_instance->execute_query("SELECT * FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)", [$chat_partner, $_SESSION["userid"], $_SESSION["userid"], $chat_partner]);
        $chat_partner_image = "";
        $my_chat_image = $user->get_avatar($user->get_user_name());

        foreach ($result as $row) {
            if ($row["senderid"] == $chat_partner) {
                if ($row["hasread"] === 0) {
                    $has_new_messages = true;
                }

                if (empty($chat_partner_image)) {
                    $chat_partner_image = $user->get_avatar($row["sender"]) ?? "";
                }

                echo "<div class='sender-bubble'>
                            <div class='image-and-user message-border'>
                                <img class='user-image' src='$chat_partner_image' alt='Nutzerbild'> " . $row["sender"] . " am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "
                                " . ($row["hasread"] == 0 ? " <span class='error'>(neu!)</span>" : "") . "
                            </div>
                            " . $row["message"] . "
                        </div>";
            } else { // You have written
                echo "<div class='receiver-bubble'>
                            <div class='image-and-user message-border'>
                                <img class='user-image' src='$my_chat_image' alt='Nutzerbild'> Du am " . date("d.m.Y \u\m H:i:s", $row["date"]) . " <a href='messages.php?action=delete&m_id=" . $row["id"] . "'>
                                <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a>
                            </div>
                            " . $row["message"] . "
                        </div>";
            }

            if ($row["hasread"] == 0 && $row["receiverid"] == $_SESSION["userid"]) {
                $db_instance->execute_query("UPDATE messages SET hasread = 1 WHERE id = ?", [$row["id"]]);
            }
        }
    }

    $html = ob_get_clean();

    echo json_encode([
        "html" => $html,
        "hasNewMessages" => $has_new_messages
    ]);
} else {
    change_location("Location: messages.php");
}
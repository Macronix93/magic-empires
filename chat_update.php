<?php
if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    global $db_instance;
    require_once("functions.php");

    // Get chat partner
    $chatPartner = htmlspecialchars($_GET["s"]);

    // Render the conversation HTML
    ob_start();

    $stmt = $db_instance->prepare("SELECT * FROM messages WHERE (sender = ? AND receiver = ?) OR (sender = ? AND receiver = ?)");
    $stmt->bind_param("ssss", $chatPartner, $_SESSION["username"], $_SESSION["username"], $chatPartner);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // The other side has written
        if ($row["sender"] == $_GET["s"]) {
            echo "<div class='sender-bubble'><u>" . $row["sender"] . " am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "</u>" . ($row["hasread"] == 0 ? " <span class='error'>(neu!)</span>" : "") . "<br>" . $row["message"] . "</div>";
        } else { // You have written
            echo "<div class='receiver-bubble'><u>Du am " . date("d.m.Y \u\m H:i:s", $row["date"]) . " <a href='messages.php?action=delete&m_id=" . $row["id"] . "'><img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a></u><br>" . $row["message"] . "</div>";
        }

        if ($row["hasread"] == 0 && $row["receiver"] == $_SESSION["username"]) {
            $stmt = $db_instance->prepare("UPDATE messages SET hasread = 1 WHERE id = ?");
            $stmt->bind_param("i", $row["id"]);
            $stmt->execute();
        }
    }

    $html = ob_get_clean();

    echo $html;
} else {
    changeLocation("Location: messages.php");
}
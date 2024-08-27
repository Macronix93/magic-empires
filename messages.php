<?php
global $db_instance, $user;
require_once("functions.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php");
    exit;
}

$error = null;
$htmlToDisplay = "";

function showInbox($db_instance): string {
    // Get all conversations for the user
    $stmtMsg = $db_instance->prepare("
                    SELECT participant, 
                           MAX(date) AS latest_message_date
                    FROM (
                        SELECT sender AS participant, date
                        FROM messages
                        WHERE receiver = ?
                        UNION
                        SELECT receiver AS participant, date
                        FROM messages
                        WHERE sender = ?
                    ) AS combined
                    GROUP BY participant
                    ORDER BY latest_message_date DESC
                ");

    $stmtMsg->bind_param("ss", $_SESSION["username"], $_SESSION["username"]);
    $stmtMsg->execute();
    $query = $stmtMsg->get_result();
    $stmtMsg->close();

    $htmlToDisplay = "";

    if ($query->num_rows > 0) {
        $htmlToDisplay .= '<table class="table">
                     <tr>
                         <td class="td-center td-gradient" style="word-break: break-word">
                             <b>Konversation mit</b>
                         </td>
                         <td class="td-center td-gradient" style="word-break: break-word">
                             <b>Letzte Nachricht</b>
                         </td>
                         <td class="td-center td-gradient" style="word-break: break-word">
                             <b>Aktion</b>
                         </td>
                     </tr>';

        while ($row = $query->fetch_assoc()) {
            $stmt = $db_instance->prepare("
                SELECT COUNT(*) AS unread_count 
                FROM messages 
                WHERE sender = ? 
                  AND receiver = ? 
                  AND hasread = 0
            ");
            $stmt->bind_param("ss", $row["participant"], $_SESSION["username"]);
            $stmt->execute();
            $result = $stmt->get_result();
            $row2 = $result->fetch_assoc();
            $num_unread_messages = $row2["unread_count"];
            $stmt->close();

            if (strcmp($row["participant"], "Server") === 0) {
                continue;
            }

            $participant = $row["participant"];
            $htmlToDisplay .= "<tr>
                                    <td class='highlight-on-hover' onclick='window.location.href=\"messages.php?action=read&s=" . $row["participant"] . "\";'>$participant " . showNewMessagesIndicator($num_unread_messages) . "</td>
                                    <td class='highlight-on-hover' onclick='window.location.href=\"messages.php?action=read&s=" . $row["participant"] . "\";'>am " . date("d.m.Y \u\m H:i:s", $row["latest_message_date"]) . "</td>
                                    <td style='text-align: center'><a href='messages.php?action=delete&s=" . $participant . "'><img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a></td>
                                </tr>";
        }

        $htmlToDisplay .= "</table>";
    } else {
        $htmlToDisplay = "Du hast keine Konversationen!";
    }

    $htmlToDisplay .= "<br>
                        <form action='messages.php' method='GET'>
                            <input type='hidden' name='action' value='new'>
                            <input type='submit' value='Neue Konversation' style='margin-top: 5px;'>
                        </form>";

    return $htmlToDisplay;
}

// For a new conversation
if (isset($_POST["sendpm"])) {
    $receiver = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\p{Mn}/u'], ['', ' ', ''], $_POST["receiver"]);
    $text = nl2br(htmlspecialchars($_POST["text"], ENT_QUOTES, "UTF-8"));
    $error = getError($text, $receiver);

    if ($error == null) {
        // Prevent HTML Injection
        $userid = $user->getUserID();
        $receiver = htmlspecialchars($receiver, ENT_QUOTES, "UTF-8");
        $textToOutput = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\s+/u', '/\p{Mn}/u'], ['', ' ', ' ', ''], $text);
        $time = time();
        $ratelimit = $time - MESSAGES_RATE_LIMIT;

        $query = "SELECT
                        (SELECT COUNT(*) FROM users WHERE username = ?) AS userexists,
                        (SELECT lastsentmsg FROM users WHERE id = ?) AS lastsent
                ";

        $stmt = $db_instance->prepare($query);
        $stmt->bind_param("si", $receiver, $userid);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $userexists = $row["userexists"];
        $lastsent = $row["lastsent"];

        if ($userexists == 0) {
            $error = "Dieser Benutzer existiert nicht!";
        } else {
            // Query to count messages sent in the last 5 minutes
            $stmt = $db_instance->prepare("SELECT COUNT(*) AS messagecount FROM messages WHERE senderid = ? AND date > ?");
            $stmt->bind_param("ii", $userid, $ratelimit);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $messagecount = $row["messagecount"];
            $stmt->close();

            $remainingTimeInSeconds = MESSAGES_RATE_LIMIT - ($time - $lastsent);

            if ($messagecount >= MAX_MESSAGES_PER_RATELIMIT) {
                echo "<script type='text/javascript'>alert('Du schickst zuviele Nachrichten! Warte bitte.')</script>";
            } else {
                $notread = 0;

                // Anti spam
                $stmt = $db_instance->prepare("UPDATE users SET lastsentmsg = $time WHERE id = ?");
                $stmt->bind_param("i", $userid);
                $stmt->execute();
                $stmt->close();

                // Insert message
                $stmt = $db_instance->prepare("INSERT INTO messages (senderid, sender, receiver, date, hasread, message) VALUES (?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("issiis", $_SESSION["userid"], $_SESSION["username"], $receiver, $time, $notread, $textToOutput);
                    $stmt->execute();
                    $stmt->close();
                }

                unset($_POST["text"]);

                changeLocation("messages.php?action=read&s=" . $receiver);
            }
        }
    }
}

if (isset($_GET["action"])) {
    if ($_GET["action"] == "new") {
        $receiver = isset($_GET["s"]) ? htmlspecialchars($_GET["s"]) : "";
        $receiverText = isset($_GET["receiver"])
            ? '<label>
           <input type="text" name="receiver" maxlength="16"
                  value="' . htmlspecialchars($_GET["s"]) . '">
       </label>'
            : '<label>
           <input type="text" name="receiver" maxlength="16"
                  value="' . (isset($_POST["receiver"]) ? htmlspecialchars($_POST["receiver"]) : '') . '">
       </label> 
       <a href="javascript:userList()">
           <input type="button" value="Benutzerliste">
       </a>';
        $message = isset($_POST["text"]) ? htmlspecialchars($_POST["text"]) : "";

        if (isset($_POST["text"]) && $error == null) {
            $htmlToDisplay .= showInbox($db_instance);
        } else {
            $htmlToDisplay .= "
            <form name=\"newmessage\"
                  action=\"messages.php?action=new\"
                  method=\"POST\" style=\"width: 100%;\">
                <table class=\"table\">
                    <tr>
                        <td style=\"width: 25%;\">
                            <b>Empfänger:</b>
                        </td>
                        <td>
                            {$receiverText}
                        </td>
                    </tr>
                    <tr>
                        <td><b>Nachricht:</b></td>
                        <td>
                            <label>
                                <textarea name=\"text\" rows=\"8\"
                                          maxlength=\"" . MAX_MESSAGE_LENGTH . "\"
                                          style=\"width: 100%; resize: vertical;\">$message</textarea>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan=\"2\">
                            <input type=\"submit\" name=\"sendpm\" value=\"Abschicken\">
                        </td>
                    </tr>
                </table>
            </form>";
        }
    } else if ($_GET["action"] == "read") {
        if (isset($_GET["s"]) && htmlspecialchars($_GET["s"]) != null) {
            $stmt = $db_instance->prepare("SELECT * FROM messages WHERE (sender = ? AND receiver = ?) OR (sender = ? AND receiver = ?)");
            $stmt->bind_param("ssss", $_GET["s"], $_SESSION["username"], $_SESSION["username"], $_GET["s"]);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 0) {
                $error = "Du hast keine Konversation mit " . htmlspecialchars($_GET["s"]) . "!";
            } else {
                $htmlToDisplay = '<div style="float: left;"><button onclick="window.location.href=\'messages.php\';">Zurück</button></div>';
                $htmlToDisplay .= '<h3>Konversation mit 
                                        <a href="javascript:void(0);" 
                                         onclick="openUserDetails(\'userinfo.php?userid=' . htmlspecialchars($_GET["s"]) . '\');" 
                                         class="popup" 
                                         style="cursor: pointer;">
                                         ' . htmlspecialchars($_GET["s"]) . '
                                        </a>
                                    </h3>';
                $htmlToDisplay .= '<br><div id="messages-section">';

                while ($row = $result->fetch_assoc()) {
                    // The other side has written
                    if ($row["sender"] == $_GET["s"]) {
                        $htmlToDisplay .= "<div class='sender-bubble'><u>" . $row["sender"] . " am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "</u>" . ($row["hasread"] == 0 ? " <span class='error'>(neu!)</span>" : "") . "<br>" . $row["message"] . "</div>";
                    } else { // You have written
                        $htmlToDisplay .= "<div class='receiver-bubble'><u>Du am " . date("d.m.Y \u\m H:i:s", $row["date"]) . " <a href='messages.php?action=delete&m_id=" . $row["id"] . "'><img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a></u><br>" . $row["message"] . "</div>";
                    }

                    if ($row["hasread"] == 0 && $row["receiver"] == $_SESSION["username"]) {
                        $stmt = $db_instance->prepare("UPDATE messages SET hasread = 1 WHERE id = ?");
                        $stmt->bind_param("i", $row["id"]);
                        $stmt->execute();
                    }
                }

                $htmlToDisplay .= "</div><div id='newmessage-section'>
                                    <form name=\"newmessage\"
                                          id='newmessage'
                                          action=\"messages.php?action=read&s=" . htmlspecialchars($_GET["s"]) . "\"
                                          method=\"POST\" style=\"width: 100%;\">
                                           <input type=\"hidden\" name=\"receiver\" value=\"" . htmlspecialchars($_GET["s"]) . "\">
                                            <textarea id=\"message-input\" name=\"text\" rows=\"5\"
                                                      maxlength=\"" . MAX_MESSAGE_LENGTH . "\"
                                                      style=\"width: 100%; resize: vertical; margin-right: 10px;\">" . (isset($_POST["text"]) ? htmlspecialchars($_POST["text"]) : '') . "</textarea>
                                            <input type=\"submit\" name=\"sendpm\" value=\"Absenden\n[ENTER]\"/>
                                    </form>
                                </div>";
                $htmlToDisplay .= "<script type='text/javascript'>
                                        initializeChat();
                                    </script>";
            }
        } else {
            $error = "Der Benutzer {$_GET["s"]} existiert nicht!";
        }
    } else if ($_GET["action"] == "delete") {
        if (isset($_GET["s"])) {
            if (empty($_GET["s"])) {
                changeLocation("messages.php");
            } else {
                $stmt = $db_instance->prepare("SELECT * FROM messages WHERE (sender = ? AND receiver = ?) OR (sender = ? AND receiver = ?)");
                $stmt->bind_param("ssss", $_GET["s"], $_SESSION["username"], $_SESSION["username"], $_GET["s"]);
                $stmt->execute();
                $result = $stmt->get_result();
                $stmt->close();

                if ($result->num_rows == 0) {
                    $error = "Du hast keine Konversation mit " . htmlspecialchars($_GET["s"]) . "!";
                } else {
                    $stmt = $db_instance->prepare("DELETE FROM messages WHERE (sender = ? AND receiver = ?) OR (sender = ? AND receiver = ?)");
                    $stmt->bind_param("ssss", $_GET["s"], $_SESSION["username"], $_SESSION["username"], $_GET["s"]);
                    $stmt->execute();
                    $stmt->close();

                    changeLocation("messages.php");
                }
            }
        } else {
            $stmt = $db_instance->prepare("SELECT * FROM messages WHERE id = ?");
            $stmt->bind_param("i", $_GET["m_id"]);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();

                if ($row["sender"] != $_SESSION["username"]) {
                    $error = "Diese Nachricht kannst du nicht löschen!";

                    changeLocation("messages.php", 2);
                } else {
                    $stmt = $db_instance->prepare("DELETE FROM messages WHERE id = ?");
                    $stmt->bind_param("i", $_GET["m_id"]);
                    $stmt->execute();
                    $stmt->close();

                    changeLocation("messages.php?action=read&s={$row["receiver"]}");
                }
            } else {
                $error = "Diese Nachricht existiert nicht!";

                changeLocation("messages.php", 2);
            }
        }
    } else {
        changeLocation("messages.php");
    }
} else {
    $htmlToDisplay = showInbox($db_instance);
}
?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/banner.html");
?>
<div class="content">
    <div class="content-box">
        <div class="left-container">
            <?php
            include_once("layout/left.php");
            ?>
        </div>

        <div class="middle-container">
            <div class="big-box-container">
                <div class="big-box-header">
                    <p>Nachrichten</p>
                </div>
                <div class="big-box-content">
                    <?php
                    if ($error != null) {
                        echo $error . "<br><br>";
                    }

                    echo $htmlToDisplay;
                    ?>

                    <div id="error-modal">
                        <div id="error-message"></div>
                    </div>

                    <script type="text/javascript">
                        scrollToLatestMessage();
                    </script>
                </div>
            </div>
        </div>

        <div class="right-container">
            <?php
            include_once("layout/right.php");
            ?>
        </div>
    </div>
</div>
<?php
include_once("layout/footer.php");
?>
</body>
</html>
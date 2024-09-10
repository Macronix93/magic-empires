<?php
global $db_instance, $user;
require_once("includes/core.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php");
    exit;
}

$error = null;
$htmlToDisplay = "";

function showInbox($db_instance): string {
    $htmlToDisplay = "";

    // Get all conversations for the user
    $query = "
                SELECT participant, 
                    MAX(date) AS latest_message_date
                FROM (
                    SELECT senderid AS participant, date
                    FROM messages
                        WHERE receiverid = ?
                        UNION
                        SELECT receiverid AS participant, date
                        FROM messages
                        WHERE senderid = ?
                    ) AS combined
                GROUP BY participant
                ORDER BY latest_message_date DESC
    ";
    $result = $db_instance->execute_query($query, [$_SESSION["userid"], $_SESSION["userid"]]);

    if ($result->num_rows > 0) {
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

        $query = "
                    SELECT 
                        u.username AS sendername,
                        COUNT(CASE WHEN m.hasread = 0 THEN 1 END) AS unreadcount
                    FROM users u
                    LEFT JOIN messages m 
                        ON u.id = m.senderid 
                        AND m.receiverid = ? 
                    WHERE u.id = ?
        ";

        foreach ($result as $row) {
            $result2 = $db_instance->execute_query($query, [$_SESSION["userid"], $row["participant"]]);
            $row2 = $result2->fetch_assoc();
            $num_unread_messages = $row2["unreadcount"];
            $senderName = $row2["sendername"];

            $htmlToDisplay .= "<tr class='tr-hover'>
                                    <td class='td-cursor' onclick='window.location.href=\"messages.php?action=read&s=" . $row["participant"] . "\";'>$senderName " . showNewMessagesIndicator($num_unread_messages) . "</td>
                                    <td class='td-cursor' onclick='window.location.href=\"messages.php?action=read&s=" . $row["participant"] . "\";'>am " . date("d.m.Y \u\m H:i:s", $row["latest_message_date"]) . "</td>
                                    <td style='text-align: center'><a href='messages.php?action=delete&s=" . $senderName . "'><img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a></td>
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
    $_SESSION["msgreceiver"] = $receiver;
    $text = nl2br(htmlspecialchars($_POST["text"], ENT_QUOTES, "UTF-8"));
    $error = getError($text, $receiver);

    if ($error == null) {
        // Prevent HTML Injection
        $userid = $user->getUserID();
        $receiver = htmlspecialchars($receiver, ENT_QUOTES, "UTF-8");
        $textToOutput = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\s+/u', '/\p{Mn}/u'], ['', ' ', ' ', ''], $text);
        $time = time();
        $ratelimit = $time - MESSAGES_RATE_LIMIT;

        $query = "
                    SELECT
                        (SELECT COUNT(*) FROM users WHERE username = ?) AS userexists,
                        (SELECT lastsentmsg FROM users WHERE id = ?) AS lastsent
        ";
        $result = $db_instance->execute_query($query, [$receiver, $userid]);
        $row = $result->fetch_assoc();
        $userexists = $row["userexists"];
        $lastsent = $row["lastsent"];

        if ($userexists == 0) {
            $error = "Dieser Benutzer existiert nicht!";
        } else {
            // Query to count messages sent in the last 5 minutes
            $result = $db_instance->execute_query("SELECT COUNT(*) AS messagecount FROM messages WHERE senderid = ? AND date > ?", [$userid, $ratelimit]);
            $row = $result->fetch_assoc();
            $messagecount = $row["messagecount"];
            $remainingTimeInSeconds = MESSAGES_RATE_LIMIT - ($time - $lastsent);

            if ($messagecount >= MAX_MESSAGES_PER_RATELIMIT) {
                echo "<script type='text/javascript'>alert('Du schickst zuviele Nachrichten! Warte bitte.')</script>";
            } else {
                // Anti spam
                $db_instance->execute_query("UPDATE users SET lastsentmsg = $time WHERE id = ?", [$userid]);

                // Get receiverid based on receiver name
                $result = $db_instance->execute_query("SELECT id FROM users WHERE username = ?", [$receiver]);
                $row = $result->fetch_assoc();
                $receiverid = $row["id"];

                // Insert message
                $query = "INSERT INTO messages (senderid, sender, receiverid, receiver, date, hasread, message) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $db_instance->execute_query($query, [$_SESSION["userid"], $_SESSION["username"], $receiverid, $receiver, $time, 0, $textToOutput]);

                unset($_POST["text"]);

                changeLocation("messages.php?action=read&s=" . $receiverid);
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
            $_SESSION["msgreceiver"] = htmlspecialchars($_GET["s"]);

            // Get chat partner name based on id
            $result = $db_instance->execute_query("SELECT username FROM users WHERE id = ?", [$_SESSION["msgreceiver"]]);
            $row = $result->fetch_assoc();
            $chatPartner = $row["username"];

            $query = "SELECT * FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)";
            $result = $db_instance->execute_query($query, [$_GET["s"], $_SESSION["userid"], $_SESSION["userid"], $_GET["s"]]);

            if ($result->num_rows == 0) {
                $error = "Du hast keine Konversation mit " . $chatPartner . "!";
            } else {
                $htmlToDisplay = '<div style=""><button onclick="window.location.href=\'messages.php\';">Zurück</button></div>';
                $htmlToDisplay .= '<h3>Konversation mit 
                                        <a href="javascript:void(0);" 
                                         onclick="openUserDetails(\'userinfo.php?userid=' . htmlspecialchars($_GET["s"]) . '\');" 
                                         class="popup" 
                                         style="cursor: pointer;">
                                         ' . $chatPartner . '
                                        </a>
                                    </h3>';
                $htmlToDisplay .= '<br><div id="messages-section">';

                foreach ($result as $row) {
                    // The other side has written
                    if ($row["senderid"] == $_GET["s"]) {
                        $htmlToDisplay .= "<div class='sender-bubble'><u>" . $row["sender"] . " am " . date("d.m.Y \u\m H:i:s", $row["date"]) . "</u>" . ($row["hasread"] == 0 ? " <span class='error'>(neu!)</span>" : "") . "<br>" . $row["message"] . "</div>";
                    } else { // You have written
                        $htmlToDisplay .= "<div class='receiver-bubble'><u>Du am " . date("d.m.Y \u\m H:i:s", $row["date"]) . " <a href='messages.php?action=delete&m_id=" . $row["id"] . "'>
                                            <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a></u><br>" . $row["message"] . "</div>";
                    }

                    if ($row["hasread"] == 0 && $row["receiverid"] == $_SESSION["userid"]) {
                        $db_instance->execute_query("UPDATE messages SET hasread = 1 WHERE id = ?", [$row["id"]]);
                    }
                }
                $htmlToDisplay .= "</div><div id='newmessage-section'>
                                    <form name=\"newmessage\"
                                          id='newmessage'
                                          action=\"messages.php?action=read&s=" . htmlspecialchars($_GET["s"]) . "\"
                                          method=\"POST\" style=\"width: 100%;\">
                                           <input type=\"hidden\" name=\"receiver\" value=\"" . $_SESSION["msgreceiver"] . "\">
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
            $error = "Der Benutzer existiert nicht!";
        }
    } else if ($_GET["action"] == "delete") {
        if (isset($_GET["s"])) {
            if (empty($_GET["s"])) {
                changeLocation("messages.php");
            } else {
                $query = "SELECT * FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)";
                $result = $db_instance->execute_query($query, [$_GET["s"], $_SESSION["userid"], $_SESSION["userid"], $_GET["s"]]);

                if ($result->num_rows == 0) {
                    $error = "Du hast keine Konversation mit " . htmlspecialchars($_GET["s"]) . "!";
                } else {
                    $query = "DELETE FROM messages WHERE (senderid = ? AND receiverid = ?) OR (senderid = ? AND receiverid = ?)";
                    $db_instance->execute_query($query, [$_GET["s"], $_SESSION["userid"], $_SESSION["userid"], $_GET["s"]]);

                    changeLocation("messages.php");
                }
            }
        } else {
            $result = $db_instance->execute_query("SELECT * FROM messages WHERE id = ?", [$_GET["m_id"]]);

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();

                if ($row["senderid"] != $_SESSION["userid"]) {
                    $error = "Diese Nachricht kannst du nicht löschen!";

                    changeLocation("messages.php", 2);
                } else {
                    $db_instance->execute_query("DELETE FROM messages WHERE id = ?", [$_GET["m_id"]]);

                    changeLocation("messages.php?action=read&s={$row["receiverid"]}");
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
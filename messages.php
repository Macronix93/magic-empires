<?php
global $db_instance, $user;
require_once("functions.php");

// Check if user is not logged in, and if so, redirect him to login page
if (!($user->isLoggedIn())) {
    changeLocation("login.php", 0);
    exit;
}

?>
<!DOCTYPE html>
<html lang="de">
<?php
include_once("layout/head.html");
?>
<body>
<?php
include_once("layout/header.php");
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
                    $mysqli = $db_instance;

                    if (isset($_POST["sendpm"])) {
                        $receiver = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\p{Mn}/u'], ['', ' ', ''], $_POST["receiver"]);
                        $subject = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\p{Mn}/u'], ['', ' ', ''], $_POST["subject"]);
                        $text = preg_replace(['/^\s+/', '/\p{Z}+/u', '/\s+/u', '/\p{Mn}/u'], ['', ' ', ' ', ''], $_POST["text"]);
                        $error = null;

                        // Check different errors
                        if ($receiver == $_SESSION["username"]) {
                            $error = "Du kannst keine Nachrichten an dich selbst senden!";
                        } else if (preg_match('/\s/', $receiver)) {
                            $error = "Dieser Benutzer existiert nicht!";
                        } else if (empty(trim($subject)) || empty(trim($text))) {
                            $error = "Bitte alle Felder ausfüllen!";
                        } else if (strlen($subject) > MAX_SUBJECT_LENGTH) {
                            $error = "Betreff darf maximal " . MAX_SUBJECT_LENGTH . " Zeichen lang sein!";
                        } else if (strlen($text) > MAX_MESSAGE_LENGTH) {
                            $error = "Die Nachricht darf maximal " . MAX_MESSAGE_LENGTH . " Zeichen lang sein!";
                        }

                        // Prevent HTML Injection
                        $receiver = htmlspecialchars($receiver, ENT_QUOTES, "UTF-8");
                        $subject = htmlspecialchars($subject, ENT_QUOTES, "UTF-8");
                        $text = htmlspecialchars($text, ENT_QUOTES, "UTF-8");

                        if ($error !== null) {
                            echo $error . "<br><br>";
                            //changeLocation("messages.php?action=new", 2);
                        } else {
                            $stmt = $mysqli->prepare("SELECT COUNT(*) FROM messages WHERE receiver = ?");
                            $stmt->bind_param('s', $receiver);
                            $stmt->execute();
                            $stmt->bind_result($sum);
                            $stmt->fetch();
                            $stmt->close();

                            if ($sum >= MAX_USER_MESSAGES) {
                                echo "Der Posteingang dieses Benutzers ist voll!<br><br>";
                            } else {
                                $stmt = $mysqli->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                                $stmt->bind_param('s', $receiver);
                                $stmt->execute();
                                $stmt->bind_result($exist);
                                $stmt->fetch();
                                $stmt->close();

                                if ($exist == 0) {
                                    echo "Dieser Benutzer existiert nicht!<br><br>";
                                } else {
                                    $time = time();
                                    $notread = 0;

                                    $stmt = $db_instance->prepare("INSERT INTO messages (sender, receiver, date, hasread, subject, message) VALUES (?, ?, ?, ?, ?, ?)");
                                    if ($stmt) {
                                        $stmt->bind_param("ssiiss", $_SESSION['username'], $receiver, $time, $notread, $subject, $text);
                                        $stmt->execute();
                                        $stmt->close();
                                    }

                                    $_SESSION["msgsent"] = true;
                                    //echo "Deine Nachricht wurde verschickt!<br><br>";
                                    //changeLocation("messages.php", 2);
                                }
                            }
                        }
                    } //else {
                    if (isset($_GET["action"])) {
                        if ($_GET["action"] == "folder") {
                            if ($_GET["folderid"] == 1) {
                                $query = $db_instance->query("SELECT * FROM messages WHERE receiver = '" . $_SESSION["username"] . "' ORDER BY date DESC");
                                $check = $query->num_rows;

                                if ($check != 0) {
                                    ?>
                                    <table class=table>
                                        <tr>
                                            <td class="td-center td-gradient" style="word-break: break-word">
                                                <b>Betreff</b>
                                            </td>
                                            <td class="td-center td-gradient" style="word-break: break-word">
                                                <b>Absender</b>
                                            </td>
                                            <td class="td-center td-gradient" style="word-break: break-word"><b>Datum
                                                    & Zeit</b></td>
                                            <td class="td-center td-gradient" style="word-break: break-word">
                                                <b>Aktion</b>
                                            </td>
                                        </tr>
                                        <?php
                                        while ($row = $query->fetch_assoc()) {
                                            echo "<tr><td><a href='messages.php?action=read&m_id=" . $row["id"] . "'>" . $row["subject"] . "</a> " . ($row["hasread"] ? "" : "<b>(neu!)</b>") . "</td><td style='word-break: break-word'>" . $row["sender"] . "</td><td>" . date("d.m.Y H:i:s", $row["date"]) . "</td>
                                                                <td style='text-align: center;'><a href='messages.php?action=read&m_id=" . $row["id"] . "'><img src='images/icons/icon_loupe.png' class='ressource-icons' alt='Lesen'></a> <a href='messages.php?action=delete&m_id=" . $row["id"] . "'><img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen'></a></td></tr>";
                                        }
                                        ?>
                                    </table>
                                    <?php
                                } else {
                                    echo "Dein Nachrichtenordner ist leer!";
                                    ?>
                                    <br><br>
                                    <form action='messages.php' method='GET'>
                                        <input type='hidden' name='action' value='new'>
                                        <input type='submit' value='Neue Nachricht' style='margin-top: 5px;'>
                                    </form>
                                    <?php
                                }
                            } else if ($_GET["folderid"] == 2) {
                                echo "Ordner \"Gilden-Nachrichten\"";
                            } else {
                                echo "Ordner existiert nicht!";
                                changeLocation("messages.php", 2);
                            }
                        } else if ($_GET["action"] == "new") {
                            if (isset($_SESSION["msgsent"]) && $_SESSION["msgsent"]) {
                                $_SESSION["msgsent"] = false;
                                echo "Deine Nachricht wurde verschickt!";
                                changeLocation("messages.php", 2);
                            } else {
                                ?>
                                <form name="newmessage" action="messages.php?action=new" method="POST"
                                      style="width: 100%;">
                                    <table class="table">
                                        <tr>
                                            <td style="width: 25%;">
                                                <b>Empfänger:</b>
                                            </td>
                                            <td>
                                                <?php
                                                if (isset($_GET["receiver"])) {
                                                    ?>
                                                    <label>
                                                        <input type="text" name="receiver" maxlength="16"
                                                               value="<?php echo $_GET["receiver"]; ?>">
                                                    </label>
                                                    <?php
                                                } else {
                                                    ?>
                                                    <label>
                                                        <input type="text" name="receiver" maxlength="16" value="">
                                                    </label> <a href="javascript:userList()"><input type="button"
                                                                                                    value="Benutzerliste"></a>
                                                    <?php
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><b>Betreff:</b></td>
                                            <td>
                                                <label>
                                                    <input type="text" name="subject"
                                                           maxlength="<?php echo MAX_SUBJECT_LENGTH; ?>"
                                                           value="<?php echo($_GET["subject"] ?? ""); ?>">
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><b>Nachricht:</b></td>
                                            <td>
                                                <label>
                                                        <textarea name="text" rows="8"
                                                                  maxlength="<?php echo MAX_MESSAGE_LENGTH; ?>"
                                                                  style="width: 100%; resize: vertical;"></textarea>
                                                </label>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="2">
                                                <input type="submit" name="sendpm" value="Abschicken">
                                            </td>
                                        </tr>
                                    </table>
                                </form>
                                <?php
                            }
                        } else if ($_GET["action"] == 'read') {
                            $stmt = $db_instance->prepare("SELECT * FROM messages WHERE id = ?");
                            $stmt->bind_param("i", $_GET["m_id"]);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $row = $result->fetch_assoc();

                            if ($row["receiver"] != $_SESSION["username"]) {
                                echo "Diese Nachricht hast du nicht empfangen!";
                            } else {
                                if ($row["hasread"] == 0) {
                                    $stmt = $db_instance->prepare("UPDATE messages SET hasread = 1 WHERE id = ?");
                                    $stmt->bind_param("i", $_GET["m_id"]);
                                    $stmt->execute();
                                }

                                echo "<h3><u>Von " . $row["sender"] . " am " . date("d.m.Y \u\m H:i:s", $row["date"]) . ": " . $row["subject"] . "</u></h3><br>";
                                echo $row["message"] . "<br><br>";

                                $replysubject = preg_replace('/^(RE: )*/', 'RE: ', stripcslashes($row["subject"]));
                                echo "<form action='messages.php' method='GET'>
                                                <input type='hidden' name='action' value='new'>
                                                <input type='hidden' name='receiver' value='" . $row["sender"] . "'>
                                                <input type='hidden' name='subject' value='" . $replysubject . "'>
                                                <input type='submit' value='Antworten'>
                                            </form>
                                            <form action='messages.php' method='GET'>
                                                <input type='hidden' name='action' value='delete'>
                                                <input type='hidden' name='m_id' value='" . $row["id"] . "'>
                                                <input type='submit' value='Löschen'>
                                            </form>";
                            }
                        } else if ($_GET["action"] == "delete") {
                            //changeLocation("messages.php?action=folder&folderid=1", 2);

                            $stmt = $db_instance->prepare("SELECT * FROM messages WHERE id = ?");
                            $stmt->bind_param("i", $_GET["m_id"]);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $stmt->close();

                            if ($result->num_rows > 0) {
                                $row = $result->fetch_assoc();

                                if ($row["receiver"] != $_SESSION["username"]) {
                                    changeLocation("messages.php?action=folder&folderid=1", 2);
                                    echo "Diese Nachricht kannst du nicht löschen!";
                                } else {
                                    $stmt = $db_instance->prepare("DELETE FROM messages WHERE id = ?");
                                    $stmt->bind_param("i", $_GET["m_id"]);
                                    $stmt->execute();
                                    $stmt->close();

                                    changeLocation("messages.php?action=folder&folderid=1", 0);
                                    //echo "Du hast die Nachricht erfolgreich gelöscht!";
                                }
                            } else {
                                changeLocation("messages.php?action=folder&folderid=1", 2);
                                echo "Diese Nachricht existiert nicht!";
                            }
                        } else {
                            changeLocation("messages.php", 0);
                        }
                    } else {
                        // Count all messages for the user
                        $stmt_msgs = $db_instance->prepare("SELECT id FROM messages WHERE receiver = ?");
                        $stmt_msgs->bind_param("s", $_SESSION["username"]);
                        $stmt_msgs->execute();
                        $result_msgs = $stmt_msgs->get_result();
                        $sum = $result_msgs->num_rows;
                        $stmt_msgs->close();

                        // Count unread messages for the user
                        $stmt = $db_instance->prepare("SELECT COUNT(*) AS unread_count FROM messages WHERE receiver = ? AND hasread = 0");
                        $stmt->bind_param("s", $_SESSION["username"]);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $row = $result->fetch_assoc();
                        $num_unread_messages = $row["unread_count"];
                        $stmt->close();

                        echo "" . showNewMessagesIndicator($num_unread_messages) . "<a href='messages.php?action=folder&folderid=1'> Private Nachrichten (" . $sum . " / " . MAX_USER_MESSAGES . ")</a><br>";
                        echo "<a href='messages.php?action=folder&folderid=2'>Gilden-Nachrichten (0 / " . MAX_GUILD_MESSAGES . ")</a>";
                        ?>
                        <br><br>
                        <form action='messages.php' method='GET'>
                            <input type='hidden' name='action' value='new'>
                            <input type='submit' value='Neue Nachricht' style='margin-top: 5px;'>
                        </form>
                        <?php
                    }
                    //}
                    ?>
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
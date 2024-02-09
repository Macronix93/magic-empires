<?php
/************************************
 * Functions file
 ************************************/

/*
    Check session and create if non-existent
*/
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
    Constants (defines)
*/
const HOST = "localhost";
const USER = "newme";
const PASSWORD = "q42i7aw8c3";
const DATABASE = "newme";
const PORT = "34156";
const MIN_USERNAME_LENGTH = 4;
const MAX_USERNAME_LENGTH = 16;
const MIN_PASSWORD_LENGTH = 5;
const MAX_PASSWORD_LENGTH = 65;
const MAX_X = 100;
const MAX_Y = 100;
const MAX_MAP_SEARCHES = 3;
const ACTION_BUILD_BUILDING = 1;
const ACTION_BUILD_TROOPS = 2;
const ACTION_SEND_TROOPS = 3;
const BUILDING_COST_WOOD = 1;
const BUILDING_COST_FOOD = 2;
const BUILDING_COST_STONE = 3;
const BUILDING_COST_GOLD = 4;
const MAX_BUILDING_LEVEL = 10;
const DEFAULT_WALL_HP = 200;
const TIMEOUT_MAX_SECONDS = 1800;
const USER_UPDATE_TICK = 30;
const MAX_USER_MESSAGES = 50;
const MAX_GUILD_MESSAGES = 50;
const MAX_MESSAGE_LENGTH = 400;
const MAX_SUBJECT_LENGTH = 16;
const INACTIVITY_DELAY = 864000;

ini_set('max_execution_time', 300);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
    AutoLoad classes
*/
spl_autoload_register(function ($class_name) {
    $class = strtolower($class_name);
    include("classes/$class.php");
});

// Database instance for classes
$db = Database::getInstance();
$db_instance = $db->getConnection();

// Create User instance
$user = new User($db_instance);

// Timeout Check
if (!isset($_SESSION["lastactivity"])) {
    // initiate value
    $_SESSION["lastactivity"] = time();
}

// last activity is more than TIMEOUT_MAX_SECONDS seconds ago
if (time() - $_SESSION["lastactivity"] > TIMEOUT_MAX_SECONDS) {
    //changeLocation("login.php", 0);
    header("Location: login.php");

    session_destroy();
    exit;
} else {
    // update last activity timestamp
    $currentTimestamp = time();

    if ($currentTimestamp - $_SESSION["lastactivity"] > USER_UPDATE_TICK) {
        $stmt = $db_instance->prepare("UPDATE users SET lastactivity = $currentTimestamp WHERE id = ?");
        $userID = $user->getUserID();
        $stmt->bind_param('i', $userID);
        $stmt->execute();
        $stmt->close();
    }

    $_SESSION["lastactivity"] = $currentTimestamp;
}

/*
    Useful functions
*/

// Make Input data secure
function makeSecure($data) {
    $data = trim($data);
    $data = stripslashes($data);
    return htmlspecialchars($data);
}

// Show messages indicator
function showNewMessagesIndicator($number) {
    echo ($number == 0) ? "" : "<img src='images/icons/icon_" . ($number > 5 ? "more_than_5" : $number) . ".png' class='menu-icons' style='width: 16px; height: 16px;' alt='' />";
}

// Convert seconds to a string
function convertSecToStr($secs) {
    if ($secs == 0) {
        return '0s';
    }

    $output = '';

    if ($secs >= 86400) {
        $days = floor($secs / 86400);
        $secs = $secs % 86400;
        $output .= $days . 'd ';
    }

    if ($secs >= 3600) {
        $hours = floor($secs / 3600);
        $secs = $secs % 3600;
        $output .= $hours . 'h ';
    }

    if ($secs >= 60) {
        $minutes = floor($secs / 60);
        $secs = $secs % 60;
        $output .= $minutes . 'm ';
    }

    if ($secs > 0) {
        $output .= $secs . 's';
    }

    return trim($output);
}

function changeLocation($url, $seconds) {
    $urlJson = json_encode($url);
    $secondsJson = json_encode($seconds);

    echo '<script type="text/javascript">', 'changeLoc(', $secondsJson, ', ', $urlJson, ');', '</script>';
}

?>
<!-- HTML Stuff -->
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE-edge">
    <meta name="viewport" content="width=device-width, initial-scale:1.0">
    <link rel="stylesheet" href="styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
            href="https://fonts.googleapis.com/css2?family=Londrina+Outline&family=Londrina+Solid&family=Roboto&family=Signika+Negative:wght@500&display=swap"
            rel="stylesheet">

    <script type="text/javascript">
        function setup() {
            let el = getElementsByClassName('popup');

            for (let i = 0; i < el.length; i++) {

                let box = document.getElementById(el[i].id + '_box');
                box.style.display = 'none';

                el[i].onmouseover = function (e) {
                    let mousePos = getMouseLocation(e);
                    let box = document.getElementById(this.id + '_box');

                    box.style.display = 'block';
                    box.style.top = (mousePos[1]) + 'px';
                    box.style.left = (mousePos[0] + 20) + 'px';
                };
                el[i].onmousemove = function (e) {
                    let mousePos = getMouseLocation(e);
                    let box = document.getElementById(this.id + '_box');

                    box.style.top = (mousePos[1]) + 'px';
                    box.style.left = (mousePos[0] + 20) + 'px';
                };
                el[i].onmouseout = function () {
                    let box = document.getElementById(this.id + '_box');
                    box.style.display = 'none';
                };
            }
        }

        function navigateTo(url, clickedBox) {
            const boxes = document.querySelectorAll('.box');
            boxes.forEach(box => {
                if (box !== clickedBox) {
                    box.classList.remove('active');
                }
            });
            clickedBox.classList.add('active');

            window.location.href = url;
        }

        function getMouseLocation(e) {
            let posx, posy;
            if (e.pageX || e.pageY) {
                posx = e.pageX;
                posy = e.pageY;
            } else if (e.clientX || e.clientY) {
                posx = e.clientX + document.body.scrollLeft + document.documentElement.scrollLeft;
                posy = e.clientY + document.body.scrollTop + document.documentElement.scrollTop;
            }
            return [posx, posy];
        }

        window.onload = setup;

        function getElementsByClassName(className, tag, elm) {
            let testClass = new RegExp("(^|\\s)" + className + "(\\s|$)");
            tag = tag || "*";
            elm = elm || document;
            let elements = (tag === "*") ? elm.querySelectorAll("." + className) : elm.querySelectorAll(tag + "." + className);
            let returnElements = [];
            let current;
            let length = elements.length;
            for (let i = 0; i < length; i++) {
                current = elements[i];
                if (testClass.test(current.className)) {
                    returnElements.push(current);
                }
            }
            return returnElements;
        }

        // User detail popup
        function openUserDetails(url) {
            const width = 550;
            const height = 400;

            // Calculate the monitor where the browser is located
            const currentMonitor = screen.width * window.screenLeft / screen.width;

            // Calculate left position to open the popup on the same monitor
            const left = currentMonitor + (screen.width / 2) - (width / 2);
            const top = (screen.height - height) / 2;

            window.open(url, "popup", `scrollbars=yes, width=${width}, height=${height}, left=${left}, top=${top}`);
        }

        // Change location after x seconds
        function changeLoc(seconds, url) {
            setTimeout(() => {
                window.location.href = url;
            }, seconds * 1000);
        }

        function userList() {
            const width = 550;
            const height = 400;

            // Calculate the monitor where the browser is located
            const currentMonitor = screen.width * window.screenLeft / screen.width;

            // Calculate left position to open the popup on the same monitor
            const left = currentMonitor + (screen.width / 2) - (width / 2);
            const top = (screen.height - height) / 2;

            window.open("userlist.php", "popup", `scrollbars=yes, width=${width}, height=${height}, left=${left}, top=${top}`);
        }

        function adjustUsernameDisplay() {
            let usernameContainer = document.getElementById("usernameContainer");
            let usernameDiv = document.getElementById("username");

            if (usernameDiv.scrollWidth > usernameContainer.clientWidth) {
                usernameDiv.style.textOverflow = "ellipsis";
            }
        }

        function startCountdown(initialSeconds) {
            let seconds = initialSeconds;
            let countdownInterval;

            function countDown() {
                let minutes = Math.floor(seconds / 60);
                let remainingSeconds = seconds % 60;

                minutes = (minutes < 10) ? "0" + minutes : minutes;
                remainingSeconds = (remainingSeconds < 10) ? "0" + remainingSeconds : remainingSeconds;

                document.getElementById("counter").innerHTML = minutes + ":" + remainingSeconds;

                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    document.getElementById("counter").innerHTML = "Fertig!";
                } else {
                    seconds--;
                }
            }

            // Initial call to set up the countdown
            countDown();

            countdownInterval = setInterval(countDown, 1000);
            return false;
        }

        function startCountup(initialSeconds) {
            let seconds = initialSeconds;

            function countUp() {
                let hours = Math.floor(seconds / 3600);
                let minutes = Math.floor((seconds % 3600) / 60);
                let remainingSeconds = seconds % 60;

                hours = (hours < 10) ? "0" + hours : hours;
                minutes = (minutes < 10) ? "0" + minutes : minutes;
                remainingSeconds = (remainingSeconds < 10) ? "0" + remainingSeconds : remainingSeconds;

                document.getElementById("counter").innerHTML = hours + ":" + minutes + ":" + remainingSeconds;

                seconds++;
            }

            // Initial call to set up the countup
            countUp();
            setInterval(countUp, 1000);
            return false;
        }

        function updateTime(initialSeconds) {
            let serverTime = document.getElementById("servertime");

            updateDisplay();

            function updateDisplay() {
                let currentTime = new Date(initialSeconds * 1000);
                serverTime.innerHTML = " " + currentTime.toTimeString().split(' ')[0];

                setTimeout(() => {
                    initialSeconds++;
                    updateDisplay();
                }, 1000);
            }

            return false;
        }
    </script>
    <title>Magic Empires</title>
</head>
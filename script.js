const CHAT_UPDATE_INTERVAL = 5000;

function setup() {
    let el = getElementsByClassName('popup');

    for (let i = 0; i < el.length; i++) {
        let box = document.getElementById(el[i].id + '_box');
        if (box) {
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
    const width = 740;
    const height = 400;

    // Calculate the monitor where the browser is located
    const currentMonitor = screen.width * window.screenLeft / screen.width;

    // Calculate left position to open the popup on the same monitor
    const left = currentMonitor + (screen.width / 2) - (width / 2);
    const top = (screen.height - height) / 2;

    window.open(url, "popup", `scrollbars=yes, width=${width}, height=${height}, left=${left}, top=${top}`);
}

function userList() {
    const width = 740;
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

function startCountdown(initialSeconds, timerType = 0) {
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
            if (timerType === 0) {
                document.getElementById("counter").innerHTML = "Fertig!";
            }
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

function updateTime(initialSeconds, inactivitySeconds) {
    let serverTime = document.getElementById("servertime");

    updateDisplay();

    // Inactivity check
    window.onload = function () {
        inactivityLogout(inactivitySeconds - 1);
    };

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

function sendUpdateMapRequest() {
    let startX, startY, inputX, inputY;
    let startXField = document.getElementById("startx");
    let startYField = document.getElementById("starty");

    if (startXField && startXField.value) {
        startX = inputX = startXField.value;
    }
    if (startYField && startYField.value) {
        startY = inputY = startYField.value;
    }

    if (startX !== undefined && startY !== undefined) {
        startX = Math.max(1, Math.min(parseInt(startX) - 5, 91));
        startY = Math.max(1, Math.min(parseInt(startY) - 5, 91));

        //clearFieldHighlighting();
        updateMap(startX, startY, inputX, inputY);
    }
}

function updateMap(newStartX, newStartY, inputX, inputY) {
    // Make an AJAX request to update the map
    let xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
            document.getElementById("map-container").innerHTML = this.responseText;

            // Check if update map was requested via the input fields or the map arrows
            if (inputX !== undefined && inputY !== undefined) {
                document.getElementById('startx').value = inputX;
                document.getElementById('starty').value = inputY;

                let cell = document.querySelector(`td[data-x="${inputX}"][data-y="${inputY}"]`);

                if (cell) {
                    let fieldID = cell.getAttribute('data-fieldid');

                    highlightField(parseInt(fieldID), inputX, inputY);
                }
            } else {
                let highlightedCell = document.querySelector('td.highlight');

                if (highlightedCell) {
                    let fieldID = highlightedCell.getAttribute('data-fieldid');
                    let x = highlightedCell.getAttribute('data-x');
                    let y = highlightedCell.getAttribute('data-y');

                    highlightField(parseInt(fieldID), parseInt(x), parseInt(y));
                } else {
                    let oldField = document.getElementById("highlightedfield");
                    let x = oldField.getAttribute("data-x");
                    let y = oldField.getAttribute("data-y");

                    highlightEnteredCoordinates(x, y);
                }
            }
        }
    };
    xhttp.open("GET", "map_update.php?startx=" + newStartX + "&starty=" + newStartY, false);
    xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    xhttp.send();
}

function highlightField(clickedfield = -1, x = -1, y = -1) {
    // If the clicked td is already highlighted, stop executing the rest
    /*if (field.classList.contains("highlight")) {
        return;
    }*/

    let fieldToHighlight = document.getElementById("highlightedfield");
    fieldToHighlight.setAttribute("data-x", x.toString());
    fieldToHighlight.setAttribute("data-y", y.toString());

    // Remove every other td's highlighting
    clearFieldHighlighting();
    highlightEnteredCoordinates(x, y);

    // Make an AJAX request to update map and show field info
    let xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
            // Update the map HTML with the response
            document.getElementById("field-info").innerHTML = this.responseText;
        }
    };
    xhttp.open("GET", "field_info.php?clickedfield=" + clickedfield + "&x=" + x + "&y=" + y, true);
    xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    xhttp.send();
}

function highlightEnteredCoordinates(x, y) {
    let cell = document.querySelector(`td[data-x="${x}"][data-y="${y}"]`);

    if (cell) {
        cell.classList.add("highlight");
    }
}

function clearFieldHighlighting() {
    document.querySelectorAll('td.highlight').forEach(cell => {
        cell.classList.remove('highlight');
    });
}

function showTimedMessage(message, duration) {
    let messageDiv = document.createElement("div");
    //messageDiv.className = "timed-message"; // Add class for styling
    messageDiv.textContent = message;

    document.body.appendChild(messageDiv);

    // Set a timeout to remove the message after the specified duration
    setTimeout(function () {
        messageDiv.style.display = "none"; // Hide the message
    }, duration);
}

function scrollToLatestMessage() {
    let messagesSection = document.getElementById("messages-section");
    if (messagesSection) {
        messagesSection.scrollTop = messagesSection.scrollHeight - messagesSection.clientHeight;
    }
}

function sendUpdateChatRequest() {
    const queryString = window.location.search;
    const urlParams = new URLSearchParams(queryString);
    const chatPartner = urlParams.get("s");

    if (chatPartner !== undefined) {
        updateChat(chatPartner);
    }
}

function updateChat(chatPartner) {
    // Make an AJAX request to update the chat
    let xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
            const response = JSON.parse(xhttp.responseText);

            document.getElementById("messages-section").innerHTML = response.html;

            if (response.hasNewMessages) {
                scrollToLatestMessage();
            }
        }
    };
    xhttp.open("GET", "chat_update.php?action=read&s=" + chatPartner, true);
    xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    xhttp.send();

    return undefined;
}

function insertNewChatMessage(e) {
    e.preventDefault();

    let messageInput = document.getElementById("message-input");
    let receiver = document.querySelector('input[name="receiver"]').value;
    let text = messageInput.value;

    let xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
            try {
                let response = JSON.parse(this.responseText);

                if (response.error) {
                    alert(response.error);
                } else if (response.html) {
                    document.getElementById("messages-section").innerHTML += response.html;
                    messageInput.value = "";

                    scrollToLatestMessage();
                }
            } catch (e) {
                console.error("Error parsing JSON:", e);
            }
        }
    };
    xhttp.open("POST", "chat_insert.php", true);
    xhttp.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");

    xhttp.send("receiver=" + encodeURIComponent(receiver) + "&text=" + encodeURIComponent(text));
}

function initializeChat() {
    // Focus on the message input field
    document.getElementById("message-input").focus();

    //
    document.getElementById("message-input").addEventListener("keypress", e => {
        if (e.key === "Enter" && !e.shiftKey) {
            insertNewChatMessage(e);
        }
    });

    // Add event listener for form submission
    document.getElementById("newmessage").addEventListener("submit", e => {
        insertNewChatMessage(e);
    });

    // Chat update function
    setInterval(() => {
        sendUpdateChatRequest();
    }, CHAT_UPDATE_INTERVAL);
}

function updateKingdom() {
    // Get the selected kingdom ID from the dropdown
    let kingdomID = document.getElementById("choosekingdom").value;

    if (kingdomID) {
        // Prepare the form data
        let formData = new FormData();
        formData.append("choosekingdom", kingdomID);

        // Make an AJAX request to update the kingdom info
        let xhttp = new XMLHttpRequest();
        xhttp.onreadystatechange = function () {
            if (this.readyState === 4 && this.status === 200) {
                // Construct the new URL based on the current page
                let currentUrl = new URL(window.location.href);
                let pathname = currentUrl.pathname;
                let params = new URLSearchParams(currentUrl.search);
                let newUrl;

                if (pathname.includes('buildings.php')) {
                    // When on buildings.php, keep only the id parameter
                    let id = params.get('id');

                    params = new URLSearchParams();
                    if (id) {
                        params.set('id', id);
                    }
                }

                newUrl = `${pathname}?${params.toString()}`;
                window.location.href = newUrl;
            }
        };
        xhttp.open("POST", "change_kingdom.php", true);
        xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhttp.send(formData);
    }
}

function inactivityLogout(seconds) {
    setTimeout(() => {
        window.location.href = 'login.php?logout=inactive';
    }, seconds * 1000);
}

document.addEventListener("DOMContentLoaded", function () {
    const mobileNav = document.getElementById("mobile-nav");
    const hamburgerIcon = document.getElementById("hamburger-icon");

    if (hamburgerIcon) {
        hamburgerIcon.addEventListener("click", function () {
            mobileNav.classList.toggle("open");
            hamburgerIcon.classList.toggle("open");
        });
    }

    // Load popup box setup
    setup();
});
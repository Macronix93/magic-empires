function setup() {
    const popups = getElementsByClassName('popup');

    for (let i = 0; i < popups.length; i++) {
        let box = document.getElementById(popups[i].id + '_box');
        if (box) {
            box.style.display = 'none';

            popups[i].onmouseover = function (e) {
                if (box) {
                    let mousePos = getMouseLocation(e);
                    box.style.display = 'block';
                    box.style.top = (mousePos[1]) + 'px';
                    box.style.left = (mousePos[0] + 20) + 'px';
                }
            };

            popups[i].onmousemove = function (e) {
                if (box) {
                    let mousePos = getMouseLocation(e);
                    box.style.top = (mousePos[1]) + 'px';
                    box.style.left = (mousePos[0] + 20) + 'px';
                }
            };

            popups[i].onmouseout = function () {
                if (box) {
                    box.style.display = 'none';
                }
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
    tag = tag || "*";
    elm = elm || document;
    return Array.from(elm.querySelectorAll(tag + "." + className));
}

function adjustUsernameDisplay() {
    let usernameContainer = document.getElementById("usernameContainer");
    let usernameDiv = document.getElementById("username");

    if (usernameDiv.scrollWidth > usernameContainer.clientWidth) {
        usernameDiv.style.textOverflow = "ellipsis";
    }
}

function updateServerTime(initialSeconds) {
    function updateDisplay() {
        let serverTimeElements = document.getElementsByClassName("servertime");

        // Iterate through each "servertime" element
        for (let serverTime of serverTimeElements) {
            // Visibility check for server time element
            if (serverTime.offsetParent !== null) {
                let currentTime = new Date(initialSeconds * 1000);
                serverTime.innerHTML = " " + currentTime.toTimeString().split(' ')[0];
            }
        }

        // Update every second
        setTimeout(() => {
            initialSeconds++;
            updateDisplay();
        }, 1000);
    }

    updateDisplay();

    return false;
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
        xhttp.open("POST", "ajax/change_kingdom.php", true);
        xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhttp.send(formData);
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

window.addEventListener("DOMContentLoaded", function () {
    const mobileNav = document.getElementById("mobile-nav");
    const hamburgerIcon = document.getElementById("hamburger-icon");

    if (hamburgerIcon) {
        hamburgerIcon.addEventListener("click", function () {
            mobileNav.classList.toggle("open");
            hamburgerIcon.classList.toggle("open");
        });
    }

    // Inactivity check
    setTimeout(() => {
        window.location.href = 'login.php?logout=inactive';
    }, 1799 * 1000);

    // Setup for popup boxes
    setup();

    // Calculate and update the styling of the username dynamically
    adjustUsernameDisplay();
});
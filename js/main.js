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

function adjustUsernameDisplay() {
    let usernameContainer = document.getElementById("usernameContainer");
    let usernameDiv = document.getElementById("username");

    if (usernameDiv.scrollWidth > usernameContainer.clientWidth) {
        usernameDiv.style.textOverflow = "ellipsis";
    }
}

function updateServerTime(initialSeconds, inactivitySeconds) {
    updateDisplay();

    // Inactivity check
    window.onload = function () {
        inactivityLogout(inactivitySeconds - 1);
    };

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
                const response = JSON.parse(this.responseText);
                console.log("Response:", response);

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
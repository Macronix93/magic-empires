function setup() {
    const popups = getElementsByClassName('popup');

    for (let i = 0; i < popups.length; i++) {
        /** @type {HTMLElement} */
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

    /** @type {HTMLElement} */
    let usernameDiv = document.getElementById("username");

    if (usernameDiv && usernameDiv.scrollWidth > usernameContainer.clientWidth) {
        usernameDiv.style.textOverflow = "ellipsis";
    }
}

function updateServerTime(initialSeconds) {
    function updateDisplay() {
        let serverTimeElements = document.getElementsByClassName("servertime");

        // Iterate through each "servertime" element
        for (let i = 0; i < serverTimeElements.length; i++) {
            /** @type {HTMLElement} */
            const serverTime = serverTimeElements[i];

            if (serverTime.offsetParent !== null) {
                const currentTime = new Date(initialSeconds * 1000);
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
}

function updateKingdom(selectElement) {
    // Get the selected kingdom ID from the dropdown
    const chosenKingdom = selectElement;

    if (chosenKingdom) {
        const kingdomID = chosenKingdom.value;

        let formData = new FormData();
        formData.append("choosekingdom", kingdomID);

        // Make an AJAX request to update the kingdom info
        let xhttp = new XMLHttpRequest();
        xhttp.onreadystatechange = function () {
            if (this.readyState === 4 && this.status === 200) {
                // Construct the new URL based on the current page
                /*let currentUrl = new URL(window.location.href);
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

                newUrl = `${pathname}?${params.toString()}`;*/

                window.location.href = new URL(window.location.href).pathname;
            }
        };
        xhttp.open("POST", "ajax/change_kingdom.php", true);
        xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhttp.send(formData);
    }
}

function showConfirmationDialog(dialogText, buttonYesText, buttonNoText, buttonYesAction) {
    const infoBoxBg = document.createElement("div");
    const infoBoxOverlay = document.createElement("div");
    const infoBoxTextBox = document.createElement("p");
    const buttonYes = document.createElement("button");
    const buttonNo = document.createElement("button");

    buttonYes.onclick = buttonYesAction;
    buttonYes.innerText = buttonYesText;
    buttonNo.onclick = cancelDialog;
    buttonNo.innerText = buttonNoText;

    infoBoxTextBox.innerText = dialogText;

    infoBoxBg.id = "info-box-bg";
    infoBoxBg.classList.add("info-box-bg");

    infoBoxOverlay.id = "info-box-overlay";
    infoBoxOverlay.classList.add("info-box-overlay");
    infoBoxOverlay.append(infoBoxTextBox, buttonYes, buttonNo);

    document.body.append(infoBoxBg, infoBoxOverlay);
}

function cancelDialog() {
    document.getElementById('info-box-bg').remove();
    document.getElementById('info-box-overlay').remove();
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

    // Setup for popup boxes
    setup();

    // Calculate and update the styling of the username dynamically
    adjustUsernameDisplay();
});
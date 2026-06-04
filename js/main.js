const ClickActions = new Map();

registerAction("redirect", (el) => {
    const url = el.dataset.url;
    if (url) {
        window.location.href = url;
    }
});
registerAction("fillMax", (el) => {
    const targetId = el.dataset.target;
    const maxValue = el.dataset.value;

    /** @type {HTMLInputElement} */
    const input = document.getElementById(targetId);

    if (input) {
        input.value = maxValue;
    }
});
registerAction("switchKingdom", (el) => {
    const kingdomId = el.dataset.id;
    if (typeof switchKingdomAndReload === "function") {
        switchKingdomAndReload(kingdomId);
    }
});
registerAction("switchKingdomPrev", () => {
    if (typeof switchKingdom === "function") switchKingdom(-1);
});

registerAction("switchKingdomNext", () => {
    if (typeof switchKingdom === "function") switchKingdom(1);
});
registerAction("pickUser", (el) => {
    const username = el.dataset.username;

    if (typeof selectUser === "function") {
        selectUser(username);
    }
});
registerAction("navigate", (el) => {
    const url = el.dataset.url;
    if (url) window.location.href = url;
});
registerAction("changeKingdomSelect", (el) => {
    if (typeof updateKingdom === "function") {
        updateKingdom(el);
    }
});

function registerAction(name, callback) {
    ClickActions.set(name, callback);
    const selector = `[data-on-click="${name}"], [data-on-submit="${name}"]`;
    document.querySelectorAll(selector).forEach(bindActions);
}

function bindActions(el) {
    if (!el.dataset) return;

    const actionName = el.dataset.onClick;
    const callback = ClickActions.get(actionName);

    if (callback && !el.dataset.bound) {
        el.addEventListener("click", (e) => {
            e.preventDefault();

            callback(el, e);
        });

        el.dataset.bound = "true";
    }

    if (el.dataset.onSubmit && !el.dataset.boundSubmit) {
        el.addEventListener("submit", (e) => {
            const callback = ClickActions.get(el.dataset.onSubmit);

            if (callback) callback(el, e);
        });

        el.dataset.boundSubmit = "true";
    }

    if (el.dataset.onChange && !el.dataset.boundChange) {
        const callback = ClickActions.get(el.dataset.onChange);

        if (callback) {
            el.addEventListener("change", (e) => callback(el, e));
            el.dataset.boundChange = "true";
        }
    }
}

const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
            if (node.nodeType === 1) {
                if (node.dataset.onClick || node.dataset.onSubmit) bindActions(node);

                node.querySelectorAll('[data-on-click]').forEach(bindActions);
            }
        });
    });
});

observer.observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ["data-on-click", "data-on-submit", "data-on-change"]
});

function setup() {
    const popups = getElementsByClassName('popup');

    for (let i = 0; i < popups.length; i++) {
        /** @type {HTMLElement} */
        let box = document.getElementById(popups[i].id + '_box');

        if (box) {
            box.style.display = 'none';

            const positionBox = function (e) {
                let mousePos = getMouseLocation(e);

                box.style.display = 'block';

                const boxWidth = box.offsetWidth;
                const boxHeight = box.offsetHeight;
                const windowWidth = window.innerWidth;
                const windowHeight = window.innerHeight;

                let left = mousePos[0] - (boxWidth / 2);

                if (left < 10) {
                    left = 10;
                } else if (left + boxWidth > windowWidth - 10) {
                    left = windowWidth - boxWidth - 10;
                }

                let top = mousePos[1] + 25;

                if (e.clientY + 25 + boxHeight > windowHeight) {
                    top = mousePos[1] - boxHeight - 15;
                }

                box.style.left = left + 'px';
                box.style.top = top + 'px';
            };

            popups[i].onmouseover = positionBox;
            popups[i].onmousemove = positionBox;
            popups[i].onmouseout = function () {
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
    // 1. Berechne beim Seitenstart einmalig die Sekunden bis zur nächsten vollen Stunde
    const now = new Date(initialSeconds * 1000);
    const startMinutes = now.getMinutes();
    const startSeconds = now.getSeconds();

    // Wie viele Sekunden verbleiben in dieser aktuellen Stunde?
    let secondsUntilFull = 3600 - ((startMinutes * 60) + startSeconds);

    function updateDisplay() {
        const serverTimeElements = document.getElementsByClassName("servertime");
        const tickTimers = document.getElementsByClassName("tick-timer");
        const tickFills = document.getElementsByClassName("tick-progress-fill");

        const currentTime = new Date(initialSeconds * 1000);
        const timeString = currentTime.toTimeString().split(' ')[0];

        for (let i = 0; i < serverTimeElements.length; i++) {
            /** @type {HTMLElement} */
            const el = serverTimeElements[i];

            if (el.offsetParent !== null) {
                el.innerHTML = " " + timeString;
            }
        }

        let displayTime, percent;

        if (secondsUntilFull > 0) {
            const displayMin = Math.floor(secondsUntilFull / 60);
            const displaySec = secondsUntilFull % 60;
            displayTime = String(displayMin).padStart(2, '0') + ":" + String(displaySec).padStart(2, '0');

            const currentMinutes = currentTime.getMinutes();
            const currentSeconds = currentTime.getSeconds();
            const currentPassed = (currentMinutes * 60) + currentSeconds;
            percent = (currentPassed / 3600) * 100;

            secondsUntilFull--;
        } else {
            displayTime = "00:00";
            percent = 100;
        }

        for (let i = 0; i < tickTimers.length; i++) {
            tickTimers[i].innerText = displayTime;
        }

        for (let i = 0; i < tickFills.length; i++) {
            /** @type {HTMLElement} */
            const el = tickFills[i];
            el.style.width = percent + "%";
        }

        setTimeout(() => {
            initialSeconds++;
            updateDisplay();
        }, 1000);
    }

    updateDisplay();
}

function switchKingdom(direction) {
    /** @type {HTMLSelectElement} */
    const select = document.getElementById("choosekingdom");

    if (!select) return;

    let newIndex = select.selectedIndex + direction;

    if (newIndex < 0) {
        newIndex = select.options.length - 1;
    } else if (newIndex >= select.options.length) {
        newIndex = 0;
    }

    select.selectedIndex = newIndex;

    updateKingdom(select);
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
                let currentUrl = new URL(window.location.href);

                // Check if we are on conquest page and keep x and y coordinates
                if (currentUrl.pathname.includes("sendtroops.php")) {
                    window.location.href = currentUrl.pathname + currentUrl.search;
                } else {
                    window.location.href = currentUrl.pathname;
                }
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
    const leftTrigger = document.getElementById("nav-left-trigger");
    const leftMenu = document.getElementById("nav-left-menu");
    const rightTrigger = document.getElementById("nav-right-trigger");
    const rightMenu = document.getElementById("nav-right-menu");
    document.querySelectorAll('[data-on-click], [data-on-submit]').forEach(bindActions);

    function closeMenus() {
        if (leftMenu) leftMenu.classList.remove("open");
        if (leftTrigger) leftTrigger.classList.remove("open");
        if (rightMenu) rightMenu.classList.remove("open");
        if (rightTrigger) rightTrigger.classList.remove("open");
    }

    window.addEventListener("resize", function () {
        if (window.innerWidth > 1392) {
            closeMenus();
        }
    });

    if (leftTrigger) {
        leftTrigger.addEventListener("click", function (e) {
            e.stopPropagation();
            rightMenu.classList.remove("open");
            rightTrigger.classList.remove("open");
            leftMenu.classList.toggle("open");
            leftTrigger.classList.toggle("open");
        });
    }

    if (rightTrigger) {
        rightTrigger.addEventListener("click", function (e) {
            e.stopPropagation();
            leftMenu.classList.remove("open");
            leftTrigger.classList.remove("open");
            rightMenu.classList.toggle("open");
            rightTrigger.classList.toggle("open");
        });
    }

    document.addEventListener("click", function (e) {
        if (!leftMenu || !rightMenu) return;

        if (!leftMenu.contains(e.target) && !rightMenu.contains(e.target) &&
            !leftTrigger.contains(e.target) && !rightTrigger.contains(e.target)) {
            closeMenus();
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    setup();
    adjustUsernameDisplay();
});

function selectUser(id) {
    const form = document.forms["newmessage"];

    if (form) {
        form.receiver.value = id;
    }
}
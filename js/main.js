const ClickActions = new Map();

registerAction("redirect", (el) => {
    const url = el.dataset.url;
    if (url) {
        const forbiddenProtocols = ["javascript:", "data:", "vbscript:"];
        const isForbidden = forbiddenProtocols.some(proto => url.trim().toLowerCase().startsWith(proto));

        if (!isForbidden) {
            window.location.href = url;
        }
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
    const selector = `[data-on-click="${name}"], [data-on-submit="${name}"], [data-on-change="${name}"]`;
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
    // Alle Elemente mit der Klasse 'popup' finden
    const popups = document.querySelectorAll('.popup');

    popups.forEach(trigger => {
        // Die Box finden, die sich innerhalb des Triggers befindet
        const box = trigger.querySelector('.popupbox');

        if (box) {
            // WICHTIG: Die Box an den Body verschieben,
            // damit sie nicht von der Sidebar abgeschnitten wird.
            document.body.appendChild(box);

            const positionBox = function (e) {
                let mousePos = getMouseLocation(e);

                // Box sichtbar machen und über alles andere legen
                box.style.display = 'block';
                box.style.position = 'absolute';
                box.style.zIndex = '999999';

                const boxWidth = box.offsetWidth;
                const boxHeight = box.offsetHeight;
                const windowWidth = window.innerWidth;
                const windowHeight = window.innerHeight;

                // Horizontale Position berechnen (Zentriert unter/über Maus)
                let left = mousePos[0] - (boxWidth / 2);

                // Rand-Check links
                if (left < 10) left = 10;
                // Rand-Check rechts (verhindert, dass die Box rechts rausragt)
                if (left + boxWidth > windowWidth - 10) {
                    left = windowWidth - boxWidth - 10;
                }

                // Vertikale Position (Standard: 25px unter der Maus)
                let top = mousePos[1] + 25;

                // Mobile/Viewport Check: Wenn die Box unten rausragen würde,
                // zeige sie oberhalb der Maus/des Fingers an.
                let checkY = (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY;
                if (checkY + 25 + boxHeight > windowHeight) {
                    top = mousePos[1] - boxHeight - 20;
                }

                box.style.left = left + 'px';
                box.style.top = top + 'px';
            };

            // Mouse Events
            trigger.onmouseover = positionBox;
            trigger.onmousemove = positionBox;
            trigger.onmouseout = function () {
                box.style.display = 'none';
            };

            // Touch Support (Handy)
            trigger.addEventListener('touchstart', function (e) {
                if (box.style.display === 'block') {
                    box.style.display = 'none';
                } else {
                    // Alle anderen Popups schließen
                    document.querySelectorAll('.popupbox').forEach(b => b.style.display = 'none');
                    positionBox(e);
                }
            }, {passive: true});
        }
    });
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

function updateServerTime(initialServerTimestamp) {
    const clientStartTime = Date.now();
    const serverStartTime = initialServerTimestamp * 1000;

    function updateDisplay() {
        const now = Date.now();
        const elapsed = now - clientStartTime;

        const currentServerTime = new Date(serverStartTime + elapsed);

        const timeString = currentServerTime.toTimeString().split(' ')[0];
        const serverTimeElements = document.getElementsByClassName("servertime");
        for (let i = 0; i < serverTimeElements.length; i++) {
            if (serverTimeElements[i].offsetParent !== null) {
                serverTimeElements[i].textContent = timeString;
            }
        }

        const secondsIntoHour = (currentServerTime.getMinutes() * 60) + currentServerTime.getSeconds();
        const secondsUntilFull = 3600 - secondsIntoHour;

        const displayMin = Math.floor(secondsUntilFull / 60);
        const displaySec = secondsUntilFull % 60;
        const displayTime = String(displayMin).padStart(2, '0') + ":" + String(displaySec).padStart(2, '0');

        const tickTimers = document.getElementsByClassName("tick-timer");
        for (let i = 0; i < tickTimers.length; i++) {
            tickTimers[i].innerText = (secondsUntilFull <= 0) ? "Jetzt" : displayTime;
        }

        const percent = (secondsIntoHour / 3600) * 100;
        const tickFills = document.getElementsByClassName("tick-progress-fill");
        for (let i = 0; i < tickFills.length; i++) {
            tickFills[i].style.width = percent + "%";
        }
    }

    setInterval(updateDisplay, 500);
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
    const timeoutSeconds = parseInt(document.body.dataset.timeout);
    const serverTime = document.body.dataset.serverTime;
    document.querySelectorAll('[data-on-click], [data-on-submit]').forEach(bindActions);

    if (timeoutSeconds > 0) {
        let lastActivityTimestamp = Date.now();
        const logoutLimitMs = timeoutSeconds * 1000;

        const resetActivity = () => {
            lastActivityTimestamp = Date.now();
        };

        ["mousedown", "mousemove", "keypress", "scroll", "touchstart"].forEach(eventName => {
            document.addEventListener(eventName, resetActivity, {passive: true});
        });

        setInterval(() => {
            const now = Date.now();
            const inactiveTime = now - lastActivityTimestamp;

            if (inactiveTime >= logoutLimitMs) {
                window.location.href = "index.php?logout=inactive";
            }
        }, 10000);
    }

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

    if (serverTime) updateServerTime(parseInt(serverTime));
    initAutomaticCountdowns();
});

function selectUser(id) {
    const form = document.forms["newmessage"];

    if (form) {
        form.receiver.value = id;
    }
}

function initAutomaticCountdowns() {
    document.querySelectorAll('.js-countdown').forEach(el => {
        const seconds = parseInt(el.dataset.seconds) || 0;
        const timerType = parseInt(el.dataset.timerType) || 0;
        const hideID = el.dataset.hideId || null;
        const keepParams = el.dataset.keepParams === "true";
        const noReload = el.dataset.noReload === "true";

        startCountdown(el, seconds, timerType, hideID, keepParams, noReload);
    });
}
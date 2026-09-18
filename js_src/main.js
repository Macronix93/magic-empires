const ClickActions = new Map();
let titleInterval = null;
const originalTitle = document.title;
const TITLE_INTERVAL = 2000;
let isKingdomSwitching = false;
let flashTimeout = null;

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
registerAction("toggleMobileKingdomMenu", (el, e) => {
    if (e) e.stopPropagation();
    const dropdown = document.getElementById("mobile-kingdom-dropdown");
    if (dropdown) {
        dropdown.classList.toggle("open");
    }
});
registerAction("selectMobileKingdom", (el) => {
    const kingdomId = el.dataset.id;
    const dropdown = document.getElementById("mobile-kingdom-dropdown");
    if (dropdown) dropdown.classList.remove("open");

    if (typeof switchKingdomAndReload === "function") {
        switchKingdomAndReload(kingdomId);
    }
});
registerAction("switchKingdom", (el, e) => {
    if (window.innerWidth <= 600) {
        if (e) e.preventDefault();
        return;
    }

    const kingdomId = el.dataset.id;
    if (typeof switchKingdomAndReload === "function") {
        switchKingdomAndReload(kingdomId);
    }
});
registerAction("switchKingdomPrev", () => {
    if (isKingdomSwitching) return;
    if (typeof switchKingdom === "function") switchKingdom(-1);
});

registerAction("switchKingdomNext", () => {
    if (isKingdomSwitching) return;
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
registerAction("toggleEmojis", () => {
    const menu = document.getElementById("emoji-menu");
    if (menu) menu.classList.toggle("open");
});
registerAction("pickEmoji", (el) => {
    insertEmoji(el.innerText);
});
registerAction("finishTutorial", () => {
    fetch("ajax/tutorial_done.php", {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const overlay = document.getElementById("tutorial-overlay");
                if (overlay) overlay.remove();
            }
        });
});
registerAction("toggleBadges", (el) => {
    const container = el.closest('.badge-container');
    if (!container) return;

    container.querySelectorAll('.badge-hide-mobile, .badge-hide-desktop').forEach(item => {
        item.classList.remove("badge-hide-mobile", "badge-hide-desktop");
        item.style.display = "inline-flex";
    });

    el.remove();
});
registerAction("quoteMessage", (el) => {
    const author = el.dataset.author;
    const text = el.dataset.text;

    const input = document.getElementById("message-input");
    if (input) {
        const quoteTag = `[quote=${author}]${text}[/quote] `;

        const start = input.selectionStart;
        const end = input.selectionEnd;
        const currentVal = input.value;

        const prefix = (start > 0 && currentVal[start - 1] !== "\n") ? "\n" : "";
        const insertion = prefix + quoteTag;

        input.value = currentVal.substring(0, start) + insertion + currentVal.substring(end);

        const newPos = start + insertion.length;
        input.focus();
        input.setSelectionRange(newPos, newPos);

        input.scrollIntoView({behavior: "smooth", block: "center"});
    }
});
registerAction("toggleReaction", (el) => {
    const container = el.closest('.reaction-container');

    sendReaction(el.dataset.type, el.dataset.id, el.dataset.emoji, container);
});
registerAction("toggleReactionPicker", (el) => {
    const container = el.closest('.reaction-add-wrapper');
    const picker = container.querySelector('.reaction-picker');
    const isVisible = window.getComputedStyle(picker).display === "grid";

    document.querySelectorAll('.reaction-picker').forEach(p => p.style.display = "none");

    if (!isVisible) {
        const rect = el.getBoundingClientRect();

        picker.style.display = "grid";
        const pickerHeight = picker.offsetHeight;
        const pickerWidth = picker.offsetWidth;

        if (window.innerHeight - rect.bottom < 250) {
            picker.style.top = (rect.top - pickerHeight - 5) + "px";
        } else {
            picker.style.top = (rect.bottom + 5) + "px";
        }

        let leftPos = rect.right - pickerWidth;
        if (leftPos < 10) leftPos = 10;

        picker.style.left = leftPos + "px";
    } else {
        picker.style.display = "none";
    }
});
registerAction("openReactorList", (el) => {
    const type = el.dataset.type;
    const id = el.dataset.id;

    if (typeof openOverlay === "function") {
        openOverlay(`ajax/reaction_details.php?type=${type}&id=${id}`, "Wer hat reagiert?");
    }
});
registerAction("toggleMassExcludeSpecials", (el) => {
    document.cookie = "me_mass_exclude_specials=" + (el.checked ? "1" : "0") + "; path=/; max-age=31536000; SameSite=Lax";
});

function registerAction(name, callback) {
    ClickActions.set(name, callback);
    const selector = `[data-on-click="${name}"], [data-on-submit="${name}"], [data-on-change="${name}"], [data-on-input="${name}"]`;
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

    if (el.dataset.onInput && !el.dataset.boundInput) {
        const callback = ClickActions.get(el.dataset.onInput);

        if (callback) {
            el.addEventListener("input", (e) => callback(el, e));
            el.dataset.boundInput = "true";
        }
    }
}

const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
            if (node.nodeType === 1) {
                if (node.dataset.onClick || node.dataset.onSubmit) bindActions(node);

                node.querySelectorAll('[data-on-click]').forEach(bindActions);

                if (node.classList.contains("popup") || node.querySelector('.popup')) {
                    setup();
                }
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

function cleanupPopups() {
    const boxes = document.querySelectorAll('body > .popupbox');
    boxes.forEach(box => {
        const triggerId = box.id.replace('_box', '');
        const trigger = document.getElementById(triggerId);

        if (!trigger) {
            box.remove();
        } else {
            if (box.style.display === "block" && !trigger.matches(':hover')) {
                box.style.display = "none";
            }
        }
    });
}

function sendReaction(type, id, emoji, sourceContainer) {
    document.querySelectorAll('.reaction-picker').forEach(p => p.style.display = "none");

    const chatBubble = sourceContainer.closest('.sender-bubble, .receiver-bubble');

    let targetContainer = sourceContainer;
    let mode;

    if (chatBubble) {
        mode = "badges_only";

        const footer = chatBubble.querySelector('.chat-reaction-footer');
        if (footer) {
            targetContainer = footer;
        }
    } else {
        mode = "full";
    }

    const badge = targetContainer.querySelector(`.reaction-badge[data-emoji="${emoji}"]`);
    if (badge) {
        const countEl = badge.querySelector('small');
        let count = parseInt(countEl.innerText);

        if (badge.classList.contains("active")) {
            badge.classList.remove("active");
            countEl.innerText = count - 1;
        } else {
            badge.classList.add("active");
            countEl.innerText = count + 1;
        }
    }

    const formData = new URLSearchParams();
    formData.append("type", type);
    formData.append("id", id);
    formData.append("emoji", emoji);
    formData.append("mode", mode);

    fetch('ajax/reaction_toggle.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData.toString()
    })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                cleanupPopups();

                if (chatBubble) {
                    targetContainer.innerHTML = res.html;
                } else {
                    targetContainer.outerHTML = res.html;
                }
            } else if (res.error) {
                showConfirmationDialog(res.error, "Ok", "", () => {
                });
            }
        });
}

function formatNumJS(number) {
    if (!number || isNaN(number)) return "0";
    let n = Math.floor(Number(number));

    if (n >= 1000000) {
        let main = Math.floor(n / 1000000);
        let sub = Math.floor((n % 1000000) / 10000);

        if (sub === 0) return main + 'M';

        let subStr = sub.toString().padStart(2, '0').replace(/0$/, '');
        return main + ',' + subStr + 'M';
    }

    if (n >= 100000) {
        let main = Math.floor(n / 1000);
        let sub = Math.floor((n % 1000) / 100);

        if (sub === 0) return main + 'k';

        return main + ',' + sub + 'k';
    }

    return n.toLocaleString("de-DE");
}

function setup() {
    const popups = document.querySelectorAll('.popup');

    popups.forEach(trigger => {
        const box = trigger.querySelector('.popupbox');

        if (box) {
            if (box.parentNode !== document.body) {
                document.body.appendChild(box);
            }

            const positionBox = function (e) {
                if (box.dataset.enabled === "false") return;

                let mousePos = getMouseLocation(e);

                box.style.position = "absolute";
                box.style.left = "-9999px";
                box.style.top = "0px";
                box.style.display = "block";
                box.style.visibility = "hidden";
                box.style.zIndex = "2000005";

                const boxWidth = box.offsetWidth;
                const boxHeight = box.offsetHeight;

                const viewportWidth = window.innerWidth;
                const viewportHeight = window.innerHeight;
                const scrollX = window.pageXOffset || document.documentElement.scrollLeft;

                // Horizontal
                let left = mousePos[0] - (boxWidth / 2);

                if (left < scrollX + 10) {
                    left = scrollX + 10;
                }
                if (left + boxWidth > scrollX + viewportWidth - 10) {
                    left = scrollX + viewportWidth - boxWidth - 10;
                }

                // Vertical
                let top = mousePos[1] + 25;
                let clientY = (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY;

                if (clientY + 25 + boxHeight > viewportHeight) {
                    top = mousePos[1] - boxHeight - 20;
                }

                box.style.left = left + "px";
                box.style.top = top + "px";
                box.style.visibility = "visible";
            };

            // Mouse Events
            trigger.onmouseover = positionBox;
            trigger.onmousemove = positionBox;
            trigger.onmouseout = function () {
                box.style.display = "none";
            };

            // Touch Support
            trigger.addEventListener("touchstart", function (e) {
                if (window.innerWidth <= 600 && trigger.id && trigger.id.startsWith("activity")) {
                    return;
                }

                if (box.style.display === "block") {
                    box.style.display = "none";
                } else {
                    document.querySelectorAll('.popupbox').forEach(b => b.style.display = "none");
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

    const startHour = new Date(serverStartTime).getHours();
    let tickReached = false;

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

        const currentHour = currentServerTime.getHours();

        if (currentHour !== startHour) {
            tickReached = true;
        }

        const minutes = currentServerTime.getMinutes();
        const seconds = currentServerTime.getSeconds();
        const secondsIntoHour = (minutes * 60) + seconds;
        const secondsUntilFull = 3600 - secondsIntoHour;

        if (secondsUntilFull <= 5 && !tickReached && document.hidden) {
            startTitleFlash("+++ Ress.-Ertrag fällig! +++");
        }

        let displayTime;

        if (tickReached) {
            displayTime = "Jetzt";
        } else {
            const displayMin = Math.floor(secondsUntilFull / 60);
            const displaySec = secondsUntilFull % 60;
            displayTime = String(displayMin).padStart(2, '0') + ":" + String(displaySec).padStart(2, '0');
        }

        const tickTimers = document.getElementsByClassName("tick-timer");
        for (let i = 0; i < tickTimers.length; i++) {
            tickTimers[i].innerText = displayTime;
        }

        const percent = tickReached ? 100 : (secondsIntoHour / 3600) * 100;
        const sidebarTick = document.querySelector("#ressource-box .tick-progress-fill");
        if (sidebarTick) {
            sidebarTick.style.width = percent + "%";
        }
    }

    setInterval(updateDisplay, 500);

    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") {
            updateDisplay();
        }
    });

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

    updateKingdom(select, false);
}

function updateKingdom(selectElement, keepMenu = true) {
    if (isKingdomSwitching) return;

    isKingdomSwitching = true;

    // Get the selected kingdom ID from the dropdown
    const chosenKingdom = selectElement;

    if (chosenKingdom) {
        const kingdomID = chosenKingdom.value;

        let formData = new FormData();
        formData.append("choosekingdom", kingdomID);

        // Make an AJAX request to update the kingdom info
        let xhttp = new XMLHttpRequest();
        xhttp.onreadystatechange = function () {
            if (this.readyState === 4) {
                if (this.status === 200) {
                    if (keepMenu && window.innerWidth <= 1392) {
                        sessionStorage.setItem("keepRightMenuOpen", "true");
                    } else {
                        sessionStorage.removeItem("keepRightMenuOpen");
                    }

                    let currentUrl = new URL(window.location.href);
                    let pathname = currentUrl.pathname;
                    let search = currentUrl.search;
                    let filename = pathname.split('/').pop();

                    const keepParamsPages = [
                        "messages.php",
                        "ranking.php",
                        "support.php",
                        "sendtroops.php",
                        "map.php",
                    ];

                    if (filename === "barracks.php") {
                        const cat = currentUrl.searchParams.get("cat");

                        if (cat !== null) {
                            window.location.href = `${pathname}?cat=${cat}`;
                        } else {
                            window.location.href = pathname;
                        }
                    } else if (keepParamsPages.includes(filename)) {
                        window.location.href = pathname + search;
                    } else {
                        window.location.href = pathname;
                    }
                } else {
                    isKingdomSwitching = false;
                }
            }
        };
        xhttp.open("POST", "ajax/change_kingdom.php", true);
        xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
        xhttp.send(formData);
    }
}

function showConfirmationDialog(dialogText, buttonYesText, buttonNoText, buttonYesAction) {
    if (document.getElementById("info-box-overlay")) {
        return;
    }

    if (document.activeElement) {
        document.activeElement.blur();
    }

    const infoBoxBg = document.createElement("div");
    const infoBoxOverlay = document.createElement("div");
    const infoBoxTextBox = document.createElement("p");
    const buttonYes = document.createElement("button");

    const closeAndCleanup = () => {
        document.removeEventListener("keydown", handleKeyDown);
        if (infoBoxBg.parentNode) infoBoxBg.remove();
        if (infoBoxOverlay.parentNode) infoBoxOverlay.remove();
    };

    const handleKeyDown = (e) => {
        if (e.key === "Enter") {
            e.preventDefault();
            buttonYesAction();
            closeAndCleanup();
        } else if (e.key === "Escape") {
            closeAndCleanup();
        }
    };

    buttonYes.onclick = () => {
        buttonYesAction();
        closeAndCleanup();
    };
    buttonYes.innerText = buttonYesText;

    infoBoxTextBox.innerText = dialogText;
    infoBoxTextBox.style.marginBottom = 0;

    infoBoxBg.id = "info-box-bg";
    infoBoxBg.classList.add("info-box-bg");

    infoBoxOverlay.id = "info-box-overlay";
    infoBoxOverlay.classList.add("info-box-overlay");
    infoBoxOverlay.append(infoBoxTextBox, buttonYes);

    if (buttonNoText && buttonNoText.trim() !== "") {
        const buttonNo = document.createElement("button");

        buttonNo.onclick = closeAndCleanup;
        buttonNo.innerText = buttonNoText;

        infoBoxOverlay.append(buttonNo);
    }

    document.addEventListener("keydown", handleKeyDown);
    document.body.append(infoBoxBg, infoBoxOverlay);

    buttonYes.focus();
}

document.addEventListener("visibilitychange", () => {
    if (!document.hidden) {
        stopTitleFlash();
    }
});

window.addEventListener("DOMContentLoaded", function () {
    const leftTrigger = document.getElementById("nav-left-trigger");
    const leftMenu = document.getElementById("nav-left-menu");
    const rightTrigger = document.getElementById("nav-right-trigger");
    const rightMenu = document.getElementById("nav-right-menu");
    const timeoutSeconds = parseInt(document.body.dataset.timeout);
    const serverTime = document.body.dataset.serverTime;
    document.querySelectorAll('[data-on-click], [data-on-submit]').forEach(bindActions);

    if (!isNaN(timeoutSeconds) && timeoutSeconds > 0) {
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
                const token = Math.random().toString(36).substring(2, 15);
                document.cookie = "logout_verify=" + token + "; path=/; max-age=30; SameSite=Lax";

                window.location.href = "index.php?logout=inactive&v=" + token;
            }
        }, 10000);
    }

    function closeMenus() {
        if (leftMenu) leftMenu.classList.remove("open");
        if (leftTrigger) leftTrigger.classList.remove("open");
        if (rightMenu) rightMenu.classList.remove("open");
        if (rightTrigger) rightTrigger.classList.remove("open");

        toggleMobileElements(false);
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

            closeOverlay();

            const isAnyMenuOpen = leftMenu.classList.contains("open") || rightMenu.classList.contains("open");
            toggleMobileElements(isAnyMenuOpen);
        });
    }

    if (rightTrigger) {
        rightTrigger.addEventListener("click", function (e) {
            e.stopPropagation();

            leftMenu.classList.remove("open");
            leftTrigger.classList.remove("open");
            rightMenu.classList.toggle("open");
            rightTrigger.classList.toggle("open");

            closeOverlay();

            const isAnyMenuOpen = leftMenu.classList.contains("open") || rightMenu.classList.contains("open");
            toggleMobileElements(isAnyMenuOpen);
        });
    }

    document.addEventListener("click", function (e) {
        const menu = document.getElementById("emoji-menu");
        const trigger = document.querySelector(".emoji-trigger");
        const kingdomDropdown = document.getElementById("mobile-kingdom-dropdown");
        const kingdomDisplay = document.querySelector(".mobile-kingdom-display");

        if (kingdomDropdown && kingdomDropdown.classList.contains("open")) {
            if (!kingdomDropdown.contains(e.target) && !kingdomDisplay.contains(e.target)) {
                kingdomDropdown.classList.remove("open");
            }
        }

        if (menu && !menu.contains(e.target) && e.target !== trigger) {
            menu.classList.remove("open");
        }

        if (!leftMenu || !rightMenu) return;

        if (!leftMenu.contains(e.target) && !rightMenu.contains(e.target) &&
            !leftTrigger.contains(e.target) && !rightTrigger.contains(e.target)) {
            closeMenus();
        }

        if (!e.target.closest('.reaction-container')) {
            document.querySelectorAll('.reaction-picker').forEach(p => p.style.display = "none");
        }
    });

    document.addEventListener("mousedown", function (e) {
        if (e.target.closest('.emoji-menu span')) {
            e.preventDefault();
        }
    });

    if (sessionStorage.getItem("keepRightMenuOpen") === "true") {
        const rightTrigger = document.getElementById("nav-right-trigger");
        const rightMenu = document.getElementById("nav-right-menu");

        if (rightTrigger && rightMenu) {
            document.body.classList.add("no-transition");

            rightTrigger.classList.add("open");
            rightMenu.classList.add("open");

            void document.body.offsetHeight;

            document.body.classList.remove("no-transition");
        }
        sessionStorage.removeItem("keepRightMenuOpen");
    }

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    setup();
    adjustUsernameDisplay();

    if (serverTime) updateServerTime(parseInt(serverTime));
    initAutomaticCountdowns();

    document.addEventListener("focus", function (e) {
        if (e.target.tagName === "INPUT") {
            if (e.target.getAttribute("inputmode") === "numeric" ||
                e.target.classList.contains("js-unit-input") ||
                e.target.classList.contains("js-recruit-input")) {
                e.target.select();
            }
        }
    }, true);

    setTimeout(() => {
        document.body.classList.remove("preload");
    }, 100);
});

function toggleMobileElements(hide) {
    const arrows = document.querySelectorAll(".mobile-nav-arrow");
    const kingdom = document.querySelector(".mobile-kingdom-display");

    arrows.forEach(arrow => {
        arrow.style.opacity = hide ? "0.2" : "";
    });
    if (kingdom) kingdom.style.opacity = hide ? "0.2" : "";
}

function selectUser(id) {
    const form = document.forms["newmessage"];

    if (form) {
        form.receiver.value = id;

        closeOverlay();
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

function insertEmoji(emoji) {
    const input = document.getElementById("message-input") ||
        document.getElementById("new-news-content") ||
        (document.activeElement.tagName === 'TEXTAREA' ? document.activeElement : null) ||
        document.querySelector('textarea[id^="edit-news-text-"]');
    if (!input) return;

    const text = input.value;
    let start, end;

    if (document.activeElement === input) {
        start = input.selectionStart;
        end = input.selectionEnd;
    } else {
        start = end = text.length;
    }

    input.value = text.substring(0, start) + emoji + text.substring(end);
    const newPos = start + emoji.length;

    const isTouch = ("ontouchstart" in window || navigator.maxTouchPoints > 0);

    if (document.activeElement === input) {
        input.setSelectionRange(newPos, newPos);
    } else {
        if (isTouch) {
            input.selectionStart = input.selectionEnd = newPos;
        } else {
            input.focus();
            input.setSelectionRange(newPos, newPos);
        }
    }
}

function startTitleFlash(message) {
    if (titleInterval) return;

    titleInterval = setInterval(() => {
        document.title = (document.title === originalTitle) ? message : originalTitle;
    }, TITLE_INTERVAL);

    const flashLimit = parseInt(document.body.dataset.flashLimit) || 60;
    flashTimeout = setTimeout(() => {
        stopTitleFlash();
    }, flashLimit * 1000);
}

function stopTitleFlash() {
    clearInterval(titleInterval);
    clearTimeout(flashTimeout);

    titleInterval = null;
    flashTimeout = null;

    document.title = originalTitle;
}
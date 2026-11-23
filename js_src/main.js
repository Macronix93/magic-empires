const ClickActions = new Map();
let titleInterval = null;
const originalTitle = document.title;
const TITLE_INTERVAL = 2000;
let isKingdomSwitching = false;
let flashTimeout = null;
let activeHoverPopup = null;
const KINGDOM_SWITCH_COOLDOWN = 800;
const UPDATE_MSG_BADGES_INTERVAL = 60000;
const UPDATE_MSG_BADGES_LAST_FETCH = 30000;
let isPageUnloading = false;
let cachedChatEmojis = null;
let globalPopupBox = null;
let lastPopupTouchTime = 0;
window.ME_CLOCK_ANCHOR = Date.now();
const clockSubscribers = new Set();
let globalClockTimeout = null;
let actionLoaderTimer = null;
let activeLoaderState = null;

window.addEventListener("beforeunload", () => {
    isPageUnloading = true;
});

registerAction("navigate", (el) => {
    const url = el.dataset.url;
    if (url) {
        showActionLoader("Lade Seite...");
        window.location.href = url;
    }
});
registerAction("redirect", (el) => {
    const url = el.dataset.url;
    if (url) {
        const forbiddenProtocols = ["javascript:", "data:", "vbscript:"];
        const isForbidden = forbiddenProtocols.some(proto => url.trim().toLowerCase().startsWith(proto));

        if (!isForbidden) {
            showActionLoader("Lade Seite...");
            window.location.href = url;
        }
    }
});
registerAction("closeAdventModal", (el) => {
    const targetUrl = el?.getAttribute("href") && el.getAttribute("href") !== "#"
        ? el.getAttribute("href") : null;

    fetch("ajax/advent_intro_done.php", {
        method: "POST",
        headers: {"X-Requested-With": "XMLHttpRequest"}
    }).then(() => {
        const overlay = document.getElementById("advent-overlay");
        if (overlay) overlay.remove();

        if (targetUrl) {
            window.location.href = targetUrl;
        }
    });
});
registerAction("switchKingdomFromPopup", (el, e) => {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }

    const kid = el.dataset.id;
    if (kid) {
        el.disabled = true;
        el.innerText = "Wechsle...";
        hideGlobalPopup();

        switchKingdomById(kid);
    }
});
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

    if (el.classList.contains("active")) {
        return;
    }

    switchKingdomById(kingdomId);
});
registerAction("switchKingdom", (el) => {
    if (window.innerWidth <= 600 && el.closest('.overview-table')) {
        return;
    }

    const kid = el.dataset.id;
    if (kid) {
        switchKingdomById(kid);
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
registerAction("toggleEmojis", (el) => {
    const container = el.closest(".emoji-picker-container") || document;
    const menu = container.querySelector(".emoji-menu") || document.getElementById("emoji-menu");
    if (menu) menu.classList.toggle("open");
});
registerAction("pickEmoji", (el) => {
    insertEmoji(el.innerText);
});
registerAction("showTutorialModal", () => {
    const existing = document.getElementById("tutorial-overlay");
    if (existing) existing.remove();

    fetch("ajax/tutorial_modal.php", {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.text())
        .then(html => {
            document.body.insertAdjacentHTML("beforeend", html);

            const overlay = document.getElementById("tutorial-overlay");

            if (overlay) {
                overlay.querySelectorAll('[data-on-click]').forEach(bindActions);
            }
        })
        .catch(err => showGameError("Fehler beim Laden des Tutorials:" + err));
});
registerAction("closeTutorial", (el) => {
    const overlay = document.getElementById("tutorial-overlay");
    if (overlay) overlay.remove();

    const targetUrl = el.getAttribute("href") && el.getAttribute("href") !== "#" ? el.getAttribute("href") : null;
    if (targetUrl) {
        window.location.href = targetUrl;
    }
});
registerAction("finishTutorial", (el) => {
    const targetUrl = el.getAttribute("href")
    && el.getAttribute("href") !== "#" ? el.getAttribute("href") : (el.dataset.url || null);

    fetch("ajax/tutorial_done.php", {
        method: "POST",
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (targetUrl) {
                    window.location.href = targetUrl;
                } else {
                    const tutorialOverlay = document.getElementById("tutorial-overlay");
                    if (tutorialOverlay) tutorialOverlay.remove();

                    if (data.show_advent) {
                        const renderModal = (html) => {
                            if (html && html.trim() !== "") {
                                document.body.insertAdjacentHTML("beforeend", html);

                                const adventOverlay = document.getElementById("advent-overlay");
                                if (adventOverlay) {
                                    adventOverlay.querySelectorAll('[data-on-click]').forEach(bindActions);
                                }
                            }
                        };

                        if (data.advent_html) {
                            renderModal(data.advent_html);
                        } else {
                            fetch("ajax/advent_modal.php", {
                                headers: {"X-Requested-With": "XMLHttpRequest"}
                            })
                                .then(r => r.text())
                                .then(renderModal);
                        }
                    }
                }
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
    window.lastReactionClick = Date.now();
    const container = el.closest('.reaction-container');

    sendReaction(el.dataset.type, el.dataset.id, el.dataset.emoji, container);
});
registerAction("toggleReactionPicker", (el) => {
    const container = el.closest('.reaction-add-wrapper');
    const picker = container.querySelector('.reaction-picker');
    const isVisible = window.getComputedStyle(picker).display === "grid";

    document.querySelectorAll('.reaction-picker').forEach(p => {
        p.style.display = "none";
        p.style.maxHeight = "";
        p.style.maxWidth = "";
    });

    if (!isVisible) {
        if (picker.children.length === 0) {
            const type = el.dataset.type || container.closest('.reaction-container')?.dataset.type || '';
            const id = el.dataset.id || container.closest('.reaction-container')?.dataset.id || '';

            picker.innerHTML = getChatEmojis().map(emoji =>
                `<span class="picker-emoji" data-on-click="toggleReaction" data-type="${type}" data-id="${id}" data-emoji="${emoji}">${emoji}</span>`
            ).join('');
            picker.querySelectorAll('[data-on-click]').forEach(bindActions);
        }

        picker.style.display = "grid";
        picker.style.maxHeight = "";
        picker.style.maxWidth = "";

        const rect = el.getBoundingClientRect();
        let pickerHeight = picker.offsetHeight;
        let pickerWidth = picker.offsetWidth;

        const isMobile = window.innerWidth <= 600;
        const chatSection = el.closest('#messages-section');
        const cRect = chatSection ? chatSection.getBoundingClientRect() : null;

        const boundLeft = (!isMobile && cRect) ? (cRect.left + 5) : 10;
        const boundRight = (!isMobile && cRect) ? (cRect.right - 5) : (window.innerWidth - 10);

        const maxAvailWidth = boundRight - boundLeft;
        if (pickerWidth > maxAvailWidth) {
            picker.style.maxWidth = maxAvailWidth + "px";
            pickerWidth = picker.offsetWidth;
        }

        const spaceBelowInChat = cRect ? (cRect.bottom - rect.bottom - 5) : (window.innerHeight - rect.bottom - 10);
        const spaceAboveInChat = cRect ? (rect.top - cRect.top - 5) : (rect.top - 10);

        let openAbove;

        if ((spaceBelowInChat < pickerHeight && spaceAboveInChat >= pickerHeight) || !chatSection) {
            openAbove = true;
        } else if (spaceBelowInChat < pickerHeight && spaceAboveInChat < pickerHeight) {
            openAbove = (spaceAboveInChat > spaceBelowInChat);
        } else {
            openAbove = false;
        }

        let topPos;
        const minTop = (!isMobile && cRect) ? (cRect.top + 5) : 10;
        const maxBottom = (!isMobile && cRect) ? (cRect.bottom - 5) : (window.innerHeight - 10);

        if (openAbove) {
            topPos = rect.top - pickerHeight - 5;
            if (topPos < minTop) {
                const availH = rect.top - minTop - 5;
                if (availH > 100) {
                    picker.style.maxHeight = availH + "px";
                    topPos = rect.top - picker.offsetHeight - 5;
                } else {
                    topPos = minTop;
                }
            }
        } else {
            topPos = rect.bottom + 5;
            if (topPos + pickerHeight > maxBottom) {
                const availH = maxBottom - topPos;
                if (availH > 100) {
                    picker.style.maxHeight = availH + "px";
                }
            }
        }

        let leftPos = rect.right - pickerWidth;
        if (leftPos < boundLeft) {
            leftPos = boundLeft;
        }
        if (leftPos + pickerWidth > boundRight) {
            leftPos = boundRight - pickerWidth;
        }

        picker.style.top = topPos + "px";
        picker.style.left = leftPos + "px";
    } else {
        picker.style.display = "none";
        picker.style.maxHeight = "";
        picker.style.maxWidth = "";
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
registerAction("toggleShareTarget", (el) => {
    const recipientWrap = document.getElementById("share-recipient-wrap");
    const recipientInput = document.getElementById("share-recipient-input");
    if (!recipientWrap) return;

    if (el.value === "private") {
        recipientWrap.style.display = "block";
        if (recipientInput) {
            recipientInput.required = true;
            recipientInput.focus();
        }
    } else {
        recipientWrap.style.display = "none";
        if (recipientInput) {
            recipientInput.required = false;
        }
    }
});
registerAction("submitShareCoords", (form, e) => {
    if (e) e.preventDefault();
    const submitBtn = document.getElementById("btn-share-submit");
    if (submitBtn) submitBtn.disabled = true;

    const formData = new FormData(form);
    const fallbackMessage = form.querySelector('input[name="message"]')?.value || "";

    fetch("ajax/share_coords_send.php", {
        method: "POST",
        headers: {"X-Requested-With": "XMLHttpRequest"},
        body: formData
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const textToPrefill = data.message || fallbackMessage;
                if (textToPrefill) {
                    sessionStorage.setItem("me_chat_prefill", textToPrefill);
                }

                closeOverlay();

                if (data.redirect_url) {
                    window.location.href = data.redirect_url;
                }
            } else {
                showGameError(data.error || "Fehler beim Senden.");

                if (submitBtn) submitBtn.disabled = false;
            }
        })
        .catch(() => {
            showGameError("Netzwerkfehler.");

            if (submitBtn) submitBtn.disabled = false;
        });
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
                if (node.dataset.onClick || node.dataset.onSubmit || node.dataset.onChange || node.dataset.onInput) {
                    bindActions(node);
                }

                node.querySelectorAll('[data-on-click], [data-on-submit], [data-on-change], [data-on-input]').forEach(bindActions);

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
    attributeFilter: ["data-on-click", "data-on-submit", "data-on-change"]
});

function scheduleGlobalClockTick() {
    if (globalClockTimeout !== null || clockSubscribers.size === 0) return;

    const now = Date.now();
    const elapsedInSec = ((now - window.ME_CLOCK_ANCHOR) % 1000 + 1000) % 1000;
    const delay = (1000 - elapsedInSec) + 25;

    globalClockTimeout = setTimeout(() => {
        globalClockTimeout = null;
        runGlobalClockTick();
    }, delay);
}

function runGlobalClockTick() {
    const now = Date.now();

    clockSubscribers.forEach(fn => {
        try {
            fn(now);
        } catch (e) {
            console.error(e);
        }
    });

    if (clockSubscribers.size > 0) {
        scheduleGlobalClockTick();
    }
}

window.registerClockTick = function (fn) {
    clockSubscribers.add(fn);
    scheduleGlobalClockTick();
};
window.unregisterClockTick = function (fn) {
    clockSubscribers.delete(fn);

    if (clockSubscribers.size === 0 && globalClockTimeout !== null) {
        clearTimeout(globalClockTimeout);
        globalClockTimeout = null;
    }
};
window.resyncGlobalClock = function () {
    if (globalClockTimeout !== null) {
        clearTimeout(globalClockTimeout);
        globalClockTimeout = null;
    }

    if (clockSubscribers.size > 0) {
        runGlobalClockTick();
    }
};

function getChatEmojis() {
    if (cachedChatEmojis) return cachedChatEmojis;

    const meta = document.querySelector('meta[name="chat-emojis"]');
    if (meta && meta.content) {
        try {
            cachedChatEmojis = JSON.parse(meta.content);
            return cachedChatEmojis;
        } catch (e) {
        }
    }

    const menuSpans = document.querySelectorAll('#emoji-menu span');
    if (menuSpans.length > 0) {
        cachedChatEmojis = Array.from(menuSpans).map(s => s.textContent.trim());
        return cachedChatEmojis;
    }

    return [];
}

function updateSidebarAndHeaderDOM(data) {
    if (!data) return;

    const deskHtml = data.sidebar_html_desk || data.sidebar_html;
    const mobHtml = data.sidebar_html_mob || data.sidebar_html;
    if (!deskHtml && !mobHtml) return;

    const updateContainer = (container, html) => {
        if (!container || !html) return;

        const tempDiv = document.createElement("div");
        tempDiv.innerHTML = html;

        const newMobileKname = tempDiv.querySelector(".mobile-only-kname");
        const currentMobileKname = container.querySelector(".mobile-only-kname");
        if (newMobileKname && currentMobileKname) {
            currentMobileKname.innerHTML = newMobileKname.innerHTML;
        }

        const newKingdomInfo = tempDiv.querySelector("#kingdom-info");
        const currentKingdomInfo = container.querySelector("#kingdom-info");
        if (newKingdomInfo && currentKingdomInfo) {
            currentKingdomInfo.innerHTML = newKingdomInfo.innerHTML;
        }

        const newBuildings = tempDiv.querySelector("#kingdom-buildings");
        const currentBuildings = container.querySelector("#kingdom-buildings");
        if (newBuildings && currentBuildings) {
            currentBuildings.innerHTML = newBuildings.innerHTML;
        }

        const newSelect = tempDiv.querySelector("#choosekingdom");
        const currentSelect = container.querySelector("#choosekingdom");
        if (newSelect && currentSelect) {
            currentSelect.value = newSelect.value;
        }

        container.querySelectorAll('[data-on-click], [data-on-change], [data-on-submit]').forEach(bindActions);
    };

    const deskContainer = document.querySelector(".right-container");
    if (deskContainer && deskHtml) {
        updateContainer(deskContainer, deskHtml);
    }

    const mobContainer = document.getElementById("nav-right-menu");
    if (mobContainer && mobHtml) {
        updateContainer(mobContainer, mobHtml);
    }

    if (data.kingdom) {
        document.querySelectorAll("select[name='choosekingdom']").forEach(s => {
            s.value = data.kingdom.id;
        });
    }

    const mobTitle = document.querySelector(".mobile-kingdom-title");
    if (mobTitle && data.kingdom) {
        const items = document.querySelectorAll(".mobile-kingdom-dropdown-item");
        let pos = 1;

        items.forEach((it, idx) => {
            if (parseInt(it.dataset.id) === parseInt(data.kingdom.id)) {
                pos = idx + 1;
                it.classList.add("active");
            } else {
                it.classList.remove("active");
            }
        });
        mobTitle.innerHTML = `${pos} - ${data.kingdom.name} <span style="font-size: 10px; opacity: 0.7;">▾</span>`;
    }

    if (typeof setup === "function") {
        setup();
    }
}

function showActionLoader(text = "Bitte warten...", targetContainer = null, delayMs = 500) {
    hideActionLoader();

    const modal = targetContainer?.classList?.contains("overlay-modal")
        ? targetContainer
        : (targetContainer?.closest?.(".overlay-modal") || null);

    // MOBILE & CO
    if (!modal && !targetContainer && window.innerWidth <= 1392) {
        activeLoaderState = {
            container: document.body,
            contentEl: null,
            isModal: false,
            isFullscreen: true,
            shown: false
        };

        const renderFullscreen = () => {
            if (!activeLoaderState || !activeLoaderState.isFullscreen) return;
            activeLoaderState.shown = true;

            document.querySelector(".js-fullscreen-action-loader")?.remove();
            const loader = document.createElement("div");
            loader.className = "js-action-loader js-fullscreen-action-loader";
            loader.innerHTML = `<div class="loading-spinner"></div><div class="loader-text" style="font-size: 16px;"></div>`;
            loader.querySelector(".loader-text").textContent = text;
            document.body.appendChild(loader);

            if (delayMs <= 0) {
                loader.classList.add("open");
            } else {
                requestAnimationFrame(() => loader.classList.add("open"));
            }
        };

        if (delayMs <= 0) {
            renderFullscreen();
        } else {
            actionLoaderTimer = setTimeout(renderFullscreen, delayMs);
        }
        return;
    }

    // DESKTOP
    const container = modal || targetContainer || document.querySelector(".middle-container .big-box-container");
    if (!container) return;

    const isModal = container.classList.contains("overlay-modal");
    const contentEl = isModal
        ? container.querySelector(".overlay-body, #overlay-content-body")
        : container.querySelector(".big-box-content");

    if (!isModal && window.getComputedStyle(container).position === "static") {
        container.style.position = "relative";
    }
    container.style.pointerEvents = "none";

    activeLoaderState = {container, contentEl, isModal, isFullscreen: false, shown: false};

    const renderContainerLoader = () => {
        if (!activeLoaderState || activeLoaderState.container !== container) return;
        activeLoaderState.shown = true;

        if (contentEl) {
            contentEl.style.opacity = "0.25";
        }

        container.querySelector(".js-action-loader")?.remove();
        const loader = document.createElement("div");
        loader.className = "js-action-loader";
        loader.style.cssText =
            "position: absolute; inset: 0; z-index: 500; pointer-events: all; " +
            "opacity: 0;";

        const inner = document.createElement("div");
        inner.style.cssText =
            "display: flex; flex-direction: column; align-items: center; justify-content: center; " +
            "width: 100%; padding: 20px; box-sizing: border-box;";
        inner.innerHTML = `<div class="loading-spinner"></div><div class="loader-text" style="font-size: 16px;"></div>`;
        inner.querySelector(".loader-text").textContent = text;

        if (isModal) {
            const header = container.querySelector(".overlay-header");

            loader.style.top = (header ? header.offsetHeight : 0) + "px";
            loader.style.display = "flex";
            loader.style.alignItems = "center";
            loader.style.justifyContent = "center";
            loader.appendChild(inner);
        } else {
            const rect = container.getBoundingClientRect();

            if (rect.height > window.innerHeight * 0.85) {
                const visibleTop = Math.max(0, -rect.top);
                const visibleBottom = Math.min(rect.height, window.innerHeight - rect.top);
                const centerY = Math.max(60, Math.min(rect.height - 60, (visibleTop + visibleBottom) / 2));
                inner.style.position = "absolute";
                inner.style.top = centerY + "px";
                inner.style.left = "0";
                inner.style.transform = "translateY(-50%)";
            } else {
                loader.style.display = "flex";
                loader.style.alignItems = "center";
                loader.style.justifyContent = "center";
            }
            loader.appendChild(inner);
        }

        container.appendChild(loader);
        if (delayMs <= 0) {
            loader.style.opacity = "1";
        } else {
            requestAnimationFrame(() => {
                loader.style.opacity = "1";
            });
        }
    };

    if (delayMs <= 0) {
        renderContainerLoader();
    } else {
        actionLoaderTimer = setTimeout(renderContainerLoader, delayMs);
    }
}

function hideActionLoader() {
    clearTimeout(actionLoaderTimer);
    actionLoaderTimer = null;

    if (activeLoaderState) {
        const {container, contentEl} = activeLoaderState;
        container.querySelector(".js-action-loader")?.remove();
        container.style.pointerEvents = "";

        if (contentEl) {
            contentEl.style.opacity = "";
            contentEl.style.transition = "";
        }

        activeLoaderState = null;
    }

    document.querySelectorAll(".js-action-loader").forEach(el => el.remove());
}

window.showActionLoader = showActionLoader;
window.hideActionLoader = hideActionLoader;

function showKingdomSwitchLoader() {
    document.querySelectorAll(".arrow-nav, .mobile-nav-arrow, .mobile-kingdom-display, .mobile-trigger").forEach(el => {
        el.style.pointerEvents = "none";
    });

    showActionLoader("Wechsle Königreich...");
    setTimeout(() => {
        if (activeLoaderState?.shown) {
            document.querySelectorAll("select[name='choosekingdom']").forEach(s => s.disabled = true);

            if (!activeLoaderState.isFullscreen) {
                document.querySelectorAll(".arrow-nav, .mobile-nav-arrow, .mobile-kingdom-display, .mobile-trigger").forEach(el => {
                    el.style.opacity = "0.5";
                });
            }
        }
    }, 260);
}

function resetKingdomSwitchLoader() {
    isKingdomSwitching = false;
    hideActionLoader();

    document.querySelectorAll("select[name='choosekingdom']").forEach(s => s.disabled = false);
    document.querySelectorAll(".arrow-nav, .mobile-nav-arrow, .mobile-kingdom-display, .mobile-trigger").forEach(el => {
        el.style.pointerEvents = "";
        el.style.opacity = "";
    });
}

// function showKingdomSwitchLoader() {
//     document.querySelectorAll(".arrow-nav, .mobile-nav-arrow, .mobile-kingdom-display, .mobile-trigger").forEach(el => {
//         el.style.pointerEvents = "none";
//     });
//
//     if (typeof window.closeMobileMenus === "function") {
//         window.closeMobileMenus();
//     }
//
//     const bigBox = document.querySelector(".middle-container .big-box-container");
//     if (bigBox) {
//         bigBox.style.position = "relative";
//         bigBox.style.pointerEvents = "none";
//     }
//
//     clearTimeout(kingdomLoaderTimer);
//     kingdomLoaderTimer = setTimeout(() => {
//         document.querySelectorAll("select[name='choosekingdom']").forEach(s => s.disabled = true);
//         document.querySelectorAll(".arrow-nav, .mobile-nav-arrow, .mobile-kingdom-display, .mobile-trigger").forEach(el => {
//             el.style.opacity = "0.5";
//         });
//
//         if (bigBox) {
//             const content = bigBox.querySelector(".big-box-content");
//             if (content) {
//                 content.style.opacity = "0.25";
//             }
//
//             if (!document.getElementById("kingdom-switch-loader")) {
//                 const loader = document.createElement("div");
//                 loader.id = "kingdom-switch-loader";
//                 loader.style.cssText = "position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; " +
//                     "z-index: 500; pointer-events: all; opacity: 0; transition: opacity 0.2s ease;";
//                 loader.innerHTML = `<div class="loading-spinner"></div><div class="loader-text" style="font-size: 16px;">Wechsle Königreich...</div>`;
//                 bigBox.appendChild(loader);
//
//                 requestAnimationFrame(() => {
//                     loader.style.opacity = "1";
//                 });
//             }
//         }
//     }, 250);
// }
//
// function resetKingdomSwitchLoader() {
//     isKingdomSwitching = false;
//     clearTimeout(kingdomLoaderTimer);
//
//     document.getElementById("kingdom-switch-loader")?.remove();
//     document.querySelectorAll("select[name='choosekingdom']").forEach(s => s.disabled = false);
//     document.querySelectorAll(".arrow-nav, .mobile-nav-arrow, .mobile-kingdom-display, .mobile-trigger").forEach(el => {
//         el.style.pointerEvents = "";
//         el.style.opacity = "";
//     });
//
//     const bigBox = document.querySelector(".middle-container .big-box-container");
//     if (bigBox) {
//         bigBox.style.pointerEvents = "";
//         const content = bigBox.querySelector(".big-box-content");
//         if (content) content.style.opacity = "";
//     }
// }

function switchKingdomById(kingdomId) {
    if (isKingdomSwitching) return;
    isKingdomSwitching = true;

    const currentUrl = new URL(window.location.href);
    const filename = currentUrl.pathname.split('/').pop();
    const keepParamsPages = ["ranking.php", "support.php", "guild.php", "arena.php", "settings.php"];

    const isGuildChatActive = filename === "guild.php"
        && document.getElementById("guild_tab_chat")
        && window.getComputedStyle(document.getElementById("guild_tab_chat")).display !== "none";

    const overlay = document.getElementById("onpage-overlay");
    const isEventOverlayOpen = filename === "events.php"
        && overlay
        && window.getComputedStyle(overlay).display !== "none";

    const isInPlaceSwitch = (
        filename === "map.php" ||
        filename === "messages.php" ||
        filename === "chat_embed.php" ||
        filename === "support.php" ||
        isGuildChatActive ||
        isEventOverlayOpen
    );

    if (!isInPlaceSwitch) {
        showKingdomSwitchLoader();
        document.cookie = "me_switch_kid=" + encodeURIComponent(kingdomId) + "; path=/; max-age=30; SameSite=Lax";

        let targetUrl = currentUrl.pathname;

        if (filename === "barracks.php") {
            const cat = currentUrl.searchParams.get("cat");
            if (cat !== null) targetUrl = `${currentUrl.pathname}?cat=${cat}`;
        } else if (filename === "warsim.php") {
            targetUrl = "warsim.php?keep_sim=1";
        } else if (keepParamsPages.includes(filename)) {
            targetUrl = currentUrl.pathname + currentUrl.search;
        }

        window.location.href = targetUrl;
        return;
    }

    const formData = new FormData();
    formData.append("choosekingdom", kingdomId);

    fetch("ajax/change_kingdom.php", {
        method: "POST",
        headers: {"X-Requested-With": "XMLHttpRequest"},
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (filename === "map.php" && typeof window.handleMapKingdomSwitch === "function") {
                    window.handleMapKingdomSwitch(data);
                    isKingdomSwitching = false;
                    return;
                }

                if (isEventOverlayOpen) {
                    updateSidebarAndHeaderDOM(data);

                    const rightDot = document.getElementById("mobile-right-nav-dot");
                    if (rightDot && data.has_any_alchemy_ready !== undefined) {
                        rightDot.style.display = data.has_any_alchemy_ready ? "block" : "none";
                    }

                    const singleBtn = document.querySelector("button[data-url*='ajax/send_troops.php']");
                    if (singleBtn && data.kingdom) {
                        const hasBarracks = !!document.querySelector("#kingdom-buildings .box[data-url='barracks.php']");
                        const eventLocked = singleBtn.dataset.eventLocked === "true";
                        singleBtn.disabled = eventLocked || !hasBarracks || !!data.kingdom.commandsFull;
                    }

                    const massBtn = document.querySelector("button[name='attack_all_kingdoms']");
                    if (massBtn && data.kingdom && massBtn.dataset.eventLocked !== "true") {
                        massBtn.disabled = !!data.kingdom.commandsFull;
                    }

                    if (typeof window.reloadOverlay === "function") {
                        window.reloadOverlay();
                    }

                    isKingdomSwitching = false;
                    return;
                }

                if (filename === "messages.php" || filename === "chat_embed.php" || filename === "support.php" || isGuildChatActive) {
                    updateSidebarAndHeaderDOM(data);
                    isKingdomSwitching = false;
                    return;
                }

                if (filename === "barracks.php") {
                    const cat = currentUrl.searchParams.get("cat");
                    window.location.href = cat !== null ? `${currentUrl.pathname}?cat=${cat}` : currentUrl.pathname;
                } else if (filename === "warsim.php") {
                    window.location.href = "warsim.php?keep_sim=1";
                } else if (keepParamsPages.includes(filename)) {
                    window.location.href = currentUrl.pathname + currentUrl.search;
                } else {
                    window.location.href = currentUrl.pathname;
                }
            } else {
                isKingdomSwitching = false;

                showGameError(data.error || "Königreich konnte nicht gewechselt werden.");
                setTimeout(() => window.location.reload(), 1500);
            }
        })
        .catch(err => {
            isKingdomSwitching = false;
            showGameError("Kingdom-Switch Fehler: " + err);
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

    const isNewEmoji = (chatBubble && !targetContainer.querySelector(`.reaction-badge[data-emoji="${emoji}"]`));

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

                    syncFeedbackIcon(chatBubble, type, id);

                    if (isNewEmoji) {
                        setTimeout(() => {
                            const messagesSection = document.getElementById("messages-section");

                            if (messagesSection && messagesSection.contains(chatBubble)) {
                                const sectionRect = messagesSection.getBoundingClientRect();
                                const bubbleRect = chatBubble.getBoundingClientRect();

                                const isFullyVisible =
                                    bubbleRect.top >= sectionRect.top &&
                                    bubbleRect.bottom <= sectionRect.bottom;

                                if (!isFullyVisible) {
                                    const bubbleBottom = chatBubble.offsetTop + chatBubble.offsetHeight;
                                    const targetScroll = bubbleBottom - messagesSection.clientHeight + 15;

                                    messagesSection.scrollTo({
                                        top: targetScroll,
                                        behavior: "smooth"
                                    });
                                }
                            } else if (chatBubble) {
                                const rect = chatBubble.getBoundingClientRect();

                                const isFullyVisible =
                                    rect.top >= 0 &&
                                    rect.bottom <= window.innerHeight;

                                if (!isFullyVisible) {
                                    chatBubble.scrollIntoView({
                                        behavior: "smooth",
                                        block: "end"
                                    });
                                }
                            }
                        }, 50);
                    }
                } else {
                    targetContainer.outerHTML = res.html;
                }
            } else if (res.error) {
                showConfirmationDialog(res.error, "Ok", "", () => {
                });
            }
        });
}

function formatNumJS(number, simpleFormat = false) {
    if (!number || isNaN(number)) return "0";
    let n = Math.floor(Number(number));

    if (simpleFormat) {
        return n.toLocaleString("de-DE");
    }

    if (n >= 1000000000) {
        let main = Math.floor(n / 1000000000);
        let sub = Math.floor((n % 1000000000) / 10000000);

        if (sub === 0) return main + 'B';

        let subStr = sub.toString().padStart(2, '0').replace(/0$/, '');
        return main + ',' + subStr + 'B';
    }

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

function getGlobalPopupBox() {
    if (!globalPopupBox) {
        globalPopupBox = document.createElement("div");
        globalPopupBox.id = "global-popup-box";
        globalPopupBox.className = "popupbox";
        document.body.appendChild(globalPopupBox);
    }
    return globalPopupBox;
}

function hideGlobalPopup() {
    if (globalPopupBox) globalPopupBox.style.display = "none";
    activeHoverPopup = null;
}

function showGlobalPopup(trigger, e) {
    const sourceBox = trigger?.querySelector(".popupbox");
    if (!sourceBox || sourceBox.dataset.enabled === "false") return hideGlobalPopup();

    const isTouch = e.type.startsWith("touch");
    const isTouchCapable = window.matchMedia("(hover: none)").matches || window.innerWidth <= 600 || ("ontouchstart" in window);
    const isAction = trigger.dataset.onClick === "openOverlay" || trigger.classList.contains("user-name-link") || /^(activity|act_link)/.test(trigger.id);

    if ((isTouch || isTouchCapable) && (isAction || trigger.closest(".reaction-badge"))) {
        if (isTouch) hideGlobalPopup();
        return;
    }
    if (isTouch && window.innerWidth <= 600 && trigger.id?.startsWith("activity")) return;

    const box = getGlobalPopupBox();
    if (!activeHoverPopup || activeHoverPopup.trigger !== trigger || box.style.display === "none") {
        box.className = sourceBox.className;
        box.setAttribute("style", sourceBox.getAttribute("style") || "");
        box.dataset.sourceId = sourceBox.id || "";
        box.innerHTML = sourceBox.innerHTML;
        box.querySelectorAll("[id]").forEach(el => el.removeAttribute("id"));
        box.querySelectorAll("[data-on-click]").forEach(bindActions);
    }

    box.style.position = "absolute";
    box.style.left = "-9999px";
    box.style.top = "0px";
    box.style.display = "block";
    box.style.visibility = "hidden";
    box.style.zIndex = "2000005";

    const bw = box.offsetWidth, bh = box.offsetHeight;
    const vw = window.innerWidth, vh = window.innerHeight;
    const sx = window.pageXOffset || document.documentElement.scrollLeft;
    const sy = window.pageYOffset || document.documentElement.scrollTop;
    let left, top;

    if (box.classList.contains("popup-right") && vw < 600) {
        const r = (trigger.querySelector(".kingdom-link") || trigger).getBoundingClientRect();
        left = Math.max(sx + 8, Math.min(r.right + sx + 8, sx + vw - bw - 10));
        top = (r.top - 10 + bh > vh - 10) ? Math.max(sy + 10, sy + vh - bh - 10) : (r.top + sy - 10);
    } else {
        const [px, py] = getMouseLocation(e);
        const pt = e.touches?.[0] || e;
        left = Math.max(sx + 10, Math.min(px - bw / 2, sx + vw - bw - 10));
        top = (pt?.clientY + 25 + bh > vh) ? Math.max(sy + 10, py - bh - 20) : (py + 25);
    }

    box.style.left = left + "px";
    box.style.top = top + "px";
    box.style.visibility = "visible";
    activeHoverPopup = {box, trigger};
}

function setup() {
    getGlobalPopupBox();
    cleanupPopups();
}

function cleanupPopups() {
    if (activeHoverPopup && !document.body.contains(activeHoverPopup.trigger)) hideGlobalPopup();
}

function getMouseLocation(e) {
    const p = e.touches?.[0] || e.changedTouches?.[0] || e;
    if (p.pageX !== undefined || p.pageY !== undefined) return [p.pageX, p.pageY];

    return [
        p.clientX + document.body.scrollLeft + document.documentElement.scrollLeft,
        p.clientY + document.body.scrollTop + document.documentElement.scrollTop
    ];
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
    const clientStartTime = window.ME_CLOCK_ANCHOR;
    const serverStartTime = initialServerTimestamp * 1000;
    const startHour = new Date(serverStartTime).getHours();
    let tickReached = false;

    function updateDisplay(now = Date.now()) {
        const elapsed = Math.max(0, now - clientStartTime);
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
        document.querySelectorAll(".resource-tick-wrapper .tick-progress-fill").forEach(bar => {
            bar.style.width = percent + "%";
        });
    }

    updateDisplay(clientStartTime);
    window.registerClockTick(updateDisplay);
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

    document.querySelectorAll("select[name='choosekingdom']").forEach(s => {
        s.selectedIndex = newIndex;
    });

    const newKingdomId = select.options[newIndex].value;
    switchKingdomById(newKingdomId);
}

function updateKingdom(selectElement) {
    if (!selectElement) return;
    switchKingdomById(selectElement.value);
}

function showConfirmationDialog(dialogText, buttonYesText, buttonNoText, buttonYesAction, inputConfig = null) {
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

    const isMultiInput = Array.isArray(inputConfig);
    const fieldsConfig = inputConfig ? (isMultiInput ? inputConfig : [inputConfig]) : [];
    const inputMap = new Map();
    let firstInput = null;

    const closeAndCleanup = () => {
        document.removeEventListener("keydown", handleKeyDown);
        if (infoBoxBg.parentNode) infoBoxBg.remove();
        if (infoBoxOverlay.parentNode) infoBoxOverlay.remove();
    };

    const confirmAndRun = () => {
        if (fieldsConfig.length > 0) {
            for (const cfg of fieldsConfig) {
                const key = cfg.name || "value";
                const inp = inputMap.get(key);

                if (!inp) continue;

                if (cfg.required && inp.value.trim() === "") {
                    inp.classList.add("input-error");
                    inp.focus();
                    return;
                }

                inp.classList.remove("input-error");
            }

            let payload;
            if (isMultiInput) {
                payload = {};

                fieldsConfig.forEach(cfg => {
                    const key = cfg.name || "value";
                    payload[key] = inputMap.get(key)?.value ?? "";
                });
            } else {
                const key = fieldsConfig[0].name || "value";
                payload = inputMap.get(key)?.value ?? "";
            }

            closeAndCleanup();
            buttonYesAction(payload);
        } else {
            closeAndCleanup();
            buttonYesAction();
        }
    };

    const handleKeyDown = (e) => {
        if (e.key === "Enter") {
            e.preventDefault();
            confirmAndRun();
        } else if (e.key === "Escape") {
            closeAndCleanup();
        }
    };

    buttonYes.type = "button";
    buttonYes.onclick = confirmAndRun;
    buttonYes.innerText = buttonYesText;

    infoBoxTextBox.innerText = dialogText;
    infoBoxTextBox.style.marginBottom = 0;

    infoBoxBg.id = "info-box-bg";
    infoBoxBg.classList.add("info-box-bg");
    infoBoxOverlay.id = "info-box-overlay";
    infoBoxOverlay.classList.add("info-box-overlay");

    infoBoxOverlay.append(infoBoxTextBox);

    if (fieldsConfig.length > 0) {
        const inputsWrap = document.createElement("div");
        inputsWrap.className = "info-box-overlay-inputs";

        fieldsConfig.forEach((cfg, idx) => {
            const fieldWrap = document.createElement("div");
            fieldWrap.className = "info-box-overlay-field";

            const key = cfg.name || `field_${idx}`;
            const inputId = `dialog-input-${key}`;

            if (cfg.label) {
                const label = document.createElement("label");
                label.htmlFor = inputId;
                label.innerText = cfg.label;
                fieldWrap.appendChild(label);
            }

            const input = document.createElement("input");
            input.id = inputId;
            input.type = cfg.type || "text";
            input.value = cfg.defaultValue ?? "";

            if (cfg.placeholder) input.placeholder = cfg.placeholder;
            if (cfg.maxLength) input.maxLength = cfg.maxLength;
            if (cfg.inputmode) input.setAttribute("inputmode", cfg.inputmode);
            if (cfg.numericOnly) {
                input.addEventListener("input", () => {
                    input.value = input.value.replace(/\D/g, "");
                });
            }

            input.addEventListener("input", () => input.classList.remove("input-error"));

            inputMap.set(cfg.name || "value", input);
            if (!firstInput) firstInput = input;

            fieldWrap.appendChild(input);
            inputsWrap.appendChild(fieldWrap);
        });

        infoBoxOverlay.appendChild(inputsWrap);
    }

    infoBoxOverlay.append(buttonYes);

    if (buttonNoText && buttonNoText.trim() !== "") {
        const buttonNo = document.createElement("button");
        buttonNo.type = "button";
        buttonNo.onclick = closeAndCleanup;
        buttonNo.innerText = buttonNoText;
        infoBoxOverlay.append(buttonNo);
    }

    document.addEventListener("keydown", handleKeyDown);
    document.body.append(infoBoxBg, infoBoxOverlay);

    if (firstInput) {
        firstInput.focus();
        firstInput.select();
    } else {
        buttonYes.focus();
    }
}

document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") {
        stopTitleFlash();
        window.resyncGlobalClock();
    }
});

document.addEventListener("submit", (e) => {
    const form = e.target;
    if (!form || form.tagName !== "FORM") return;
    if (e.defaultPrevented) return;

    const method = (form.getAttribute("method") || "GET").toUpperCase();
    const action = form.getAttribute("action") || "";
    if (method !== "POST" || action.startsWith("javascript:")) return;
    if (form.dataset.isSubmitting === "true") {
        e.preventDefault();
        e.stopImmediatePropagation();
        return;
    }

    form.dataset.isSubmitting = "true";
    const submitter = e.submitter;
    if (submitter && submitter.name) {
        const hidden = document.createElement("input");
        hidden.type = "hidden";
        hidden.name = submitter.name;
        hidden.value = submitter.value;
        form.appendChild(hidden);
    }

    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(btn => {
        btn.disabled = true;
    });

    if (form.dataset.noLoader !== "true") {
        const loaderText = submitter?.dataset?.loaderText || form.dataset.loaderText || "Bitte warten...";
        showActionLoader(loaderText, form.closest(".overlay-modal"));
    }
});

const nativeFormSubmit = HTMLFormElement.prototype.submit;
HTMLFormElement.prototype.submit = function () {
    const method = (this.getAttribute("method") || "GET").toUpperCase();

    if (method === "POST" && this.dataset.noLoader !== "true") {
        const loaderText = this.dataset.loaderText || "Bitte warten...";
        showActionLoader(loaderText, this.closest?.(".overlay-modal") || null);
    }
    return nativeFormSubmit.call(this);
};

// document.addEventListener("submit", (e) => {
//     const form = e.target;
//     if (!form || form.tagName !== "FORM") return;
//     if (e.defaultPrevented) return;
//
//     const method = (form.getAttribute("method") || "GET").toUpperCase();
//     const action = form.getAttribute("action") || "";
//     if (method !== "POST" || action.startsWith("javascript:")) return;
//
//     if (form.dataset.isSubmitting === "true") {
//         e.preventDefault();
//         e.stopImmediatePropagation();
//         return;
//     }
//
//     form.dataset.isSubmitting = "true";
//
//     const submitter = e.submitter;
//     if (submitter && submitter.name) {
//         const hidden = document.createElement("input");
//         hidden.type = "hidden";
//         hidden.name = submitter.name;
//         hidden.value = submitter.value;
//         form.appendChild(hidden);
//     }
//
//     form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(btn => {
//         btn.disabled = true;
//     });
// });

window.addEventListener("pageshow", (e) => {
    hideActionLoader();
    if (e.persisted) {
        resetKingdomSwitchLoader();

        if (typeof window.closeMobileMenus === "function") {
            document.body.classList.add("no-transition");
            window.closeMobileMenus();
            void document.body.offsetHeight;
            document.body.classList.remove("no-transition");
        }

        document.querySelectorAll('form[data-is-submitting="true"]').forEach(form => {
            delete form.dataset.isSubmitting;

            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(btn => {
                btn.disabled = false;
            });
        });
    }
});

window.addEventListener("DOMContentLoaded", function () {
    const leftTrigger = document.getElementById("nav-left-trigger");
    const leftMenu = document.getElementById("nav-left-menu");
    const rightTrigger = document.getElementById("nav-right-trigger");
    const rightMenu = document.getElementById("nav-right-menu");
    const timeoutSeconds = parseInt(document.body.dataset.timeout);
    const serverTime = document.body.dataset.serverTime;
    document.querySelectorAll('[data-on-click], [data-on-submit], [data-on-change], [data-on-input]').forEach(bindActions);

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

        const kingdomDropdown = document.getElementById("mobile-kingdom-dropdown");
        if (kingdomDropdown) kingdomDropdown.classList.remove("open");

        toggleMobileElements(false);
    }

    window.closeMobileMenus = closeMenus;

    window.addEventListener("resize", function () {
        if (window.innerWidth > 1392) {
            closeMenus();
        }
    });

    if (leftTrigger) {
        leftTrigger.addEventListener("click", function (e) {
            if (isKingdomSwitching) return;
            e.stopPropagation();

            const kingdomDropdown = document.getElementById("mobile-kingdom-dropdown");
            if (kingdomDropdown) kingdomDropdown.classList.remove("open");

            rightMenu.classList.remove("open");
            rightTrigger.classList.remove("open");
            leftMenu.classList.toggle("open");
            leftTrigger.classList.toggle("open");

            if (typeof closeAllOverlays === "function") {
                closeAllOverlays();
            } else if (typeof closeOverlay === "function") {
                closeOverlay();
            }

            const isAnyMenuOpen = leftMenu.classList.contains("open") || rightMenu.classList.contains("open");
            toggleMobileElements(isAnyMenuOpen);
        });
    }

    if (rightTrigger) {
        rightTrigger.addEventListener("click", function (e) {
            if (isKingdomSwitching) return;
            e.stopPropagation();

            const kingdomDropdown = document.getElementById("mobile-kingdom-dropdown");
            if (kingdomDropdown) kingdomDropdown.classList.remove("open");

            leftMenu.classList.remove("open");
            leftTrigger.classList.remove("open");
            rightMenu.classList.toggle("open");
            rightTrigger.classList.toggle("open");

            if (typeof closeAllOverlays === "function") {
                closeAllOverlays();
            } else if (typeof closeOverlay === "function") {
                closeOverlay();
            }

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

        if (e.defaultPrevented) return;
        if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;

        const link = e.target.closest("a[href]");
        if (!link) return;
        if (link.target && link.target !== "_self") return;
        if (link.hasAttribute("download")) return;
        if (link.dataset.onClick || link.dataset.noLoader === "true") return;

        const rawHref = (link.getAttribute("href") || "").trim();
        if (!rawHref || rawHref.startsWith("#") || rawHref.toLowerCase().startsWith("javascript:") || rawHref.startsWith("mailto:")) {
            return;
        }

        try {
            const targetUrl = new URL(link.href, window.location.href);
            if (targetUrl.origin === window.location.origin &&
                targetUrl.pathname === window.location.pathname &&
                targetUrl.search === window.location.search &&
                targetUrl.hash) {
                return;
            }
        } catch (_) {
            return;
        }

        closeMenus();
        showActionLoader("Lade Seite...");
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

            toggleMobileElements(true);

            void document.body.offsetHeight;
            document.body.classList.remove("no-transition");
        }
        sessionStorage.removeItem("keepRightMenuOpen");
    }

    setup();
    adjustUsernameDisplay();

    window.ME_CLOCK_ANCHOR = Date.now();
    if (serverTime) updateServerTime(parseInt(serverTime));
    initAutomaticCountdowns();

    const isTouchDevice = ('ontouchstart' in window || navigator.maxTouchPoints > 0);
    let pageLoadedAt = Date.now();
    let userTouchedPage = false;
    let currentFocusedInput = null;

    if (isTouchDevice) {
        window.addEventListener("touchstart", () => {
            userTouchedPage = true;
        }, {passive: true, capture: true, once: true});
    }

    document.addEventListener("focus", function (e) {
        const target = e.target;
        if (target.tagName !== "INPUT" && target.tagName !== "TEXTAREA") return;

        const isNumericInput = target.getAttribute("inputmode") === "numeric" ||
            target.classList.contains("js-unit-input") ||
            target.classList.contains("js-recruit-input");
        if (!isNumericInput) return;

        if (currentFocusedInput === target) return;
        currentFocusedInput = target;

        if (!isTouchDevice) {
            target.select();
            return;
        }

        if (!userTouchedPage && (Date.now() - pageLoadedAt < 350)) {
            target.blur();
            return;
        }

        setTimeout(() => {
            if (document.activeElement === target) {
                try {
                    target.setSelectionRange(0, target.value.length);
                } catch (_) {
                    target.select();
                }
            }
        }, 50);

        window.resyncGlobalClock();
    }, true);

    document.addEventListener("focusout", function (e) {
        if (currentFocusedInput === e.target) {
            currentFocusedInput = null;
        }
    }, true);

    const flashContainer = document.getElementById("middle-flash-container");
    if (flashContainer) {
        const boxes = flashContainer.querySelectorAll(".info-box");

        if (boxes.length > 0) {
            const dismissBox = (box) => {
                if (!box || box.classList.contains("fade-out")) return;

                box.classList.add("fade-out");

                box.addEventListener("animationend", () => {
                    box.remove();
                }, {once: true});
            };

            boxes.forEach(box => {
                setTimeout(() => {
                    dismissBox(box);
                }, 4000);

                box.addEventListener("click", () => {
                    dismissBox(box);
                });
            });
        }
    }

    const faqPing = document.getElementById("faq-ping-meta");
    if (faqPing) {
        const delayMs = (parseInt(faqPing.dataset.delay) + 1) * 1000;

        setTimeout(() => {
            fetch("ajax/faq_ping.php", {
                method: "POST",
                headers: {"X-Requested-With": "XMLHttpRequest"}
            }).catch(() => {
            });
        }, delayMs);
    }

    requestAnimationFrame(() => {
        document.body.classList.remove("preload");
    });
});

function toggleMobileElements(hide) {
    if (hide) {
        document.body.classList.add("mobile-menu-open");
    } else {
        document.body.classList.remove("mobile-menu-open");
    }
}

function selectUser(username) {
    const shareInput = document.getElementById("share-recipient-input");
    const newMsgForm = document.forms["newmessage"];

    if (shareInput) {
        shareInput.value = username;
    }
    if (newMsgForm) {
        newMsgForm.receiver.value = username;
    }

    closeOverlay();
}

function initAutomaticCountdowns() {
    const batchNow = window.ME_CLOCK_ANCHOR;

    document.querySelectorAll('.js-countdown').forEach(el => {
        const seconds = parseInt(el.dataset.seconds) || 0;
        const timerType = parseInt(el.dataset.timerType) || 0;
        const hideID = el.dataset.hideId || null;
        const keepParams = el.dataset.keepParams === "true";
        const noReload = el.dataset.noReload === "true";

        startCountdown(el, seconds, timerType, hideID, keepParams, noReload, batchNow);
    });
}

function insertEmoji(emoji) {
    const input = document.getElementById("comment-input") ||
        document.getElementById("message-input") ||
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

function updateSidebarBadges(counts) {
    const messageSection = document.getElementById("messages-section");
    const activeChatType = messageSection?.dataset.chatType;

    const isWorldChatActive = (activeChatType === "world") || window.location.search.includes("worldchat");
    if (isWorldChatActive) {
        counts.world = 0;
    }

    const isGuildChatActive = (activeChatType === "guild") ||
        (window.location.pathname.includes("guild.php") && document.getElementById("guild_tab_chat")?.style.display !== "none");
    if (isGuildChatActive) {
        counts.guild = 0;
    }

    const update = (selector, count) => {
        document.querySelectorAll(selector).forEach(el => {
            if (count > 0) {
                el.innerText = count > 9 ? "9+" : count;
                el.style.display = "flex";
            } else {
                el.style.display = "none";
            }
        });
    };

    if (counts.priv !== undefined) update(".js-badge-priv", counts.priv);
    if (counts.world !== undefined) update(".js-badge-world", counts.world);
    if (counts.guild !== undefined) update(".js-badge-guild", counts.guild);
    if (counts.suggestions !== undefined) update(".js-badge-suggestions", counts.suggestions);
    if (counts.forum !== undefined) update(".js-badge-forum", counts.forum);
    if (counts.achievements !== undefined) update(".js-badge-achievements", counts.achievements);

    const unreadArena = counts.arena || 0;
    const hasArenaCoins = Boolean(counts.arena_coins);

    update(".js-badge-arena", unreadArena);

    document.querySelectorAll(".js-badge-arena-coins").forEach(el => {
        el.style.display = (unreadArena === 0 && hasArenaCoins) ? "flex" : "none";
    });

    const total = (counts.priv || 0) + (counts.world || 0) + (counts.guild || 0) + (counts.suggestions || 0) +
        (counts.achievements || 0) + unreadArena + (counts.forum || 0);

    const mobileDot = document.getElementById("mobile-nav-dot");
    if (mobileDot) {
        const hasAlert = mobileDot.dataset.hasAlert === "true";

        mobileDot.style.display = (total > 0 || hasAlert) ? "block" : "none";
    }
}

function syncFeedbackIcon(msgElement, type = null, id = null) {
    if (!msgElement) return;
    const footer = msgElement.querySelector('.chat-reaction-footer');
    const btnContainer = msgElement.querySelector('.reaction-container.mode-btn_only');
    if (!footer || !btnContainer) return;

    const hasBadges = !!footer.querySelector('.reaction-badge');
    const infoBtn = btnContainer.querySelector("[data-on-click='openReactorList']");

    if (hasBadges && !infoBtn) {
        const btn = document.createElement("img");
        btn.src = "images/icons/icon_feedback.png";
        btn.className = "ressource-icons";
        btn.style.cursor = "pointer";
        btn.style.opacity = "0.7";
        btn.dataset.onClick = "openReactorList";
        btn.dataset.type = type || btnContainer.dataset.type || "";
        btn.dataset.id = id || btnContainer.dataset.id || "";
        btn.title = "Wer hat reagiert?";
        btn.alt = "";
        btnContainer.appendChild(btn);

        if (typeof bindActions === "function") {
            bindActions(btn);
        }
    } else if (!hasBadges && infoBtn) {
        infoBtn.remove();
    }
}

window.syncFeedbackIcon = syncFeedbackIcon;


function getActiveModalBody() {
    const openModals = Array.from(document.querySelectorAll(".overlay-modal"))
        .filter(m => window.getComputedStyle(m).display !== "none");
    if (openModals.length === 0) return null;

    const topModal = openModals[openModals.length - 1];
    return topModal.querySelector(".overlay-body, #overlay-content-body");
}

function renderToastInContainer(container, type, icon, message) {
    const oldBox = container.querySelector(`.info-box.event-${type}`);
    if (oldBox) {
        clearTimeout(oldBox._timer);
        oldBox.remove();
    }

    const box = document.createElement("div");
    box.className = `info-box event-${type}`;
    box.innerHTML = `<img src="images/icons/${icon}" alt="${type}"><span>${message}</span>`;
    container.appendChild(box);

    const dismiss = () => {
        if (box.classList.contains("fade-out")) return;
        box.classList.add("fade-out");
        box.addEventListener("animationend", () => box.remove(), {once: true});
    };

    box._timer = setTimeout(dismiss, 4000);
    box.addEventListener("click", () => {
        clearTimeout(box._timer);
        dismiss();
    });
}

function showGameMessage(message, type = "error") {
    if (isPageUnloading || !message) return;

    const iconMap = {
        passed: "icon_checked.png",
        error: "icon_error.png",
        warning: "icon_warning.png"
    };
    const icon = iconMap[type] || "icon_error.png";
    const boxHtml = `
        <div class="info-box event-${type}">
            <img src="images/icons/${icon}" alt="${type}">
            <span>${message}</span>
        </div>`;

    const modalBody = getActiveModalBody();
    if (modalBody && modalBody.offsetParent !== null) {
        let modalFlash = modalBody.querySelector(".modal-flash-container");
        if (!modalFlash) {
            modalFlash = document.createElement("div");
            modalFlash.className = "modal-flash-container";
            modalBody.prepend(modalFlash);
        }

        renderToastInContainer(modalFlash, type, icon, message);
        return;
    }

    const arenaInline = document.getElementById("arena-attack-inline-container");
    if (arenaInline && arenaInline.style.display !== "none") {
        const oldMsg = arenaInline.querySelector(".info-box");
        if (oldMsg) oldMsg.remove();

        arenaInline.insertAdjacentHTML("afterbegin", boxHtml);
        window.scrollTo({top: 0, behavior: "smooth"});
        return;
    }

    const flashContainer = document.getElementById("middle-flash-container");
    if (flashContainer) {
        renderToastInContainer(flashContainer, type, icon, message);
        return;
    }

    // Fallback: big-box-content
    const bigBoxContent = document.querySelector(".big-box-content");
    if (bigBoxContent) {
        const oldMsg = bigBoxContent.querySelector(".event-error, .event-passed, .event-warning");
        if (oldMsg) oldMsg.remove();

        bigBoxContent.insertAdjacentHTML("afterbegin", boxHtml);
        window.scrollTo({top: 0, behavior: "smooth"});
    }
}

window.showGameMessage = showGameMessage;
window.showGameError = (msg) => showGameMessage(msg, "error");
window.showGameSuccess = (msg) => showGameMessage(msg, "passed");
window.showGameWarning = (msg) => showGameMessage(msg, "warning");

document.addEventListener("mouseover", (e) => {
    if (Date.now() - lastPopupTouchTime < 500) return;

    const trigger = e.target.closest(".popup");
    if (trigger) showGlobalPopup(trigger, e);
});

document.addEventListener("mousemove", (e) => {
    if (Date.now() - lastPopupTouchTime < 500 || !activeHoverPopup) return;

    const trigger = e.target.closest(".popup");
    if (trigger === activeHoverPopup.trigger) showGlobalPopup(trigger, e);
    else if (!trigger && !globalPopupBox?.contains(e.target)) hideGlobalPopup();
}, {passive: true});

document.addEventListener("mouseout", (e) => {
    if (Date.now() - lastPopupTouchTime < 500 || !activeHoverPopup) return;

    const rel = e.relatedTarget;
    if (!rel || (!activeHoverPopup.trigger.contains(rel) && !globalPopupBox?.contains(rel) && !rel.closest?.(".popup"))) {
        hideGlobalPopup();
    }
});

document.addEventListener("touchstart", (e) => {
    lastPopupTouchTime = Date.now();
    if (globalPopupBox?.style.display === "block" && globalPopupBox.contains(e.target)) return;

    const trigger = e.target.closest(".popup");
    if (!trigger) return hideGlobalPopup();

    if (activeHoverPopup?.trigger === trigger && globalPopupBox?.style.display === "block") {
        hideGlobalPopup();
    } else {
        showGlobalPopup(trigger, e);
    }
}, {passive: true});

document.addEventListener("scroll", () => {
    if (activeHoverPopup) hideGlobalPopup();
}, {passive: true, capture: true});

document.addEventListener("pointerdown", (e) => {
    const btn = e.target.closest('#global-popup-box[data-source-id^="pop_k_preview_"] button[data-on-click="switchKingdomFromPopup"]');
    if (btn) {
        e.preventDefault();
        e.stopPropagation();

        if (btn.dataset.id) {
            btn.disabled = true;
            btn.innerText = "Wechsle...";
            hideGlobalPopup();
            switchKingdomById(btn.dataset.id);
        }
        return;
    }

    if (window.innerWidth <= 600 && activeHoverPopup && !globalPopupBox?.contains(e.target) && !e.target.closest(".popup")) {
        hideGlobalPopup();
    }
}, true);

if (document.body.classList.contains("logged-in")) {
    let lastBadgeFetch = Date.now();

    const fetchBadges = () => {
        lastBadgeFetch = Date.now();
        fetch("ajax/badge_counts.php", {
            headers: {"X-Requested-With": "XMLHttpRequest"}
        })
            .then(r => r.json())
            .then(counts => updateSidebarBadges(counts))
            .catch(() => {
            });
    };

    setInterval(() => {
        if (!document.hidden) {
            fetchBadges();
        }
    }, UPDATE_MSG_BADGES_INTERVAL);

    document.addEventListener("visibilitychange", () => {
        if (!document.hidden && (Date.now() - lastBadgeFetch >= UPDATE_MSG_BADGES_LAST_FETCH)) {
            fetchBadges();
        }
    });
}

document.addEventListener("keydown", (e) => {
    const active = document.activeElement;
    if (active && (active.tagName === "INPUT" || active.tagName === "TEXTAREA" || active.isContentEditable || active.tagName === "SELECT")) {
        return;
    }

    if (document.body.classList.contains("modal-open") || document.getElementById("info-box-overlay")) {
        return;
    }

    const select = document.getElementById("choosekingdom");
    if (!select || select.options.length <= 1) {
        return;
    }

    const isModifier = e.ctrlKey || e.metaKey;

    const isLeft = (e.key === "y" || e.key === "Y" || e.key === "ArrowLeft");
    const isRight = (e.key === "x" || e.key === "X" || e.key === "ArrowRight");

    if (isModifier && (isLeft || isRight)) {
        e.preventDefault();
        e.stopPropagation();

        const now = Date.now();
        const lastSwitch = parseInt(sessionStorage.getItem("last_kingdom_switch") || "0", 10);

        if (now - lastSwitch < KINGDOM_SWITCH_COOLDOWN) {
            return;
        }
        sessionStorage.setItem("last_kingdom_switch", now);

        if (isKingdomSwitching || typeof switchKingdom !== "function") {
            return;
        }

        if (isLeft) {
            switchKingdom(-1);
        } else {
            switchKingdom(1);
        }
    }
});

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="csrf_token"]')?.value
        || "";
}

window.getCsrfToken = getCsrfToken;

const originalFetch = window.fetch;
window.fetch = function (url, options = {}) {
    options = options || {};
    const method = (options.method || "GET").toUpperCase();

    if (method !== "GET") {
        const token = getCsrfToken();

        if (token) {
            options.headers = options.headers || {};
            if (options.headers instanceof Headers) {
                if (!options.headers.has("X-CSRF-TOKEN")) options.headers.set("X-CSRF-TOKEN", token);
            } else if (typeof options.headers === "object") {
                options.headers["X-CSRF-TOKEN"] = options.headers["X-CSRF-TOKEN"] || token;
            }

            if (options.body instanceof FormData) {
                if (!options.body.has("csrf_token")) {
                    options.body.append("csrf_token", token);
                }
            } else if (options.body instanceof URLSearchParams) {
                if (!options.body.has("csrf_token")) {
                    options.body.append("csrf_token", token);
                }
            } else if (typeof options.body === "string" && options.body.includes("=") && !options.body.includes("csrf_token=")) {
                options.body += (options.body.length > 0 ? "&" : "") + "csrf_token=" + encodeURIComponent(token);
            }
        }
    }

    return originalFetch.call(this, url, options).then(response => {
        const contentType = response.headers.get("content-type") || "";

        if (contentType.includes("application/json")) {
            response.clone().json().then(data => {
                if (data && data.redirect) {
                    isPageUnloading = true;

                    window.location.href = data.redirect;
                }
            }).catch(() => {
            });
        }

        return response;
    });
};
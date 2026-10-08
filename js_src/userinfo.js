let currentOverlayUrl = "";

registerAction("openOverlay", (el) => {
    const url = el.dataset.url;
    const title = el.dataset.title || "";
    const width = el.dataset.width || null;

    openOverlay(url, title, width, false, el);
});
registerAction("closeOverlay", () => {
    closeOverlay();
});
registerAction("mapJump", (el) => {
    const x = el.dataset.x;
    const y = el.dataset.y;

    if (typeof redirectToMap === "function") {
        redirectToMap(x, y);
    }
});

function getScriptPath(urlStr) {
    if (!urlStr) return "";

    try {
        const u = new URL(urlStr, window.location.origin);

        return u.pathname;
    } catch (e) {
        return urlStr.split("?")[0];
    }
}

function normalizeUrl(urlStr) {
    if (!urlStr) return "";

    try {
        const u = new URL(urlStr, window.location.href);

        return u.pathname + u.search;
    } catch (e) {
        return urlStr;
    }
}

function applyOverlayStyles(modal = null) {
    const modals = modal ? [modal] : Array.from(document.querySelectorAll('.overlay-modal'));

    modals.forEach(m => {
        if (!m || m.style.display === "none") return;

        if (window.innerWidth <= 600 || window.innerHeight < 600) {
            m.style.top = "50px";
        } else {
            m.style.top = "50px";
        }
    });
}

function initOverlayDrag(modal) {
    const dragItem = modal.querySelector(".overlay-header");
    if (!dragItem || dragItem.dataset.dragInit === "true") return;
    dragItem.dataset.dragInit = "true";

    let xOffset = 0;
    let yOffset = 0;
    let initialX, initialY;
    let isDragging = false;

    function setTranslate(x, y) {
        modal.style.transform = `translate3d(${x}px, ${y}px, 0) translateX(-50%)`;
    }

    function dragStart(e) {
        if (e.target.closest(".overlay-close-btn")) return;
        if (e.target !== dragItem && !dragItem.contains(e.target)) return;
        if (window.getSelection) window.getSelection().removeAllRanges();
        if (e.cancelable) e.preventDefault();
        isDragging = true;

        const clientX = e.type === "touchstart" ? e.touches[0].clientX : e.clientX;
        const clientY = e.type === "touchstart" ? e.touches[0].clientY : e.clientY;
        initialX = clientX - xOffset;
        initialY = clientY - yOffset;
    }

    function drag(e) {
        if (!isDragging) return;
        if (e.cancelable) e.preventDefault();
        const clientX = e.type === "touchmove" ? e.touches[0].clientX : e.clientX;
        const clientY = e.type === "touchmove" ? e.touches[0].clientY : e.clientY;
        const x = clientX - initialX;
        const y = clientY - initialY;

        const rect = modal.getBoundingClientRect();
        const winW = window.innerWidth;
        const winH = window.innerHeight;
        const halfWidth = rect.width / 2;
        const minX = -(winW / 2) + halfWidth;
        const maxX = (winW / 2) - halfWidth;
        const minY = -parseInt(window.getComputedStyle(modal).top || "50", 10);
        const maxY = winH - rect.height - 20;

        xOffset = Math.min(Math.max(x, minX), maxX);
        yOffset = Math.min(Math.max(y, minY), maxY);
        setTranslate(xOffset, yOffset);
    }

    function dragEnd() {
        isDragging = false;
    }

    dragItem.addEventListener("mousedown", dragStart);
    document.addEventListener("mouseup", dragEnd);
    document.addEventListener("mousemove", drag);
    dragItem.addEventListener("touchstart", dragStart, {passive: false});
    document.addEventListener("touchmove", drag, {passive: false});
    document.addEventListener("touchend", dragEnd);
}

function openOverlay(url, title = "", width = null, forceNew = false, sourceElement = null, isReload = false) {
    if (typeof window.closeMobileMenus === "function") {
        window.closeMobileMenus();
    } else {
        document.querySelectorAll('.mobile-side-nav').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.mobile-trigger').forEach(t => t.classList.remove('open'));
        if (typeof toggleMobileElements === "function") toggleMobileElements(false);
    }

    const openModals = Array.from(document.querySelectorAll('.overlay-modal')).filter(m => m.style.display !== "none");

    const targetUrlNorm = normalizeUrl(url);
    const isAlreadyOpenAnywhere = openModals.some(m => normalizeUrl(m.dataset.currentUrl) === targetUrlNorm);

    if (isAlreadyOpenAnywhere && !forceNew && !isReload) {
        return;
    }

    const sourceModal = sourceElement ? sourceElement.closest('.overlay-modal') : null;

    let targetModal = null;
    let isStacking = false;
    let isAlreadyOpen = false;

    if (openModals.length === 0 || !sourceModal) {
        document.querySelectorAll('.stacked-overlay').forEach(m => m.remove());
        targetModal = document.getElementById("onpage-overlay");
        isAlreadyOpen = (targetModal && targetModal.style.display === "grid");
    } else {
        const currentUrl = sourceModal.dataset.currentUrl || "";
        const isSameScript = (getScriptPath(currentUrl) === getScriptPath(url));

        if (isSameScript && !forceNew) {
            targetModal = sourceModal;
            isAlreadyOpen = true;
        } else {
            isStacking = true;
            isAlreadyOpen = false;
            const depth = openModals.length;
            targetModal = document.createElement("div");
            targetModal.className = "overlay-modal stacked-overlay";
            targetModal.id = `overlay-modal-stack-${depth}`;
            targetModal.style.display = "none";
            targetModal.style.zIndex = (1000001 + (depth * 10)).toString();
            targetModal.innerHTML = `
                <div class="overlay-header">
                    <span class="overlay-title" id="overlay-title-stack-${depth}"></span>
                    <button type="button" class="overlay-close-btn" data-on-click="closeOverlay">&times;</button>
                </div>
                <div class="overlay-body" id="overlay-content-body-stack-${depth}"></div>
            `;
            document.body.appendChild(targetModal);
            targetModal.querySelectorAll('[data-on-click]').forEach(bindActions);

            initOverlayDrag(targetModal);
        }
    }

    if (!targetModal) return;

    if (targetModal._abortController) {
        targetModal._abortController.abort();
    }
    targetModal._abortController = new AbortController();
    const signal = targetModal._abortController.signal;

    const content = targetModal.querySelector(".overlay-body, #overlay-content-body");
    const overlayTitle = targetModal.querySelector(".overlay-title, #overlay-title");

    if (isAlreadyOpen && content) {
        content.style.minHeight = content.offsetHeight + "px";
        content.style.transition = "opacity 0.15s ease";
        content.style.opacity = "0";
    }

    targetModal.dataset.currentUrl = url;
    currentOverlayUrl = url;

    const delay = isAlreadyOpen ? 150 : 0;
    setTimeout(() => {
        if (isAlreadyOpen && content) {
            content.innerHTML = '<div class="spinner">Lade...</div>';
            content.style.opacity = "1";
        }

        fetch(url, {
            headers: {"X-Requested-With": "XMLHttpRequest"},
            cache: "no-store",
            signal: signal
        })
            .then(response => {
                const isJson = response.headers.get("content-type")?.includes("application/json");
                if (isJson) {
                    return response.json().then(data => {
                        closeOverlay(targetModal);
                        if (data.error && typeof showMapFlashMessage === "function") {
                            showMapFlashMessage(data.error, "error");
                        }
                    });
                }
                return response.text().then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, "text/html");
                    let customWidth = width;
                    let customTitle = title;
                    const titleData = doc.getElementById("modal-title-data");

                    if (titleData) {
                        if (titleData.dataset.title) customTitle = titleData.dataset.title;
                        if (titleData.dataset.width) customWidth = titleData.dataset.width;
                    }

                    if (customTitle && customTitle.trim() !== "" && overlayTitle) {
                        overlayTitle.innerText = customTitle;
                    }
                    if (customWidth) {
                        targetModal.style.maxWidth = customWidth;
                    } else if (!isAlreadyOpen && !isStacking) {
                        targetModal.style.maxWidth = "";
                    }

                    if (!isAlreadyOpen) {
                        document.body.classList.add("modal-open");
                        document.querySelectorAll('.popupbox').forEach(box => box.style.display = "none");
                        targetModal.style.display = "grid";

                        applyOverlayStyles(targetModal);
                    }

                    content.style.transition = "none";
                    content.style.opacity = isAlreadyOpen ? "0" : "1";
                    content.innerHTML = doc.body.innerHTML;
                    content.style.minHeight = "";
                    content.querySelectorAll('[data-on-click], [data-on-submit], [data-on-change], [data-on-input]').forEach(bindActions);

                    if (typeof setup === "function") {
                        setup();
                    }

                    if (isAlreadyOpen) {
                        setTimeout(() => {
                            content.style.transition = "opacity 0.15s ease";
                            content.style.opacity = "1";
                        }, 20);
                    } else {
                        content.style.opacity = "1";
                    }
                });
            })
            .catch(err => {
                if (err.name !== 'AbortError') {
                    console.error("Overlay Fehler:", err);
                    if (content) {
                        content.innerHTML = '<div class="info-box event-error" style="margin: 10px;"><span>Fehler beim Laden.</span></div>';
                        content.style.opacity = "1";
                    }
                }
            });
    }, delay);
}

function closeOverlay(targetEl = null) {
    let modalToClose = null;
    if (targetEl && targetEl.nodeType) {
        modalToClose = targetEl.closest(".overlay-modal");
    }
    if (!modalToClose) {
        const openModals = Array.from(document.querySelectorAll('.overlay-modal')).filter(m => m.style.display !== "none");
        if (openModals.length > 0) {
            modalToClose = openModals[openModals.length - 1];
        }
    }

    if (!modalToClose) return;

    if (modalToClose._abortController) {
        modalToClose._abortController.abort();
        modalToClose._abortController = null;
    }

    if (modalToClose.id === "onpage-overlay") {
        modalToClose.style.display = "none";
        modalToClose.style.maxWidth = "";
        modalToClose.dataset.currentUrl = "";
        currentOverlayUrl = "";
    } else {
        modalToClose.remove();
    }

    const remainingOpen = Array.from(document.querySelectorAll('.overlay-modal')).filter(m => m.style.display !== "none");
    if (remainingOpen.length === 0) {
        document.body.classList.remove("modal-open");
    }
}

function reloadOverlay() {
    const openModals = Array.from(document.querySelectorAll('.overlay-modal')).filter(m => m.style.display !== "none");

    if (openModals.length > 0) {
        const topModal = openModals[openModals.length - 1];
        const url = topModal.dataset.currentUrl;
        const titleEl = topModal.querySelector(".overlay-title, #overlay-title");
        const title = titleEl ? titleEl.innerText : "";

        if (url) {
            openOverlay(url, title, null, false, topModal, true);
        }
    }
}

document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
        const openModals = Array.from(document.querySelectorAll('.overlay-modal')).filter(m => m.style.display !== "none");

        if (openModals.length > 0) {
            closeOverlay(openModals[openModals.length - 1]);
        }
    }
});

document.addEventListener("touchstart", (e) => {
    if (e.target.closest('.overlay-close-btn')) {
        e.preventDefault();
        e.stopPropagation();

        closeOverlay();
    }
}, {passive: false});

document.addEventListener("DOMContentLoaded", () => {
    const baseOverlay = document.getElementById("onpage-overlay");

    if (baseOverlay) {
        initOverlayDrag(baseOverlay);
    }

    applyOverlayStyles();
});

window.addEventListener("resize", () => {
    applyOverlayStyles();
});

function redirectToMap(x, y) {
    const isOnMapPage = window.location.pathname.includes("map.php");

    if (isOnMapPage) {
        if (typeof jumpToCoordinates === "function") {
            jumpToCoordinates(x, y);
        } else {
            window.location.href = "map.php?startx=" + x + "&starty=" + y;
        }
    } else {
        window.location.href = "map.php?startx=" + x + "&starty=" + y;
    }
}

window.openOverlay = openOverlay;
window.closeOverlay = closeOverlay;
window.reloadOverlay = reloadOverlay;
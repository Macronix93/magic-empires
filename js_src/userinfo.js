let isDraggingInfoWindow = false;
let initialX;
let initialY;
let xOffset = 0;
let yOffset = 0;
let currentOverlayUrl = "";
let activeOverlayController = null;

registerAction("openOverlay", (el) => {
    const url = el.dataset.url;
    const title = el.dataset.title;
    const width = el.dataset.width || null;

    openOverlay(url, title, width);
});
registerAction("closeOverlay", () => {
    if (typeof closeOverlay === "function") {
        closeOverlay();
    }
});
registerAction("mapJump", (el) => {
    const x = el.dataset.x;
    const y = el.dataset.y;

    if (typeof redirectToMap === "function") {
        redirectToMap(x, y);
    }
});

function setTranslate(xPos, yPos, el) {
    el.style.transform = `translate3d(${xPos}px, ${yPos}px, 0) translateX(-50%)`;
}

function applyOverlayStyles() {
    /** @type {HTMLElement} */
    const overlay = document.getElementById("onpage-overlay");

    if (!overlay || overlay.style.display === "none") return;

    if (window.innerWidth <= 600 || window.innerHeight < 600) {
        overlay.style.top = "10px";
    } else {
        overlay.style.top = "50px";
    }
}

function openOverlay(url, title = "", width = null) {
    if (typeof window.closeMobileMenus === "function") {
        window.closeMobileMenus();
    } else {
        document.querySelectorAll('.mobile-side-nav').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.mobile-trigger').forEach(t => t.classList.remove('open'));
        if (typeof toggleMobileElements === "function") toggleMobileElements(false);
    }

    if (activeOverlayController) {
        activeOverlayController.abort();
    }
    activeOverlayController = new AbortController();
    const signal = activeOverlayController.signal;

    const overlay = document.getElementById("onpage-overlay");
    const content = document.getElementById("overlay-content-body");
    const overlayTitle = document.getElementById("overlay-title");
    const isAlreadyOpen = (overlay.style.display === "grid");

    if (url === currentOverlayUrl && isAlreadyOpen) return;

    if (isAlreadyOpen) {
        content.style.minHeight = content.offsetHeight + "px";
        content.style.transition = "opacity 0.15s ease";
        content.style.opacity = "0";
    }

    currentOverlayUrl = url;

    const delay = isAlreadyOpen ? 150 : 0;

    setTimeout(() => {
        if (isAlreadyOpen) {
            content.innerHTML = '<div class="spinner">Lade...</div>';
        }

        fetch(url,
            {
                headers: {"X-Requested-With": "XMLHttpRequest"},
                signal: signal
            })
            .then(response => {
                const isJson = response.headers.get("content-type")?.includes("application/json");

                if (isJson) {
                    return response.json().then(data => {
                        if (isAlreadyOpen) closeOverlay();

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

                    const hasCustomTitle = Boolean(customTitle && customTitle.trim() !== "" && customTitle !== "Info");
                    if (hasCustomTitle) overlayTitle.innerText = customTitle;

                    if (customWidth) {
                        overlay.style.maxWidth = customWidth;
                    } else if (!isAlreadyOpen) {
                        overlay.style.maxWidth = "";
                    }

                    if (!isAlreadyOpen) {
                        document.body.classList.add("modal-open");
                        document.querySelectorAll('.popupbox').forEach(box => box.style.display = "none");

                        xOffset = 0;
                        yOffset = 0;

                        setTranslate(0, 0, overlay);
                        overlay.style.display = "grid";

                        applyOverlayStyles();
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
                }
            });
    }, delay);
}

function getSecondaryOverlay() {
    let secOverlay = document.getElementById("secondary-overlay");
    if (!secOverlay) {
        secOverlay = document.createElement("div");
        secOverlay.id = "secondary-overlay";
        secOverlay.className = "overlay-modal";
        secOverlay.style.display = "none";
        secOverlay.style.zIndex = "1000010";
        secOverlay.style.top = "60px";
        secOverlay.innerHTML = `
            <div id="secondary-overlay-handle" class="overlay-header">
                <span id="secondary-overlay-title">Spielerliste</span>
                <button type="button" class="overlay-close-btn" data-on-click="closeSecondaryOverlay">&times;</button>
            </div>
            <div id="secondary-overlay-content-body" class="overlay-body">
                <div class="spinner">Lade...</div>
            </div>
        `;
        document.body.appendChild(secOverlay);
        secOverlay.querySelectorAll('[data-on-click]').forEach(bindActions);
    }
    return secOverlay;
}

function openSecondaryOverlay(url, title = "Spielerliste", width = "400px") {
    const overlay = getSecondaryOverlay();
    const content = document.getElementById("secondary-overlay-content-body");
    const overlayTitle = document.getElementById("secondary-overlay-title");

    overlay.style.width = width || "400px";
    overlay.style.display = "grid";
    overlayTitle.innerText = title;
    content.innerHTML = '<div class="spinner">Lade...</div>';

    fetch(url, {headers: {"X-Requested-With": "XMLHttpRequest"}})
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, "text/html");

            content.innerHTML = doc.body.innerHTML;

            content.querySelectorAll('[data-on-click="closeOverlay"]').forEach(btn => {
                btn.dataset.onClick = "closeSecondaryOverlay";
            });

            content.querySelectorAll('[data-on-click], [data-on-submit], [data-on-change], [data-on-input]').forEach(bindActions);
        });
}

function closeSecondaryOverlay() {
    const overlay = document.getElementById("secondary-overlay");
    if (overlay) {
        overlay.style.display = "none";
    }
}

function closeOverlay() {
    if (activeOverlayController) {
        activeOverlayController.abort();
        activeOverlayController = null;
    }

    const secOverlay = document.getElementById("secondary-overlay");
    if (secOverlay && secOverlay.style.display !== "none") {
        closeSecondaryOverlay();
        return;
    }

    document.body.classList.remove("modal-open");
    const overlay = document.getElementById("onpage-overlay");
    if (overlay) {
        overlay.style.display = "none";
        overlay.style.maxWidth = "";
        currentOverlayUrl = "";
    }
}

function reloadOverlay() {
    const overlay = document.getElementById("onpage-overlay");
    if (overlay && overlay.style.display !== "none" && currentOverlayUrl) {
        const url = currentOverlayUrl;
        const title = document.getElementById("overlay-title")?.innerText || "Info";
        currentOverlayUrl = "";

        openOverlay(url, title);
    }
}

document.addEventListener("touchstart", (e) => {
    if (e.target.closest('.overlay-close-btn')) {
        e.stopPropagation();

        closeOverlay();
    }
}, {passive: false});

document.addEventListener("DOMContentLoaded", function () {
    const dragItem = document.getElementById("overlay-handle");
    /** @type {HTMLElement} */
    const container = document.getElementById("onpage-overlay");

    window.addEventListener("resize", () => {
        applyOverlayStyles();

        if (container.style.display === "block") {
            const rect = container.getBoundingClientRect();
            const winW = window.innerWidth;
            const halfWidth = rect.width / 2;
            const minX = -(winW / 2 - halfWidth);
            const maxX = (winW / 2 - halfWidth);

            xOffset = Math.min(Math.max(xOffset, minX), maxX);
            setTranslate(xOffset, yOffset, container);
        }
    });

    if (dragItem) {
        dragItem.addEventListener("mousedown", dragStart);
        document.addEventListener("mouseup", dragEnd);
        document.addEventListener("mousemove", drag);

        dragItem.addEventListener("touchstart", dragStart, {passive: false});
        document.addEventListener("touchmove", drag, {passive: false});
        document.addEventListener("touchend", dragEnd);
    }

    function dragStart(e) {
        if (e.target === dragItem || dragItem.contains(e.target)) {
            if (window.getSelection) {
                window.getSelection().removeAllRanges();
            }

            if (e.cancelable) e.preventDefault();

            isDraggingInfoWindow = true;
        } else {
            return;
        }

        let clientX, clientY;

        if (e.type === "touchstart") {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        } else {
            clientX = e.clientX;
            clientY = e.clientY;
        }

        initialX = clientX - xOffset;
        initialY = clientY - yOffset;
    }

    function drag(e) {
        if (!isDraggingInfoWindow) return;
        if (e.cancelable) e.preventDefault();

        let clientX, clientY;

        if (e.type === "touchmove") {
            clientX = e.touches[0].clientX;
            clientY = e.touches[0].clientY;
        } else {
            clientX = e.clientX;
            clientY = e.clientY;
        }

        let x = clientX - initialX;
        let y = clientY - initialY;

        const rect = container.getBoundingClientRect();
        const winW = window.innerWidth;
        const winH = window.innerHeight;
        const halfWidth = rect.width / 2;

        const minX = -(winW / 2) + halfWidth;
        const maxX = (winW / 2) - halfWidth;
        const minY = -parseInt(window.getComputedStyle(container).top);
        const maxY = winH - rect.height - 20;

        xOffset = Math.min(Math.max(x, minX), maxX);
        yOffset = Math.min(Math.max(y, minY), maxY);

        setTranslate(xOffset, yOffset, container);
    }

    function dragEnd() {
        isDraggingInfoWindow = false;
    }

    window.addEventListener("resize", () => {
        if (container.style.display === "block") {
            const rect = container.getBoundingClientRect();
            const winW = window.innerWidth;
            const halfWidth = rect.width / 2;
            const limitX = (winW / 2) - halfWidth;

            if (Math.abs(xOffset) > limitX) {
                xOffset = xOffset > 0 ? limitX : -limitX;
                setTranslate(xOffset, yOffset, container);
            }
        }
    });
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

window.reloadOverlay = reloadOverlay;
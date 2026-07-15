let isDraggingInfoWindow = false;
let initialX;
let initialY;
let xOffset = 0;
let yOffset = 0;
let currentOverlayUrl = "";

registerAction("openOverlay", (el) => {
    const url = el.dataset.url;
    const title = el.dataset.title;
    openOverlay(url, title);
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

    if (window.innerHeight < 500) {
        overlay.style.top = "10px";
    } else {
        overlay.style.top = "50px";
    }
}

function openOverlay(url, title = "Info") {
    const overlay = document.getElementById("onpage-overlay");
    const content = document.getElementById("overlay-content-body");
    const overlayTitle = document.getElementById("overlay-title");

    if (url === currentOverlayUrl && overlay.style.display === "grid") {
        return;
    }

    if (overlay.style.display === "grid") {
        content.style.height = content.offsetHeight + "px";
    } else {
        xOffset = 0;
        yOffset = 0;
        setTranslate(0, 0, overlay);
        overlay.style.display = "grid";
        applyOverlayStyles();
    }

    currentOverlayUrl = url;
    overlayTitle.innerText = title;

    content.style.opacity = "0";

    setTimeout(() => {
        content.innerHTML = '<div class="spinner">Lade...</div>';
        content.style.opacity = "1";

        fetch(url, {headers: {"X-Requested-With": "XMLHttpRequest"}})
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, "text/html");

                content.innerHTML = doc.body.innerHTML;
                content.style.height = "auto";
                content.scrollTop = 0;
            })
            .catch(() => {
                content.textContent = "Fehler beim Laden.";
                content.style.height = "auto";
                currentOverlayUrl = "";
            });
    }, 150);
}

function closeOverlay() {
    /** @type {HTMLElement} */
    const overlay = document.getElementById("onpage-overlay");

    if (overlay) {
        overlay.style.display = "none";

        currentOverlayUrl = "";
    }
}

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

        if (e.target === dragItem || dragItem.contains(e.target)) {
            isDraggingInfoWindow = true;
        }
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

function switchKingdomAndReload(kingdomId) {
    let formData = new FormData();
    formData.append("choosekingdom", kingdomId);

    let xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
            window.location.reload();
        }
    };
    xhttp.open("POST", "ajax/change_kingdom.php", true);
    xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    xhttp.send(formData);
}
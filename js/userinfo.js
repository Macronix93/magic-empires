let isDraggingInfoWindow = false;
let currentXInfoWindow;
let currentYInfoWindow;
let initialX;
let initialY;
let xOffset = 0;
let yOffset = 0;
let currentOverlayWidth = "850px";

function applyOverlayStyles() {
    /** @type {HTMLElement} */
    const overlay = document.getElementById("onpage-overlay");

    if (!overlay || overlay.style.display === "none") return;

    if (window.innerWidth < 600) {
        overlay.style.width = "95vw";
        overlay.style.maxWidth = "95vw";
    } else {
        overlay.style.width = currentOverlayWidth;
        overlay.style.maxWidth = "95vw";
    }
}

function openOverlay(url, title = "Spieler-Info") {
    /** @type {HTMLElement} */
    const overlay = document.getElementById("onpage-overlay");
    const content = document.getElementById("overlay-content-body");
    const overlayTitle = document.getElementById("overlay-title");

    overlay.style.display = "block";

    applyOverlayStyles();

    overlayTitle.innerText = title;
    content.innerHTML = "<div class=\"spinner\">Lade...</div>";
    content.scrollTop = 0;

    fetch(url, {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, "text/html");
            content.innerHTML = doc.body.innerHTML;
        })
        .catch(err => {
            content.innerHTML = "Fehler beim Laden.";
            console.error(err);
        });
}

function closeOverlay() {
    /** @type {HTMLElement} */
    const overlay = document.getElementById("onpage-overlay");

    if (overlay) {
        overlay.style.display = "none";
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
    }

    function dragStart(e) {
        initialX = e.clientX - xOffset;
        initialY = e.clientY - yOffset;

        if (e.target === dragItem || dragItem.contains(e.target)) {
            isDraggingInfoWindow = true;
        }
    }

    function drag(e) {
        if (isDraggingInfoWindow) {
            e.preventDefault();

            let x = e.clientX - initialX;
            let y = e.clientY - initialY;

            const rect = container.getBoundingClientRect();
            const winW = window.innerWidth;
            const winH = window.innerHeight;
            const halfWidth = rect.width / 2;

            const minX = -(winW / 2 - halfWidth);
            const maxX = (winW / 2 - halfWidth);

            const minY = -100;
            const maxY = winH - 100 - rect.height;

            currentXInfoWindow = Math.min(Math.max(x, minX), maxX);
            currentYInfoWindow = Math.min(Math.max(y, minY), maxY);

            xOffset = currentXInfoWindow;
            yOffset = currentYInfoWindow;

            setTranslate(currentXInfoWindow, currentYInfoWindow, container);
        }
    }

    function setTranslate(xPos, yPos, el) {
        el.style.transform = `translate3d(${xPos}px, ${yPos}px, 0) translateX(-50%)`;
    }

    function dragEnd() {
        initialX = currentXInfoWindow;
        initialY = currentYInfoWindow;
        isDraggingInfoWindow = false;
    }
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
let isDragging = false;
let wasDragged = false;
let startX, startY;
let startMouseX, startMouseY;
let zoom = 1.0;

document.addEventListener("DOMContentLoaded", () => {
    const viewport = document.getElementById("map-viewport");
    const grid = document.getElementById("map-grid");
    const loader = document.getElementById("map-loader");
    const coordsOverlay = document.getElementById("coords-display");

    if (!viewport || !grid) return;

    viewport.addEventListener("wheel", (e) => {
        e.preventDefault();
        const delta = e.deltaY > 0 ? -0.1 : 0.1;

        const rect = viewport.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;

        applyZoom(zoom + delta, mouseX, mouseY);
    }, {passive: false});

    viewport.addEventListener("mousedown", startDrag);

    window.addEventListener("mousemove", drag);
    window.addEventListener("mouseup", stopDrag);

    viewport.addEventListener("touchstart", (e) => startDrag(e.touches[0]), {passive: false});
    window.addEventListener("touchmove", (e) => drag(e.touches[0]), {passive: false});
    window.addEventListener("touchend", stopDrag);

    window.addEventListener("resize", () => {
        clampMapPosition();
    });

    grid.addEventListener("mousemove", (e) => {
        const tile = e.target.closest(".map-tile");
        if (tile) {
            coordsOverlay.innerText = `X: ${tile.dataset.x} | Y: ${tile.dataset.y}`;
        }
    });

    fetch("ajax/map_full_load.php", {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(response => response.text())
        .then(html => {
            grid.innerHTML = html;

            requestAnimationFrame(() => {
                centerMapOn(currentX, currentY, true);

                const startTile = document.querySelector(`.map-tile[data-x="${currentX}"][data-y="${currentY}"]`);
                if (startTile) startTile.classList.add("highlight");

                grid.style.visibility = "visible";
                if (loader) {
                    loader.classList.add("loader-hidden");
                    setTimeout(() => loader.style.display = "none", 500);
                }
            });
        })
        .catch(error => {
            console.error("Fehler:", error);
            if (loader) loader.querySelector('.loader-text').innerText = "Fehler beim Laden!";
        });
});

function startDrag(e) {
    const grid = document.getElementById('map-grid');
    if (!grid) return;

    isDragging = true;
    wasDragged = false;
    grid.style.transition = "none";

    const style = window.getComputedStyle(grid);
    const currentLeft = parseInt(style.left) || 0;
    const currentTop = parseInt(style.top) || 0;

    startX = e.pageX - currentLeft;
    startY = e.pageY - currentTop;

    startMouseX = e.pageX;
    startMouseY = e.pageY;
}

function drag(e) {
    if (!isDragging) return;

    if (Math.abs(e.pageX - startMouseX) > 5 || Math.abs(e.pageY - startMouseY) > 5) {
        wasDragged = true;
    }

    e.preventDefault();

    const viewport = document.getElementById("map-viewport");
    const grid = document.getElementById("map-grid");

    let x = e.pageX - startX;
    let y = e.pageY - startY;

    const minX = -(grid.offsetWidth - viewport.offsetWidth);
    const minY = -(grid.offsetHeight - viewport.offsetHeight);

    x = Math.min(0, Math.max(x, minX));
    y = Math.min(0, Math.max(y, minY));

    grid.style.left = x + "px";
    grid.style.top = y + "px";
}

function stopDrag() {
    isDragging = false;
}

function centerMapOn(x, y, instant = false) {
    const viewport = document.getElementById("map-viewport");
    const grid = document.getElementById("map-grid");
    if (!viewport || !grid) return;

    const tileWidth = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--map-tile-size"));
    const vW = viewport.offsetWidth;
    const vH = viewport.offsetHeight;

    let left = -((x - 1) * tileWidth) + (vW / 2) - (tileWidth / 2);
    let top = -((y - 1) * tileWidth) + (vH / 2) - (tileWidth / 2);

    const minX = -(grid.offsetWidth - viewport.offsetWidth);
    const minY = -(grid.offsetHeight - viewport.offsetHeight);

    left = Math.min(0, Math.max(left, minX));
    top = Math.min(0, Math.max(top, minY));

    grid.style.transition = instant ? "none" : "all 0.5s ease-out";
    grid.style.left = left + "px";
    grid.style.top = top + "px";
}

function selectField(element) {
    if (wasDragged) return;

    const x = element.getAttribute("data-x");
    const y = element.getAttribute("data-y");
    const kingdomId = element.getAttribute("data-kingdomid");

    document.querySelectorAll('.map-tile').forEach(t => t.classList.remove("highlight"));
    element.classList.add("highlight");

    fetch(`ajax/field_info.php?clickedfield=${kingdomId}&x=${x}&y=${y}`, {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.text())
        .then(html => {
            document.getElementById("field-info").innerHTML = html;
        });
}

function jumpToCoordinates(x, y) {
    const targetX = parseInt(x);
    const targetY = parseInt(y);

    if (isNaN(targetX) || isNaN(targetY) || targetX < 1 || targetX > 100 || targetY < 1 || targetY > 100) {
        return;
    }

    centerMapOn(targetX, targetY);

    const targetTile = document.querySelector(`.map-tile[data-x="${targetX}"][data-y="${targetY}"]`);

    if (targetTile) {
        selectField(targetTile);
    }
}

function applyZoom(newZoom, mouseX = null, mouseY = null) {
    const grid = document.getElementById("map-grid");
    const viewport = document.getElementById("map-viewport");
    const oldZoom = zoom;

    zoom = Math.max(0.5, Math.min(2.0, newZoom));
    if (oldZoom === zoom) return;

    const baseSize = 60;
    const oldTileSize = baseSize * oldZoom;
    const newTileSize = baseSize * zoom;

    const currentLeft = parseInt(grid.style.left) || 0;
    const currentTop = parseInt(grid.style.top) || 0;

    if (mouseX === null) mouseX = viewport.offsetWidth / 2;
    if (mouseY === null) mouseY = viewport.offsetHeight / 2;

    const gridX = (mouseX - currentLeft) / oldTileSize;
    const gridY = (mouseY - currentTop) / oldTileSize;

    document.documentElement.style.setProperty("--map-tile-size", newTileSize + "px");

    let nextLeft = mouseX - (gridX * newTileSize);
    let nextTop = mouseY - (gridY * newTileSize);

    grid.style.left = nextLeft + "px";
    grid.style.top = nextTop + "px";

    clampMapPosition();
}

function clampMapPosition() {
    const viewport = document.getElementById("map-viewport");
    const grid = document.getElementById("map-grid");
    if (!grid || !viewport) return;

    let currentLeft = parseInt(grid.style.left) || 0;
    let currentTop = parseInt(grid.style.top) || 0;

    const minX = -(grid.offsetWidth - viewport.offsetWidth);
    const minY = -(grid.offsetHeight - viewport.offsetHeight);

    // Wenn das Grid kleiner als der Viewport ist (beim Rauszoomen), zentrieren
    let newX = grid.offsetWidth < viewport.offsetWidth ? (viewport.offsetWidth - grid.offsetWidth) / 2 : Math.min(0, Math.max(currentLeft, minX));
    let newY = grid.offsetHeight < viewport.offsetHeight ? (viewport.offsetHeight - grid.offsetHeight) / 2 : Math.min(0, Math.max(currentTop, minY));

    grid.style.left = newX + "px";
    grid.style.top = newY + "px";
}
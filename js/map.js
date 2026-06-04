/* global currentX, currentY */
let isDragging = false;
let wasDragged = false;
let startX, startY;
let startMouseX, startMouseY;
let zoom = 1.0;
let velocityX = 0;
let velocityY = 0;
let lastMouseX = 0;
let lastMouseY = 0;
let momentumID = null;
let lastMoveTime = 0;
const friction = 0.95;
let initialPinchDistance = null;
let currentTranslateX = 0;
let currentTranslateY = 0;
let baseTileSize = 60;
let baseGridSize = 6000;
const MAP_DIMENSION = 100;

registerAction("selectField", (el) => {
    if (typeof wasDragged !== "undefined" && wasDragged) return;

    if (typeof selectField === "function") {
        selectField(el);
    }
});

function refreshMapConstants() {
    const rootStyle = getComputedStyle(document.documentElement);
    const sizeFromCSS = parseInt(rootStyle.getPropertyValue("--map-tile-size"));
    if (!isNaN(sizeFromCSS)) {
        baseTileSize = sizeFromCSS;
        baseGridSize = baseTileSize * MAP_DIMENSION;
    }
}

document.addEventListener("DOMContentLoaded", () => {
    const viewport = document.getElementById("map-viewport");
    /** @type {HTMLElement} */
    const grid = document.getElementById("map-grid");
    /** @type {HTMLElement} */
    const loader = document.getElementById("map-loader");
    const coordsOverlay = document.getElementById("coords-display");

    if (!viewport || !grid) return;

    refreshMapConstants();

    grid.style.left = "0px";
    grid.style.top = "0px";

    viewport.addEventListener("wheel", (e) => {
        e.preventDefault();
        const delta = e.deltaY > 0 ? -0.1 : 0.1;
        const rect = viewport.getBoundingClientRect();
        applyZoom(zoom + delta, e.clientX - rect.left, e.clientY - rect.top);
    }, {passive: false});

    viewport.addEventListener("mousedown", startDrag);
    window.addEventListener("mousemove", drag);
    window.addEventListener("mouseup", stopDrag);

    viewport.addEventListener("touchstart", (e) => {
        if (e.touches.length === 1) startDrag(e.touches[0]);
    }, {passive: false});

    window.addEventListener("touchmove", (e) => {
        if (e.touches.length === 1 && isDragging) {
            if (e.cancelable) e.preventDefault();
            drag(e.touches[0]);
        } else if (e.touches.length === 2) {
            if (e.cancelable) e.preventDefault();
            let dist = Math.hypot(e.touches[0].pageX - e.touches[1].pageX, e.touches[0].pageY - e.touches[1].pageY);
            if (initialPinchDistance === null) {
                initialPinchDistance = dist;
            } else {
                let factor = dist / initialPinchDistance;
                let rect = viewport.getBoundingClientRect();
                let centerX = (e.touches[0].clientX + e.touches[1].clientX) / 2;
                let centerY = (e.touches[0].clientY + e.touches[1].clientY) / 2;
                applyZoom(zoom * factor, centerX - rect.left, centerY - rect.top);
                initialPinchDistance = dist;
            }
        }
    }, {passive: false});

    window.addEventListener("touchend", () => {
        stopDrag();
        initialPinchDistance = null;
    });

    window.addEventListener("resize", () => {
        refreshMapConstants();
        clampMapPosition(true);
    });

    grid.addEventListener("mousemove", (e) => {
        const tile = e.target.closest(".map-tile");
        if (tile) coordsOverlay.innerText = `X: ${tile.dataset.x} | Y: ${tile.dataset.y}`;
    });

    const pathToggle = document.getElementById("show-path-toggle");

    if (pathToggle) {
        pathToggle.addEventListener("change", function () {
            if (!this.checked) {
                document.querySelectorAll(".path-highlight").forEach(t => t.classList.remove("path-highlight"));
            } else {
                const currentHighlight = document.querySelector(".map-tile.highlight");
                if (currentHighlight) {
                    forceSelectField(currentHighlight);
                }
            }
        });
    }

    const searchForm = document.getElementById("update-map");

    if (searchForm) {
        searchForm.addEventListener("submit", (e) => {
            e.preventDefault();

            /** @type {HTMLInputElement} */
            const xInput = document.getElementById('startx');
            /** @type {HTMLInputElement} */
            const yInput = document.getElementById('starty');

            if (xInput && yInput) {
                jumpToCoordinates(xInput.value, yInput.value);
            }
        });
    }

    fetch("ajax/map_full_load.php", {headers: {"X-Requested-With": "XMLHttpRequest"}})
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
        });
});

function updateTransform(instant = false) {
    /** @type {HTMLElement} */
    const grid = document.getElementById("map-grid");

    grid.style.transition = instant ? "none" : "transform 0.3s ease-out";
    grid.style.transform = `translate3d(${currentTranslateX}px, ${currentTranslateY}px, 0) scale(${zoom})`;

    updateMobileCoords();
}

function startDrag(e) {
    cancelAnimationFrame(momentumID);
    velocityX = 0;
    velocityY = 0;
    isDragging = true;
    wasDragged = false;
    startX = e.pageX - currentTranslateX;
    startY = e.pageY - currentTranslateY;
    startMouseX = e.pageX;
    startMouseY = e.pageY;
    lastMouseX = e.pageX;
    lastMouseY = e.pageY;
}

function drag(e) {
    if (!isDragging) return;
    if (Math.abs(e.pageX - startMouseX) > 5 || Math.abs(e.pageY - startMouseY) > 5) wasDragged = true;
    velocityX = e.pageX - lastMouseX;
    velocityY = e.pageY - lastMouseY;
    lastMoveTime = Date.now();
    lastMouseX = e.pageX;
    lastMouseY = e.pageY;
    currentTranslateX = e.pageX - startX;
    currentTranslateY = e.pageY - startY;
    clampMapPosition(true);
}

function stopDrag() {
    if (!isDragging) return;
    isDragging = false;
    if (Date.now() - lastMoveTime > 100) {
        velocityX = 0;
        velocityY = 0;
    }
    if (wasDragged && (Math.abs(velocityX) > 0.5 || Math.abs(velocityY) > 0.5)) applyMomentum();
}

function clampMapPosition(instant = true) {
    /** @type {HTMLElement} */
    const viewport = document.getElementById("map-viewport");

    if (!viewport) return;

    const scaledGridSize = baseGridSize * zoom;
    const minX = -(scaledGridSize - viewport.offsetWidth);
    const minY = -(scaledGridSize - viewport.offsetHeight);

    if (scaledGridSize < viewport.offsetWidth) {
        currentTranslateX = (viewport.offsetWidth - scaledGridSize) / 2;
    } else {
        currentTranslateX = Math.min(0, Math.max(currentTranslateX, minX));
    }

    if (scaledGridSize < viewport.offsetHeight) {
        currentTranslateY = (viewport.offsetHeight - scaledGridSize) / 2;
    } else {
        currentTranslateY = Math.min(0, Math.max(currentTranslateY, minY));
    }
    updateTransform(instant);
}

function applyMomentum() {
    velocityX *= friction;
    velocityY *= friction;
    currentTranslateX += velocityX;
    currentTranslateY += velocityY;

    /** @type {HTMLElement} */
    const viewport = document.getElementById("map-viewport");
    const scaledGridSize = baseGridSize * zoom;
    const minX = -(scaledGridSize - viewport.offsetWidth);
    const minY = -(scaledGridSize - viewport.offsetHeight);

    if (currentTranslateX > 0 || currentTranslateX < minX) {
        velocityX = 0;
        currentTranslateX = Math.min(0, Math.max(currentTranslateX, minX));
    }
    if (currentTranslateY > 0 || currentTranslateY < minY) {
        velocityY = 0;
        currentTranslateY = Math.min(0, Math.max(currentTranslateY, minY));
    }

    updateTransform(true);
    if (Math.abs(velocityX) > 0.1 || Math.abs(velocityY) > 0.1) {
        momentumID = requestAnimationFrame(applyMomentum);
    }
}

function centerMapOn(x, y, instant = false) {
    cancelAnimationFrame(momentumID);

    velocityX = 0;
    velocityY = 0;

    /** @type {HTMLElement} */
    const viewport = document.getElementById("map-viewport");
    const tileCenterX = (x - 0.5) * baseTileSize * zoom;
    const tileCenterY = (y - 0.5) * baseTileSize * zoom;

    currentTranslateX = (viewport.offsetWidth / 2) - tileCenterX;
    currentTranslateY = (viewport.offsetHeight / 2) - tileCenterY;

    clampMapPosition(instant);
}

function applyZoom(newZoom, mouseX = null, mouseY = null) {
    /** @type {HTMLElement} */
    const viewport = document.getElementById("map-viewport");
    const oldZoom = zoom;
    zoom = Math.max(0.5, Math.min(2.0, newZoom));

    if (oldZoom === zoom) return;

    if (mouseX === null) mouseX = viewport.offsetWidth / 2;
    if (mouseY === null) mouseY = viewport.offsetHeight / 2;

    const worldX = (mouseX - currentTranslateX) / oldZoom;
    const worldY = (mouseY - currentTranslateY) / oldZoom;

    currentTranslateX = mouseX - (worldX * zoom);
    currentTranslateY = mouseY - (worldY * zoom);

    clampMapPosition(true);
}

function updateMobileCoords() {
    /** @type {HTMLElement} */
    const viewport = document.getElementById("map-viewport");
    const coordsOverlay = document.getElementById("coords-display");
    const currentScaledTileSize = baseTileSize * zoom;

    const centerX = (viewport.offsetWidth / 2) - currentTranslateX;
    const centerY = (viewport.offsetHeight / 2) - currentTranslateY;

    const tileX = Math.floor(centerX / currentScaledTileSize) + 1;
    const tileY = Math.floor(centerY / currentScaledTileSize) + 1;

    if (tileX >= 1 && tileX <= MAP_DIMENSION && tileY >= 1 && tileY <= MAP_DIMENSION) {
        coordsOverlay.innerText = `X: ${tileX} | Y: ${tileY}`;
    }
}

function jumpToCoordinates(x, y) {
    const targetX = parseInt(x);
    const targetY = parseInt(y);
    if (isNaN(targetX) || isNaN(targetY) || targetX < 1 || targetX > MAP_DIMENSION || targetY < 1 || targetY > MAP_DIMENSION) return;

    centerMapOn(targetX, targetY);
    const targetTile = document.querySelector(`.map-tile[data-x="${targetX}"][data-y="${targetY}"]`);
    if (targetTile) forceSelectField(targetTile);
}

function forceSelectField(element) {
    const x = element.getAttribute("data-x");
    const y = element.getAttribute("data-y");
    const kingdomId = element.getAttribute("data-kingdomid");

    document.querySelectorAll('.map-tile').forEach(t => t.classList.remove("highlight"));
    element.classList.add("highlight");

    fetch(`ajax/field_info.php?clickedfield=${kingdomId}&x=${x}&y=${y}`, {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(data => {
            /** @type {{html: string, path: Array<{x: number, y: number}>}} */
            document.getElementById("field-info").innerHTML = data.html;

            document.querySelectorAll('.path-highlight').forEach(t => t.classList.remove("path-highlight"));

            /** @type {HTMLInputElement} */
            const pathToggle = document.getElementById("show-path-toggle");
            const isPathEnabled = pathToggle.checked;

            if (isPathEnabled && data.path && data.path.length > 0) {
                data.path.forEach(step => {
                    const pathTile = document.querySelector(`.map-tile[data-x="${step.x}"][data-y="${step.y}"]`);

                    if (pathTile) {
                        pathTile.classList.add("path-highlight");
                    }
                });
            }
        })
        .catch(err => console.error("Fehler beim Laden der Felddaten:", err));
}

function selectField(element) {
    if (wasDragged) return;

    forceSelectField(element);
}
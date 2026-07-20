let canvas, ctx, viewport;
let mapData = [];
let images = {};
let isDragging = false;
let wasDragged = false;
let startX, startY;
let currentTranslateX = 0;
let currentTranslateY = 0;
let zoom = 1.0;
let mapCache = null;

let velocityX = 0;
let velocityY = 0;
let lastMouseX = 0;
let lastMouseY = 0;
let lastMoveTime = 0;
let momentumID = null;
const friction = 0.95;

let selectedX = null;
let selectedY = null;
let currentPath = [];

const MAP_DIMENSION = 100;
const MAX_X = MAP_DIMENSION;
const MAX_Y = MAP_DIMENSION
const BASE_TILE_SIZE = 60;
let initialPinchDistance = null;

const COLORS = {
    1: "#B97A57", 2: "#00A2E8", 3: "#22B14C", 4: "#FFC90E", 5: "#B5E61D"
};

document.addEventListener("DOMContentLoaded", () => {
    viewport = document.getElementById("map-viewport");
    canvas = document.getElementById("map-canvas");
    if (!canvas) return;
    ctx = canvas.getContext("2d", {alpha: false});

    const iconSources = {
        house: 'images/icons/house.png',
        town: 'images/icons/town.png',
        tower2: 'images/icons/tower2.png',
        castle: 'images/icons/castle.png',
        gems: 'images/icons/icon_gems.png'
    };

    let loadedCount = 0;
    const totalIcons = Object.keys(iconSources).length;
    for (let key in iconSources) {
        images[key] = new Image();
        images[key].src = iconSources[key];
        images[key].onload = () => {
            if (++loadedCount === totalIcons) startMap();
        };
    }

    function startMap() {
        const mapCont = document.getElementById("map-container");
        selectedX = parseInt(mapCont.dataset.startX) || 1;
        selectedY = parseInt(mapCont.dataset.startY) || 1;

        fetch("ajax/map_full_load.php", {headers: {"X-Requested-With": "XMLHttpRequest"}})
            .then(r => r.json())
            .then(data => {
                mapData = data;
                mapCache = null;
                resizeCanvas();
                centerMapOn(selectedX, selectedY, true);
                document.getElementById("map-loader").style.display = "none";

                selectField(selectedX, selectedY, false);
            });
    }

    // Events
    window.addEventListener("resize", resizeCanvas);
    viewport.addEventListener("wheel", handleWheel, {passive: false});
    viewport.addEventListener("mousedown", dragStart);
    window.addEventListener("mousemove", dragMove);
    window.addEventListener("mouseup", dragEnd);

    // Spezial-Logik für Mobile (Touch)
    viewport.addEventListener("touchstart", (e) => {
        if (e.touches.length === 1) {
            // Ein Finger: Normales Verschieben starten
            dragStart(e.touches[0]);
        } else if (e.touches.length === 2) {
            // Zwei Finger: Zoom-Start (Momentum stoppen)
            cancelAnimationFrame(momentumID);
            initialPinchDistance = Math.hypot(
                e.touches[0].pageX - e.touches[1].pageX,
                e.touches[0].pageY - e.touches[1].pageY
            );
        }
    }, {passive: false});

    window.addEventListener("touchmove", (e) => {
        if (e.touches.length === 1 && isDragging) {
            if (e.cancelable) e.preventDefault();
            dragMove(e.touches[0]);
        } else if (e.touches.length === 2) {
            // Zwei Finger: Zoomen
            if (e.cancelable) e.preventDefault();
            const currentDist = Math.hypot(
                e.touches[0].pageX - e.touches[1].pageX,
                e.touches[0].pageY - e.touches[1].pageY
            );

            if (initialPinchDistance !== null) {
                const factor = currentDist / initialPinchDistance;
                const rect = canvas.getBoundingClientRect();

                // Mittelpunkt zwischen den zwei Fingern berechnen
                const centerX = (e.touches[0].clientX + e.touches[1].clientX) / 2;
                const centerY = (e.touches[0].clientY + e.touches[1].clientY) / 2;

                applyZoomAt(zoom * factor, centerX - rect.left, centerY - rect.top);
                initialPinchDistance = currentDist;
            }
        }
    }, {passive: false});

    window.addEventListener("touchend", () => {
        isDragging = false;
        initialPinchDistance = null;
        if (wasDragged && Date.now() - lastMoveTime < 100) {
            applyMomentum();
        }
    });

    // Suche & Pfad-Toggle (bleibt gleich)
    const searchForm = document.getElementById("update-map");
    if (searchForm) {
        searchForm.addEventListener("submit", (e) => {
            e.preventDefault();
            jumpTo(parseInt(document.getElementById('startx').value), parseInt(document.getElementById('starty').value));
        });
    }

    const pathToggle = document.getElementById("show-path-toggle");
    if (pathToggle) {
        pathToggle.addEventListener("change", () => draw());
    }
});

function applyZoomAt(newZoom, mouseX, mouseY) {
    const oldZoom = zoom;
    zoom = Math.max(0.15, Math.min(2.0, newZoom));

    if (oldZoom !== zoom) {
        const worldX = (mouseX - currentTranslateX) / oldZoom;
        const worldY = (mouseY - currentTranslateY) / oldZoom;

        currentTranslateX = mouseX - (worldX * zoom);
        currentTranslateY = mouseY - (worldY * zoom);

        clampMapPosition();
        draw();
    }
}

function resizeCanvas() {
    const ratio = window.devicePixelRatio || 1;

    canvas.width = viewport.offsetWidth * ratio;
    canvas.height = viewport.offsetHeight * ratio;

    canvas.style.width = viewport.offsetWidth + "px";
    canvas.style.height = viewport.offsetHeight + "px";

    ctx.scale(ratio, ratio);

    ctx.imageSmoothingEnabled = false;
    ctx.mozImageSmoothingEnabled = false;
    ctx.webkitImageSmoothingEnabled = false;
    ctx.msImageSmoothingEnabled = false;

    clampMapPosition();
    draw();
}

function draw() {
    if (!mapData.length) return;

    if (!mapCache) {
        mapCache = {1: [], 2: [], 3: [], 4: [], 5: []};
        mapData.forEach(tile => {
            if (mapCache[tile[2]]) mapCache[tile[2]].push(tile);
        });
    }

    ctx.fillStyle = "#1a120b";
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    const scaledTile = BASE_TILE_SIZE * zoom;

    const showGrid = zoom > 0.15;
    const showIcons = zoom > 0.1;

    for (let type in mapCache) {
        ctx.fillStyle = COLORS[type];
        ctx.beginPath();

        mapCache[type].forEach(tile => {
            const [x, y] = tile;
            const posX = (x - 1) * scaledTile + currentTranslateX;
            const posY = (y - 1) * scaledTile + currentTranslateY;

            if (posX + scaledTile >= 0 && posX <= canvas.width &&
                posY + scaledTile >= 0 && posY <= canvas.height) {
                ctx.rect(posX, posY, scaledTile + 0.4, scaledTile + 0.4);
            }
        });

        ctx.fill();
    }

    if (showGrid) {
        ctx.strokeStyle = "rgba(0,0,0,0.1)";
        ctx.lineWidth = 1;
        ctx.beginPath();
        mapData.forEach(tile => {
            const [x, y] = tile;
            const posX = (x - 1) * scaledTile + currentTranslateX;
            const posY = (y - 1) * scaledTile + currentTranslateY;
            if (posX + scaledTile >= 0 && posX <= canvas.width && posY + scaledTile >= 0 && posY <= canvas.height) {
                ctx.rect(posX, posY, scaledTile, scaledTile);
            }
        });
        ctx.stroke();
    }

    if (showIcons) {
        mapData.forEach(tile => {
            const [x, y, , kid, level] = tile;

            if (kid === -1) return;

            const posX = (x - 1) * scaledTile + currentTranslateX;
            const posY = (y - 1) * scaledTile + currentTranslateY;

            if (posX + scaledTile < 0 || posX > canvas.width || posY + scaledTile < 0 || posY > canvas.height) return;

            if (kid === -2) {
                ctx.drawImage(images.gems, posX + scaledTile * 0.2, posY + scaledTile * 0.2, scaledTile * 0.6, scaledTile * 0.6);
            } else {
                let img = images.house;
                if (level >= 8) img = images.castle;
                else if (level >= 6) img = images.tower2;
                else if (level >= 3) img = images.town;
                ctx.drawImage(img, posX, posY, scaledTile, scaledTile);
            }
        });
    }

    if (selectedX && selectedY) {
        const sPosX = (selectedX - 1) * scaledTile + currentTranslateX;
        const sPosY = (selectedY - 1) * scaledTile + currentTranslateY;
        ctx.strokeStyle = "#f62222";
        ctx.lineWidth = 3;
        ctx.strokeRect(sPosX + 1, sPosY + 1, scaledTile - 2, scaledTile - 2);
    }

    if (currentPath.length > 0 && document.getElementById("show-path-toggle")?.checked) {
        drawPath(scaledTile);
    }
}

function clampMapPosition() {
    const scaledSize = BASE_TILE_SIZE * zoom * MAP_DIMENSION;
    const minX = viewport.offsetWidth - scaledSize;
    const minY = viewport.offsetHeight - scaledSize;

    if (scaledSize < viewport.offsetWidth) {
        currentTranslateX = (viewport.offsetWidth - scaledSize) / 2;
    } else {
        currentTranslateX = Math.min(0, Math.max(currentTranslateX, minX));
    }

    if (scaledSize < viewport.offsetHeight) {
        currentTranslateY = (viewport.offsetHeight - scaledSize) / 2;
    } else {
        currentTranslateY = Math.min(0, Math.max(currentTranslateY, minY));
    }
}

function applyMomentum() {
    velocityX *= friction;
    velocityY *= friction;
    currentTranslateX += velocityX;
    currentTranslateY += velocityY;
    clampMapPosition();
    draw();

    if (Math.abs(velocityX) > 0.1 || Math.abs(velocityY) > 0.1) {
        momentumID = requestAnimationFrame(applyMomentum);
    }
}

function dragStart(e) {
    cancelAnimationFrame(momentumID);
    isDragging = true;
    wasDragged = false;
    startX = e.pageX - currentTranslateX;
    startY = e.pageY - currentTranslateY;
    lastMouseX = e.pageX;
    lastMouseY = e.pageY;
    lastMoveTime = Date.now();
}

function dragMove(e) {
    if (!isDragging) return;
    const now = Date.now();
    velocityX = e.pageX - lastMouseX;
    velocityY = e.pageY - lastMouseY;

    if (Math.abs(e.pageX - (startX + currentTranslateX)) > 5) wasDragged = true;

    currentTranslateX = e.pageX - startX;
    currentTranslateY = e.pageY - startY;
    lastMouseX = e.pageX;
    lastMouseY = e.pageY;
    lastMoveTime = now;

    clampMapPosition();
    draw();
}

function dragEnd(e) {
    if (!isDragging) return;

    isDragging = false;

    if (wasDragged) {
        if (Date.now() - lastMoveTime < 100) applyMomentum();
    } else {
        const rect = canvas.getBoundingClientRect();
        const mouseX = e.pageX - rect.left - window.scrollX;
        const mouseY = e.pageY - rect.top - window.scrollY;
        const tx = Math.floor((mouseX - currentTranslateX) / (BASE_TILE_SIZE * zoom)) + 1;
        const ty = Math.floor((mouseY - currentTranslateY) / (BASE_TILE_SIZE * zoom)) + 1;
        if (tx >= 1 && tx <= MAX_X && ty >= 1 && ty <= MAX_Y) selectField(tx, ty);
    }
}

function handleWheel(e) {
    e.preventDefault();

    const delta = e.deltaY > 0 ? -0.15 : 0.15;
    const oldZoom = zoom;
    zoom = Math.max(0.15, Math.min(2.0, zoom + delta));

    if (oldZoom !== zoom) {
        const rect = canvas.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;
        const worldX = (mouseX - currentTranslateX) / oldZoom;
        const worldY = (mouseY - currentTranslateY) / oldZoom;
        currentTranslateX = mouseX - (worldX * zoom);
        currentTranslateY = mouseY - (worldY * zoom);
        clampMapPosition();
        draw();
    }
}

function selectField(x, y, shouldCenter = false) {
    selectedX = x;
    selectedY = y;
    if (shouldCenter) centerMapOn(x, y);

    const tile = mapData.find(t => t[0] === x && t[1] === y);
    const kid = tile ? tile[3] : -1;

    fetch(`ajax/field_info.php?clickedfield=${kid}&x=${x}&y=${y}`, {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(data => {
            document.getElementById("field-info").innerHTML = data.html;
            currentPath = data.path || [];
            draw();
        });
}

function drawPath(scaledTile) {
    ctx.strokeStyle = "rgba(246, 34, 34, 0.7)";
    ctx.setLineDash([5, 5]);
    ctx.lineWidth = Math.max(2, 4 * zoom);
    ctx.beginPath();

    currentPath.forEach((p, index) => {
        const px = (p.x - 0.5) * scaledTile + currentTranslateX;
        const py = (p.y - 0.5) * scaledTile + currentTranslateY;
        if (index === 0) ctx.moveTo(px, py);
        else ctx.lineTo(px, py);
    });

    ctx.stroke();
    ctx.setLineDash([]);
}

function centerMapOn(x, y) {
    const scaledTile = BASE_TILE_SIZE * zoom;
    currentTranslateX = (canvas.width / 2) - (x - 0.5) * scaledTile;
    currentTranslateY = (canvas.height / 2) - (y - 0.5) * scaledTile;
    clampMapPosition();
    draw();
}

function jumpTo(x, y) {
    if (x >= 1 && x <= MAX_X && y >= 1 && y <= MAX_Y) {
        selectField(x, y, true);
    }
}

window.jumpToCoordinates = jumpTo;
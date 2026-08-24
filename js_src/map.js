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
let panAnimationID = null;
let costGrid = {};

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

let gameConfig = {};

const COLORS = {
    1: "#576574", // Gebirge
    2: "#0984e3", // Küste
    3: "#166733", // Wald
    4: "#dca34b", // Wüste
    5: "#78a55a"  // Hochland
};

document.addEventListener("DOMContentLoaded", () => {
    viewport = document.getElementById("map-viewport");
    canvas = document.getElementById("map-canvas");
    if (!canvas) return;
    ctx = canvas.getContext("2d", {alpha: false});

    const iconSources = {
        house: 'images/icons/icon_house.png',
        town: 'images/icons/icon_town.png',
        tower2: 'images/icons/icon_tower2.png',
        castle: 'images/icons/icon_castle.png',
        gems: 'images/icons/icon_gems.png',
        fire: 'images/icons/icon_fire.png',
        monster1: 'images/icons/icon_goblin.png',  // Level 1-3
        monster2: 'images/icons/icon_golem_small.png',  // Level 4-7
        monster3: 'images/icons/icon_dragon_small.png'  // Level 8-10
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

        gameConfig = JSON.parse(mapCont.dataset.config);

        selectedX = parseInt(mapCont.dataset.startX) || 1;
        selectedY = parseInt(mapCont.dataset.startY) || 1;

        if (window.innerWidth < 600) {
            zoom = 0.5;
        } else {
            zoom = 1.0;
        }

        fetch("ajax/map_full_load.php", {headers: {"X-Requested-With": "XMLHttpRequest"}})
            .then(r => r.json())
            .then(data => {
                mapData = data.map_data;
                window.activeEventInfo = data.event_info;
                mapCache = null;

                resizeCanvas();
                centerMapOn(selectedX, selectedY, true);

                document.getElementById("map-loader").style.display = "none";
                selectField(selectedX, selectedY, true);

                if (window.innerWidth <= 1392) {
                    const statusMsg = document.querySelector(".big-box-content > .info-box");
                    const legend = document.getElementById("map-legend-fieldtypes");

                    const targetElement = statusMsg || legend;
                    const yOffset = targetElement === statusMsg ? -60 : -20;

                    if (targetElement) {
                        const y = targetElement.getBoundingClientRect().top + window.pageYOffset + yOffset;

                        window.scrollTo({top: y, behavior: "smooth"});
                    }
                }
            });
    }

    // Events
    window.addEventListener("resize", resizeCanvas);
    viewport.addEventListener("wheel", handleWheel, {passive: false});
    viewport.addEventListener("mousedown", dragStart);
    window.addEventListener("mousemove", dragMove);
    window.addEventListener("mouseup", dragEnd);
    viewport.addEventListener("touchstart", (e) => {
        if (e.touches.length === 1) {
            dragStart(e.touches[0]);
        } else if (e.touches.length === 2) {
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
            if (e.cancelable) e.preventDefault();
            const currentDist = Math.hypot(
                e.touches[0].pageX - e.touches[1].pageX,
                e.touches[0].pageY - e.touches[1].pageY
            );

            if (initialPinchDistance !== null) {
                const factor = currentDist / initialPinchDistance;
                const rect = canvas.getBoundingClientRect();

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

    ["startx", "starty"].forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('input', function () {
                let val = this.value.replace(/\D/g, '');

                if (val.length > 1 && val.startsWith('0')) {
                    val = val.replace(/^0+/, '');
                }

                if (val !== '') {
                    let num = parseInt(val);
                    if (num > 100) val = '100';
                    if (num < 1 && val.length >= 1) val = '1';
                }

                this.value = val;
            });

            input.addEventListener('keydown', function (e) {
                const allowedKeys = ['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight', 'Enter'];

                if (!allowedKeys.includes(e.key) && !/^\d$/.test(e.key)) {
                    e.preventDefault();
                }
            });
        }
    });

    const filters = ["filter-players", "filter-resources", "filter-monsters"];
    filters.forEach(id => {
        const el = document.getElementById(id);

        if (el) {
            el.addEventListener("change", () => draw());
        }
    });
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
            const [x, y, , kid, level, isBurning, monsterLevel, , , , owner_id, , , myTroopIcon] = tile;

            if (kid === -1) return;

            const posX = (x - 1) * scaledTile + currentTranslateX;
            const posY = (y - 1) * scaledTile + currentTranslateY;

            if (posX + scaledTile < 0 || posX > canvas.width || posY + scaledTile < 0 || posY > canvas.height) return;

            const isOwn = (owner_id === gameConfig.currentKingdom.ownerId);
            const filterPlayers = document.getElementById("filter-players").checked;
            const filterResources = document.getElementById("filter-resources").checked;
            const filterMonsters = document.getElementById("filter-monsters").checked;

            if (kid > 0) {
                if (!filterPlayers) return;
            } else if (kid === -2) {
                if (!filterResources) return;
            } else if (kid === -3) {
                if (!filterMonsters) return;
            } else if (kid === -999) {
                ctx.fillStyle = "rgba(230, 0, 0, 0.1)";
                ctx.fillRect(posX, posY, scaledTile, scaledTile);

                const isEventActive = window.activeEventInfo && window.activeEventInfo.is_active;

                if (!isEventActive) {
                    ctx.strokeStyle = "rgb(230, 0, 0)";
                    ctx.lineWidth = 2;
                    ctx.beginPath();

                    if (x === 49) {
                        ctx.moveTo(posX, posY);
                        ctx.lineTo(posX, posY + scaledTile);
                    }
                    if (x === 51) {
                        ctx.moveTo(posX + scaledTile, posY);
                        ctx.lineTo(posX + scaledTile, posY + scaledTile);
                    }
                    if (y === 49) {
                        ctx.moveTo(posX, posY);
                        ctx.lineTo(posX + scaledTile, posY);
                    }
                    if (y === 51) {
                        ctx.moveTo(posX, posY + scaledTile);
                        ctx.lineTo(posX + scaledTile, posY + scaledTile);
                    }

                    ctx.stroke();
                }
                return;
            }

            if (isOwn) {
                ctx.fillStyle = "rgba(11, 218, 81, 0.2)";
                ctx.fillRect(posX, posY, scaledTile, scaledTile);

                ctx.strokeStyle = "#0BDA51";
                ctx.lineWidth = 1;
                ctx.strokeRect(posX, posY, scaledTile, scaledTile);
            }

            if (kid === -2) {
                ctx.drawImage(images.gems, posX + scaledTile * 0.2, posY + scaledTile * 0.2, scaledTile * 0.6, scaledTile * 0.6);
            } else if (kid === -3) {
                let mIcon = images.monster1;

                if (monsterLevel >= 8) mIcon = images.monster3;
                else if (monsterLevel >= 4) mIcon = images.monster2;

                ctx.drawImage(mIcon, posX + scaledTile * 0.1, posY + scaledTile * 0.1, scaledTile * 0.8, scaledTile * 0.8);
            } else {
                let img = images.house;

                if (level >= 8) img = images.castle;
                else if (level >= 6) img = images.tower2;
                else if (level >= 3) img = images.town;

                ctx.drawImage(img, posX, posY, scaledTile, scaledTile);

                if (isBurning === 1) {
                    ctx.drawImage(images.fire, posX + scaledTile * 0.4, posY - scaledTile * 0.1, scaledTile * 0.7, scaledTile * 0.7);
                }
            }

            if (myTroopIcon && myTroopIcon !== "") {
                const miniIconSize = scaledTile * 0.45;
                const padding = 2;
                const miniX = posX + scaledTile - miniIconSize - padding;
                const miniY = posY + scaledTile - miniIconSize - padding;

                ctx.fillStyle = "rgba(0, 0, 0, 0.6)";
                ctx.fillRect(miniX - 1, miniY - 1, miniIconSize + 2, miniIconSize + 2);

                if (!images[myTroopIcon]) {
                    images[myTroopIcon] = new Image();
                    images[myTroopIcon].src = `images/icons/${myTroopIcon}.png`;
                    images[myTroopIcon].onload = () => draw();
                }

                if (images[myTroopIcon].complete && images[myTroopIcon].naturalWidth !== 0) {
                    ctx.drawImage(images[myTroopIcon], miniX, miniY, miniIconSize, miniIconSize);
                }
            }
        });

        if (window.activeEventInfo && window.activeEventInfo.is_active) {
            const ev = window.activeEventInfo;
            const scaledTile = BASE_TILE_SIZE * zoom;
            const bossPosX = (49 - 1) * scaledTile + currentTranslateX;
            const bossPosY = (49 - 1) * scaledTile + currentTranslateY;
            const bossSize = scaledTile * 3;

            if (!window.eventMonsterImage) {
                window.eventMonsterImage = new Image();
                window.eventMonsterImage.src = `images/icons/${ev.monster_icon}.png`;
                window.eventMonsterImage.onload = () => draw();
            }

            if (window.eventMonsterImage.complete) {
                ctx.drawImage(window.eventMonsterImage, bossPosX, bossPosY, bossSize, bossSize);
            }
        }
    }

    if (selectedX && selectedY) {
        const scaledTile = BASE_TILE_SIZE * zoom;
        const isEventSelection = (selectedX >= 49 && selectedX <= 51 && selectedY >= 49 && selectedY <= 51);

        if (isEventSelection) {
            const sPosX = (49 - 1) * scaledTile + currentTranslateX;
            const sPosY = (49 - 1) * scaledTile + currentTranslateY;
            const sSize = scaledTile * 3;

            ctx.strokeStyle = "#f62222";
            ctx.lineWidth = 3;
            ctx.strokeRect(sPosX + 1, sPosY + 1, sSize - 2, sSize - 2);
        } else {

            const sPosX = (selectedX - 1) * scaledTile + currentTranslateX;
            const sPosY = (selectedY - 1) * scaledTile + currentTranslateY;

            ctx.strokeStyle = "#f62222";
            ctx.lineWidth = 3;

            ctx.strokeRect(sPosX + 1, sPosY + 1, scaledTile - 2, scaledTile - 2);
        }
    }

    if (currentPath.length > 0 && document.getElementById("show-path-toggle")?.checked) {
        drawPath(scaledTile);
    }

    updateCenterCoords();
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
    cancelAnimationFrame(panAnimationID);

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

    const moveX = Math.abs(e.pageX - (startX + currentTranslateX));
    const moveY = Math.abs(e.pageY - (startY + currentTranslateY));

    if (moveX > 2 || moveY > 2) {
        wasDragged = true;
    }

    velocityX = e.pageX - lastMouseX;
    velocityY = e.pageY - lastMouseY;

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
    if (x === selectedX && y === selectedY && !shouldCenter) return;

    selectedX = x;
    selectedY = y;
    draw();

    if (shouldCenter) centerMapOn(x, y);

    const tile = mapData.find(t => t[0] === x && t[1] === y);
    if (!tile) return;

    const [tx, ty, , kid, , , m_lvl, owner, kname, score, ownerId, fieldName, expiresAt] = tile;

    const pathResult = calculatePathLocal(gameConfig.currentKingdom.x, gameConfig.currentKingdom.y, tx, ty);
    currentPath = pathResult.path;

    let baseTravelTime = pathResult.totalTime * gameConfig.currentKingdom.marchMultiplier;
    const now = Math.floor(Date.now() / 1000);

    let html = "";

    if (kid === -1) {
        // --- EMPTY FIELD
        html += `<div class="title-border">${fieldName}</div>`;
        html += `<table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">`;
        html += `<tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>${tx}:${ty}</td></tr>`;
        html += `<tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>${formatTimeJS(Math.round(baseTravelTime))}</td></tr>`;
        html += `<tr><td colspan="2" class="td-mapinfo" style="text-align: center;">`;

        if (gameConfig.currentKingdom.troops[gameConfig.constants.SOLDIER_SETTLER] > 0) {
            html += `<button data-on-click="redirect" data-url="sendtroops.php?x=${tx}&y=${ty}">Erobern</button>`;
        } else {
            html += `<small class="error">Gründungskarren benötigt!</small>`;
        }

        html += `</td></tr></table>`;
    } else if (kid === -2) {
        // --- RESOURCE TILE
        const arrivalScout = Math.round(baseTravelTime * gameConfig.constants.MONSTER_CAMP_SCOUT_BOOST);
        const lifetime = expiresAt - now;

        html += `<div class="title-border">Verlassenes Vorratslager</div>`;
        html += `<table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">`;
        html += `<tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>${tx}:${ty}</td></tr>`;
        html += `<tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>${formatTimeJS(Math.round(baseTravelTime))}<br><small>(${formatTimeJS(arrivalScout)} Spionage)</small></td></tr>`;
        html += `<tr><td class="td-mapinfo"><b>Restzeit</b></td><td>${formatTimeJS(lifetime, false)}</td></tr>`;
        html += `<tr><td colspan="2" class="td-mapinfo" style="text-align: center;">`;

        const canPlunder = gameConfig.currentKingdom.troops[gameConfig.constants.SOLDIER_RAIDER] > 0;
        const canSpy = gameConfig.currentKingdom.troops[gameConfig.constants.SOLDIER_SCOUT] > 0;

        if (canPlunder || canSpy) {
            const mode = canPlunder ? "plunder" : "spy";
            const label = canPlunder ? "Plündern" : "Spionieren";
            html += `<button data-on-click="redirect" data-url="sendtroops.php?x=${tx}&y=${ty}&mode=${mode}">${label}</button>`;
        } else {
            html += `<small class="error">Räuber oder Späher benötigt!</small>`;
        }

        html += `</td></tr></table>`;
    } else if (kid === -3) {
        // --- MONSTER CAMP
        const travelMonster = baseTravelTime * gameConfig.constants.MONSTER_CAMP_TRAVEL_BOOST;
        const arrivalScout = (travelMonster / gameConfig.constants.MONSTER_CAMP_TRAVEL_BOOST) * gameConfig.constants.MONSTER_CAMP_SCOUT_BOOST;
        const lifetime = expiresAt - now;

        html += `<div class="title-border">Monstercamp (Stufe ${m_lvl})</div>`;
        html += `<table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">`;
        html += `<tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>${tx}:${ty}</td></tr>`;
        html += `<tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>${formatTimeJS(Math.round(travelMonster))}<br><small>(${formatTimeJS(Math.round(arrivalScout))} Spionage)</small></td></tr>`;
        html += `<tr><td class="td-mapinfo"><b>Restzeit</b></td><td>${formatTimeJS(lifetime, false)}</td></tr>`;
        html += `<tr><td colspan="2" class="td-mapinfo" style="text-align: center;">`;
        html += `<button data-on-click="redirect" data-url="sendtroops.php?x=${tx}&y=${ty}">Camp angreifen</button>`;
        html += `</td></tr></table>`;
    } else if (kid === -999) {
        // --- EVENT CENTER
        const isEventActive = window.activeEventInfo && window.activeEventInfo.is_active;

        if (isEventActive) {
            const event_data = window.activeEventInfo;
            const type_label = (window.activeEventInfo.type === "BOSS_HP") ? "Weltenboss" : "Großer Angriff";
            const target_url = `sendtroops.php?x=${tx}&y=${ty}`;
            const time_left = event_data.end_time - Math.floor(Date.now() / 1000);
            const disabled = event_data.current_hp <= 0 && (window.activeEventInfo.type === "BOSS_HP") ? "disabled" : "";

            html += `<div class="title-border">${type_label}</div>`;
            html += `<table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">`;
            html += `<tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>${tx}:${ty}</td></tr>`;
            html += `<tr><td class="td-mapinfo"><b>Endet in</b></td><td><span class="js-countdown" data-seconds="${time_left}">-</span></td></tr>`;

            if (event_data.type === "BOSS_HP") {
                let hpRawPercent = event_data.total_hp > 0 ? (event_data.current_hp / event_data.total_hp) * 100 : 0;
                let hpDisplayText, barWidth;

                if (event_data.current_hp > 0 && hpRawPercent < 0.1) {
                    hpDisplayText = "< 0.1";
                    barWidth = 0.5;
                } else {
                    hpDisplayText = Math.round(hpRawPercent * 10) / 10;
                    barWidth = hpDisplayText;
                }

                const formattedHp = typeof formatNumJS === "function" ? formatNumJS(event_data.current_hp) : event_data.current_hp;

                html += `<tr><td class="td-mapinfo"><b>Status</b></td><td>`;

                if (event_data.current_hp > 0) {
                    html += `<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                                <span style="display: inline-flex; align-items: center; gap: 5px;">
                                    <img src="images/icons/icon_health.png" class="ressource-icons" alt="HP" style="margin: 0; width: 16px; height: 16px;"> 
                                    <span>${formattedHp} / ${formatNumJS(event_data.total_hp)}</span>
                                </span>
                                <span>${hpDisplayText}%</span>
                            </div>
                            <div style="width: 100%; height: 12px; background: #333; border: 1px solid var(--border-gold); border-radius: 3px; overflow: hidden;">
                                <div style="width: ${barWidth}%; height: 100%; background: linear-gradient(90deg, #a62121, #ff4d4d);"></div>
                            </div>`;
                } else {
                    html += `<span class="passed">BESIEGT</span>`;
                }

                html += `</td></tr>`;
            }

            html += `<tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>30 Sek.</td></tr>`;
            html += `<tr><td colspan="2" class="td-mapinfo" style="text-align: center;">`;
            html += `<button data-on-click="redirect" data-url="${target_url}" ${disabled}>In die Schlacht!</button>`;
            html += `<p style='font-size: 13px; margin-top: 10px;'><i>Hinweis: Truppen kehren von Welt-Events immer ohne Verluste heim.</i></p></td></tr></table>`;
        } else {
            html += `<div class="title-border">Das Auge des Sturms</div>`;
            html += `<table class="table" style="margin-top: 20px; max-width: 500px;">`;
            html += `<tr><td class="td-mapinfo"><b>Status</b></td><td>Versiegelt</td></tr>`;
            html += `<tr><td colspan="2" style="text-align: center; padding: 15px;">`;
            html += `<i>Truppen können diesen Bereich aktuell nicht betreten.</i>`;
            html += `</td></tr></table>`;
        }
    } else {
        // --- PLAYER KINGDOM
        const scoreIcon = `<img src="../images/icons/icon_score.png" class="ressource-icons" alt="">`;
        const ownerDisplay = `<a href="#" data-on-click="openOverlay" data-url="userinfo.php?userid=${ownerId}" data-title="Spieler-Info">${owner}</a>`;

        html += `<div class="title-border">Königreich-Info</div>`;
        html += `<table class="table" style="margin-top: 20px; max-width: 500px; text-align: left;">`;
        html += `<tr><td class="td-mapinfo"><b>Koordinaten</b></td><td>${tx}:${ty}</td></tr>`;
        html += `<tr><td class="td-mapinfo"><b>Königreich</b></td><td>${kname}</td></tr>`;
        html += `<tr><td class="td-mapinfo"><b>Besitzer</b></td><td>${ownerDisplay} ${scoreIcon} ${score.toLocaleString()}</td></tr>`;

        if (kid !== gameConfig.currentKingdom.id) {
            html += `<tr><td class="td-mapinfo"><b>Ankunftszeit</b></td><td>${formatTimeJS(Math.round(baseTravelTime))}</td></tr>`;
            const btnText = (ownerId === gameConfig.currentKingdom.ownerId) ? "Stationieren" : "Angreifen";
            html += `<tr><td colspan="2" class="td-mapinfo" style="text-align: center;">`;
            html += `<button data-on-click="redirect" data-url="sendtroops.php?x=${tx}&y=${ty}">${btnText}</button>`;
            html += `</td></tr>`;
        }

        html += `</table>`;
    }

    document.getElementById("field-info").innerHTML = html;

    const fieldInfoEl = document.getElementById("field-info");
    if (fieldInfoEl) {
        fieldInfoEl.innerHTML = html;

        if (typeof initAutomaticCountdowns === "function") {
            initAutomaticCountdowns();
        }
    }

    draw();
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

function centerMapOn(x, y, immediate = false) {
    const scaledTile = BASE_TILE_SIZE * zoom;
    const scaledSize = scaledTile * MAP_DIMENSION;

    let targetTX = (viewport.offsetWidth / 2) - (x - 0.5) * scaledTile;
    let targetTY = (viewport.offsetHeight / 2) - (y - 0.5) * scaledTile;

    const minX = viewport.offsetWidth - scaledSize;
    const minY = viewport.offsetHeight - scaledSize;

    if (scaledSize < viewport.offsetWidth) {
        targetTX = (viewport.offsetWidth - scaledSize) / 2;
    } else {
        targetTX = Math.min(0, Math.max(targetTX, minX));
    }

    if (scaledSize < viewport.offsetHeight) {
        targetTY = (viewport.offsetHeight - scaledSize) / 2;
    } else {
        targetTY = Math.min(0, Math.max(targetTY, minY));
    }

    if (immediate) {
        cancelAnimationFrame(panAnimationID);

        currentTranslateX = targetTX;
        currentTranslateY = targetTY;

        clampMapPosition();
        draw();
        return;
    }

    const animate = () => {
        const dx = targetTX - currentTranslateX;
        const dy = targetTY - currentTranslateY;

        if (Math.abs(dx) < 0.1 && Math.abs(dy) < 0.1) {
            currentTranslateX = targetTX;
            currentTranslateY = targetTY;

            clampMapPosition();
            draw();
            return;
        }

        currentTranslateX += dx * 0.15;
        currentTranslateY += dy * 0.15;

        clampMapPosition();
        draw();
        panAnimationID = requestAnimationFrame(animate);
    };

    cancelAnimationFrame(panAnimationID);
    panAnimationID = requestAnimationFrame(animate);
}

function updateCenterCoords() {
    const coordsDisplay = document.getElementById("coords-display");
    if (!coordsDisplay || !mapData.length) return;

    const scaledTile = BASE_TILE_SIZE * zoom;

    const centerX = viewport.offsetWidth / 2;
    const centerY = viewport.offsetHeight / 2;

    const tx = Math.floor((centerX - currentTranslateX) / scaledTile) + 1;
    const ty = Math.floor((centerY - currentTranslateY) / scaledTile) + 1;

    if (tx >= 1 && tx <= MAX_X && ty >= 1 && ty <= MAX_Y) {
        coordsDisplay.innerText = `X: ${tx} | Y: ${ty}`;
    }
}

function formatTimeJS(s, showSeconds = true) {
    if (s <= 0) return "0s";
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;

    let parts = [];
    if (d > 0) parts.push(d + "T");
    if (h > 0) parts.push(h + " Std.");
    if (m > 0) parts.push(m + " Min.");
    if (showSeconds && (sec > 0 || parts.length === 0)) parts.push(sec + " Sek.");

    return parts.join(" ");
}

function calculatePathLocal(sx, sy, ex, ey) {
    if (Object.keys(costGrid).length === 0) {
        mapData.forEach(tile => {
            if (!costGrid[tile[0]]) costGrid[tile[0]] = {};
            costGrid[tile[0]][tile[1]] = tile[2];
        });
    }

    const start = {x: sx, y: sy};
    const end = {x: ex, y: ey};

    let openList = [];
    let closedList = new Set();
    let nodes = new Map();

    const encode = (p) => `${p.x},${p.y}`;
    const getCost = (p) => {
        const type = costGrid[p.x] ? costGrid[p.x][p.y] : 5;
        return gameConfig.fieldMeta[type] || 100;
    };

    const startKey = encode(start);
    nodes.set(startKey, {g: 0, f: Math.abs(sx - ex) + Math.abs(sy - ey), x: sx, y: sy, parent: null});
    openList.push(nodes.get(startKey));

    while (openList.length > 0) {
        openList.sort((a, b) => a.f - b.f);
        let current = openList.shift();

        if (current.x === end.x && current.y === end.y) {
            let path = [];
            let totalTime = current.g;
            let temp = current;
            while (temp) {
                path.push({x: temp.x, y: temp.y});
                temp = temp.parent;
            }
            return {path: path.reverse(), totalTime: totalTime};
        }

        closedList.add(encode(current));

        const neighbors = [
            {x: current.x, y: current.y + 1}, {x: current.x, y: current.y - 1},
            {x: current.x + 1, y: current.y}, {x: current.x - 1, y: current.y}
        ];

        for (let n of neighbors) {
            if (n.x < 1 || n.x > 100 || n.y < 1 || n.y > 100) continue;
            if (closedList.has(encode(n))) continue;

            let moveCost = getCost(n);
            let gScore = current.g + moveCost;
            let nKey = encode(n);
            let neighborNode = nodes.get(nKey);

            if (!neighborNode || gScore < neighborNode.g) {
                let h = Math.abs(n.x - end.x) + Math.abs(n.y - end.y);
                let newNode = {g: gScore, f: gScore + h, x: n.x, y: n.y, parent: current};
                nodes.set(nKey, newNode);
                if (!neighborNode) openList.push(newNode);
            }
        }
    }
    return {path: [], totalTime: 0};
}

function jumpTo(x, y) {
    x = parseInt(x);
    y = parseInt(y);

    if (x >= 1 && x <= MAX_X && y >= 1 && y <= MAX_Y) {
        document.getElementById("startx").value = x;
        document.getElementById("starty").value = y;

        cancelAnimationFrame(momentumID);
        cancelAnimationFrame(panAnimationID);
        velocityX = 0;
        velocityY = 0;

        if (mapData.length === 0) {
            setTimeout(() => jumpTo(x, y), 100);
            return;
        }

        selectField(x, y, true);
    }
}

window.jumpToCoordinates = jumpTo;
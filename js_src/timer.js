const countdownRegistry = new Map();
let masterTimerInterval = null;

function formatTime(totalSeconds) {
    if (totalSeconds <= 0) return "00:00";

    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = Math.floor(totalSeconds % 60);

    const hDisplay = String(hours).padStart(2, '0');
    const mDisplay = String(minutes).padStart(2, '0');
    const sDisplay = String(seconds).padStart(2, '0');

    if (days > 0) {
        return days + "T " + hDisplay + ":" + mDisplay + ":" + sDisplay;
    } else if (hours > 0) {
        return hDisplay + ":" + mDisplay + ":" + sDisplay;
    } else {
        return mDisplay + ":" + sDisplay;
    }
}

function startCountdown(target, initialSeconds, timerType = 0, hideID = null, keepParams = false, noReload = false) {
    let el = (typeof target === "string") ? document.getElementById(target) : target;
    if (!el) return;

    if (!el.id) {
        el.id = "cd-" + Math.random().toString(36).substring(2, 11);
    }
    const key = el.id;

    const seconds = parseInt(initialSeconds);
    if (isNaN(seconds)) return;

    const endTime = Date.now() + (seconds * 1000);

    if (countdownRegistry.has(key)) {
        const existing = countdownRegistry.get(key);

        if (Math.abs(existing.endTime - endTime) < 1500) return;
    }

    const msLeftInitial = endTime - Date.now();
    const secLeftInitial = Math.ceil(msLeftInitial / 1000);

    if (msLeftInitial <= 0) {
        el.textContent = (timerType === 0) ? "Fertig!" : "00:00";
    } else {
        el.textContent = formatTime(secLeftInitial);
    }

    countdownRegistry.set(key, {
        element: el,
        endTime: endTime,
        timerType: timerType,
        hideID: hideID,
        keepParams: keepParams,
        noReload: noReload,
        lastTickValue: secLeftInitial
    });

    if (!masterTimerInterval) {
        startMasterTimer();
    }
}

function startMasterTimer() {
    masterTimerInterval = setInterval(() => {
        const now = Date.now();
        let needsReload = false;
        let anyKeepParams = false;
        let anyTimerActive = false;

        countdownRegistry.forEach((timer, key) => {
            const msLeft = timer.endTime - now;
            const secLeft = Math.ceil(msLeft / 1000);

            if (msLeft <= 0) {
                timer.element.textContent = (timer.timerType === 0) ? "Fertig!" : "00:00";

                if (timer.hideID) {
                    const hideEl = document.getElementById(timer.hideID);
                    if (hideEl) hideEl.style.display = "none";
                }

                if (!timer.noReload) {
                    const timerCat = timer.element.dataset.timerCat;

                    if (timerCat !== undefined) {
                        const isListView = document.cookie.includes("me_list_view=1");

                        const activeTabEl = document.querySelector("#barracks-tabs-standard .tablinks.active");
                        const domCat = activeTabEl ? activeTabEl.dataset.category : null;
                        const urlCat = new URLSearchParams(window.location.search).get("cat");

                        const currentCat = urlCat !== null ? urlCat : (domCat !== null ? domCat : "0");

                        if (isListView || currentCat === timerCat || !document.getElementById("barracks-tabs-standard")) {
                            needsReload = true;
                            anyKeepParams = true;
                        }
                    } else {
                        needsReload = true;

                        if (timer.keepParams) anyKeepParams = true;
                    }
                }

                countdownRegistry.delete(key);
            } else {
                if (timer.lastTickValue !== secLeft) {
                    timer.element.textContent = formatTime(secLeft);
                    timer.lastTickValue = secLeft;
                }

                anyTimerActive = true;
            }
        });

        if (!anyTimerActive) {
            clearInterval(masterTimerInterval);
            masterTimerInterval = null;
        }

        if (needsReload) {
            needsReload = false;

            setTimeout(() => {
                if (anyKeepParams || window.location.search.includes("logpage")) {
                    location.reload();
                } else {
                    window.location.href = window.location.pathname;
                }
            }, 1000);
        }
    }, 500);
}

function startCountup(target, initialSeconds) {
    let seconds = parseInt(initialSeconds);
    const el = (typeof target === "string") ? document.getElementById(target) : target;
    if (!el) return;

    const startTime = Date.now() - (seconds * 1000);

    function update() {
        const now = Date.now();
        const diff = Math.floor((now - startTime) / 1000);

        let hours = Math.floor(diff / 3600);
        let minutes = Math.floor((diff % 3600) / 60);
        let secs = diff % 60;

        el.textContent =
            String(hours).padStart(2, '0') + ":" +
            String(minutes).padStart(2, '0') + ":" +
            String(secs).padStart(2, '0');
    }

    update();
    setInterval(update, 1000);
}

function initMineProgress() {
    const mineElements = document.querySelectorAll('.js-mine-progress');
    if (mineElements.length === 0) return;

    const clientStartTime = Date.now();

    setInterval(() => {
        const now = Date.now();
        const elapsedSec = (now - clientStartTime) / 1000;

        mineElements.forEach(el => {
            const workDone = parseFloat(el.dataset.workDone) || 0;
            const workTotal = parseFloat(el.dataset.workTotal) || 1;
            const rate = parseFloat(el.dataset.rate) || 0;

            if (rate <= 0) return;

            const currentWork = Math.min(workTotal, workDone + (elapsedSec * rate));
            const percent = Math.min(100, (currentWork / workTotal) * 100);

            el.textContent = percent.toFixed(1).replace('.', ',') + " %";

            const cell = el.closest('td');
            if (cell) {
                const bar = cell.querySelector('.js-mine-progress-bar');
                if (bar) {
                    bar.style.width = Math.min(100, percent) + "%";
                }
            }
        });
    }, 1000);
}

document.addEventListener("DOMContentLoaded", () => {
    const el = document.getElementById("login-counter");

    if (el) {
        startCountup(el, parseInt(el.dataset.start));
    }

    initMineProgress();
});

document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") {
        const now = Date.now();

        countdownRegistry.forEach((timer) => {
            const msLeft = timer.endTime - now;
            const secLeft = Math.ceil(msLeft / 1000);

            if (msLeft > 0) {
                timer.element.textContent = formatTime(secLeft);
                timer.lastTickValue = secLeft;
            }
        });
    }
});
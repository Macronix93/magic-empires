const countdownRegistry = new Map();

function onQueueUnitFinished(element) {
    const villCost = parseInt(element.dataset.villagerCost, 10) || 0;

    if (villCost > 0) {
        document.querySelectorAll("img[src*='icon_villager']").forEach(img => {
            const container = img.closest('.split-content');

            if (container) {
                const span = container.querySelector("span");

                if (span && span.innerText.includes("/")) {
                    const parts = span.innerText.split("/");
                    const currentVal = parseInt(parts[0].replace(/\D/g, ''), 10) || 0;
                    const maxVal = parseInt(parts[1].replace(/\D/g, ''), 10) || 0;
                    const newVal = Math.max(0, currentVal - villCost);

                    span.innerText = `${newVal.toLocaleString("de-DE")} / ${parts[1].trim()}`;

                    if (maxVal > 0) {
                        span.className = (newVal >= maxVal) ? "over-limit" : "under-limit";
                    }
                }
            }
        });
    }

    const soldierId = element.dataset.producedSoldierId;
    if (soldierId) {
        document.querySelectorAll(`.js-unit-owned-count[data-soldier-id='${soldierId}']`).forEach(span => {
            const current = parseInt(span.innerText.replace(/\D/g, ''), 10) || 0;
            const updated = current + 1;
            span.innerText = (typeof formatNumJS === "function") ? formatNumJS(updated) : updated.toLocaleString("de-DE");
        });
    }
}

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

function startCountdown(target, initialSeconds, timerType = 0, hideID = null, keepParams = false, noReload = false, referenceNow = null) {
    let el = (typeof target === "string") ? document.getElementById(target) : target;
    if (!el) return;

    if (!el.id) {
        el.id = "cd-" + Math.random().toString(36).substring(2, 11);
    }

    const key = el.id;
    const seconds = parseInt(initialSeconds, 10);
    if (isNaN(seconds)) return;

    const anchor = window.ME_CLOCK_ANCHOR ?? Date.now();
    const now = referenceNow ?? Date.now();
    const alignedNow = anchor + Math.round((now - anchor) / 1000) * 1000;
    const endTime = alignedNow + (seconds * 1000);

    if (countdownRegistry.has(key)) {
        const existing = countdownRegistry.get(key);
        if (Math.abs(existing.endTime - endTime) < 1500) return;
    }

    const msLeftInitial = endTime - Date.now();
    const secLeftInitial = Math.min(seconds, Math.max(0, Math.ceil(msLeftInitial / 1000)));
    el.textContent = msLeftInitial <= 0
        ? (el.dataset.zeroText || (timerType === 0 ? "Fertig!" : "00:00"))
        : formatTime(secLeftInitial);

    countdownRegistry.set(key, {
        element: el,
        endTime: endTime,
        timerType: timerType,
        hideID: hideID,
        keepParams: keepParams,
        noReload: noReload,
        lastTickValue: secLeftInitial
    });

    if (typeof window.registerClockTick === "function") {
        window.registerClockTick(tickAllCountdowns);
    }
}

function tickAllCountdowns(now = Date.now()) {
    let needsReload = false;
    let anyKeepParams = false;
    let anyTimerActive = false;

    countdownRegistry.forEach((timer, key) => {
        if (!document.contains(timer.element)) {
            countdownRegistry.delete(key);
            return;
        }
        const msLeft = timer.endTime - now;
        const secLeft = Math.ceil(msLeft / 1000);

        if (msLeft <= 0) {
            const unitTime = parseInt(timer.element.dataset.unitTime, 10) || 0;
            let goal = parseInt(timer.element.dataset.goal, 10) || 0;

            if (unitTime > 0 && goal > 1) {
                const unitsPassed = Math.floor(Math.abs(msLeft) / (unitTime * 1000)) + 1;
                if (unitsPassed < goal) {
                    anyTimerActive = true;
                    goal -= unitsPassed;
                    timer.element.dataset.goal = goal.toString();

                    const goalEl = document.getElementById(timer.element.dataset.goalTarget);
                    if (goalEl) goalEl.innerText = goal.toString();

                    for (let u = 0; u < unitsPassed; u++) {
                        onQueueUnitFinished(timer.element);
                    }

                    timer.endTime += unitsPassed * unitTime * 1000;
                    const newSecLeft = Math.max(1, Math.ceil((timer.endTime - now) / 1000));
                    timer.lastTickValue = newSecLeft;
                    timer.element.textContent = formatTime(newSecLeft);
                    return;
                }

                for (let u = 0; u < goal; u++) {
                    onQueueUnitFinished(timer.element);
                }

                goal = 0;
            }

            if (unitTime > 0 && goal === 1) {
                onQueueUnitFinished(timer.element);
            }

            timer.element.textContent = timer.element.dataset.zeroText || (timer.timerType === 0 ? "Fertig!" : "00:00");
            if (timer.element.id === "counter") {
                const errorBox = timer.element.closest('.event-error, .info-box');
                if (errorBox) errorBox.style.display = "none";
                document.dispatchEvent(new CustomEvent("chatRateLimitEnded"));
            }

            if (timer.hideID) {
                const hideEl = document.getElementById(timer.hideID);
                if (hideEl) hideEl.style.display = "none";
            }

            const cell = timer.element.closest('td, .td-timer-cell');
            cell?.querySelector('.delete-btn')?.remove();

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

    if (!anyTimerActive && typeof window.unregisterClockTick === "function") {
        window.unregisterClockTick(tickAllCountdowns);
    }

    if (needsReload) {
        setTimeout(() => {
            if (anyKeepParams || window.location.search.includes("logpage")) {
                location.reload();
            } else {
                window.location.href = window.location.pathname;
            }
        }, 1000);
    }
}

function stopCountdown(target) {
    let el = (typeof target === "string") ? document.getElementById(target) : target;
    const key = el ? el.id : target;
    if (key && countdownRegistry.has(key)) {
        countdownRegistry.delete(key);
    }
    if (countdownRegistry.size === 0 && typeof window.unregisterClockTick === "function") {
        window.unregisterClockTick(tickAllCountdowns);
    }
}

window.stopCountdown = stopCountdown;

function startCountup(target, initialSeconds) {
    let seconds = parseInt(initialSeconds, 10) || 0;
    const el = (typeof target === "string") ? document.getElementById(target) : target;
    if (!el) return;

    const startTime = (window.ME_CLOCK_ANCHOR ?? Date.now()) - (seconds * 1000);

    function update(now = Date.now()) {
        if (!document.contains(el)) {
            window.unregisterClockTick?.(update);
            return;
        }
        const diff = Math.max(0, Math.floor((now - startTime) / 1000));
        const hours = Math.floor(diff / 3600);
        const minutes = Math.floor((diff % 3600) / 60);
        const secs = diff % 60;

        el.textContent =
            String(hours).padStart(2, '0') + ":" +
            String(minutes).padStart(2, '0') + ":" +
            String(secs).padStart(2, '0');
    }

    update();
    window.registerClockTick?.(update);
}

function initMineProgress() {
    const mineElements = document.querySelectorAll('.js-mine-progress');
    if (mineElements.length === 0) return;

    const clientStartTime = window.ME_CLOCK_ANCHOR ?? Date.now();

    const formatPercent = (num) => {
        let fixed = num.toFixed(1);
        if (fixed.endsWith('.0')) {
            fixed = fixed.slice(0, -2);
        }
        return fixed.replace('.', ',') + " %";
    };

    function updateMines(now = Date.now()) {
        const elapsedSec = Math.max(0, (now - clientStartTime) / 1000);
        mineElements.forEach(el => {
            const workDone = parseFloat(el.dataset.workDone) || 0;
            const workTotal = parseFloat(el.dataset.workTotal) || 1;
            const rate = parseFloat(el.dataset.rate) || 0;
            if (rate <= 0) return;

            const currentWork = Math.min(workTotal, workDone + (elapsedSec * rate));
            const percent = Math.min(100, (currentWork / workTotal) * 100);
            el.textContent = formatPercent(percent);

            const cell = el.closest('td');
            const bar = cell?.querySelector('.js-mine-progress-bar');
            if (bar) bar.style.width = Math.min(100, percent) + "%";
        });
    }

    window.registerClockTick?.(updateMines);
}

document.addEventListener("DOMContentLoaded", () => {
    const el = document.getElementById("login-counter");
    if (el) {
        startCountup(el, parseInt(el.dataset.start, 10));
    }
    initMineProgress();
});
const activeCountdowns = {};

function formatTime(totalSeconds) {
    if (totalSeconds < 0) totalSeconds = 0;

    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = Math.floor(totalSeconds % 60);

    const hDisplay = String(hours).padStart(2, '0');
    const mDisplay = String(minutes).padStart(2, '0');
    const sDisplay = String(seconds).padStart(2, '0');

    if (days > 0) {
        return `${days}T ${hDisplay}:${mDisplay}:${sDisplay}`;
    } else if (hours > 0) {
        return `${hDisplay}:${mDisplay}:${sDisplay}`;
    } else {
        return `${mDisplay}:${sDisplay}`;
    }
}

function startCountdown(target, initialSeconds, timerType = 0, hideID = null,
                        keepParams = false, noReload = false) {
    let el = (typeof target === "string") ? document.getElementById(target) : target;
    if (!el) return;

    if (!el.id) {
        el.id = "cd-" + Math.random().toString(36).substr(2, 9);
    }
    const key = el.id;

    const seconds = parseInt(initialSeconds);
    if (isNaN(seconds)) return;

    const endTime = Date.now() + (seconds * 1000);

    if (activeCountdowns[key]) {
        const timeDiff = Math.abs(activeCountdowns[key].endTime - endTime);
        if (timeDiff < 1500) {
            return;
        }
        clearInterval(activeCountdowns[key].interval);
    }

    const update = () => {
        const now = Date.now();
        const msLeft = endTime - now;
        const secLeft = Math.ceil(msLeft / 1000);

        if (msLeft <= 0) {
            clearInterval(activeCountdowns[key].interval);
            delete activeCountdowns[key];
            el.textContent = (timerType === 0) ? "Fertig!" : "00:00";

            if (hideID) {
                const hideEl = document.getElementById(hideID);
                if (hideEl) hideEl.style.display = "none";
            }

            if (!noReload) {
                setTimeout(() => {
                    if (keepParams) location.reload();
                    else window.location.href = window.location.pathname;
                }, 1000);
            }
            return;
        }

        el.textContent = formatTime(secLeft);
    };

    update();
    activeCountdowns[key] = {
        interval: setInterval(update, 200),
        endTime: endTime
    };
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

document.addEventListener("DOMContentLoaded", () => {
    const el = document.getElementById("login-counter");

    if (el) {
        startCountup(el, parseInt(el.dataset.start));
    }
});
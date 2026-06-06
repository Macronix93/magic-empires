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
    let counterElement;
    let internalKey;

    if (typeof target === "string") {
        counterElement = document.getElementById(target);
        internalKey = target;
    } else {
        counterElement = target;

        if (!counterElement.id) {
            counterElement.id = "cd-" + Math.random().toString(36).substr(2, 9);
        }
        internalKey = counterElement.id;
    }

    if (!counterElement) return;

    let seconds = parseInt(initialSeconds);

    if (activeCountdowns[internalKey]) {
        if (seconds <= activeCountdowns[internalKey + "_seconds"]) {
            return;
        }
        clearInterval(activeCountdowns[internalKey]);
    }

    function countDown() {
        activeCountdowns[internalKey + "_seconds"] = seconds;
        counterElement.textContent = formatTime(seconds);

        if (seconds <= 0) {
            clearInterval(activeCountdowns[internalKey]);
            delete activeCountdowns[internalKey];

            if (timerType === 0) {
                counterElement.textContent = "Fertig!";
            }

            if (hideID) {
                const elementToHide = document.getElementById(hideID);
                if (elementToHide) elementToHide.style.display = 'none';
            }

            if (!noReload) {
                setTimeout(() => {
                    if (keepParams) {
                        location.reload();
                    } else {
                        window.location.href = window.location.pathname;
                    }
                }, 1000);
            }
        } else {
            seconds--;
        }
    }

    countDown();
    activeCountdowns[internalKey] = setInterval(countDown, 1000);
}

function startCountup(target, initialSeconds) {
    let seconds = initialSeconds;

    const el = (typeof target === "string") ? document.getElementById(target) : target;

    if (!el) return;

    function countUp() {
        let hours = Math.floor(seconds / 3600);
        let minutes = Math.floor((seconds % 3600) / 60);
        let remainingSeconds = seconds % 60;

        hours = (hours < 10) ? "0" + hours : hours;
        minutes = (minutes < 10) ? "0" + minutes : minutes;
        remainingSeconds = (remainingSeconds < 10) ? "0" + remainingSeconds : remainingSeconds;

        el.textContent = `${hours}:${minutes}:${remainingSeconds}`;
        seconds++;
    }

    countUp();
    setInterval(countUp, 1000);
}

document.addEventListener("DOMContentLoaded", () => {
    const el = document.getElementById("login-counter");

    if (el) {
        startCountup(el, parseInt(el.dataset.start));
    }
});
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

function startCountdown(counterID = "counter", initialSeconds, timerType = 0, hideID = null,
                        keepParams = false) {
    let seconds = parseInt(initialSeconds);

    if (activeCountdowns[counterID]) {
        if (seconds <= activeCountdowns[counterID + "_seconds"]) {
            return;
        }

        clearInterval(activeCountdowns[counterID]);
    }

    function countDown() {
        const counterElement = document.getElementById(counterID);

        if (!counterElement) {
            clearInterval(activeCountdowns[counterID]);
            delete activeCountdowns[counterID];
            delete activeCountdowns[counterID + "_seconds"];
            return;
        }
        
        activeCountdowns[counterID + "_seconds"] = seconds;

        counterElement.innerHTML = formatTime(seconds);

        if (seconds <= 0) {
            clearInterval(activeCountdowns[counterID]);
            delete activeCountdowns[counterID];
            delete activeCountdowns[counterID + "_seconds"];

            if (timerType === 0) {
                counterElement.innerHTML = "Fertig!";
            }

            if (hideID) {
                const elementToHide = document.getElementById(hideID);
                if (elementToHide) elementToHide.style.display = 'none';
            }

            setTimeout(() => {
                if (keepParams) {
                    location.reload();
                } else {
                    window.location.href = window.location.pathname;
                }
            }, 1000);
        } else {
            seconds--;
        }
    }

    countDown();
    activeCountdowns[counterID] = setInterval(countDown, 1000);
}

function startCountup(initialSeconds) {
    let seconds = initialSeconds;

    function countUp() {
        let hours = Math.floor(seconds / 3600);
        let minutes = Math.floor((seconds % 3600) / 60);
        let remainingSeconds = seconds % 60;

        hours = (hours < 10) ? "0" + hours : hours;
        minutes = (minutes < 10) ? "0" + minutes : minutes;
        remainingSeconds = (remainingSeconds < 10) ? "0" + remainingSeconds : remainingSeconds;

        document.getElementById("counter").innerHTML = hours + ":" + minutes + ":" + remainingSeconds;

        seconds++;
    }

    // Initial call to set up the countup
    countUp();
    setInterval(countUp, 1000);
    return false;
}
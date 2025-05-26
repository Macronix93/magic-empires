function formatNumber(seconds) {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = seconds % 60;

    // Format hours, minutes, and seconds to be two digits
    const formattedHours = (hours < 10) ? "0" + hours : hours;
    const formattedMinutes = (minutes < 10) ? "0" + minutes : minutes;
    const formattedSeconds = (remainingSeconds < 10) ? "0" + remainingSeconds : remainingSeconds;

    return formattedHours + ":" + formattedMinutes + ":" + formattedSeconds;
}

function startCountdown(counterID = "counter", initialSeconds, timerType = 0) {
    let seconds = initialSeconds;
    let countdownInterval;

    function countDown() {
        const counterElement = document.getElementById(counterID);

        if (counterElement) {
            counterElement.innerHTML = formatNumber(seconds);
        }

        if (seconds <= 0) {
            clearInterval(countdownInterval);
            if (timerType === 0) {
                counterElement.innerHTML = "Fertig!";
            }
        } else {
            seconds--;
        }
    }

    // Initial call to set up the countdown
    countDown();

    countdownInterval = setInterval(countDown, 1000);
    return formatNumber(seconds + 1);
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
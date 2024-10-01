function startCountdown(initialSeconds, timerType = 0) {
    let seconds = initialSeconds;
    let countdownInterval;

    function countDown() {
        let minutes = Math.floor(seconds / 60);
        let remainingSeconds = seconds % 60;

        minutes = (minutes < 10) ? "0" + minutes : minutes;
        remainingSeconds = (remainingSeconds < 10) ? "0" + remainingSeconds : remainingSeconds;

        document.getElementById("counter").innerHTML = minutes + ":" + remainingSeconds;

        if (seconds <= 0) {
            clearInterval(countdownInterval);
            if (timerType === 0) {
                document.getElementById("counter").innerHTML = "Fertig!";
            }
        } else {
            seconds--;
        }
    }

    // Initial call to set up the countdown
    countDown();

    countdownInterval = setInterval(countDown, 1000);
    return false;
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
let mainWindow = window.opener;
const popupWidth = 740;
const popupHeight = 400;

function openPopup(url) {
    // Calculate the monitor where the browser is located
    const currentMonitor = screen.width * window.screenLeft / screen.width;

    // Calculate left position to open the popup on the same monitor
    const left = currentMonitor + (screen.width / 2) - (popupWidth / 2);
    const top = (screen.height - popupHeight) / 2;

    window.open(url, "popup", `scrollbars=yes, width=${popupWidth}, height=${popupHeight}, left=${left}, top=${top}`);
}

function redirectToMap(x, y) {
    if (mainWindow === null || mainWindow.closed) {
        mainWindow = window.open("map.php?startx=" + x + "&starty=" + y, "mainWindow");
    } else {
        let url = mainWindow.location.href;

        if (url.includes("magic-empires")) {
            if (url.includes("map.php")) {
                mainWindow.document.getElementById('startx').value = x;
                mainWindow.document.getElementById('starty').value = y;
                sendUpdateMapRequest();
            } else {
                mainWindow.location.href = "map.php?startx=" + x + "&starty=" + y;
            }
        } else {
            mainWindow.location.href = "map.php?startx=" + x + "&starty=" + y;
        }
    }
}
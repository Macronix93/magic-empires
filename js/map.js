function sendUpdateMapRequest() {
    let startX, startY, inputX, inputY;
    /** @type {HTMLInputElement} */
    let startXField = document.getElementById("startx");
    /** @type {HTMLInputElement} */
    let startYField = document.getElementById("starty");
    const metaTag = document.querySelector('meta[data-max-map-size]');
    /** @type {{ maxMapSize: number }} */
    const jsonData = metaTag ? JSON.parse(metaTag.getAttribute('data-max-map-size')) : {maxMapSize: 0};

    if (startXField && startXField.value) {
        startX = inputX = startXField.value;
    }
    if (startYField && startYField.value) {
        startY = inputY = startYField.value;
    }

    // Check if input values are out of bounds
    if (startX <= 0 || startX > jsonData.maxMapSize || startY <= 0 || startY > jsonData.maxMapSize) {
        return;
    }

    if (startX !== undefined && startY !== undefined) {
        startX = Math.max(1, Math.min(parseInt(startX) - 5, 91));
        startY = Math.max(1, Math.min(parseInt(startY) - 5, 91));

        updateMap(startX, startY, inputX, inputY);
    }
}

function updateMap(newStartX, newStartY, inputX, inputY) {
    fetch(`ajax/map_update.php?startx=${newStartX}&starty=${newStartY}`, {
        method: "GET",
        headers: {
            "X-Requested-With": "XMLHttpRequest"
        }
    })
        .then(response => response.text())
        .then(html => {
            document.getElementById("map-container").innerHTML = html;

            // Update input fields and highlight
            if (inputX !== undefined && inputY !== undefined) {
                document.getElementById('startx').value = inputX;
                document.getElementById('starty').value = inputY;

                const cell = document.querySelector(`td[data-x="${inputX}"][data-y="${inputY}"]`);

                if (cell) {
                    const fieldID = cell.getAttribute('data-fieldid');
                    highlightField(parseInt(fieldID), inputX, inputY);
                }
            } else {
                const highlightedCell = document.querySelector('td.highlight');

                if (highlightedCell) {
                    const fieldID = highlightedCell.getAttribute('data-fieldid');
                    const x = highlightedCell.getAttribute('data-x');
                    const y = highlightedCell.getAttribute('data-y');

                    highlightField(parseInt(fieldID), parseInt(x), parseInt(y));
                } else {
                    const oldField = document.getElementById("highlightedfield");
                    const x = oldField.getAttribute("data-x");
                    const y = oldField.getAttribute("data-y");

                    highlightEnteredCoordinates(x, y);
                }
            }
        })
        .catch(error => {
            console.error("Map update error:", error);
        });
}


function highlightField(clickedField = -1, x = -1, y = -1) {
    const fieldToHighlight = document.getElementById("highlightedfield");
    fieldToHighlight.setAttribute("data-x", x.toString());
    fieldToHighlight.setAttribute("data-y", y.toString());

    clearFieldHighlighting();
    highlightEnteredCoordinates(x, y);

    fetch(`ajax/field_info.php?clickedfield=${clickedField}&x=${x}&y=${y}`, {
        method: "GET",
        headers: {
            "X-Requested-With": "XMLHttpRequest"
        }
    })
        .then(response => response.text())
        .then(html => {
            document.getElementById("field-info").innerHTML = html;
        })
        .catch(error => {
            console.error("Field info error:", error);
        });
}

function highlightEnteredCoordinates(x, y) {
    let cell = document.querySelector(`td[data-x='${x}'][data-y='${y}']`);

    if (cell) {
        cell.classList.add("highlight");
    }
    return cell;
}

function clearFieldHighlighting() {
    document.querySelectorAll('td.highlight').forEach(cell => {
        cell.classList.remove('highlight');
    });
}
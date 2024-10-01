function sendUpdateMapRequest() {
    let startX, startY, inputX, inputY;
    let startXField = document.getElementById("startx");
    let startYField = document.getElementById("starty");
    const metaTag = document.querySelector('meta[data-max-map-size]');
    const data = metaTag.getAttribute('data-max-map-size');
    const jsonData = JSON.parse(data);

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
    // Make an AJAX request to update the map
    let xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
            document.getElementById("map-container").innerHTML = this.responseText;

            // Check if update map was requested via the input fields or the map arrows
            if (inputX !== undefined && inputY !== undefined) {
                document.getElementById('startx').value = inputX;
                document.getElementById('starty').value = inputY;

                let cell = document.querySelector(`td[data-x="${inputX}"][data-y="${inputY}"]`);

                if (cell) {
                    let fieldID = cell.getAttribute('data-fieldid');

                    highlightField(parseInt(fieldID), inputX, inputY);
                }
            } else {
                let highlightedCell = document.querySelector('td.highlight');

                if (highlightedCell) {
                    let fieldID = highlightedCell.getAttribute('data-fieldid');
                    let x = highlightedCell.getAttribute('data-x');
                    let y = highlightedCell.getAttribute('data-y');

                    highlightField(parseInt(fieldID), parseInt(x), parseInt(y));
                } else {
                    let oldField = document.getElementById("highlightedfield");
                    let x = oldField.getAttribute("data-x");
                    let y = oldField.getAttribute("data-y");

                    highlightEnteredCoordinates(x, y);
                }
            }
        }
    };
    xhttp.open("GET", "ajax/map_update.php?startx=" + newStartX + "&starty=" + newStartY, true);
    xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    xhttp.send();
}

function highlightField(clickedField = -1, x = -1, y = -1) {
    let fieldToHighlight = document.getElementById("highlightedfield");
    fieldToHighlight.setAttribute("data-x", x.toString());
    fieldToHighlight.setAttribute("data-y", y.toString());

    // Remove every other td's highlighting
    clearFieldHighlighting();
    highlightEnteredCoordinates(x, y);

    // Make an AJAX request to update map and show field info
    let xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
            // Update the map HTML with the response
            document.getElementById("field-info").innerHTML = this.responseText;
        }
    };
    xhttp.open("GET", "ajax/field_info.php?clickedfield=" + clickedField + "&x=" + x + "&y=" + y, true);
    xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    xhttp.send();
}

function highlightEnteredCoordinates(x, y) {
    let cell = document.querySelector(`td[data-x='${x}'][data-y='${y}']`);

    if (cell) {
        cell.classList.add("highlight");
    }
}

function clearFieldHighlighting() {
    document.querySelectorAll('td.highlight').forEach(cell => {
        cell.classList.remove('highlight');
    });
}
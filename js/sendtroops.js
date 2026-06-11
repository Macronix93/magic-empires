registerAction("filterSendTroops", (el) => {
    const category = String(el.dataset.category);
    const allRows = document.querySelectorAll(".unit-row");
    const allTabs = document.querySelectorAll(".tablinks");

    allTabs.forEach(tab => tab.classList.remove("active"));
    el.classList.add("active");

    allRows.forEach(row => {
        const rowCat = String(row.getAttribute("data-unit-category"));

        if (rowCat === category) {
            row.style.display = "table-row";
        } else {
            row.style.display = "none";
        }
    });
});
registerAction("fillMaxAndRefresh", (el) => {
    const targetId = el.dataset.target;
    const maxValue = el.dataset.value;
    const input = document.getElementById(targetId);

    if (input) {
        if (parseInt(maxValue) !== 0) {
            input.value = maxValue;

            updateTroopSummary();
        }
    }
});
registerAction("resetUnitAndRefresh", (el) => {
    const targetId = el.dataset.target;
    const input = document.getElementById(targetId);

    if (input) {
        input.value = "";

        updateTroopSummary();
    }
});

function updateTroopSummary() {
    const inputs = document.querySelectorAll(".js-unit-input");
    const summaryList = document.getElementById("troop-summary-list");
    const summaryContainer = document.getElementById("troop-summary-container");

    if (!summaryList || !summaryContainer) return;

    let html = "";
    let totalUnits = 0;

    inputs.forEach(input => {
        let rawValue = input.value;

        if (rawValue === "") {
            return;
        }

        let cleanValue = rawValue.replace(/[^0-9]/g, '');
        let val = parseInt(cleanValue) || 0;
        const max = parseInt(input.dataset.max) || 0;

        if (val > max) {
            val = max;
            input.value = max;
        } else if (rawValue !== cleanValue) {
            input.value = cleanValue;
        }

        if (val > 0) {
            const name = input.dataset.name;
            const iconName = input.dataset.icon;
            const iconPath = `images/icons/${iconName}.png`;

            html += `<div class="legend-item">
                        <img src="${iconPath}" class="ressource-icons" alt=""> 
                        <b>${val}x</b> ${name}
                     </div>`;

            totalUnits += val;
        }
    });

    summaryList.innerHTML = html;
    summaryContainer.style.display = (totalUnits > 0) ? "flex" : "none";
}

document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("send-troops-form");

    if (form) {
        form.addEventListener("input", (e) => {
            if (e.target.classList.contains("js-unit-input")) {
                updateTroopSummary();
            }
        });

        updateTroopSummary();
    }
});
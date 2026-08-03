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
registerAction("clearAllTroops", () => {
    const inputs = document.querySelectorAll(".js-unit-input");

    inputs.forEach(input => {
        input.value = "";
    });

    if (typeof updateTroopSummary === "function") {
        updateTroopSummary();
    }
});

function updateTroopSummary() {
    const inputs = document.querySelectorAll(".js-unit-input");
    const summaryList = document.getElementById("troop-summary-list");
    const summaryContainer = document.getElementById("troop-summary-container");
    const actionButtons = document.getElementById("troop-action-buttons");

    if (!summaryList || !summaryContainer) return;

    let html = "";
    let totalUnits = 0;
    let totalAtk = 0;
    let totalDef = 0;

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

            const unitAtk = parseInt(input.dataset.atk) || 0;
            const unitDef = parseInt(input.dataset.def) || 0;
            totalAtk += val * unitAtk;
            totalDef += val * unitDef;

            html += `<div class="unit-badge" title="${name}">
                        <img src="${iconPath}" alt=""> 
                        <b>${val.toLocaleString("de-DE")}x</b>
                     </div>`;

            totalUnits += val;
        }
    });

    if (totalUnits > 0) {
        html += `
        <div style="width: 100%; padding-top: 8px; display: flex; justify-content: center; gap: 20px; font-weight: bold;">
            <div style="display: flex; align-items: center; gap: 5px;" title="Gesamt-Angriff">
                <img src="../images/icons/icon_sword.png" class="ressource-icons" alt="Angriff"> 
                <span>${formatNumJS(totalAtk)}</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;" title="Gesamt-Verteidigung">
                <img src="../images/icons/icon_shield.png" class="ressource-icons" alt="Verteidigung"> 
                <span>${formatNumJS(totalDef)}</span>
            </div>
        </div>`;
    }

    summaryList.innerHTML = html;
    summaryList.style.display = "flex";
    summaryList.style.flexWrap = "wrap";
    summaryList.style.justifyContent = "center";
    summaryList.style.gap = "5px";

    summaryContainer.style.display = (totalUnits > 0) ? "flex" : "none";

    if (actionButtons) {
        actionButtons.style.display = (totalUnits > 0) ? "flex" : "none";
    }
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
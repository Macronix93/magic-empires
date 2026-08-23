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
registerAction("selectAllTroops", () => {
    const inputs = document.querySelectorAll(".js-unit-input:not([disabled])");

    inputs.forEach(input => {
        const max = input.dataset.max;
        if (max && parseInt(max) > 0) {
            input.value = max;
        }
    });

    if (typeof updateTroopSummary === "function") {
        updateTroopSummary();
    }
});

function updateTroopSummary() {
    const inputs = document.querySelectorAll(".js-unit-input");
    const summaryList = document.getElementById("troop-summary-list");
    const summaryTotals = document.getElementById("troop-summary-totals");
    const summaryContainer = document.getElementById("troop-summary-container");
    const actionButtons = document.getElementById("troop-action-buttons");

    if (!summaryList || !summaryContainer || !summaryTotals) return;

    let badgesHtml = "";
    let totalUnits = 0;
    let totalAtk = 0;
    let totalDef = 0;

    inputs.forEach(input => {
        let rawValue = input.value;
        if (rawValue === "") return;

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

            badgesHtml += `<div class="unit-badge" title="${name}">
                             <img src="${iconPath}" alt=""> 
                             <b>${val.toLocaleString("de-DE")}</b>
                           </div>`;

            totalUnits += val;
        }
    });

    summaryList.innerHTML = badgesHtml;

    if (totalUnits > 0) {
        const atkTitle = totalAtk >= 100000 ? ` title="${totalAtk.toLocaleString("de-DE")}" style="cursor:help;"` : "";
        const defTitle = totalDef >= 100000 ? ` title="${totalDef.toLocaleString("de-DE")}" style="cursor:help;"` : "";

        summaryTotals.innerHTML = `
            <div style="min-width: 250px; padding: 8px; display: flex; justify-content: center; gap: 20px; font-weight: bold; background-color: rgba(0, 0, 0, 0.4); border: 1px solid var(--border-gold);
                    border-radius: 5px;">
                <div style="display: flex; align-items: center; gap: 5px;" title="Gesamt-Angriff">
                    <img src="images/icons/icon_sword.png" class="ressource-icons" alt="Angriff" style="width:18px; height:18px;"> 
                    <span${atkTitle}>${formatNumJS(totalAtk)}</span>
                </div>
                <div style="display: flex; align-items: center; gap: 5px;" title="Gesamt-Verteidigung">
                    <img src="images/icons/icon_shield.png" class="ressource-icons" alt="Verteidigung" style="width:18px; height:18px;"> 
                    <span${defTitle}>${formatNumJS(totalDef)}</span>
                </div>
            </div>`;
    } else {
        summaryTotals.innerHTML = "";
    }

    summaryContainer.style.display = (totalUnits > 0) ? "flex" : "none";

    if (actionButtons) {
        actionButtons.style.display = "flex";
        const submitBtn = actionButtons.querySelector('input[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = (totalUnits <= 0);
        }
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
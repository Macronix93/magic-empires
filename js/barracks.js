const resEl = document.getElementById("kingdom-resources");
const kingdomRes = resEl ? {
    food: parseInt(resEl.dataset.food),
    wood: parseInt(resEl.dataset.wood),
    stone: parseInt(resEl.dataset.stone),
    gold: parseInt(resEl.dataset.gold),
    villager: parseInt(resEl.dataset.villager)
} : null;

registerAction("filterBarracks", (el) => {
    const category = el.dataset.category;
    const allRows = document.querySelectorAll(".unit-row");
    const allTabs = document.querySelectorAll(".tablinks");

    allTabs.forEach(tab => tab.classList.remove("active"));
    el.classList.add("active");

    allRows.forEach(row => {
        if (row.dataset.unitCategory === category) {
            row.style.display = "table-row";
        } else {
            row.style.display = "none";
        }
    });

    const url = new URL(window.location);
    url.searchParams.set("cat", category);
    window.history.replaceState({}, '', url);

    document.querySelectorAll('form[action="barracks.php"]').forEach(form => {
        let catInput = form.querySelector('input[name="cat"]');

        if (catInput) {
            catInput.value = category;
        }
    });
});
registerAction("fillMaxAndCalc", (el) => {
    const targetId = el.dataset.target;
    const input = document.getElementById(targetId);
    if (!input) return;

    const resDiv = document.getElementById("kingdom-resources");
    const dynamicLimit = parseInt(resDiv.dataset.dynamicLimit);
    const multiplier = parseFloat(resDiv.dataset.smithyMultiplier);

    const resources = {
        food: parseInt(resDiv.dataset.food),
        wood: parseInt(resDiv.dataset.wood),
        stone: parseInt(resDiv.dataset.stone),
        gold: parseInt(resDiv.dataset.gold),
        villager: parseInt(resDiv.dataset.villager)
    };

    const form = input.closest("form");
    const upgradeSelect = form.querySelector(".js-upgrade-select");
    const isUpgrade = upgradeSelect && upgradeSelect.value !== "";

    let maxCanAfford = dynamicLimit;

    if (isUpgrade) {
        const selectedOpt = upgradeSelect.selectedOptions[0];
        const ownedUnits = parseInt(input.dataset.owned);

        const diffs = {
            food: Math.max(0, parseInt(selectedOpt.dataset.ufood) - parseInt(input.dataset.costFood)),
            wood: Math.max(0, parseInt(selectedOpt.dataset.uwood) - parseInt(input.dataset.costWood)),
            stone: Math.max(0, parseInt(selectedOpt.dataset.ustone) - parseInt(input.dataset.costStone)),
            gold: Math.max(0, parseInt(selectedOpt.dataset.ugold) - parseInt(input.dataset.costGold))
        };

        for (const [res, cost] of Object.entries(diffs)) {
            if (cost > 0) {
                const affordable = Math.floor(resources[res] / (cost * multiplier));
                maxCanAfford = Math.min(maxCanAfford, affordable);
            }
        }

        maxCanAfford = Math.min(maxCanAfford, ownedUnits);
    } else {
        const costs = {
            food: parseInt(input.dataset.costFood),
            wood: parseInt(input.dataset.costWood),
            stone: parseInt(input.dataset.costStone),
            gold: parseInt(input.dataset.costGold),
            villager: parseInt(input.dataset.costVillager)
        };

        for (const [res, cost] of Object.entries(costs)) {
            if (cost > 0) {
                const resMultiplier = (res === 'villager') ? 1 : multiplier;
                const affordable = Math.floor(resources[res] / (cost * resMultiplier));
                maxCanAfford = Math.min(maxCanAfford, affordable);
            }
        }
    }

    input.value = Math.max(0, maxCanAfford);
    updateRecruitCosts(input);
});

function updateRecruitCosts(input) {
    const val = parseInt(input.value);
    const amount = isNaN(val) ? 0 : val;
    const displayAmount = (amount <= 0) ? 1 : amount;

    const resEl = document.getElementById("kingdom-resources");
    const smithyMultiplier = parseFloat(resEl.dataset.smithyMultiplier) || 1.0;

    const id = input.dataset.id;
    const form = input.closest("form");
    const upgradeSelect = form.querySelector(".js-upgrade-select");
    const selectedUpgrade = (upgradeSelect && upgradeSelect.value !== "") ? upgradeSelect.selectedOptions[0] : null;

    let rawTimePerUnit = parseInt(input.dataset.timePerUnit) || 0;
    if (selectedUpgrade) {
        rawTimePerUnit = parseInt(selectedUpgrade.dataset.utime) || 0;
    }

    let discountedUnitTime = Math.round(rawTimePerUnit * smithyMultiplier);

    const timeEl = document.getElementById(`time-${id}`);
    if (timeEl) {
        const totalSec = discountedUnitTime * displayAmount;

        const h = Math.floor(totalSec / 3600);
        const m = Math.floor((totalSec % 3600) / 60);
        const s = totalSec % 60;

        let timeParts = [];
        if (h > 0) timeParts.push(h + " Std.");
        if (m > 0) timeParts.push(m + " Min.");
        if (s > 0) timeParts.push(s + " Sek.");

        timeEl.innerText = timeParts.length > 0 ? timeParts.join(" ") : "0 Sek.";
    }

    const resources = ["food", "gold", "stone", "wood", "villager"];

    resources.forEach(res => {
        let baseCostPerUnit = parseInt(input.dataset["cost" + res.charAt(0).toUpperCase() + res.slice(1)]) || 0;

        let finalUnitCost;
        if (selectedUpgrade) {
            const targetCost = parseInt(selectedUpgrade.dataset["u" + res.toLowerCase()]) || 0;
            finalUnitCost = Math.max(0, targetCost - baseCostPerUnit);
        } else {
            finalUnitCost = baseCostPerUnit;
        }

        let currentMultiplier = (res === "villager") ? 1.0 : smithyMultiplier;

        const totalCost = Math.floor(finalUnitCost * currentMultiplier) * displayAmount;

        const displayEl = document.getElementById(`cost-${res}-${id}`);
        if (displayEl) {
            displayEl.innerText = totalCost.toLocaleString("de-DE");

            if (amount > 0 && totalCost > kingdomRes[res]) {
                displayEl.classList.add("error");
            } else {
                displayEl.classList.remove("error");
            }
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".js-recruit-input").forEach(input => {
        input.addEventListener("input", () => updateRecruitCosts(input));
    });

    document.querySelectorAll(".js-upgrade-select").forEach(select => {
        select.addEventListener("change", () => {
            const input = select.closest("form").querySelector(".js-recruit-input");

            updateRecruitCosts(input);
        });
    });
});
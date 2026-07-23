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
        if (catInput) catInput.value = category;
    });
});
registerAction("fillMaxAndCalc", (el) => {
    const targetId = el.dataset.target;
    const input = document.getElementById(targetId);
    const kRes = getLatestKingdomResources();

    if (!input || !kRes) return;

    const form = input.closest("form");
    const upgradeSelect = form.querySelector(".js-upgrade-select");
    const isUpgrade = upgradeSelect && upgradeSelect.value !== "";

    let maxCanAfford = kRes.dynamicLimit;

    if (isUpgrade) {
        const selectedOpt = upgradeSelect.selectedOptions[0];
        if (!selectedOpt) return;

        const ownedUnits = parseInt(input.dataset.owned) || 0;

        const diffs = {
            food: Math.max(0, (parseInt(selectedOpt.dataset.ufood) || 0) - (parseInt(input.dataset.costFood) || 0)),
            wood: Math.max(0, (parseInt(selectedOpt.dataset.uwood) || 0) - (parseInt(input.dataset.costWood) || 0)),
            stone: Math.max(0, (parseInt(selectedOpt.dataset.ustone) || 0) - (parseInt(input.dataset.costStone) || 0)),
            gold: Math.max(0, (parseInt(selectedOpt.dataset.ugold) || 0) - (parseInt(input.dataset.costGold) || 0))
        };

        for (const [res, cost] of Object.entries(diffs)) {
            if (cost > 0) {
                const affordable = Math.floor(kRes[res] / (cost * kRes.multiplier));
                maxCanAfford = Math.min(maxCanAfford, affordable);
            }
        }
        maxCanAfford = Math.min(maxCanAfford, ownedUnits);
    } else {
        const costs = {
            food: parseInt(input.dataset.costFood) || 0,
            wood: parseInt(input.dataset.costWood) || 0,
            stone: parseInt(input.dataset.costStone) || 0,
            gold: parseInt(input.dataset.costGold) || 0,
            villager: parseInt(input.dataset.costVillager) || 0
        };

        for (const [res, cost] of Object.entries(costs)) {
            if (cost > 0) {
                const resMultiplier = (res === 'villager') ? 1 : kRes.multiplier;
                const affordable = Math.floor(kRes[res] / (cost * resMultiplier));
                maxCanAfford = Math.min(maxCanAfford, affordable);
            }
        }
    }

    input.value = Math.max(0, maxCanAfford);
    updateRecruitCosts(input);
});

function getLatestKingdomResources() {
    const resEl = document.getElementById("kingdom-resources");
    if (!resEl) return null;

    return {
        food: parseInt(resEl.dataset.food) || 0,
        wood: parseInt(resEl.dataset.wood) || 0,
        stone: parseInt(resEl.dataset.stone) || 0,
        gold: parseInt(resEl.dataset.gold) || 0,
        villager: parseInt(resEl.dataset.villager) || 0,
        dynamicLimit: parseInt(resEl.dataset.dynamicLimit) || 10,
        multiplier: parseFloat(resEl.dataset.smithyMultiplier) || 1.0
    };
}

function updateRecruitCosts(input) {
    const val = parseInt(input.value);
    const amount = isNaN(val) ? 0 : val;
    const displayAmount = (amount <= 0) ? 1 : amount;

    const kRes = getLatestKingdomResources();
    if (!kRes) return;

    const smithyMultiplier = kRes.multiplier;
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

            if (amount > 0 && totalCost > kRes[res]) {
                displayEl.classList.add("error");
            } else {
                displayEl.classList.remove("error");
            }
        }
    });

    const submitBtn = form.querySelector('input[type="submit"]');
    const maxBtn = form.querySelector('input[type="button"]');
    let hasRelevantError = false;

    resources.forEach(res => {
        const displayEl = document.getElementById(`cost-${res}-${id}`);
        if (displayEl && displayEl.classList.contains("error")) {
            if (res !== "villager" || !selectedUpgrade) {
                hasRelevantError = true;
            }
        }
    });

    if (parseInt(input.dataset.owned) > 0) {
        input.disabled = false;
        if (maxBtn) maxBtn.disabled = false;
    }

    if (submitBtn) {
        submitBtn.disabled = (hasRelevantError || amount <= 0);
    }
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
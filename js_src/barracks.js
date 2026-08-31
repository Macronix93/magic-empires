registerAction("filterBarracks", (el) => {
    const category = el.dataset.category;
    const recruitmentTable = document.getElementById("recruitment-table");
    const supportContainer = document.getElementById("support-container");
    const allTabs = document.querySelectorAll(".tablinks");

    allTabs.forEach(tab => tab.classList.remove("active"));
    el.classList.add("active");

    if (category === "4") {
        if (recruitmentTable) recruitmentTable.style.display = "none";
        if (supportContainer) supportContainer.style.display = "block";
    } else {
        if (recruitmentTable) recruitmentTable.style.display = "table";
        if (supportContainer) supportContainer.style.display = "none";

        document.querySelectorAll(".unit-row").forEach(row => {
            row.style.display = (row.dataset.unitCategory === category) ? "table-row" : "none";
        });
    }
    
    const url = new URL(window.location);
    url.searchParams.set("cat", category);
    window.history.replaceState({}, '', url);
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
                const affordable = Math.floor(kRes[res] / cost);
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
                const affordable = Math.floor(kRes[res] / cost);
                maxCanAfford = Math.min(maxCanAfford, affordable);
            }
        }
    }

    if (!isUpgrade) {
        maxCanAfford = Math.min(maxCanAfford, kRes.spaceLeft);
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
        multiplier: parseFloat(resEl.dataset.smithyMultiplier) || 1.0,
        spaceLeft: parseInt(resEl.dataset.spaceLeft) || 0
    };
}

function formatRecruitTime(totalSec) {
    if (totalSec <= 0) return "0 Sek.";
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    let parts = [];
    if (h > 0) parts.push(h + " Std.");
    if (m > 0) parts.push(m + " Min.");
    if (s > 0) parts.push(s + " Sek.");
    return parts.join(" ");
}

function updateRecruitCosts(input) {
    const val = parseInt(input.value);
    const amount = isNaN(val) ? 0 : val;
    const displayAmount = (amount <= 0) ? 1 : amount;

    const kRes = getLatestKingdomResources();
    if (!kRes) return;

    const id = input.dataset.id;
    const form = input.closest("form");
    if (!form) return;

    const upgradeSelect = form.querySelector(".js-upgrade-select");
    const isUpgrade = upgradeSelect && upgradeSelect.value !== "";
    const submitBtn = form.querySelector('input[type="submit"]');
    const maxBtn = form.querySelector('input[data-on-click="fillMaxAndCalc"]');

    const smithyMultiplier = kRes.multiplier;
    const selectedUpgrade = (upgradeSelect && upgradeSelect.value !== "") ? upgradeSelect.selectedOptions[0] : null;

    let rawTimePerUnit = parseInt(input.dataset.timePerUnit) || 0;
    if (selectedUpgrade) {
        rawTimePerUnit = parseInt(selectedUpgrade.dataset.utime) || 0;
    }
    let discountedUnitTime = Math.round(rawTimePerUnit * smithyMultiplier);

    const timeEl = document.getElementById(`time-${id}`);
    if (timeEl) {
        timeEl.innerText = formatRecruitTime(discountedUnitTime * displayAmount);
    }

    const resources = ["food", "gold", "stone", "wood", "villager"];
    let hasRelevantError = false;
    let canAffordOne = true;

    resources.forEach(res => {
        let baseCostPerUnit = parseInt(input.dataset["cost" + res.charAt(0).toUpperCase() + res.slice(1)]) || 0;
        let finalUnitCost;

        if (selectedUpgrade) {
            const targetCost = parseInt(selectedUpgrade.dataset["u" + res.toLowerCase()]) || 0;
            finalUnitCost = (res === "villager") ? 0 : Math.max(0, targetCost - baseCostPerUnit);
        } else {
            finalUnitCost = baseCostPerUnit;
        }

        let currentMultiplier = 1.0;
        const totalCostForSelectedAmount = Math.floor(finalUnitCost * currentMultiplier) * amount;
        const previewCostForOne = Math.floor(finalUnitCost * currentMultiplier) * displayAmount;

        const displayEl = document.getElementById(`cost-${res}-${id}`);
        if (displayEl) {
            displayEl.innerText = formatNumJS(previewCostForOne);

            if (previewCostForOne >= 100000) {
                displayEl.title = previewCostForOne.toLocaleString("de-DE");
                displayEl.style.cursor = "help";
            } else {
                displayEl.title = "";
                displayEl.style.cursor = "";
            }

            if ((amount > 0 && totalCostForSelectedAmount > kRes[res]) || (amount === 0 && previewCostForOne > kRes[res])) {
                displayEl.classList.add("error");
                if (amount > 0) hasRelevantError = true;
            } else {
                displayEl.classList.remove("error");
            }

            if (previewCostForOne > kRes[res]) {
                canAffordOne = false;
            }
        }
    });

    const noUnitsToUpgrade = isUpgrade && (parseInt(input.dataset.owned) <= 0);
    const trainingBlocked = (!isUpgrade && kRes.spaceLeft <= 0) || !canAffordOne || noUnitsToUpgrade;

    if (trainingBlocked) {
        input.disabled = true;
        input.value = "";
    } else {
        input.disabled = false;
    }

    if (!isUpgrade && amount > kRes.spaceLeft) {
        input.style.color = "#ff4d4d";
    } else {
        input.style.color = "";
    }

    if (maxBtn) {
        maxBtn.disabled = trainingBlocked;
    }

    if (submitBtn) {
        const isOverLimit = !isUpgrade && amount > kRes.spaceLeft;

        submitBtn.disabled = (
            amount <= 0 ||
            hasRelevantError ||
            !canAffordOne ||
            noUnitsToUpgrade ||
            isOverLimit
        );
    }
}

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".js-recruit-input").forEach(input => {
        // Initiale Anzeige der Kosten für "1" oder "0"
        updateRecruitCosts(input);

        input.addEventListener("input", function () {
            // 1. Nur Zahlen erlauben (Buchstaben sofort entfernen)
            let rawValue = this.value.replace(/[^0-9]/g, '');

            if (rawValue === "") {
                this.value = "";
                updateRecruitCosts(this);
                return;
            }

            let amount = parseInt(rawValue) || 0;
            const kRes = getLatestKingdomResources();
            if (!kRes) return;

            const form = this.closest("form");
            const upgradeSelect = form.querySelector(".js-upgrade-select");
            const isUpgrade = upgradeSelect && upgradeSelect.value !== "";

            // 2. Limits berechnen (Was ist das absolute Maximum?)
            let maxAllowed = kRes.dynamicLimit;

            if (!isUpgrade) {
                // Bei Neubau: Truppenlimit beachten
                maxAllowed = Math.min(maxAllowed, kRes.spaceLeft);
                // Bei Neubau: Dorfbewohner beachten
                const villCost = parseInt(this.dataset.costVillager) || 0;
                if (villCost > 0) {
                    maxAllowed = Math.min(maxAllowed, Math.floor(kRes.villager / villCost));
                }
            } else {
                // Bei Upgrade: Vorhandene Einheiten beachten
                const ownedUnits = parseInt(this.dataset.owned) || 0;
                maxAllowed = Math.min(maxAllowed, ownedUnits);
            }

            // 3. Ressourcen-Limits beachten (Nahrung, Holz, Stein, Gold)
            const resources = ["food", "wood", "stone", "gold"];
            resources.forEach(res => {
                let costPerUnit;
                const baseCost = parseInt(this.dataset["cost" + res.charAt(0).toUpperCase() + res.slice(1)]) || 0;

                if (isUpgrade) {
                    const selectedOpt = upgradeSelect.selectedOptions[0];
                    const targetCost = parseInt(selectedOpt.dataset["u" + res]) || 0;
                    costPerUnit = Math.max(0, targetCost - baseCost);
                } else {
                    costPerUnit = baseCost;
                }

                if (costPerUnit > 0) {
                    const affordable = Math.floor(kRes[res] / costPerUnit);
                    maxAllowed = Math.min(maxAllowed, affordable);
                }
            });

            // 4. Korrektur anwenden
            if (amount > maxAllowed) {
                amount = maxAllowed;
            }

            // Den korrigierten Wert ins Feld schreiben
            this.value = amount;

            // 5. Die Anzeige der Kosten unter dem Namen aktualisieren
            updateRecruitCosts(this);
        });
    });

    document.querySelectorAll(".js-upgrade-select").forEach(select => {
        select.addEventListener("change", () => {
            const input = select.closest("form").querySelector(".js-recruit-input");
            // Event manuell triggern, um die Limits beim Wechsel von Bau zu Upgrade neu zu prüfen
            input.dispatchEvent(new Event('input'));
        });
    });
});
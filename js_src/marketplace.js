const mConfigEl = document.getElementById("market-configs");

if (mConfigEl) {
    window.curKingdomStorage = JSON.parse(mConfigEl.dataset.storage);
    window.marketConfig = JSON.parse(mConfigEl.dataset.config);
}

/** @var curKingdomStorage */
/** @var marketConfig */

registerAction("checkMarket", (form, event) => {
    event.preventDefault();

    const isListing = form.dataset.isListing === "true";
    let resType, amountToReceive;

    if (isListing) {
        const valS = parseInt(document.getElementById("sv").value) || 0;
        const valD = parseInt(document.getElementById("dv").value) || 0;

        if (valS <= 0 || valD <= 0) {
            showGameError("Bitte gib gültige Mengen an.");
            return;
        }

        const maxRatio = window.marketConfig.max_ratio;
        const ratio1 = valS / valD;
        const ratio2 = valD / valS;
        const grace = 0.01;

        if (ratio1 > maxRatio + grace || ratio2 > maxRatio + grace) {
            showGameError(`Das Handelsverhältnis ist zu extrem! Erlaubt ist maximal ein Verhältnis von 1:${maxRatio}.`,);
            return;
        }

        const listingFee = Math.max(1, Math.ceil(valS / window.marketConfig.listing_fee_step));
        resType = document.getElementById("d").value;
        amountToReceive = valD;

        const overflowMsg = getOverflowWarning(resType, amountToReceive);
        const coinString = listingFee === 1 ? "Münze" : "Münzen";
        const confirmMsg = `${overflowMsg}Das Erstellen dieses Angebots kostet dich sofort ${listingFee} ${coinString}.\n\nMöchtest du das Angebot jetzt veröffentlichen?`;

        showConfirmationDialog(confirmMsg, "Ja, Erstellen", "Abbrechen", () => {
            form.submit();
        });
    } else {
        resType = form.dataset.resType;
        amountToReceive = parseInt(form.dataset.amount) || 0;

        const overflowWarning = getOverflowWarning(resType, amountToReceive);

        if (overflowWarning !== "") {
            showConfirmationDialog(
                overflowWarning + "Möchtest du das Angebot trotzdem annehmen?",
                "Ja, Annehmen",
                "Abbrechen",
                () => {
                    form.submit();
                }
            );
        } else {
            form.submit();
        }
    }
});
registerAction("checkInternalTransport", (form, event) => {
    if (form.dataset.dailyFull === "true" || (window.marketConfig && window.marketConfig.is_daily_full)) {
        event.preventDefault();
        showGameError("Du hast dein tägliches Limit an Handelsaktionen bereits erreicht!");
        return;
    }

    const arrivalDataEl = document.getElementById("internal-arrival-data");
    const targetSelect = document.getElementById("target_k");
    if (!arrivalDataEl || !targetSelect) return;

    const targetStocks = JSON.parse(arrivalDataEl.dataset.stocks || "{}");
    const selectedId = targetSelect.value;
    const tData = targetStocks[selectedId];
    if (!tData) return;

    const resNames = {
        food: "Nahrung",
        wood: "Holz",
        stone: "Stein",
        gold: "Gold"
    };

    const resKeys = [
        {key: "food", index: 0},
        {key: "wood", index: 1},
        {key: "stone", index: 2},
        {key: "gold", index: 3}
    ];

    const overflows = [];
    resKeys.forEach(item => {
        const input = form.querySelector(`input[name="am[${item.index}]"]`);
        const entered = parseInt(input?.value, 10) || 0;
        if (entered <= 0) return;

        const curStock = tData[item.key] || 0;
        const inFlight = tData["inc_" + item.key] || 0;
        const maxStock = tData["max" + item.key] || 0;
        const totalProjected = curStock + inFlight + entered;

        if (totalProjected > maxStock) {
            const diff = totalProjected - maxStock;
            overflows.push(`• ${resNames[item.key]}: ca. ${diff.toLocaleString("de-DE")} Einheiten zu viel`);
        }
    });

    if (overflows.length > 0) {
        event.preventDefault();
        const warningText = `⚠️ LAGER-ÜBERLAUF IN ${tData.name.toUpperCase()}:\n\n` +
            `Bei Eintreffen der Karawane wird die Lagerkapazität überschritten:\n` +
            overflows.join("\n") +
            `\n\nÜberschüssige Ressourcen verfallen bei Ankunft!\nMöchtest du den Transport trotzdem starten?`;

        showConfirmationDialog(
            warningText,
            "Trotzdem senden",
            "Abbrechen",
            () => {
                form.submit();
            }
        );
    }
});

function getOverflowWarning(resType, amount) {
    const storageData = window.curKingdomStorage;
    const resNames = ["Nahrung", "Holz", "Stein", "Gold"];

    if (storageData && storageData[resType]) {
        const storage = storageData[resType];
        const current = parseInt(storage.cur);
        const max = parseInt(storage.max);

        if (current + amount > max) {
            const diff = (current + amount) - max;
            return `⚠️ ÜBERLAUF-WARNUNG:\nDein Lager für ${resNames[resType]} wird um ca. ${diff.toLocaleString()} Einheiten überlaufen!\n\n`;
        }
    }
    return "";
}

document.addEventListener("DOMContentLoaded", function () {
    const targetSelect = document.getElementById("target_k");
    const arrivalDataEl = document.getElementById("internal-arrival-data");
    const displayEl = document.getElementById("target-arrival-display");
    const supplySelect = document.querySelector("select[name='s']");
    const demandSelect = document.querySelector("select[name='d']");
    const internalInputs = document.querySelectorAll(".js-internal-res-input");
    const sumDisplay = document.getElementById("internal-sum-display");
    const internalSubmit = document.getElementById("internal-submit");
    const inputs = [
        document.getElementById("sv"),
        document.getElementById("dv")
    ];
    const resKeys = [
        {key: "food", index: 0, elId: "target-stock-food"},
        {key: "wood", index: 1, elId: "target-stock-wood"},
        {key: "stone", index: 2, elId: "target-stock-stone"},
        {key: "gold", index: 3, elId: "target-stock-gold"}
    ];

    const updateTargetStockDisplay = () => {
        if (!arrivalDataEl || !targetSelect) return;
        const targetStocks = JSON.parse(arrivalDataEl.dataset.stocks || "{}");
        const selectedId = targetSelect.value;
        const tData = targetStocks[selectedId];
        if (!tData) return;

        const headerEl = document.getElementById("target-k-stock-header");
        if (headerEl) {
            headerEl.innerHTML = `Vorräte in <b>${tData.name}</b>:`;
        }

        resKeys.forEach(item => {
            const stockEl = document.getElementById(item.elId);
            if (!stockEl) return;

            const input = document.querySelector(`input[name="am[${item.index}]"]`);
            const entered = parseInt(input?.value) || 0;
            const curStock = tData[item.key] || 0;
            const inFlight = tData["inc_" + item.key] || 0;
            const maxStock = tData["max" + item.key] || 0;

            const totalProjected = curStock + inFlight + entered;
            const isOverflown = totalProjected > maxStock;

            stockEl.innerHTML = `
            <span class="${isOverflown ? 'error' : ''}"">
                ${formatNumJS(totalProjected)} / ${formatNumJS(maxStock)}
            </span>
        `;
        });
    };

    const updateInternalSum = () => {
        let total = 0;
        const maxCap = window.marketConfig ? (parseInt(window.marketConfig.max_capacity_per_offer, 10) || 1) : 1;
        const remainingTrades = window.marketConfig ? (parseInt(window.marketConfig.remaining_trades, 10) || 0) : 0;
        const form = document.getElementById("form-internal-transport");
        const isDailyFull = (form && form.dataset.dailyFull === "true") || (window.marketConfig && window.marketConfig.is_daily_full);

        internalInputs.forEach(input => {
            total += parseInt(input.value, 10) || 0;
        });

        const requiredActions = total > 0 ? Math.ceil(total / maxCap) : 0;
        const exceedsTrades = requiredActions > remainingTrades;

        if (sumDisplay) {
            if (total === 0) {
                sumDisplay.innerHTML = `Handelsaktionen: 0`;
            } else {
                const statusClass = exceedsTrades ? "error" : "passed";
                sumDisplay.innerHTML = `Handelsaktionen: <span class="${statusClass}">${requiredActions}</span>`;
            }
        }

        if (internalSubmit) {
            internalSubmit.disabled = (total <= 0 || exceedsTrades || isDailyFull);
        }

        updateTargetStockDisplay();
    };

    internalInputs.forEach(input => {
        input.addEventListener("input", function () {
            let rawValue = this.value.replace(/[^0-9]/g, '');

            if (rawValue.length > 1 && rawValue.startsWith('0')) {
                rawValue = rawValue.replace(/^0+/, '');
            }

            if (rawValue === '') {
                this.value = '';
                updateInternalSum();
                return;
            }

            let val = parseInt(rawValue, 10) || 0;
            const match = this.name.match(/am\[(\d+)]/);
            const resIndex = match ? match[1] : null;
            const storage = window.curKingdomStorage;

            const stock = (resIndex !== null && storage && storage[resIndex])
                ? parseInt(storage[resIndex].cur, 10)
                : 0;

            if (val > stock) {
                val = stock;
            }
            this.value = val > 0 ? val : '';
            updateInternalSum();
        });
    });

    const clampOfferInputs = () => {
        const config = window.marketConfig;
        const storage = window.curKingdomStorage;
        if (!config) return;

        const maxCap = parseInt(config.max_capacity_per_offer, 10) || 0;
        const inputS = document.getElementById("sv");
        const inputD = document.getElementById("dv");

        if (inputS && supplySelect) {
            const resTypeS = supplySelect.value;
            const currentStock = (storage && storage[resTypeS]) ? parseInt(storage[resTypeS].cur, 10) : maxCap;
            const maxAllowedS = Math.max(0, Math.min(maxCap, currentStock));

            let cleanS = inputS.value.replace(/[^0-9]/g, '');
            if (cleanS.length > 1 && cleanS.startsWith('0')) cleanS = cleanS.replace(/^0+/, '');

            if (cleanS !== '') {
                let numS = parseInt(cleanS, 10) || 0;
                if (numS > maxAllowedS) numS = maxAllowedS;

                inputS.value = numS > 0 ? numS : '';
            } else {
                inputS.value = '';
            }
        }

        if (inputD) {
            let cleanD = inputD.value.replace(/[^0-9]/g, '');
            if (cleanD.length > 1 && cleanD.startsWith('0')) cleanD = cleanD.replace(/^0+/, '');

            if (cleanD !== '') {
                let numD = parseInt(cleanD, 10) || 0;
                if (numD > maxCap) numD = maxCap;

                inputD.value = numD > 0 ? numD : '';
            } else {
                inputD.value = '';
            }
        }
    };

    inputs.forEach(input => {
        if (!input) return;

        input.addEventListener("input", function () {
            clampOfferInputs();
            calculateLiveFee();
        });
    });

    [supplySelect, demandSelect].forEach(select => {
        if (!select) return;

        select.addEventListener("change", function () {
            updateDropdowns();
            clampOfferInputs();
            calculateLiveFee();
        });
    });

    function updateDropdowns() {
        if (!supplySelect || !demandSelect) return;
        let supplyValue = supplySelect.value;
        let demandValue = demandSelect.value;
        Array.from(supplySelect.options).forEach(o => o.hidden = false);
        Array.from(demandSelect.options).forEach(o => o.hidden = false);

        if (supplyValue) {
            let opt = demandSelect.querySelector(`option[value='${supplyValue}']`);
            if (opt) opt.hidden = true;
        }
        if (demandValue) {
            let opt = supplySelect.querySelector(`option[value='${demandValue}']`);
            if (opt) opt.hidden = true;
        }
    }

    if (targetSelect && arrivalDataEl && displayEl) {
        const times = JSON.parse(arrivalDataEl.dataset.times || "{}");

        const updateTimeDisplay = () => {
            const selectedId = targetSelect.value;

            if (times[selectedId]) {
                displayEl.innerText = "(Dauer: " + times[selectedId] + ")";
            } else {
                displayEl.innerText = "";
            }

            updateTargetStockDisplay();
        };

        targetSelect.addEventListener("change", updateTimeDisplay);
        updateTimeDisplay();
    }

    const guildOnlyBox = document.getElementById("guild_only");
    if (guildOnlyBox) {
        guildOnlyBox.addEventListener("change", calculateLiveFee);
    }

    updateDropdowns();
});

function calculateLiveFee() {
    const amountInputS = document.getElementById("sv");
    const amountInputD = document.getElementById("dv");
    const listingDisplay = document.getElementById("live-listing-fee");
    const buyerDisplay = document.getElementById("live-buyer-fee");
    const submitBtn = document.getElementById("market-offer-submit");
    const config = window.marketConfig;

    if (!amountInputS || !amountInputD || !listingDisplay || !buyerDisplay || !config) return;

    const valS = parseInt(amountInputS.value) || 0;
    const valD = parseInt(amountInputD.value) || 0;

    let isRatioInvalid = false;

    if (valS > 0 && valD > 0) {
        const maxRatio = config.max_ratio;
        const ratio1 = valS / valD;
        const ratio2 = valD / valS;
        const grace = 0.01;

        if (ratio1 > maxRatio + grace || ratio2 > maxRatio + grace) {
            isRatioInvalid = true;

            amountInputS.classList.add("input-error");
            amountInputD.classList.add("input-error");
        } else {
            amountInputS.classList.remove("input-error");
            amountInputD.classList.remove("input-error");
        }
    } else {
        amountInputS.classList.remove("input-error");
        amountInputD.classList.remove("input-error");
    }

    const listingFee = valS > 0 ? Math.max(1, Math.ceil(valS / config.listing_fee_step)) : 1;
    const userCoins = parseInt(config.user_coins, 10) || 0;
    const cannotAffordFee = listingFee > userCoins;

    listingDisplay.innerText = listingFee.toLocaleString("de-DE");
    listingDisplay.classList.toggle("error", cannotAffordFee);

    if (valS <= 0 || valD <= 0) {
        buyerDisplay.innerText = "1";
    } else {
        const typeS = document.getElementById('s').value;
        const typeD = document.getElementById('d').value;
        const feeS = Math.floor(valS * (config.factors[typeS] || 0));
        const feeD = Math.floor(valD * (config.factors[typeD] || 0));
        const maxVarFee = Math.max(feeS, feeD);
        let buyerFee = (config.base + maxVarFee);
        const guildOnlyBox = document.getElementById("guild_only");

        if (guildOnlyBox && guildOnlyBox.checked && config.guild_trade_discount > 0) {
            buyerFee = Math.max(1, Math.round(buyerFee * (1.0 - config.guild_trade_discount)));
        }

        buyerDisplay.innerText = buyerFee.toLocaleString("de-DE");
    }

    if (submitBtn) {
        submitBtn.disabled = (
            config.is_daily_full ||
            valS <= 0 ||
            valD <= 0 ||
            isRatioInvalid ||
            cannotAffordFee
        );
    }
}
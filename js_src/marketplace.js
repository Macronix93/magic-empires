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
            showConfirmationDialog("Bitte gib gültige Mengen an.",
                "Ok",
                "",
                () => {
                });
            return;
        }

        const maxRatio = window.marketConfig.max_ratio;
        const ratio1 = valS / valD;
        const ratio2 = valD / valS;
        const grace = 0.01;

        if (ratio1 > maxRatio + grace || ratio2 > maxRatio + grace) {
            showConfirmationDialog(
                `Das Handelsverhältnis ist zu extrem! Erlaubt ist maximal ein Verhältnis von 1:${maxRatio}.`,
                "Ok",
                "",
                () => {
                }
            );
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
    const inputs = [
        document.getElementById("sv"),
        document.getElementById("dv"),
        document.getElementById("am")
    ];

    inputs.forEach(input => {
        if (!input) return;

        input.dataset.lastValidValue = input.value;

        input.addEventListener("input", function () {
            let cleanValue = this.value.replace(/[^0-9]/g, '');

            if (cleanValue !== this.dataset.lastValidValue) {
                this.value = cleanValue;
                this.dataset.lastValidValue = cleanValue;
                calculateLiveFee();
            } else {
                this.value = cleanValue;
            }
        });
    });

    [supplySelect, demandSelect].forEach(select => {
        if (!select) return;
        select.addEventListener("change", function () {
            updateDropdowns();
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
        const times = JSON.parse(arrivalDataEl.dataset.times);

        const updateTimeDisplay = () => {
            const selectedId = targetSelect.value;

            if (times[selectedId]) {
                displayEl.innerText = "(Dauer: " + times[selectedId] + ")";
            } else {
                displayEl.innerText = "";
            }
        };

        targetSelect.addEventListener("change", updateTimeDisplay);
        updateTimeDisplay();
    }

    updateDropdowns();
});

function calculateLiveFee() {
    const amountInputS = document.getElementById("sv");
    const amountInputD = document.getElementById("dv");
    const listingDisplay = document.getElementById("live-listing-fee");
    const buyerDisplay = document.getElementById("live-buyer-fee");
    const config = window.marketConfig;

    if (!amountInputS || !amountInputD || !listingDisplay || !buyerDisplay || !config) return;

    const valS = parseInt(amountInputS.value) || 0;
    const valD = parseInt(amountInputD.value) || 0;

    if (valS > 0 && valD > 0) {
        const maxRatio = config.max_ratio;
        const ratio1 = valS / valD;
        const ratio2 = valD / valS;
        const grace = 0.01;

        if (ratio1 > maxRatio + grace || ratio2 > maxRatio + grace) {
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
    listingDisplay.innerText = listingFee.toLocaleString();

    if (valS <= 0 || valD <= 0) {
        buyerDisplay.innerText = "1";
    } else {
        const typeS = document.getElementById('s').value;
        const typeD = document.getElementById('d').value;

        const feeS = Math.floor(valS * (config.factors[typeS] || 0));
        const feeD = Math.floor(valD * (config.factors[typeD] || 0));

        const maxVarFee = Math.max(feeS, feeD);
        buyerDisplay.innerText = (config.base + maxVarFee).toLocaleString();
    }
}
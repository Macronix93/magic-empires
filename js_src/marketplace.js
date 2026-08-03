const mConfigEl = document.getElementById("market-configs");

if (mConfigEl) {
    window.curKingdomStorage = JSON.parse(mConfigEl.dataset.storage);
    window.marketConfig = JSON.parse(mConfigEl.dataset.config);
}

/** @var curKingdomStorage */
/** @var marketConfig */

registerAction("checkMarket", (form, event) => {
    event.preventDefault();

    let resType, amount;

    if (form.dataset.typeField && form.dataset.amountField) {
        resType = document.getElementById(form.dataset.typeField).value;
        amount = document.getElementById(form.dataset.amountField).value;
    } else {
        resType = form.dataset.resType;
        amount = form.dataset.amount;
    }

    const isListing = form.dataset.isListing === "true";

    if (typeof checkMarketOverflow === "function") {
        const noOverflowDetected = checkMarketOverflow(form, resType, amount, isListing);

        if (noOverflowDetected === true) {
            form.submit();
        }
    }
});

document.addEventListener("DOMContentLoaded", function () {
    const supplySelect = document.querySelector("select[name='s']");
    const demandSelect = document.querySelector("select[name='d']");
    const inputs = [document.getElementById("sv"), document.getElementById("dv")];

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

    updateDropdowns();
});

/**
 * @param {HTMLFormElement} form
 * @param {number|string} resType
 * @param {number|string} incomingAmount
 * @param {boolean} [isListing=false]
 * @returns {boolean}
 */
function checkMarketOverflow(form, resType, incomingAmount, isListing = false) {
    const storageData = window.curKingdomStorage;
    const typeKey = parseInt(resType);

    if (!storageData || !storageData[typeKey]) return true;

    const storage = storageData[typeKey];
    const current = parseInt(storage.cur);
    const max = Number(storage.max);
    const amount = Number(incomingAmount) || 0;

    const resNames = ["Nahrung", "Holz", "Stein", "Gold"];

    if (current + amount > max) {
        const overflow = (current + amount) - max;
        const msg = isListing
            ? `Wenn dieses Angebot angenommen wird, würde dein Lager für ${resNames[resType]} überlaufen (Verlust von ca. ${overflow} ${resNames[resType]}). Trotzdem erstellen?`
            : `Warnung: Durch diesen Handel wird dein Lager für ${resNames[resType]} überlaufen. Du verlierst ca. ${overflow} Einheiten. Trotzdem annehmen?`;

        showConfirmationDialog(msg, "Ja", "Abbrechen", () => {
            form.submit();
        });
        return false;
    }
    return true;
}

function calculateLiveFee() {
    const amountInputS = document.getElementById("sv");
    const amountInputD = document.getElementById("dv");
    const feeDisplay = document.getElementById("live-fee");
    const config = window.marketConfig;

    if (!amountInputS || !amountInputD || !feeDisplay || !config) return;

    const valS_raw = amountInputS.value;
    const valD_raw = amountInputD.value;

    if (valS_raw === "" || valD_raw === "" || /\D/.test(valS_raw) || /\D/.test(valD_raw)) {
        feeDisplay.innerText = "1";
        return;
    }

    const valS = parseInt(valS_raw);
    const valD = parseInt(valD_raw);

    if (valS === 0 || valD === 0) {
        feeDisplay.innerText = "1";
        return;
    }

    const typeS = document.getElementById('s').value;
    const typeD = document.getElementById('d').value;

    const feeS = Math.floor(valS * (config.factors[typeS] || 0));
    const feeD = Math.floor(valD * (config.factors[typeD] || 0));

    const maxVarFee = Math.max(feeS, feeD);

    feeDisplay.innerText = (config.base + maxVarFee).toLocaleString();
}
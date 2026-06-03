/** @var curKingdomStorage */
/** @var marketConfig */

document.addEventListener("DOMContentLoaded", function () {
    const supplySelect = document.querySelector("select[name='s']");
    const demandSelect = document.querySelector("select[name='d']");

    function updateDropdowns() {
        let supplyValue = supplySelect.value;
        let demandValue = demandSelect.value;

        // Enable all options first and deselect them
        Array.from(supplySelect.options).forEach(option => {
            option.hidden = false;
            option.selected = false;
        });
        Array.from(demandSelect.options).forEach(option => {
            option.hidden = false;
            option.selected = false;
        });

        // Hide the selected supply from demand dropdown
        if (supplyValue) {
            let demandOption = demandSelect.querySelector(`option[value='${supplyValue}']`);
            if (demandOption) demandOption.hidden = true;
        }

        // Hide the selected demand from supply dropdown
        if (demandValue) {
            let supplyOption = supplySelect.querySelector(`option[value='${demandValue}']`);
            if (supplyOption) supplyOption.hidden = true;
        }

        // Mark the current selection as selected
        Array.from(supplySelect.options).forEach(option => {
            if (option.value === supplyValue) {
                option.selected = true;
            }
        });

        Array.from(demandSelect.options).forEach(option => {
            if (option.value === demandValue) {
                option.selected = true;
            }
        });
    }

    supplySelect.addEventListener("change", updateDropdowns);
    demandSelect.addEventListener("change", updateDropdowns);

    const fields = ["sv", "s", "dv", "d"];
    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener("input", calculateLiveFee);
            el.addEventListener("change", calculateLiveFee);
        }
    });

    // Initial call
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
    /** @type {Object<number, {cur: string|number, max: number}>} */
    const storageData = window.curKingdomStorage;

    if (!storageData || !storageData[resType]) return true;

    const storage = storageData[resType];
    const current = parseInt(storage.cur);
    const max = Number(storage.max);
    const amount = Number(incomingAmount);

    const resNames = ["Nahrung", "Holz", "Stein", "Gold"];

    if (current + amount > max) {
        const overflow = (current + amount) - max;
        const msg = isListing
            ? `Wenn dieses Angebot angenommen wird, würde dein Lager für ${resNames[resType]} überlaufen (Verlust von ca. ${overflow} ${resNames[resType]}). 
            Trotzdem erstellen?`
            : `Warnung: Durch diesen Handel wird dein Lager für ${resNames[resType]} überlaufen. Du verlierst ca. ${overflow} Einheiten.
            Trotzdem annehmen?`;

        showConfirmationDialog(msg, "Ja", "Abbrechen", () => {
            form.onsubmit = null;
            form.submit();
        });
        return false;
    }
    return true;
}

function calculateLiveFee() {
    /** @type {HTMLInputElement} */
    const amountInputS = document.getElementById("sv"); // Supply Value
    /** @type {HTMLSelectElement} */
    const typeSelectS = document.getElementById('s');   // Supply Type
    /** @type {HTMLInputElement} */
    const amountInputD = document.getElementById("dv"); // Demand Value
    /** @type {HTMLSelectElement} */
    const typeSelectD = document.getElementById('d');   // Demand Type
    const feeDisplay = document.getElementById("live-fee");
    /** @type {{base: number, factors: Object<number, number>}} */
    const config = window.marketConfig;

    if (!amountInputS || !typeSelectS || !amountInputD || !typeSelectD || !feeDisplay || !config) return;

    const valS = parseInt(amountInputS.value) || 0;
    const typeS = typeSelectS.value;
    const valD = parseInt(amountInputD.value) || 0;
    const typeD = typeSelectD.value;

    const feeS = Math.floor(valS * (config.factors[typeS] || 0));
    const feeD = Math.floor(valD * (config.factors[typeD] || 0));

    const maxVarFee = Math.max(feeS, feeD);
    feeDisplay.innerText = config.base + maxVarFee;
}
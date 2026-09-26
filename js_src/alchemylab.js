let alchemyLiveInAmt = null;

registerAction("confirmCancelAlchemy", (el) => {
    showConfirmationDialog(
        "Möchtest du den Prozess wirklich abbrechen?\n\nDie unverbrauchten Rohstoffe werden sofort erstattet. Bereits fertige Erträge bleiben zur Abholung bereit.",
        "Ja, abbrechen",
        "Nein",
        () => {
            const form = el.closest("form");
            if (form) form.submit();
        }
    );
});
registerAction("fillAlchemyStartMax", () => {
    const configEl = document.getElementById("alchemy-config");
    const fromSel = document.getElementById("alchemy_from_res");
    const toSel = document.getElementById("alchemy_to_res");
    const amtInput = document.getElementById("alchemy_amount");
    if (!configEl || !fromSel || !toSel || !amtInput) return;

    const stocks = JSON.parse(configEl.dataset.stocks || "{}");
    const maxCap = parseInt(configEl.dataset.capacity) || 0;
    const from = parseInt(fromSel.value);
    const to = parseInt(toSel.value);
    const available = stocks[from] || 0;

    const step = getAlchemyStepSize(from, to);
    let maxAllowed = Math.min(available, maxCap);
    maxAllowed = maxAllowed - (maxAllowed % step);

    amtInput.value = Math.max(0, maxAllowed);
    updateAlchemyPreview();
});
registerAction("fillAlchemyTopUpMax", () => {
    const activeData = document.getElementById("alchemy-active-data");
    const input = document.getElementById("top_up_amount");
    if (!activeData || !input) return;

    const stock = parseFloat(activeData.dataset.stock) || 0;
    const capacity = parseFloat(activeData.dataset.capacity) || 0;
    const step = parseInt(activeData.dataset.step) || 1;
    const currentIn = (alchemyLiveInAmt !== null) ? alchemyLiveInAmt : (parseFloat(activeData.dataset.inAmt) || 0);

    const freeSpace = Math.max(0, capacity - currentIn);
    let maxPossible = Math.floor(Math.min(stock, freeSpace));
    maxPossible = maxPossible - (maxPossible % step);

    input.value = maxPossible > 0 ? maxPossible : "";

    updateTopUpButtonState();
});

function updateTopUpButtonState() {
    const activeData = document.getElementById("alchemy-active-data");
    const input = document.getElementById("top_up_amount");
    const btn = document.getElementById("btn_top_up");
    if (!activeData || !input || !btn) return;

    const step = parseInt(activeData.dataset.step) || 1;
    const capacity = parseFloat(activeData.dataset.capacity) || 0;
    const currentIn = (alchemyLiveInAmt !== null) ? alchemyLiveInAmt : (parseFloat(activeData.dataset.inAmt) || 0);
    const freeSpace = Math.max(0, capacity - currentIn);
    const usableFree = freeSpace - (freeSpace % step);

    const val = parseInt(input.value, 10) || 0;

    btn.disabled = (val < step || val > usableFree || usableFree < step);
}

function gcd(a, b) {
    return b === 0 ? a : gcd(b, a % b);
}

function getAlchemyStepSize(from, to, weights) {
    if (!weights || weights[from] === undefined || weights[to] === undefined) return 1;

    const wFrom = Math.round(weights[from] * 1000);
    const wTo = Math.round(weights[to] * 1000);

    return Math.max(1, Math.floor(wFrom / gcd(wFrom, wTo)));
}

function updateAlchemyDropdowns() {
    const fromSel = document.getElementById("alchemy_from_res");
    const toSel = document.getElementById("alchemy_to_res");
    if (!fromSel || !toSel) return;

    const fromVal = fromSel.value;
    const toVal = toSel.value;

    Array.from(toSel.options).forEach(opt => {
        opt.hidden = (opt.value === fromVal);
    });
    Array.from(fromSel.options).forEach(opt => {
        opt.hidden = (opt.value === toVal);
    });

    if (toSel.value === fromVal) {
        const nextOpt = Array.from(toSel.options).find(o => !o.hidden);
        if (nextOpt) toSel.value = nextOpt.value;
    }
}

function updateAlchemyPreview() {
    const configEl = document.getElementById("alchemy-config");
    const fromSel = document.getElementById("alchemy_from_res");
    const toSel = document.getElementById("alchemy_to_res");
    const amtInput = document.getElementById("alchemy_amount");
    const prevYield = document.getElementById("prev_yield");
    const prevTime = document.getElementById("prev_time");
    const startBtn = document.getElementById("btn_start_transmutation");

    if (!configEl || !fromSel || !toSel || !amtInput || !prevYield || !prevTime) return;

    const weights = JSON.parse(configEl.dataset.weights || "{}");
    const stocks = JSON.parse(configEl.dataset.stocks || "{}");
    const maxCap = parseInt(configEl.dataset.capacity) || 0;
    const speed = parseInt(configEl.dataset.speed) || 0;

    const from = parseInt(fromSel.value);
    const to = parseInt(toSel.value);
    const availableStock = stocks[from] || 0;
    const maxAllowed = Math.min(availableStock, maxCap);

    let rawVal = parseInt(amtInput.value.replace(/[^0-9]/g, '')) || 0;
    if (rawVal > maxAllowed) {
        rawVal = maxAllowed;
        amtInput.value = rawVal > 0 ? rawVal : "";
    }

    const step = getAlchemyStepSize(from, to, JSON.parse(configEl.dataset.weights || "{}"));
    const effectiveAmt = rawVal - (rawVal % step);

    if (from === to) {
        prevYield.innerText = "Ungültig";
        prevTime.innerText = "-";
        if (startBtn) startBtn.disabled = true;
        return;
    }

    const baseRatio = (weights[to] !== undefined && weights[from] !== undefined)
        ? (weights[to] / weights[from])
        : 0;

    const yieldVal = Math.floor(effectiveAmt * baseRatio);

    if (rawVal <= 0) {
        prevYield.innerText = "0";
    } else if (yieldVal <= 0) {
        prevYield.innerHTML = `<span class='error'>0</span>`;
    } else {
        prevYield.innerHTML = `<span class='passed'>${yieldVal.toLocaleString("de-DE")}</span>`;
    }

    if (startBtn) {
        startBtn.disabled = (effectiveAmt <= 0 || from === to || yieldVal <= 0);
    }

    const fromWeight = weights[from] !== undefined ? weights[from] : 1.0;
    const effectiveSpeed = speed * fromWeight;

    if (effectiveAmt > 0 && effectiveSpeed > 0) {
        const totalSec = Math.ceil((effectiveAmt / effectiveSpeed) * 3600);
        const h = Math.floor(totalSec / 3600);
        const m = Math.floor((totalSec % 3600) / 60);
        const s = totalSec % 60;

        let parts = [];
        if (h > 0) parts.push(h + " Std.");
        if (m > 0) parts.push(m + " Min.");
        if (s > 0 || parts.length === 0) parts.push(s + " Sek.");

        prevTime.innerText = parts.join(" ");
    } else {
        prevTime.innerText = "0 Sek.";
    }
}

function initAlchemyLiveTicker() {
    const activeData = document.getElementById("alchemy-active-data");
    if (!activeData) return;

    let inAmt = parseFloat(activeData.dataset.inAmt) || 0;
    let outAmt = parseFloat(activeData.dataset.outAmt) || 0;
    const inRate = parseFloat(activeData.dataset.inRate) || 0;
    const outRate = parseFloat(activeData.dataset.outRate) || 0;
    const capacity = parseFloat(activeData.dataset.capacity) || 1;
    const maxOutput = parseFloat(activeData.dataset.maxOutput) || 50000;

    const topUpInput = document.getElementById("top_up_amount");
    const topUpMaxBtn = document.querySelector('[data-on-click="fillAlchemyTopUpMax"]');
    const stock = parseFloat(activeData.dataset.stock) || 0;
    const step = parseInt(activeData.dataset.step) || 1;

    alchemyLiveInAmt = inAmt;

    const barEl = document.getElementById("alchemy-live-bar");
    const kettleEl = document.getElementById("live-kettle-in");
    const freeSpaceEl = document.getElementById("live-free-space");
    const claimEl = document.getElementById("live-claim-val");
    const claimBtn = document.getElementById("btn-claim");

    const updateUI = () => {
        const rawFree = Math.max(0, Math.floor(capacity - inAmt));
        const usableFree = rawFree - (rawFree % step);

        const percent = Math.min(100, Math.max(0, ((capacity - inAmt) / capacity) * 100));
        if (barEl) barEl.style.width = percent.toFixed(1) + "%";
        if (kettleEl) kettleEl.innerText = Math.max(0, Math.round(inAmt)).toLocaleString("de-DE");
        if (freeSpaceEl) freeSpaceEl.innerText = usableFree.toLocaleString("de-DE");

        if (claimEl) {
            const safeClaimable = Math.floor(Math.round(outAmt * 100) / 100);
            claimEl.innerText = safeClaimable.toLocaleString("de-DE");
        }

        if (claimBtn && Math.floor(outAmt) > 0) {
            claimBtn.disabled = false;
        }

        if (topUpInput && stock > 0) {
            const hasRoom = usableFree >= step;
            topUpInput.disabled = !hasRoom;
            if (topUpMaxBtn) topUpMaxBtn.disabled = !hasRoom;

            updateTopUpButtonState();
        }
    };

    updateUI();

    if (inAmt > 0 && inRate > 0) {
        setInterval(() => {
            if (outAmt >= maxOutput) return;

            const room = maxOutput - outAmt;
            const maxStepIn = room / (outRate / inRate);
            const stepIn = Math.min(inAmt, inRate, maxStepIn);

            inAmt -= stepIn;
            outAmt += (stepIn * (outRate / inRate));
            alchemyLiveInAmt = inAmt;

            updateUI();
        }, 1000);
    }
}

document.addEventListener("DOMContentLoaded", () => {
    const fromSel = document.getElementById("alchemy_from_res");
    const toSel = document.getElementById("alchemy_to_res");
    const amtInput = document.getElementById("alchemy_amount");

    if (fromSel && toSel && amtInput) {
        fromSel.addEventListener("change", () => {
            updateAlchemyDropdowns();
            updateAlchemyPreview();
        });

        toSel.addEventListener("change", () => {
            updateAlchemyDropdowns();
            updateAlchemyPreview();
        });

        amtInput.addEventListener("input", function () {
            this.value = this.value.replace(/[^0-9]/g, '');
            updateAlchemyPreview();
        });

        updateAlchemyDropdowns();
        updateAlchemyPreview();
    }

    const topUpInput = document.getElementById("top_up_amount");
    if (topUpInput) {
        topUpInput.addEventListener("input", function () {
            this.value = this.value.replace(/[^0-9]/g, '');
            const activeData = document.getElementById("alchemy-active-data");
            if (!activeData) return;

            const stock = parseFloat(activeData.dataset.stock) || 0;
            const capacity = parseFloat(activeData.dataset.capacity) || 0;
            const currentIn = (alchemyLiveInAmt !== null) ? alchemyLiveInAmt : (parseFloat(activeData.dataset.inAmt) || 0);
            const freeSpace = Math.max(0, capacity - currentIn);
            const maxPossible = Math.max(0, Math.floor(Math.min(stock, freeSpace)));

            let val = parseInt(this.value, 10) || 0;
            if (val > maxPossible) {
                this.value = maxPossible > 0 ? maxPossible : "";
            }

            updateTopUpButtonState();
        });
    }

    initAlchemyLiveTicker();
});
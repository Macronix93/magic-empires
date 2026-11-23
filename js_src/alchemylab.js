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

    const capacity = parseFloat(activeData.dataset.capacity) || 0;
    const currentIn = (alchemyLiveInAmt !== null) ? alchemyLiveInAmt : (parseFloat(activeData.dataset.inAmt) || 0);
    const freeSpace = Math.max(0, capacity - currentIn);
    const step = parseInt(activeData.dataset.step) || 1;

    const val = parseInt(input.value, 10) || 0;
    const effectiveVal = val - (val % step);
    btn.disabled = (val <= 0 || val > freeSpace || effectiveVal <= 0);
}

function gcd(a, b) {
    return b === 0 ? a : gcd(b, a % b);
}

function getAlchemyStepSize(from, to, weights = null) {
    if (!weights) {
        const configEl = document.getElementById("alchemy-config");
        if (configEl && configEl.dataset.weights) {
            try {
                weights = JSON.parse(configEl.dataset.weights);
            } catch (e) {
            }
        }
    }
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

    const initInAmt = parseInt(activeData.dataset.inAmt, 10) || 0;
    const initOutAmt = parseInt(activeData.dataset.outAmt, 10) || 0;
    const speedPerHour = parseInt(activeData.dataset.speedPerHour, 10) || 0;
    const outputPerStep = parseInt(activeData.dataset.outputPerStep, 10) || 1;
    const elapsedCarry = parseFloat(activeData.dataset.elapsedCarry) || 0;
    const capacity = parseInt(activeData.dataset.capacity, 10) || 1;
    const maxOutput = parseInt(activeData.dataset.maxOutput, 10) || 50000;
    const stock = parseInt(activeData.dataset.stock, 10) || 0;
    const step = parseInt(activeData.dataset.step, 10) || 1;

    const barEl = document.getElementById("alchemy-live-bar");
    const kettleEl = document.getElementById("live-kettle-in");
    const claimEl = document.getElementById("live-claim-val");
    const claimBtn = document.getElementById("btn-claim");
    const topUpInput = document.getElementById("top_up_amount");
    const topUpMaxBtn = document.querySelector('[data-on-click="fillAlchemyTopUpMax"]');
    const countdownWrap = document.getElementById("alchemy-countdown-wrap");

    const maxStepsByInput = Math.floor(initInAmt / step);
    const maxStepsByBuffer = Math.floor(Math.max(0, maxOutput - initOutAmt) / outputPerStep);
    const maxPossibleSteps = Math.min(maxStepsByInput, maxStepsByBuffer);

    const startTimeMs = Date.now();
    let inAmt = initInAmt;
    let outAmt = initOutAmt;
    alchemyLiveInAmt = inAmt;

    const updateUI = () => {
        const rawFree = Math.max(0, capacity - inAmt);
        const usableFree = rawFree - (rawFree % step);
        const percent = Math.min(100, Math.max(0, ((capacity - inAmt) / capacity) * 100));

        if (barEl) barEl.style.width = percent.toFixed(1) + "%";
        if (kettleEl) kettleEl.innerText = Math.max(0, inAmt).toLocaleString("de-DE");
        if (claimEl) claimEl.innerText = outAmt.toLocaleString("de-DE");
        if (claimBtn && outAmt > 0) claimBtn.disabled = false;
        if (topUpInput && stock > 0) {
            const hasRoom = usableFree >= step;
            topUpInput.disabled = !hasRoom;
            if (topUpMaxBtn) topUpMaxBtn.disabled = !hasRoom;

            updateTopUpButtonState();
        }
    };

    updateUI();
    if (maxPossibleSteps <= 0 || speedPerHour <= 0) return;

    const tick = () => {
        const elapsedSec = ((Date.now() - startTimeMs) / 1000) + elapsedCarry;
        const stepsDone = Math.min(
            maxPossibleSteps,
            Math.floor((elapsedSec * speedPerHour + 1e-6) / (step * 3600))
        );

        inAmt = initInAmt - (stepsDone * step);
        outAmt = initOutAmt + (stepsDone * outputPerStep);
        alchemyLiveInAmt = inAmt;

        updateUI();

        if (stepsDone >= maxPossibleSteps) {
            clearInterval(tickerInterval);

            if (typeof stopCountdown === "function") stopCountdown("alchemy-countdown");
            if (countdownWrap) {
                if ((maxOutput - outAmt) < outputPerStep && inAmt >= step) {
                    countdownWrap.innerHTML = "<span class='error'>Puffer voll (Pausiert)</span>";
                } else if (inAmt === 0) {
                    countdownWrap.innerHTML = "<span class='passed'>Kessel leer</span>";
                    const cancelForm = document.getElementById("form-cancel-alchemy");
                    if (cancelForm) cancelForm.style.display = "none";
                } else {
                    countdownWrap.innerHTML = "<span class='warning'>Zu wenig im Kessel</span>";
                }
            }
        }
    };

    const tickerInterval = setInterval(tick, 1000);
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") tick();
    });
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

        amtInput.addEventListener("blur", function () {
            let rawVal = parseInt(this.value.replace(/[^0-9]/g, '')) || 0;
            if (rawVal > 0) {
                const step = getAlchemyStepSize(parseInt(fromSel.value), parseInt(toSel.value));
                const rounded = rawVal - (rawVal % step);
                this.value = rounded > 0 ? rounded : "";
                updateAlchemyPreview();
            }
        });

        const startForm = amtInput.closest("form");
        if (startForm) {
            startForm.addEventListener("submit", function () {
                const step = getAlchemyStepSize(parseInt(fromSel.value), parseInt(toSel.value));
                let rawVal = parseInt(amtInput.value.replace(/[^0-9]/g, '')) || 0;
                const rounded = rawVal - (rawVal % step);
                amtInput.value = rounded > 0 ? rounded : 0;
            });
        }

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

        topUpInput.addEventListener("blur", function () {
            let rawVal = parseInt(this.value.replace(/[^0-9]/g, '')) || 0;
            if (rawVal > 0) {
                const activeData = document.getElementById("alchemy-active-data");
                const step = parseInt(activeData?.dataset.step) || 1;
                const rounded = rawVal - (rawVal % step);
                this.value = rounded > 0 ? rounded : "";

                updateTopUpButtonState();
            }
        });

        const topUpForm = topUpInput.closest("form");
        if (topUpForm) {
            topUpForm.addEventListener("submit", function () {
                const activeData = document.getElementById("alchemy-active-data");
                const step = parseInt(activeData?.dataset.step) || 1;
                let rawVal = parseInt(topUpInput.value.replace(/[^0-9]/g, '')) || 0;
                const rounded = rawVal - (rawVal % step);
                topUpInput.value = rounded > 0 ? rounded : 0;
            });
        }
    }

    initAlchemyLiveTicker();
});
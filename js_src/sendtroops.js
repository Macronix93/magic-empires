registerAction("toggleAllUnitsSendTroops", (el) => {
    const showAll = el.checked;

    document.cookie = "me_list_view=" + (showAll ? "1" : "0") + "; path=/; max-age=31536000; SameSite=Lax";

    const tabs = document.getElementById("sendtroops-tabs");
    const header = document.getElementById("sendtroops-header");
    const dividers = document.querySelectorAll(".unit-category-divider");
    const rows = document.querySelectorAll(".unit-row");

    if (showAll) {
        if (tabs) tabs.style.display = "none";
        if (header) header.style.display = "none";

        dividers.forEach(div => div.style.display = "");
        rows.forEach(row => row.style.display = "");

        const url = new URL(window.location);
        url.searchParams.delete("cat");
        window.history.replaceState({}, '', url);
    } else {
        if (tabs) tabs.style.display = "";
        if (header) header.style.display = "";

        dividers.forEach(div => div.style.display = "none");

        const activeTab = tabs ? tabs.querySelector(".tablinks.active") : null;
        const activeCat = activeTab ? String(activeTab.dataset.category) : "0";

        rows.forEach(row => {
            const rowCat = String(row.getAttribute("data-unit-category"));
            row.style.display = (rowCat === activeCat) ? "" : "none";
        });

        const url = new URL(window.location);
        url.searchParams.set("cat", activeCat);
        window.history.replaceState({}, '', url);
    }
});
registerAction("filterSendTroops", (el) => {
    const category = String(el.dataset.category);
    const allRows = document.querySelectorAll(".unit-row");
    const allTabs = document.querySelectorAll("#sendtroops-tabs .tablinks");

    allTabs.forEach(tab => tab.classList.remove("active"));
    el.classList.add("active");

    document.querySelectorAll(".unit-category-divider").forEach(row => {
        row.style.display = "none";
    });

    allRows.forEach(row => {
        const rowCat = String(row.getAttribute("data-unit-category"));

        if (rowCat === category) {
            row.style.display = "";
        } else {
            row.style.display = "none";
        }
    });

    const url = new URL(window.location);
    url.searchParams.set("cat", category);
    window.history.replaceState({}, '', url);
});

function getSelectedTroopCountExcept(skipInput = null) {
    let sum = 0;
    document.querySelectorAll(".js-unit-input").forEach(inp => {
        if (inp !== skipInput) {
            sum += parseInt(inp.value) || 0;
        }
    });
    return sum;
}

registerAction("fillMaxAndRefresh", (el) => {
    const targetId = el.dataset.target;
    const maxValue = parseInt(el.dataset.value) || 0;
    const input = document.getElementById(targetId);

    if (input && maxValue > 0) {
        const form = document.getElementById("send-troops-form");
        let valToSet = maxValue;

        if (form && form.dataset.mineLimit !== undefined) {
            const mineLimit = parseInt(form.dataset.mineLimit);
            const otherUnits = getSelectedTroopCountExcept(input);
            const allowed = Math.max(0, mineLimit - otherUnits);
            valToSet = Math.min(valToSet, allowed);
        }

        input.value = valToSet > 0 ? valToSet : "";
        updateTroopSummary();
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
    const form = document.getElementById("send-troops-form");
    const hasMineLimit = form && form.dataset.mineLimit !== undefined;
    let remainingQuota = hasMineLimit ? parseInt(form.dataset.mineLimit) : Infinity;

    const inputs = document.querySelectorAll(".js-unit-input:not([disabled])");
    inputs.forEach(input => {
        const max = parseInt(input.dataset.max) || 0;
        if (max > 0 && remainingQuota > 0) {
            const take = Math.min(max, remainingQuota);
            input.value = take > 0 ? take : "";
            remainingQuota -= take;
        } else {
            input.value = "";
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
    const form = document.getElementById("send-troops-form");

    if (form && form.dataset.isMine === "true") {
        const scoutId = form.dataset.scoutId || "12";
        const scoutInput = document.getElementById("sol_" + scoutId);
        let hasScoutVal = scoutInput && parseInt(scoutInput.value) > 0;
        let hasNormalVal = false;

        inputs.forEach(inp => {
            if (inp.id !== "sol_" + scoutId && parseInt(inp.value) > 0) {
                hasNormalVal = true;
            }
        });

        inputs.forEach(inp => {
            const container = inp.closest("div");
            const buttons = container ? container.querySelectorAll("input[type='button']") : [];

            if (inp.id === "sol_" + scoutId) {
                if (hasNormalVal) {
                    inp.disabled = true;
                    buttons.forEach(b => b.disabled = true);
                    if (document.activeElement !== inp) inp.value = "";
                } else {
                    inp.disabled = false;
                    buttons.forEach(b => b.disabled = false);
                }
            } else {
                if (hasScoutVal) {
                    inp.disabled = true;
                    buttons.forEach(b => b.disabled = true);
                    if (document.activeElement !== inp) inp.value = "";
                } else {
                    inp.disabled = false;
                    buttons.forEach(b => b.disabled = false);
                }
            }
        });
    }

    if (!summaryList || !summaryContainer || !summaryTotals) return;

    const hasMineLimit = form && form.dataset.mineLimit !== undefined;
    const mineLimit = hasMineLimit ? parseInt(form.dataset.mineLimit) : null;
    const mineCurrent = hasMineLimit ? parseInt(form.dataset.mineCurrent) : 0;
    const mineMax = hasMineLimit ? parseInt(form.dataset.mineMax) : 0;

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

    const mineCapDisplay = document.getElementById("mine-capacity-display");
    if (mineCapDisplay && hasMineLimit) {
        const isFriendly = form.dataset.mineFriendly === "true";

        if (isFriendly) {
            const totalWithTroops = mineCurrent + totalUnits;
            const isFull = totalWithTroops >= mineMax;
            const addedHtml = totalUnits > 0 ? ` <span class="${isFull ? 'warning' : 'passed'}">(+${totalUnits})</span>` : "";
            mineCapDisplay.innerHTML = `<b>${totalWithTroops}</b> / ${mineMax} Einheiten${addedHtml}`;
        } else {
            const isFull = totalUnits >= mineMax;
            mineCapDisplay.innerHTML = `<b class="${isFull ? 'warning' : ''}">${totalUnits}</b> / ${mineMax} Einheiten`;
        }
    }

    if (form && form.dataset.mineRemWork !== undefined) {
        const remWork = parseFloat(form.dataset.mineRemWork) || 0;
        const curAtk = parseFloat(form.dataset.mineCurrentAtk) || 0;
        const rateFactor = parseFloat(form.dataset.mineWorkFactor) || 0.15;

        const rateDisplay = document.getElementById("mine-rate-display");
        const durDisplay = document.getElementById("mine-duration-display");

        let hasOnlyScouts;
        let nonScoutCount = 0;
        let scoutCount = 0;

        inputs.forEach(input => {
            const val = parseInt(input.value) || 0;
            if (val > 0) {
                if (parseInt(input.dataset.id) === 12) scoutCount += val;
                else nonScoutCount += val;
            }
        });
        hasOnlyScouts = (scoutCount > 0 && nonScoutCount === 0);

        if (hasOnlyScouts) {
            if (rateDisplay) rateDisplay.innerHTML = `<i>Spionage-Mission (kein Abbau)</i>`;
            if (durDisplay) durDisplay.innerHTML = `-`;
        } else {
            const squadRate = totalAtk * rateFactor;
            const totalRate = (curAtk + totalAtk) * rateFactor;

            if (rateDisplay) {
                if (totalAtk > 0) {
                    const formattedTotalRate = totalRate.toFixed(1).replace('.', ',');
                    const formattedSquadRate = squadRate.toFixed(1).replace('.', ',');
                    const totalText = curAtk > 0 ? ` <small style="opacity: 0.7;">(Gesamt: ${formattedTotalRate}/s)</small>` : "";
                    rateDisplay.innerHTML = `+${formattedSquadRate} Pkt./s${totalText}`;
                } else if (curAtk > 0) {
                    const formattedCurRate = (curAtk * rateFactor).toFixed(1).replace('.', ',');
                    rateDisplay.innerHTML = `${formattedCurRate} Pkt./s`;
                } else {
                    rateDisplay.innerHTML = `<i>Keine Schürfer</i>`;
                }
            }

            if (durDisplay) {
                if (totalRate > 0 && remWork > 0) {
                    const estSec = Math.ceil(remWork / totalRate);
                    durDisplay.innerHTML = `ca. ${formatMineDuration(estSec)}`;
                } else if (remWork <= 0) {
                    durDisplay.innerHTML = `<span class="passed">Erschöpft</span>`;
                } else {
                    durDisplay.innerHTML = `<i>Stillstand (Truppen wählen)</i>`;
                }
            }
        }
    }

    if (actionButtons) {
        actionButtons.style.display = "flex";
        const submitBtn = actionButtons.querySelector('input[type="submit"]');
        if (submitBtn) {
            let disabled = (totalUnits <= 0);
            if (hasMineLimit && totalUnits > mineLimit) {
                disabled = true;
            }
            submitBtn.disabled = disabled;
        }
    }
}

function formatMineDuration(totalSeconds) {
    if (totalSeconds <= 0) return "0 Sek.";

    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    let parts = [];
    if (days > 0) parts.push(days + "T");
    if (hours > 0) parts.push(hours + " Std.");
    if (minutes > 0) parts.push(minutes + " Min.");
    if (seconds > 0 && days === 0) parts.push(seconds + " Sek.");

    return parts.length > 0 ? parts.join(" ") : "wenigen Sekunden";
}

document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("send-troops-form");

    if (form) {
        form.addEventListener("input", (e) => {
            if (e.target.classList.contains("js-unit-input")) {
                const hasMineLimit = form.dataset.mineLimit !== undefined;
                if (hasMineLimit) {
                    const mineLimit = parseInt(form.dataset.mineLimit);
                    const otherUnits = getSelectedTroopCountExcept(e.target);
                    const allowedForThis = Math.max(0, mineLimit - otherUnits);
                    const maxOwn = parseInt(e.target.dataset.max) || 0;

                    let rawVal = parseInt(e.target.value.replace(/[^0-9]/g, '')) || 0;
                    const cap = Math.min(maxOwn, allowedForThis);

                    if (rawVal > cap) {
                        e.target.value = cap > 0 ? cap : "";
                    }
                }
                updateTroopSummary();
            }
        });

        form.addEventListener("submit", () => {
            sessionStorage.setItem("restore_map_filters_after_send", "true");
        });

        updateTroopSummary();
    }
});
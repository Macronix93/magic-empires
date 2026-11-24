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
registerAction("fillMaxAndRefresh", (el) => {
    const targetId = el.dataset.target;
    const maxValue = parseInt(el.dataset.value) || 0;
    const input = document.getElementById(targetId);

    if (input && maxValue > 0) {
        const form = document.getElementById("send-troops-form");
        let valToSet = maxValue;

        if (form && form.dataset.isGift === "true") {
            document.querySelectorAll(".js-unit-input").forEach(inp => inp.value = "");
            valToSet = 1;
        } else {
            const quotaLimit = getEffectiveQuotaLimit(form);

            if (quotaLimit !== null) {
                const otherUnits = getSelectedTroopCountExcept(input);
                const allowed = Math.max(0, quotaLimit - otherUnits);

                valToSet = Math.min(valToSet, allowed);
            }

            if (form && form.dataset.isStation === "true") {
                const exemptIds = getExemptUnitIds(form);

                if (!exemptIds.includes(String(input.dataset.id))) {
                    const stationLimit = parseInt(form.dataset.stationLimit, 10);

                    if (!isNaN(stationLimit)) {
                        const otherNonExempt = getSelectedTroopCountExcept(input, exemptIds);
                        valToSet = Math.min(valToSet, Math.max(0, stationLimit - otherNonExempt));
                    }
                }
            }
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
    const quotaLimit = getEffectiveQuotaLimit(form);
    let remainingQuota = quotaLimit !== null ? quotaLimit : Infinity;

    const isMine = form && form.dataset.isMine === "true";
    const scoutId = form?.dataset.scoutId ? String(form.dataset.scoutId) : null;
    const isStation = form && form.dataset.isStation === "true";
    const stationLimitVal = isStation ? parseInt(form.dataset.stationLimit, 10) : NaN;
    let remainingStationQuota = (isStation && !isNaN(stationLimitVal)) ? stationLimitVal : Infinity;
    const exemptIds = isStation ? getExemptUnitIds(form) : [];
    let hasNormalUnitsAvailable = false;

    if (isMine) {
        document.querySelectorAll(".js-unit-input:not([disabled])").forEach(inp => {
            if (inp.id !== "sol_" + scoutId && (parseInt(inp.dataset.max) || 0) > 0) {
                hasNormalUnitsAvailable = true;
            }
        });
    }

    const inputs = document.querySelectorAll(".js-unit-input:not([disabled])");
    inputs.forEach(input => {
        if (isMine && hasNormalUnitsAvailable && input.id === "sol_" + scoutId) {
            input.value = "";
            return;
        }

        const max = parseInt(input.dataset.max) || 0;
        const isExempt = exemptIds.includes(String(input.dataset.id));
        const capByStation = (isStation && !isExempt) ? remainingStationQuota : Infinity;
        const effectiveRemaining = Math.min(remainingQuota, capByStation);

        if (max > 0 && effectiveRemaining > 0) {
            const take = Math.min(max, effectiveRemaining);
            input.value = take > 0 ? take : "";
            remainingQuota -= take;

            if (isStation && !isExempt) {
                remainingStationQuota -= take;
            }
        } else {
            input.value = "";
        }
    });

    if (typeof updateTroopSummary === "function") {
        updateTroopSummary();
    }
});
registerAction("submitSendTroopsForm", (form, e) => {
    if (e) e.preventDefault();
    if (form.dataset.submitting === "true") return;

    const submitBtn = form.querySelector('input[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.dataset.originalVal = submitBtn.value;
        submitBtn.value = "Wird entsendet...";
    }
    form.dataset.submitting = "true";

    showActionLoader("Entsende Truppen...", form.closest(".overlay-modal"));

    const formData = new FormData(form);
    formData.append("from_page", window.location.pathname);

    const targetUrl = form.dataset.ajaxUrl || "ajax/send_troops.php";
    fetch(targetUrl, {
        method: "POST",
        headers: {"X-Requested-With": "XMLHttpRequest"},
        body: formData
    })
        .then(r => r.json())
        .then(data => {
            delete form.dataset.submitting;

            if (data.success) {
                if (window.location.pathname.includes("events.php") || window.location.pathname.includes("barracks.php")) {
                    window.location.reload();
                    return;
                }

                hideActionLoader();
                closeOverlay(form);

                const mapPopup = document.getElementById("field-popup-box");
                if (mapPopup) {
                    mapPopup.style.display = "none";
                }

                showGameMessage(data.message || "Truppen erfolgreich gesendet!", "passed");

                if (typeof gameConfig !== "undefined" && gameConfig.currentKingdom) {
                    if (data.troops) {
                        gameConfig.currentKingdom.troops = data.troops;
                    }

                    gameConfig.currentKingdom.occupiedCommands++;
                    if (gameConfig.currentKingdom.occupiedCommands >= gameConfig.currentKingdom.maxCommands) {
                        gameConfig.currentKingdom.commandsFull = true;
                    }

                    if (typeof updateMapCommandDisplay === "function") {
                        updateMapCommandDisplay();
                    }
                }

                if (typeof refreshMapDataSilently === "function") {
                    refreshMapDataSilently(true);
                }
            } else {
                hideActionLoader();
                showGameError(data.error || "Fehler beim Absenden.");

                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.value = submitBtn.dataset.originalVal || "Truppen schicken";
                }
            }
        })
        .catch(err => {
            delete form.dataset.submitting;

            hideActionLoader();
            showGameError("Fehler beim Truppenversand: " + err);

            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.value = submitBtn.dataset.originalVal || "Truppen schicken";
            }
        });
});

function getExemptUnitIds(form) {
    if (!form || !form.dataset.exemptIds) return [];

    try {
        return JSON.parse(form.dataset.exemptIds).map(String);
    } catch (e) {
        return [];
    }
}

function getSelectedTroopCountExcept(skipInput = null, exemptIds = []) {
    let sum = 0;

    document.querySelectorAll(".js-unit-input").forEach(inp => {
        if (inp !== skipInput && !exemptIds.includes(String(inp.dataset.id))) {
            sum += parseInt(inp.value) || 0;
        }
    });

    return sum;
}

function getEffectiveQuotaLimit(form) {
    if (!form) return null;
    if (form.dataset.isGift === "true") return 1;

    let limits = [];
    if (form.dataset.troopLimit !== undefined) {
        limits.push(parseInt(form.dataset.troopLimit, 10));
    }

    if (form.dataset.mineLimit !== undefined) {
        limits.push(parseInt(form.dataset.mineLimit, 10));
    }

    if (form.dataset.supportLimit !== undefined) {
        limits.push(parseInt(form.dataset.supportLimit, 10));
    }

    limits = limits.filter(n => !isNaN(n));
    return limits.length > 0 ? Math.min(...limits) : null;
}

function updateTroopSummary() {
    const inputs = document.querySelectorAll(".js-unit-input");
    const summaryList = document.getElementById("troop-summary-list");
    const summaryTotals = document.getElementById("troop-summary-totals");
    const summaryContainer = document.getElementById("troop-summary-container");
    const actionButtons = document.getElementById("troop-action-buttons");
    const form = document.getElementById("send-troops-form");
    const isStation = !!(form && form.dataset.isStation === "true");
    const selectedStationUnits = new Map();

    const scoutId = String(form?.dataset.scoutId || "12");
    const thiefId = String(form?.dataset.thiefId || "11");
    const exemptIds = getExemptUnitIds(form);

    if (form && form.dataset.isMine === "true") {
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

            const maxCap = parseInt(inp.dataset.max, 10) || 0;
            if (maxCap <= 0) {
                inp.disabled = true;
                buttons.forEach(b => b.disabled = true);
                return;
            }

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
    let totalMiningUnits = 0;
    let nonExemptUnits = 0;
    let totalAtk = 0;
    let totalDef = 0;
    let scoutCount = 0;
    let thiefCount = 0;
    let otherCount = 0;

    inputs.forEach(input => {
        let rawValue = input.value;
        if (rawValue === "") return;

        let cleanValue = rawValue.replace(/[^0-9]/g, '');
        let val = parseInt(cleanValue) || 0;
        const max = parseInt(input.dataset.max) || 0;

        if (val > max) {
            val = max;
            input.value = max > 0 ? max : "";
        } else if (rawValue !== cleanValue) {
            input.value = cleanValue;
        }

        if (val > 0) {
            const uId = String(input.dataset.id);
            if (uId === scoutId) {
                scoutCount += val;
            } else if (uId === thiefId) {
                thiefCount += val;
            } else {
                otherCount += val;
            }

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

            if (input.dataset.id !== scoutId) {
                totalMiningUnits += val;
            }

            if (!exemptIds.includes(uId)) {
                nonExemptUnits += val;
            }

            if (isStation) {
                selectedStationUnits.set(uId, {
                    count: val,
                    name: name,
                    iconPath: iconPath
                });
            }
        }
    });

    const stealthWarningEl = document.getElementById("stealth-warning-box");
    if (stealthWarningEl) {
        if (scoutCount > 0 && thiefCount > 0 && otherCount === 0) {
            stealthWarningEl.innerHTML = `
                <div class="info-box event-warning" style="margin: 0; text-align: left;">
                    <img src="images/icons/icon_warning.png" alt="Hinweis">
                    <span>
                        <b>Achtung:</b> Werden Späher und Diebe gemeinsam ohne reguläre Armee entsandt, 
                        wird dies als offener PvP-Kampf gegen die Garnison gewertet und löst Alarm beim feindlichen Wachturm aus!
                        Gestohlene Ressourcen und Spionageberichte können dennoch generiert werden bei erfolgreichem Kampf.
                    </span>
                </div>`;
            stealthWarningEl.style.display = "block";
        } else {
            stealthWarningEl.style.display = "none";
            stealthWarningEl.innerHTML = "";
        }
    }

    if (isStation) {
        summaryList.innerHTML = "";
        summaryTotals.innerHTML = "";
        summaryContainer.style.display = "none";

        const targetList = document.getElementById("target-kingdom-troops-list");
        const emptyMsg = document.getElementById("target-kingdom-empty-msg");
        if (targetList) {
            targetList.querySelectorAll(".js-station-added-badge").forEach(el => el.remove());

            const existingBadges = targetList.querySelectorAll(".unit-badge[data-base-count]");
            existingBadges.forEach(badge => {
                const sid = String(badge.dataset.soldierId);
                const baseCount = parseInt(badge.dataset.baseCount, 10) || 0;
                const baseName = badge.dataset.name || "";
                const bEl = badge.querySelector("b");
                const added = selectedStationUnits.get(sid);

                if (added && added.count > 0) {
                    const newTotal = baseCount + added.count;
                    if (bEl) {
                        bEl.textContent = formatNumJS(newTotal);
                        bEl.classList.add("passed");
                    }
                    badge.title = `${baseName} (${baseCount.toLocaleString("de-DE")} + ${added.count.toLocaleString("de-DE")})`;
                    selectedStationUnits.delete(sid);
                } else {
                    if (bEl) {
                        bEl.textContent = formatNumJS(baseCount);
                        bEl.classList.remove("passed");
                    }
                    badge.title = baseName;
                }
            });

            selectedStationUnits.forEach((u, sid) => {
                const badge = document.createElement("div");
                badge.className = "unit-badge js-station-added-badge";
                badge.dataset.soldierId = sid;
                badge.title = `${u.name} (+${u.count.toLocaleString("de-DE")})`;

                const img = document.createElement("img");
                img.src = u.iconPath;
                img.className = "ressource-icons";
                img.alt = u.name;

                const b = document.createElement("b");
                b.className = "passed";
                b.textContent = formatNumJS(u.count);

                badge.append(img, b);
                targetList.appendChild(badge);
            });

            if (emptyMsg) {
                const hasAnyVisibleBadge = targetList.querySelectorAll(".unit-badge").length > 0;
                emptyMsg.style.display = hasAnyVisibleBadge ? "none" : "";
            }
        }
    } else {
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
    }

    const summaryCountEl = document.getElementById("troop-summary-count");
    if (summaryCountEl) {
        summaryCountEl.innerText = totalUnits.toLocaleString("de-DE");
    }

    const mineCapDisplay = document.getElementById("mine-capacity-display");
    if (mineCapDisplay && hasMineLimit) {
        const isFriendly = form.dataset.mineFriendly === "true";

        if (isFriendly) {
            const totalWithTroops = mineCurrent + totalMiningUnits;
            const isFull = totalWithTroops >= mineMax;
            const addedHtml = totalMiningUnits > 0 ? ` <span class="${isFull ? "warning" : "passed"}">(+${totalMiningUnits})</span>` : "";

            mineCapDisplay.innerHTML = `<b>${totalWithTroops}</b> / ${mineMax} Einheiten${addedHtml}`;
        } else {
            const isFull = totalMiningUnits >= mineMax;

            mineCapDisplay.innerHTML = `<b class="${isFull ? "warning" : ''}">${totalMiningUnits}</b> / ${mineMax} Einheiten`;
        }
    }

    const supportCapDisplay = document.getElementById("support-capacity-display");
    if (supportCapDisplay && form && form.dataset.isSupport === "true") {
        const supportCurrent = parseInt(form.dataset.supportCurrent) || 0;
        const supportMax = parseInt(form.dataset.supportMax) || 0;
        const totalWithTroops = supportCurrent + totalUnits;
        const isFull = totalWithTroops > supportMax;
        const addedHtml = totalUnits > 0 ? ` <span class="${isFull ? "error" : "passed"}">(+${totalUnits})</span>` : "";

        supportCapDisplay.className = isFull ? "error" : (totalWithTroops === supportMax ? "warning" : "passed");
        supportCapDisplay.innerHTML = `<b>${totalWithTroops}</b>${addedHtml}`;
    }

    const stationCapDisplay = document.getElementById("station-capacity-display");
    if (stationCapDisplay && form && form.dataset.isStation === "true") {
        const stationCurrent = parseInt(form.dataset.stationCurrent, 10) || 0;
        const stationMax = parseInt(form.dataset.stationMax, 10) || 0;
        const totalWithTroops = stationCurrent + nonExemptUnits;
        const isOver = totalWithTroops > stationMax;
        const isFull = totalWithTroops >= stationMax;
        const addedHtml = nonExemptUnits > 0
            ? ` <span class="${isOver ? "error" : (isFull ? "warning" : "passed")}">(+${nonExemptUnits.toLocaleString("de-DE")})</span>`
            : "";

        stationCapDisplay.className = isOver ? "error" : (isFull ? "warning" : "passed");
        stationCapDisplay.innerHTML = `<b>${totalWithTroops.toLocaleString("de-DE")}</b>${addedHtml}`;
    }

    if (form && form.dataset.mineRemWork !== undefined) {
        const workTotal = parseFloat(form.dataset.mineWorkTotal) || 0;
        const curAtk = parseFloat(form.dataset.mineCurrentAtk) || 0;
        const rateFactor = parseFloat(form.dataset.mineWorkFactor) || 0.0075;
        const mineMinDuration = parseInt(form.dataset.mineMinDuration) || 1800;
        const maxRate = workTotal / mineMinDuration;
        const workRem = parseFloat(form.dataset.mineRemWork) || workTotal;
        const isFriendly = form.dataset.mineFriendly === "true";

        const rateDisplay = document.getElementById("mine-rate-display");
        const durDisplay = document.getElementById("mine-duration-display");

        let hasOnlyScouts;
        let nonScoutCount = 0;
        let scoutCount = 0;
        const scoutId = form?.dataset.scoutId ? String(form.dataset.scoutId) : null;

        inputs.forEach(input => {
            const val = parseInt(input.value) || 0;
            if (val > 0) {
                if (input.dataset.id === scoutId) scoutCount += val;
                else nonScoutCount += val;
            }
        });
        hasOnlyScouts = (scoutCount > 0 && nonScoutCount === 0);

        if (hasOnlyScouts) {
            if (rateDisplay) rateDisplay.innerHTML = `<i>Spionage-Mission (kein Abbau)</i>`;
            if (durDisplay) durDisplay.innerHTML = `-`;
        } else {
            const squadRate = Math.min(totalAtk * rateFactor, maxRate);
            const totalRate = Math.min((curAtk + totalAtk) * rateFactor, maxRate);

            if (rateDisplay) {
                if (totalAtk > 0) {
                    const formattedSquadRate = squadRate.toFixed(1).replace('.', ',');
                    const totalText = curAtk > 0 ? ` <small style="opacity: 0.7;">(Gesamt: ${totalRate.toFixed(1).replace('.', ',')}/s)</small>` : "";
                    rateDisplay.innerHTML = `+${formattedSquadRate} Pkt./s${totalText}`;
                } else if (curAtk > 0) {
                    rateDisplay.innerHTML = `${(curAtk * rateFactor).toFixed(1).replace('.', ',')} Pkt./s`;
                } else {
                    rateDisplay.innerHTML = `<i>Keine Truppen gewählt</i>`;
                }
            }

            if (durDisplay) {
                if (totalRate > 0 && workTotal > 0) {
                    const estSec = isFriendly
                        ? Math.ceil(workRem / totalRate)
                        : Math.max(mineMinDuration, Math.ceil(workTotal / totalRate));

                    durDisplay.innerHTML = `ca. ${formatMineDuration(estSec)}`;
                } else {
                    durDisplay.innerHTML = `<i>Keine Truppen gewählt</i>`;
                }
            }
        }
    }

    if (actionButtons) {
        actionButtons.style.display = "flex";

        const submitBtn = actionButtons.querySelector('input[type="submit"]');
        if (submitBtn) {
            let disabled = (totalUnits <= 0);

            const isGift = form && form.dataset.isGift === "true";
            if (isGift && totalUnits !== 1) {
                disabled = true;
            }

            if (hasMineLimit && totalUnits > mineLimit) {
                disabled = true;
            }

            if (form && form.dataset.isSupport === "true") {
                const supportCurrent = parseInt(form.dataset.supportCurrent) || 0;
                const supportMax = parseInt(form.dataset.supportMax) || 0;

                if ((supportCurrent + totalUnits) > supportMax) {
                    disabled = true;
                }
            }

            if (form && form.dataset.isStation === "true") {
                const stationLimit = parseInt(form.dataset.stationLimit, 10);

                if (!isNaN(stationLimit) && nonExemptUnits > stationLimit) {
                    disabled = true;
                }
            }

            const hasTroopLimit = form && form.dataset.troopLimit !== undefined;
            const troopLimit = hasTroopLimit ? parseInt(form.dataset.troopLimit, 10) : null;
            if (hasTroopLimit && totalUnits > troopLimit) {
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

document.addEventListener("input", (e) => {
    if (e.target.classList.contains("js-unit-input")) {
        const form = e.target.closest("#send-troops-form");

        if (form && form.dataset.isGift === "true") {
            let rawVal = parseInt(e.target.value.replace(/[^0-9]/g, '')) || 0;
            if (rawVal > 1) {
                e.target.value = 1;
            }

            document.querySelectorAll(".js-unit-input").forEach(inp => {
                if (inp !== e.target) inp.value = "";
            });
        }

        const quotaLimit = getEffectiveQuotaLimit(form);
        let cap = parseInt(e.target.dataset.max) || 0;

        if (quotaLimit !== null) {
            const otherUnits = getSelectedTroopCountExcept(e.target);
            const allowedForThis = Math.max(0, quotaLimit - otherUnits);
            cap = Math.min(cap, allowedForThis);
        }

        if (form && form.dataset.isStation === "true") {
            const exemptIds = getExemptUnitIds(form);
            if (!exemptIds.includes(String(e.target.dataset.id))) {
                const stationLimit = parseInt(form.dataset.stationLimit, 10);
                if (!isNaN(stationLimit)) {
                    const otherNonExempt = getSelectedTroopCountExcept(e.target, exemptIds);
                    cap = Math.min(cap, Math.max(0, stationLimit - otherNonExempt));
                }
            }
        }

        let rawVal = parseInt(e.target.value.replace(/[^0-9]/g, '')) || 0;
        if (rawVal > cap) {
            e.target.value = cap > 0 ? cap : "";
        }

        updateTroopSummary();
    }
});

document.addEventListener("DOMContentLoaded", () => {
    updateTroopSummary();
});
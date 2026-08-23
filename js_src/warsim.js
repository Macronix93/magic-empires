const warsimDataEl = document.getElementById("warsim-data");
const soldierTypes = warsimDataEl ? JSON.parse(warsimDataEl.dataset.soldiers) : [];
const warsimConstEl = document.getElementById("warsim-const");
const W_CONF = {
    infAtk: parseInt(warsimConstEl.dataset.inf_atk),
    infDef: parseInt(warsimConstEl.dataset.inf_def),
    cavAtk: parseInt(warsimConstEl.dataset.cav_atk),
    cavDef: parseInt(warsimConstEl.dataset.cav_def),
    arcAtk: parseInt(warsimConstEl.dataset.arc_atk),
    arcDef: parseInt(warsimConstEl.dataset.arc_def),
    wallDefaultHp: parseInt(warsimConstEl.dataset.wall_default_hp),
    wallHpInc: parseInt(warsimConstEl.dataset.wall_hp_inc),
    wallMinDef: parseInt(warsimConstEl.dataset.wall_min_def),
    wallMaxDef: parseInt(warsimConstEl.dataset.wall_max_def),
    wallFactor: parseFloat(warsimConstEl.dataset.wall_factor),
    wallAbsorptionPerLevel: parseInt(warsimConstEl.dataset.wall_absorption_per_lvl),
    wallEffDmgFactor: parseFloat(warsimConstEl.dataset.wall_eff_dmg_factor),
    wallAccDmgFactor: parseFloat(warsimConstEl.dataset.wall_acc_dmg_factor),
    siegeBonus: parseFloat(warsimConstEl.dataset.siege_bonus),
    ramFactor: parseFloat(warsimConstEl.dataset.ram_factor),
    ramLimit: parseFloat(warsimConstEl.dataset.ram_limit),
    ramFlat: parseFloat(warsimConstEl.dataset.ram_flat),
    maxLvl: parseInt(warsimConstEl.dataset.max_lvl),
    rpsBonus: parseFloat(warsimConstEl.dataset.rps_bonus),
    lethalityPvp: parseFloat(warsimConstEl.dataset.lethality_pvp),
    lethalityPve: parseFloat(warsimConstEl.dataset.lethality_pve),
    monsterDmgClampedMaxVal: parseFloat(warsimConstEl.dataset.monster_dmg_clamped_max_val),
    monsterDmgLossExponent: parseFloat(warsimConstEl.dataset.monster_dmg_loss_exponent)
};
let currentSimWallHp = null;
let lastSimState = null;

registerAction("undoWarSim", () => {
    if (!lastSimState) return;

    lastSimState.inputs.forEach(item => {
        const el = document.getElementById(item.id);

        if (el) {
            el.value = item.value;
            el.style.color = "";
        }
    });

    currentSimWallHp = lastSimState.wallHp;

    updateLivePowerSummary();

    document.getElementById("btn-undo").disabled = true;
});
registerAction("switchSimTab", (el) => {
    const target = el.dataset.tab;
    document.querySelectorAll(".sim-tab-content").forEach(c => c.style.display = "none");
    document.querySelectorAll(".tablinks").forEach(t => t.classList.remove("active"));

    document.getElementById("sim-" + target).style.display = "block";
    el.classList.add("active");

    const enemyTechBox = document.getElementById("enemy-tech-box");
    if (target === "monsters") {
        enemyTechBox.style.opacity = "0.3";
        enemyTechBox.style.pointerEvents = "none";
    } else {
        enemyTechBox.style.opacity = "1";
        enemyTechBox.style.pointerEvents = "auto";
    }

    updateLivePowerSummary();
});
registerAction("calculateWarOutcome", () => {
    if (typeof calculateWarOutcome === "function" && typeof soldierTypes !== "undefined") {
        calculateWarOutcome(soldierTypes);
    }
});
registerAction("updateLivePower", () => {
    updateLivePowerSummary();
});
registerAction("resetFields", () => {
    resetWallToMax();

    soldierTypes.forEach(type => {
        let ownInput = document.getElementById(type + "_own");
        let enemyInput = document.getElementById(type + "_enemy");
        if (ownInput) {
            ownInput.value = "";
            ownInput.style.color = "";
        }
        if (enemyInput) {
            enemyInput.value = "";
            enemyInput.style.color = "";
        }
    });

    document.querySelectorAll(".js-mon-input").forEach(i => {
        i.value = "";
        i.style.color = "";
    });

    updateLivePowerSummary();
});
registerAction("fillSimMax", (el) => {
    const targetId = el.dataset.target;
    const value = el.dataset.value;
    const input = document.getElementById(targetId);

    if (input) {
        input.value = value;

        updateLivePowerSummary();
    }
});

document.querySelectorAll(".js-tech-input, #en_wall_lvl, .warsim-table input, .js-mon-input").forEach(input => {
    input.addEventListener("input", () => {
        if (input.type === "text") {
            input.value = input.value.replace(/\D/g, "");
        }

        let val = parseInt(input.value);
        if (isNaN(val) || val < 0) val = 0;

        if (input.classList.contains("js-tech-input") || input.id === "en_wall_lvl") {
            const maxVal = parseInt(input.max) || W_CONF.maxLvl;
            if (val > maxVal) val = maxVal;
            if (input.id === "en_wall_lvl" && val < 1) val = 1;

            input.value = val;
        }

        if (input.id === "en_wall_lvl" || input.id === "en_tech_4") {
            resetWallToMax();
        }

        if (input.type === "text") input.style.color = "";

        updateLivePowerSummary();
    });
});

function resetWallToMax() {
    const lvlInput = document.getElementById("en_wall_lvl");
    const lvl = Math.max(1, Math.min(parseInt(lvlInput.value) || 1, W_CONF.maxLvl));
    lvlInput.value = lvl;

    const techLvl = parseInt(document.getElementById("en_tech_4")?.value) || 0;
    currentSimWallHp = (lvl * W_CONF.wallDefaultHp) + (techLvl * W_CONF.wallHpInc);
}

function calculateWallDefenseBonus(hp, lvl) {
    if (lvl <= 0 || hp <= 0) return 0;
    const maxHpForLvl = lvl * W_CONF.wallDefaultHp;

    const levelScale = Math.pow((lvl - 1), W_CONF.wallFactor);
    const maxScale = Math.pow((W_CONF.maxLvl - 1), W_CONF.wallFactor);
    const scaledMaxDefense = W_CONF.wallMinDef + (W_CONF.wallMaxDef - W_CONF.wallMinDef) * (levelScale / maxScale);

    let defense = Math.floor((hp / maxHpForLvl) * scaledMaxDefense);
    return Math.max(W_CONF.wallMinDef, defense);
}

function calculateWarOutcome(soldierTypes) {
    const isMonsterMode = document.querySelector(".tablinks[data-tab='monsters']").classList.contains("active");
    let myUnits = {};
    let enemyUnits = {};

    let playerAtkPool = 0;
    let playerDefPool = 0;
    let enemyAtkPool = 0;
    let enemyDefPool = 0;

    let totalOwnUnits = 0;
    let totalEnemyUnits = 0;

    let enemyDefWithoutWall = 0;

    const lvl = parseInt(document.getElementById("en_wall_lvl").value) || 1;
    const wallBonus = calculateWallDefenseBonus(currentSimWallHp, lvl);

    // Save old inputs
    const inputs = [];
    document.querySelectorAll(".warsim-table input, .js-mon-input").forEach(i => {
        inputs.push({id: i.id, value: i.value});
    });
    lastSimState = {
        inputs: inputs,
        wallHp: currentSimWallHp
    };
    document.getElementById("btn-undo").disabled = false;

    const myShrineBonus = getDynamicShrineMult("my");
    const enShrineBonus = getDynamicShrineMult("en");

    // Collect data
    soldierTypes.forEach(type => {
        const countOwn = parseInt(document.getElementById(`${type}_own`).value) || 0;
        const statsEl = document.getElementById(`${type}_atk`);
        const defEl = document.getElementById(`${type}_def`);
        const cat = parseInt(statsEl.getAttribute("data-category"));

        let myAtkLvl = parseInt(document.getElementById("my_tech_" + (13 + (cat * 2)))?.value) || 0;
        let myDefLvl = parseInt(document.getElementById("my_tech_" + (14 + (cat * 2)))?.value) || 0;
        let aBonus = (cat === 0) ? W_CONF.infAtk : (cat === 1 ? W_CONF.cavAtk : W_CONF.arcAtk);
        let dBonus = (cat === 0) ? W_CONF.infDef : (cat === 1 ? W_CONF.cavDef : W_CONF.arcDef);

        myUnits[type] = {
            atk: (parseFloat(statsEl.getAttribute("data-attack")) * (1.0 + myShrineBonus)) + (myAtkLvl * aBonus),
            def: parseFloat(defEl.getAttribute("data-defense")) + (myDefLvl * dBonus),
            count: countOwn, initial: countOwn, cat: cat
        };

        totalOwnUnits += countOwn;
    });

    if (isMonsterMode) {
        document.querySelectorAll('.js-mon-input').forEach(input => {
            const count = parseInt(input.value) || 0;

            enemyUnits[input.id] = {
                atk: parseInt(input.dataset.atk), def: parseInt(input.dataset.def),
                count: count, initial: count, cat: -1
            };

            totalEnemyUnits += count;
            enemyDefWithoutWall += count * parseInt(input.dataset.def);
        });
    } else {
        soldierTypes.forEach(type => {
            const countEnemy = parseInt(document.getElementById(`${type}_enemy`).value) || 0;
            const statsEl = document.getElementById(`${type}_atk`);
            const cat = parseInt(statsEl.dataset.category);
            let enAtkLvl = parseInt(document.getElementById("en_tech_" + (13 + (cat * 2)))?.value) || 0;
            let enDefLvl = parseInt(document.getElementById("en_tech_" + (14 + (cat * 2)))?.value) || 0;
            let aB = (cat === 0) ? W_CONF.infAtk : (cat === 1 ? W_CONF.cavAtk : W_CONF.arcAtk);
            let dB = (cat === 0) ? W_CONF.infDef : (cat === 1 ? W_CONF.cavDef : W_CONF.arcDef);

            enemyUnits[type] = {
                atk: (parseFloat(statsEl.dataset.attack) * (1.0 + enShrineBonus)) + (enAtkLvl * aB),
                def: parseFloat(document.getElementById(`${type}_def`).dataset.defense) + (enDefLvl * dB),
                count: countEnemy, initial: countEnemy, cat: cat
            };

            totalEnemyUnits += countEnemy;
            enemyDefWithoutWall += countEnemy * enemyUnits[type].def;
        });
    }

    if (totalOwnUnits === 0 && totalEnemyUnits === 0) return;

    // Calculate Attack Pools (Rock-Paper-Scissors)
    for (let pId in myUnits) {
        let bonus = 1.0;

        if (!isMonsterMode && myUnits[pId].count > 0) {
            for (let eId in enemyUnits) {
                if (enemyUnits[eId].initial > 0) {
                    let enemyShare = enemyUnits[eId].initial / totalEnemyUnits;
                    let aCat = myUnits[pId].cat, dCat = enemyUnits[eId].cat;
                    if ((aCat === 0 && dCat === 1) || (aCat === 1 && dCat === 2) || (aCat === 2 && dCat === 0)) bonus += (W_CONF.rpsBonus * enemyShare);
                }
            }
        }

        playerAtkPool += (myUnits[pId].count * myUnits[pId].atk * bonus);
        playerDefPool += (myUnits[pId].count * myUnits[pId].def);
    }

    for (let eId in enemyUnits) {
        let bonus = 1.0;

        if (!isMonsterMode && enemyUnits[eId].count > 0) {
            for (let pId in myUnits) {
                if (myUnits[pId].initial > 0) {
                    let ownShare = myUnits[pId].initial / totalOwnUnits;
                    let aCat = enemyUnits[eId].cat, dCat = myUnits[pId].cat;
                    if ((aCat === 0 && dCat === 1) || (aCat === 1 && dCat === 2) || (aCat === 2 && dCat === 0)) bonus += (W_CONF.rpsBonus * ownShare);
                }
            }
        }

        enemyAtkPool += (enemyUnits[eId].count * enemyUnits[eId].atk * bonus);
        enemyDefPool += (enemyUnits[eId].count * enemyUnits[eId].def);
    }

    if (!isMonsterMode) {
        enemyDefPool += wallBonus;
    }

    // 1.0 = Original (very deadly!)
    // 2.0 = Troops can sustain double the amount
    // 3.0 = Troops can sustain triple the amount
    const lethality = isMonsterMode ? W_CONF.lethalityPve : W_CONF.lethalityPvp;

    // Calculate losses
    let pRatio = (playerDefPool > 0) ? Math.min(1.0, enemyAtkPool / (playerDefPool * lethality)) : 1.0;
    let eRatio = (enemyDefPool > 0) ? Math.min(1.0, playerAtkPool / (enemyDefPool * lethality)) : 1.0;

    if (isMonsterMode && playerAtkPool > 0 && enemyAtkPool > 0) {
        const ratio = playerAtkPool / enemyAtkPool;
        const lossMultiplier = Math.pow(1.0 - Math.max(0.0, Math.min(1.0, ratio / W_CONF.monsterDmgClampedMaxVal)), W_CONF.monsterDmgLossExponent);

        pRatio = pRatio * lossMultiplier;
    }

    soldierTypes.forEach(type => {
        let oIn = document.getElementById(`${type}_own`);

        if (myUnits[type].initial > 0) {
            let losses = Math.round(myUnits[type].initial * pRatio);

            oIn.value = myUnits[type].initial - losses;
            oIn.style.color = (losses > 0) ? "#F55353" : "";
        }

        if (!isMonsterMode) {
            let eIn = document.getElementById(`${type}_enemy`);

            if (enemyUnits[type].initial > 0) {
                let eLosses = Math.round(enemyUnits[type].initial * eRatio);

                eIn.value = enemyUnits[type].initial - eLosses;
                eIn.style.color = (eLosses > 0) ? "#F55353" : "";
            }
        }
    });

    if (isMonsterMode) {
        document.querySelectorAll('.js-mon-input').forEach(i => {
            const initial = parseInt(i.value) || 0;

            if (initial > 0) {
                let losses = Math.round(initial * eRatio);

                if (eRatio < 1.0 && losses >= initial) {
                    losses = initial - 1;
                }

                i.value = initial - losses;
                i.style.color = (losses > 0) ? "#F55353" : "";
            }
        });
    }

    // Wall Damage (only PvP)
    if (!isMonsterMode) {
        const wallAbsorption = lvl * W_CONF.wallAbsorptionPerLevel;
        const damageDiff = playerAtkPool - enemyDefWithoutWall;

        let effectiveDamage = Math.max(0, damageDiff - wallAbsorption);

        let wallDmgBase = Math.max(effectiveDamage * W_CONF.wallEffDmgFactor, playerAtkPool * W_CONF.wallAccDmgFactor);

        const siegeLvl = parseInt(document.getElementById("my_tech_20")?.value) || 0;
        const ramCount = parseInt(document.getElementById("Rammbock_own")?.value) || 0;

        wallDmgBase += (ramCount * W_CONF.ramFlat);

        const ramBonus = Math.min(W_CONF.ramLimit, ramCount * W_CONF.ramFactor);
        const multiplier = 1 + (siegeLvl * W_CONF.siegeBonus) + ramBonus;

        currentSimWallHp = Math.max(0, currentSimWallHp - Math.round(wallDmgBase * multiplier));
    }

    updateLivePowerSummary();
}

function updateLivePowerSummary() {
    let tAtkO = 0, tDefO = 0, tAtkE = 0, tDefE = 0;
    let totalEn = 0;

    const isMonsterMode = document.querySelector(".tablinks[data-tab='monsters']").classList.contains("active");

    const lvl = parseInt(document.getElementById("en_wall_lvl").value) || 1;
    const wallTechLvl = parseInt(document.getElementById("en_tech_4")?.value) || 0;
    const maxHp = (lvl * W_CONF.wallDefaultHp) + (wallTechLvl * W_CONF.wallHpInc);

    const myShrineBonus = getDynamicShrineMult("my");
    const enShrineBonus = getDynamicShrineMult("en");

    if (currentSimWallHp === null) currentSimWallHp = maxHp;
    const wallBonus = calculateWallDefenseBonus(currentSimWallHp, lvl);

    document.getElementById("wall_hp_display").innerText = formatNumJS(currentSimWallHp);
    document.getElementById("wall_hp_display_max").innerText = formatNumJS(maxHp);
    document.getElementById("wall_def_display").innerText = wallBonus;

    soldierTypes.forEach(type => {
        const cO = parseInt(document.getElementById(type + "_own").value) || 0;
        const stats = document.getElementById(type + "_atk").dataset;
        let myA = parseInt(document.getElementById("my_tech_" + (13 + (parseInt(stats.category) * 2)))?.value) || 0;
        let myD = parseInt(document.getElementById("my_tech_" + (14 + (parseInt(stats.category) * 2)))?.value) || 0;
        let aB = (stats.category === "0") ? W_CONF.infAtk : (stats.category === "1" ? W_CONF.cavAtk : W_CONF.arcAtk);
        let dB = (stats.category === "0") ? W_CONF.infDef : (stats.category === "1" ? W_CONF.cavDef : W_CONF.arcDef);

        tAtkO += cO * ((parseFloat(stats.attack) * (1.0 + myShrineBonus)) + (myA * aB));
        tDefO += cO * (parseFloat(document.getElementById(type + "_def").dataset.defense) + (myD * dB));
    });

    if (isMonsterMode) {
        document.querySelectorAll('.js-mon-input').forEach(input => {
            const count = parseInt(input.value) || 0;

            tAtkE += count * parseInt(input.dataset.atk);
            tDefE += count * parseInt(input.dataset.def);
        });
    } else {
        soldierTypes.forEach(type => {
            const cE = parseInt(document.getElementById(type + "_enemy").value) || 0;
            const stats = document.getElementById(type + "_atk").dataset;
            totalEn += cE;

            let enA = parseInt(document.getElementById("en_tech_" + (13 + (parseInt(stats.category) * 2)))?.value) || 0;
            let enD = parseInt(document.getElementById("en_tech_" + (14 + (parseInt(stats.category) * 2)))?.value) || 0;
            let aB = (stats.category === "0") ? W_CONF.infAtk : (stats.category === "1" ? W_CONF.cavAtk : W_CONF.arcAtk);
            let dB = (stats.category === "0") ? W_CONF.infDef : (stats.category === "1" ? W_CONF.cavDef : W_CONF.arcDef);

            tAtkE += cE * ((parseFloat(stats.attack) * (1.0 + enShrineBonus)) + (enA * aB));
            tDefE += cE * (parseFloat(document.getElementById(type + "_def").dataset.defense) + (enD * dB));
        });

        if (totalEn > 0) tDefE += wallBonus;
    }

    const ownAtkEl = document.getElementById("live-atk-own");
    const enemyAtkEl = document.getElementById("live-atk-enemy");

    ownAtkEl.innerText = formatNumJS(tAtkO);
    ownAtkEl.title = tAtkO.toLocaleString("de-DE");

    enemyAtkEl.innerText = formatNumJS(tAtkE);
    enemyAtkEl.title = tAtkE.toLocaleString("de-DE");

    const updateDisplay = (id, value) => {
        const el = document.getElementById(id);
        if (!el) return;

        el.innerText = formatNumJS(value);

        if (value >= 100000) {
            el.title = value.toLocaleString();
            el.style.cursor = "help";
        } else {
            el.title = "";
            el.style.cursor = "";
        }
    };

    updateDisplay("live-atk-own", tAtkO);
    updateDisplay("live-def-own", tDefO);
    updateDisplay("live-atk-enemy", tAtkE);
    updateDisplay("live-def-enemy", tDefE);
}

function checkMonsterImport() {
    const importEl = document.getElementById("monster-import-data");

    if (!importEl || !importEl.dataset.import) return;

    try {
        const monsterData = JSON.parse(decodeURIComponent(importEl.dataset.import));

        const monsterTabBtn = document.querySelector(".tablinks[data-tab='monsters']");
        if (monsterTabBtn) {
            monsterTabBtn.click();
        }

        for (const [id, count] of Object.entries(monsterData)) {
            const input = document.getElementById(`m_${id}_count`);
            if (input) {
                input.value = count;
            }
        }

        if (typeof updateLivePowerSummary === "function") {
            updateLivePowerSummary();
        }

        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({path: cleanUrl}, '', cleanUrl);
    } catch (e) {
        console.error("Fehler beim Monster-Import:", e);
    }
}

function getDynamicShrineMult(prefix) {
    const checkbox = document.getElementById(prefix + "_shrine_war");
    if (!checkbox || !checkbox.checked) return 0.0;

    const base = parseFloat(warsimConstEl.dataset.shrine_base) || 0.08;
    const step = parseFloat(warsimConstEl.dataset.shrine_step) || 0.05;
    const techLevel = parseInt(document.getElementById(prefix + "_tech_10")?.value) || 0;

    return base + (techLevel * step);
}

document.addEventListener("DOMContentLoaded", () => {
    resetWallToMax();
    updateLivePowerSummary();

    setTimeout(checkMonsterImport, 100);
});
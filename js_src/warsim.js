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
    monsterDmgLossExponent: parseFloat(warsimConstEl.dataset.monster_dmg_loss_exponent),
    wallCounterDmgFactor: parseFloat(warsimConstEl.dataset.wall_counter_dmg_factor),
    wallAbsorptionMult: parseInt(warsimConstEl.dataset.wall_absorption_mult),
    wallNormalDmgFactor: parseFloat(warsimConstEl.dataset.wall_normal_dmg_factor),
    wallMaxNormalDmgPerc: parseFloat(warsimConstEl.dataset.wall_max_normal_dmg_perc),
    pvpDampingThreshold: parseFloat(warsimConstEl.dataset.pvp_damping_threshold),
    pvpDampingMaxRatio: parseFloat(warsimConstEl.dataset.pvp_damping_max_ratio),
    pvpDampingExponent: parseFloat(warsimConstEl.dataset.pvp_damping_exponent),
    rpsTargetFocus: parseFloat(warsimConstEl.dataset.rps_target_focus || 0.7),
    armorWeightExponent: parseFloat(warsimConstEl.dataset.armor_weight_exponent || 0.5)
};
let currentSimWallHp = null;
let lastSimState = null;
const STORAGE_KEY = "warsim_state";
let isManualAction = true;

registerAction("stepWarsimTech", (el) => {
    const targetId = el.dataset.target;
    const step = parseInt(el.dataset.step) || 0;
    const input = document.getElementById(targetId);

    if (!input) return;

    const min = parseInt(input.dataset.min ?? input.min ?? 0);
    const max = parseInt(input.dataset.max ?? input.max ?? 10);
    let val = parseInt(input.value) || 0;

    val = Math.max(min, Math.min(max, val + step));

    input.value = val;
    input.dispatchEvent(new Event("input", {bubbles: true}));
});
registerAction("filterRelevantRows", (el) => {
    const isChecked = el.checked;
    applyRelevantFilter(isChecked);
});
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

    document.cookie = "me_sim_tab=" + target + "; path=/; max-age=31536000; SameSite=Lax";

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

    if (isManualAction) {
        saveWarsimState();
    }
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
    localStorage.removeItem(STORAGE_KEY);

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

    const filterToggle = document.getElementById('toggle-relevant-units');
    if (filterToggle) {
        applyRelevantFilter(false);
    }
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
            const maxVal = parseInt(input.dataset.max || input.max) || W_CONF.maxLvl;
            const minVal = parseInt(input.dataset.min || input.min) || 0;
            if (val > maxVal) val = maxVal;
            if (val < minVal) val = minVal;
            input.value = val;
        }

        if (input.id === "en_wall_lvl" || input.id === "en_tech_4") {
            resetWallToMax();
        }

        if (input.type === "text") input.style.color = "";

        updateLivePowerSummary();
    });
});

function applyRelevantFilter(active) {
    const sectionOwn = document.querySelector('.box-container:has(.warsim-table)');
    const sectionEnemy = document.getElementById("sim-players");
    const sectionMonsters = document.getElementById("sim-monsters");

    sectionOwn.querySelectorAll('tr.unit-row, tr:has(input[id$="_own"])').forEach(row => {
        const input = row.querySelector('input[type="text"]');
        if (!input) return;

        const val = parseInt(input.value) || 0;
        const owned = parseInt(row.querySelector('[data-value]')?.dataset.value) || 0;

        if (active && val <= 0 && owned <= 0) {
            row.style.display = "none";
        } else {
            row.style.display = '';
        }
    });

    [sectionEnemy, sectionMonsters].forEach(section => {
        if (!section) return;
        section.querySelectorAll('tr').forEach(row => {
            const input = row.querySelector('input[type="text"]');
            if (!input) return;

            const val = parseInt(input.value) || 0;

            if (active && val <= 0) {
                row.style.display = "none";
            } else {
                row.style.display = '';
            }
        });
    });
}

function resetWallToMax() {
    const lvlInput = document.getElementById("en_wall_lvl");
    if (!lvlInput) return;

    const rawVal = parseInt(lvlInput.value, 10);
    const lvl = isNaN(rawVal) ? 0 : Math.max(0, Math.min(rawVal, W_CONF.maxLvl));
    lvlInput.value = lvl;

    const techLvl = parseInt(document.getElementById("en_tech_4")?.value) || 0;

    currentSimWallHp = (lvl > 0) ? (lvl * W_CONF.wallDefaultHp) + (techLvl * W_CONF.wallHpInc) : 0;
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

    const inputs = [];
    document.querySelectorAll(".warsim-table input, .js-mon-input").forEach(i => {
        inputs.push({id: i.id, value: i.value});
    });
    lastSimState = {inputs: inputs, wallHp: currentSimWallHp};
    document.getElementById("btn-undo").disabled = false;

    const myShrineBonus = getDynamicShrineMult("my");
    const enShrineBonus = getDynamicShrineMult("en");

    // PVE MODE
    if (isMonsterMode) {
        let myUnits = {};
        let enemyUnits = {};
        let playerAtkPool = 0;
        let playerDefPool = 0;
        let enemyAtkPool = 0;
        let enemyDefPool = 0;
        let totalOwnUnits = 0;
        let totalEnemyUnits = 0;

        soldierTypes.forEach(type => {
            const countOwn = parseInt(document.getElementById(`${type}_own`).value) || 0;
            const statsEl = document.getElementById(`${type}_atk`);
            const defEl = document.getElementById(`${type}_def`);
            const cat = parseInt(statsEl.getAttribute("data-category"));

            let myAtkLvl = 0, myDefLvl = 0, aBonus = 0, dBonus = 0;
            if (cat === 0) {
                myAtkLvl = parseInt(document.getElementById("my_tech_13")?.value) || 0;
                myDefLvl = parseInt(document.getElementById("my_tech_14")?.value) || 0;
                aBonus = W_CONF.infAtk;
                dBonus = W_CONF.infDef;
            } else if (cat === 1) {
                myAtkLvl = parseInt(document.getElementById("my_tech_15")?.value) || 0;
                myDefLvl = parseInt(document.getElementById("my_tech_16")?.value) || 0;
                aBonus = W_CONF.cavAtk;
                dBonus = W_CONF.cavDef;
            } else if (cat === 2) {
                myAtkLvl = parseInt(document.getElementById("my_tech_17")?.value) || 0;
                myDefLvl = parseInt(document.getElementById("my_tech_18")?.value) || 0;
                aBonus = W_CONF.arcAtk;
                dBonus = W_CONF.arcDef;
            }

            const effectiveAtk = Math.round((parseFloat(statsEl.getAttribute("data-attack")) * (1.0 + myShrineBonus)) + (myAtkLvl * aBonus));
            const effectiveDef = Math.round(parseFloat(defEl.getAttribute("data-defense")) + (myDefLvl * dBonus));

            myUnits[type] = {
                count: countOwn,
                initial: countOwn,
                atk: effectiveAtk,
                def: effectiveDef
            };

            totalOwnUnits += countOwn;
            playerAtkPool += countOwn * effectiveAtk;
            playerDefPool += countOwn * effectiveDef;
        });

        document.querySelectorAll('.js-mon-input').forEach(input => {
            const count = parseInt(input.value) || 0;
            const atk = parseInt(input.dataset.atk) || 0;
            const def = parseInt(input.dataset.def) || 0;

            enemyUnits[input.id] = {
                count: count,
                initial: count,
                atk: atk,
                def: def
            };

            totalEnemyUnits += count;
            enemyAtkPool += count * atk;
            enemyDefPool += count * def;
        });

        if (totalOwnUnits === 0 && totalEnemyUnits === 0) return;

        let pRatio = (playerDefPool > 0) ? Math.min(1.0, enemyAtkPool / (playerDefPool * W_CONF.lethalityPve)) : 1.0;
        let eRatio = (enemyDefPool > 0) ? Math.min(1.0, playerAtkPool / (enemyDefPool * W_CONF.lethalityPve)) : 1.0;

        if (playerAtkPool > 0 && enemyAtkPool > 0) {
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
        });

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

        updateLivePowerSummary();
        return;
    }

    // PVP MODE
    let myUnits = {};
    let enemyUnits = {};
    let totalOwnUnits = 0;
    let totalEnemyUnits = 0;
    let totalOwnDef = 0;
    let totalEnemyDef = 0;

    const rawLvl = parseInt(document.getElementById("en_wall_lvl").value, 10);
    const lvl = isNaN(rawLvl) ? 0 : Math.max(0, rawLvl);

    const wallTechLvl = parseInt(document.getElementById("en_tech_4")?.value) || 0;
    const maxHp = (lvl > 0) ? (lvl * W_CONF.wallDefaultHp) + (wallTechLvl * W_CONF.wallHpInc) : 0;

    if (currentSimWallHp === null) currentSimWallHp = maxHp;
    const wallBonus = (lvl > 0) ? calculateWallDefenseBonus(currentSimWallHp, lvl) : 0;

    // Load Own Troops
    soldierTypes.forEach(type => {
        const countOwn = parseInt(document.getElementById(`${type}_own`).value) || 0;
        const statsEl = document.getElementById(`${type}_atk`);
        const defEl = document.getElementById(`${type}_def`);
        const cat = parseInt(statsEl.getAttribute("data-category"));

        let myAtkLvl = 0, myDefLvl = 0, aBonus = 0, dBonus = 0;
        if (cat === 0) {
            myAtkLvl = parseInt(document.getElementById("my_tech_13")?.value) || 0;
            myDefLvl = parseInt(document.getElementById("my_tech_14")?.value) || 0;
            aBonus = W_CONF.infAtk;
            dBonus = W_CONF.infDef;
        } else if (cat === 1) {
            myAtkLvl = parseInt(document.getElementById("my_tech_15")?.value) || 0;
            myDefLvl = parseInt(document.getElementById("my_tech_16")?.value) || 0;
            aBonus = W_CONF.cavAtk;
            dBonus = W_CONF.cavDef;
        } else if (cat === 2) {
            myAtkLvl = parseInt(document.getElementById("my_tech_17")?.value) || 0;
            myDefLvl = parseInt(document.getElementById("my_tech_18")?.value) || 0;
            aBonus = W_CONF.arcAtk;
            dBonus = W_CONF.arcDef;
        }

        const effectiveAtk = Math.round((parseFloat(statsEl.getAttribute("data-attack")) * (1.0 + myShrineBonus)) + (myAtkLvl * aBonus));
        const effectiveDef = Math.max(1, Math.round(parseFloat(defEl.getAttribute("data-defense")) + (myDefLvl * dBonus)));

        myUnits[type] = {
            atk: effectiveAtk,
            def: effectiveDef,
            count: countOwn,
            initial: countOwn,
            cat: cat
        };

        totalOwnUnits += countOwn;
        totalOwnDef += countOwn * effectiveDef;
    });

    // Load Enemies
    soldierTypes.forEach(type => {
        const countEnemy = parseInt(document.getElementById(`${type}_enemy`).value) || 0;
        const statsEl = document.getElementById(`${type}_atk`);
        const cat = parseInt(statsEl.dataset.category);

        let enAtkLvl = 0, enDefLvl = 0, aB = 0, dB = 0;
        if (cat === 0) {
            enAtkLvl = parseInt(document.getElementById("en_tech_13")?.value) || 0;
            enDefLvl = parseInt(document.getElementById("en_tech_14")?.value) || 0;
            aB = W_CONF.infAtk;
            dB = W_CONF.infDef;
        } else if (cat === 1) {
            enAtkLvl = parseInt(document.getElementById("en_tech_15")?.value) || 0;
            enDefLvl = parseInt(document.getElementById("en_tech_16")?.value) || 0;
            aB = W_CONF.cavAtk;
            dB = W_CONF.cavDef;
        } else if (cat === 2) {
            enAtkLvl = parseInt(document.getElementById("en_tech_17")?.value) || 0;
            enDefLvl = parseInt(document.getElementById("en_tech_18")?.value) || 0;
            aB = W_CONF.arcAtk;
            dB = W_CONF.arcDef;
        }

        const effectiveAtk = Math.round((parseFloat(statsEl.dataset.attack) * (1.0 + enShrineBonus)) + (enAtkLvl * aB));
        const effectiveDef = Math.max(1, Math.round(parseFloat(document.getElementById(`${type}_def`).dataset.defense) + (enDefLvl * dB)));

        enemyUnits[type] = {
            atk: effectiveAtk,
            def: effectiveDef,
            count: countEnemy,
            initial: countEnemy,
            cat: cat
        };

        totalEnemyUnits += countEnemy;
        totalEnemyDef += countEnemy * effectiveDef;
    });

    if (totalOwnUnits === 0 && totalEnemyUnits === 0) return;

    // RPS Target Category
    const getPreferredTargetCat = (cat) => {
        if (cat === 0) return 1;
        if (cat === 1) return 2;
        if (cat === 2) return 0;
        return -1; // Special Units
    };

    const hasCategoryUnits = (unitsObj, cat) => {
        return Object.values(unitsObj).some(u => u.cat === cat && u.count > 0);
    };

    const getCategoryDefPool = (unitsObj) => {
        const pools = {0: 0, 1: 0, 2: 0, 3: 0};
        for (let k in unitsObj) {
            pools[unitsObj[k].cat] = (pools[unitsObj[k].cat] || 0) + (unitsObj[k].count * unitsObj[k].def);
        }
        return pools;
    };

    const ownDefPools = getCategoryDefPool(myUnits);
    const enemyDefPools = getCategoryDefPool(enemyUnits);

    // Distribute Damage (70/30 currently)
    const distributeDamage = (attackerUnits, defenderUnits, defPools, totalDef) => {
        const targetedDmg = {0: 0, 1: 0, 2: 0, 3: 0};
        let sharedDmg = 0;
        let totalRawAtk = 0;

        const focusShare = W_CONF.rpsTargetFocus;        // z.B. 0.70
        const defaultShare = 1.0 - focusShare;           // z.B. 0.30

        for (let k in attackerUnits) {
            const u = attackerUnits[k];
            if (u.count <= 0) continue;
            const unitAtkSum = u.count * u.atk;
            totalRawAtk += unitAtkSum;

            const targetCat = getPreferredTargetCat(u.cat);
            if (targetCat !== -1 && hasCategoryUnits(defenderUnits, targetCat)) {
                const targetDamageWithBonus = unitAtkSum * focusShare * (1.0 + W_CONF.rpsBonus);

                // Overkill-Spillover
                const maxDefCapacity = defPools[targetCat] * W_CONF.lethalityPvp;
                if (targetDamageWithBonus > maxDefCapacity && maxDefCapacity > 0) {
                    const excess = targetDamageWithBonus - maxDefCapacity;
                    targetedDmg[targetCat] += maxDefCapacity;
                    sharedDmg += (excess / (1.0 + W_CONF.rpsBonus)) + (unitAtkSum * defaultShare);
                } else {
                    targetedDmg[targetCat] += targetDamageWithBonus;
                    sharedDmg += unitAtkSum * defaultShare;
                }
            } else {
                sharedDmg += unitAtkSum;
            }
        }

        const finalIncomingDmg = {0: 0, 1: 0, 2: 0, 3: 0};
        for (let cat in defPools) {
            finalIncomingDmg[cat] = targetedDmg[cat];
            if (totalDef > 0 && defPools[cat] > 0) {
                finalIncomingDmg[cat] += sharedDmg * (defPools[cat] / totalDef);
            }
        }

        return {finalIncomingDmg, totalRawAtk};
    };

    const ownOffense = distributeDamage(myUnits, enemyUnits, enemyDefPools, totalEnemyDef);
    const enemyOffense = distributeDamage(enemyUnits, myUnits, ownDefPools, totalOwnDef);

    // Wall fights back!
    let wallCounterDamage = 0;
    if (wallBonus > 0 && totalEnemyUnits > 0) {
        wallCounterDamage = wallBonus * W_CONF.wallCounterDmgFactor;
        for (let cat in ownDefPools) {
            if (totalOwnDef > 0 && ownDefPools[cat] > 0) {
                enemyOffense.finalIncomingDmg[cat] += wallCounterDamage * (ownDefPools[cat] / totalOwnDef);
            }
        }
    }

    let effectiveEnemyCounterDmg = enemyOffense.totalRawAtk + wallCounterDamage;

    // PVP Damping
    let globalAtkLossDamping = 1.0;
    let globalDefLossDamping = 1.0;

    if (ownOffense.totalRawAtk > 0 && effectiveEnemyCounterDmg > 0) {
        const range = Math.max(0.01, W_CONF.pvpDampingMaxRatio - W_CONF.pvpDampingThreshold);
        const ratioDef = effectiveEnemyCounterDmg / ownOffense.totalRawAtk;
        if (ratioDef > W_CONF.pvpDampingThreshold) {
            const clamped = Math.max(0.0, Math.min(1.0, (ratioDef - W_CONF.pvpDampingThreshold) / range));
            globalDefLossDamping *= Math.pow(1.0 - clamped, W_CONF.pvpDampingExponent);
        }
        const ratioAtk = ownOffense.totalRawAtk / effectiveEnemyCounterDmg;
        if (ratioAtk > W_CONF.pvpDampingThreshold) {
            const clamped = Math.max(0.0, Math.min(1.0, (ratioAtk - W_CONF.pvpDampingThreshold) / range));
            globalAtkLossDamping *= Math.pow(1.0 - clamped, W_CONF.pvpDampingExponent);
        }
    }

    // Base Loss Rates
    const getCategoryLossRatio = (incomingDmg, catDef, isDefender) => {
        if (catDef <= 0) return 1.0;
        let effectiveDef = catDef;
        if (isDefender && totalEnemyDef > 0) {
            effectiveDef += wallBonus * (catDef / totalEnemyDef);
        }
        const damping = isDefender ? globalDefLossDamping : globalAtkLossDamping;
        const rawRatio = incomingDmg / (effectiveDef * W_CONF.lethalityPvp);
        return rawRatio * damping;
    };

    const ownCatLossRatios = {};
    for (let c in ownDefPools) {
        ownCatLossRatios[c] = getCategoryLossRatio(enemyOffense.finalIncomingDmg[c], ownDefPools[c], false);
    }

    const enemyCatLossRatios = {};
    for (let c in enemyDefPools) {
        enemyCatLossRatios[c] = getCategoryLossRatio(ownOffense.finalIncomingDmg[c], enemyDefPools[c], true);
    }

    // Armor Calculation
    const avgOwnDef = totalOwnUnits > 0 ? (totalOwnDef / totalOwnUnits) : 1;
    const avgEnemyDef = totalEnemyUnits > 0 ? (totalEnemyDef / totalEnemyUnits) : 1;
    const exp = W_CONF.armorWeightExponent; // z.B. 0.5

    soldierTypes.forEach(type => {
        let oIn = document.getElementById(`${type}_own`);
        const u = myUnits[type];
        if (u.initial > 0) {
            const baseRatio = ownCatLossRatios[u.cat] || 0;
            const armorModifier = Math.pow(avgOwnDef / u.def, exp);
            const unitLossRatio = Math.max(0.0, Math.min(1.0, baseRatio * armorModifier));

            let losses = Math.round(u.initial * unitLossRatio);
            oIn.value = u.initial - losses;
            oIn.style.color = (losses > 0) ? "#F55353" : "";
        }

        let eIn = document.getElementById(`${type}_enemy`);
        const eU = enemyUnits[type];
        if (eU.initial > 0) {
            const baseRatio = enemyCatLossRatios[eU.cat] || 0;
            const armorModifier = Math.pow(avgEnemyDef / eU.def, exp);
            const unitLossRatio = Math.max(0.0, Math.min(1.0, baseRatio * armorModifier));

            let eLosses = Math.round(eU.initial * unitLossRatio);
            eIn.value = eU.initial - eLosses;
            eIn.style.color = (eLosses > 0) ? "#F55353" : "";
        }
    });

    // Wall Damage (PVP)
    if (lvl > 0) {
        const wallAbsorption = lvl * (W_CONF.wallAbsorptionPerLevel * W_CONF.wallAbsorptionMult);
        const damageDiff = ownOffense.totalRawAtk - totalEnemyDef;
        let effectiveDamage = Math.max(0, damageDiff - wallAbsorption);

        let normalTroopWallDmg = effectiveDamage * (W_CONF.wallEffDmgFactor * W_CONF.wallNormalDmgFactor);
        const maxNormalDmgCap = maxHp * W_CONF.wallMaxNormalDmgPerc;
        normalTroopWallDmg = Math.min(normalTroopWallDmg, maxNormalDmgCap);

        const siegeLvl = parseInt(document.getElementById("my_tech_20")?.value) || 0;
        const ramCount = parseInt(document.getElementById("Rammbock_own")?.value) || 0;

        let ramDamage = (ramCount * W_CONF.ramFlat);
        const ramBonus = Math.min(W_CONF.ramLimit, ramCount * W_CONF.ramFactor);
        const multiplier = 1 + (siegeLvl * W_CONF.siegeBonus) + ramBonus;
        let totalWallDmg = (normalTroopWallDmg + ramDamage) * multiplier;

        currentSimWallHp = Math.max(0, currentSimWallHp - Math.round(totalWallDmg));
    } else {
        currentSimWallHp = 0;
    }

    updateLivePowerSummary();
}

function updateLivePowerSummary() {
    let tAtkO = 0, tDefO = 0, tAtkE = 0, tDefE = 0;
    let totalEn = 0;

    const isMonsterMode = document.querySelector(".tablinks[data-tab='monsters']").classList.contains("active");

    const rawLvl = parseInt(document.getElementById("en_wall_lvl").value, 10);
    const lvl = isNaN(rawLvl) ? 0 : Math.max(0, rawLvl);

    const wallTechLvl = parseInt(document.getElementById("en_tech_4")?.value) || 0;
    const maxHp = (lvl > 0) ? (lvl * W_CONF.wallDefaultHp) + (wallTechLvl * W_CONF.wallHpInc) : 0;

    if (currentSimWallHp === null) currentSimWallHp = maxHp;
    const wallBonus = (lvl > 0) ? calculateWallDefenseBonus(currentSimWallHp, lvl) : 0;

    const myShrineBonus = getDynamicShrineMult("my");
    const enShrineBonus = getDynamicShrineMult("en");

    document.getElementById("wall_hp_display").innerText = formatNumJS(currentSimWallHp);
    document.getElementById("wall_hp_display_max").innerText = formatNumJS(maxHp);
    document.getElementById("wall_def_display").innerText = wallBonus;

    soldierTypes.forEach(type => {
        const cO = parseInt(document.getElementById(type + "_own").value) || 0;
        const stats = document.getElementById(type + "_atk").dataset;
        const cat = parseInt(stats.category);
        let myA = 0, myD = 0, aB = 0, dB = 0;

        if (cat === 0) {
            myA = parseInt(document.getElementById("my_tech_13")?.value) || 0;
            myD = parseInt(document.getElementById("my_tech_14")?.value) || 0;
            aB = W_CONF.infAtk;
            dB = W_CONF.infDef;
        } else if (cat === 1) {
            myA = parseInt(document.getElementById("my_tech_15")?.value) || 0;
            myD = parseInt(document.getElementById("my_tech_16")?.value) || 0;
            aB = W_CONF.cavAtk;
            dB = W_CONF.cavDef;
        } else if (cat === 2) {
            myA = parseInt(document.getElementById("my_tech_17")?.value) || 0;
            myD = parseInt(document.getElementById("my_tech_18")?.value) || 0;
            aB = W_CONF.arcAtk;
            dB = W_CONF.arcDef;
        }

        tAtkO += cO * Math.round((parseFloat(stats.attack) * (1.0 + myShrineBonus)) + (myA * aB));
        tDefO += cO * Math.round(parseFloat(document.getElementById(type + "_def").dataset.defense) + (myD * dB));
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
            const cat = parseInt(stats.category);
            totalEn += cE;

            let enA = 0, enD = 0, aB = 0, dB = 0;
            if (cat === 0) {
                enA = parseInt(document.getElementById("en_tech_13")?.value) || 0;
                enD = parseInt(document.getElementById("en_tech_14")?.value) || 0;
                aB = W_CONF.infAtk;
                dB = W_CONF.infDef;
            } else if (cat === 1) {
                enA = parseInt(document.getElementById("en_tech_15")?.value) || 0;
                enD = parseInt(document.getElementById("en_tech_16")?.value) || 0;
                aB = W_CONF.cavAtk;
                dB = W_CONF.cavDef;
            } else if (cat === 2) {
                enA = parseInt(document.getElementById("en_tech_17")?.value) || 0;
                enD = parseInt(document.getElementById("en_tech_18")?.value) || 0;
                aB = W_CONF.arcAtk;
                dB = W_CONF.arcDef;
            }

            tAtkE += cE * Math.round((parseFloat(stats.attack) * (1.0 + enShrineBonus)) + (enA * aB));
            tDefE += cE * Math.round(parseFloat(document.getElementById(type + "_def").dataset.defense) + (enD * dB));
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
        const shortEl = document.getElementById(id);
        const fullEl = document.getElementById(id + "-full");
        const boxEl = document.getElementById("pop_" + id.replace(/-/g, "_") + "_box");
        const triggerEl = document.getElementById("pop_" + id.replace(/-/g, "_"));

        if (shortEl) shortEl.innerText = formatNumJS(value);
        if (fullEl) fullEl.innerText = value.toLocaleString("de-DE");

        if (boxEl && triggerEl) {
            if (value >= 100000) {
                boxEl.dataset.enabled = "true";
            } else {
                boxEl.dataset.enabled = "false";
                boxEl.style.display = "none";
            }
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

        document.querySelectorAll(".js-mon-input").forEach(input => {
            input.value = 0;
            input.style.color = "";
        });

        for (const [id, count] of Object.entries(monsterData)) {
            const input = document.getElementById(`${id}_count`);
            if (input) {
                input.value = count;
            }
        }

        if (typeof soldierTypes !== "undefined") {
            soldierTypes.forEach(type => {
                const ownInput = document.getElementById(type + "_own");
                if (ownInput) {
                    ownInput.value = "";
                    ownInput.style.color = "";
                }
            });
        }

        const filterToggle = document.getElementById("toggle-relevant-units");
        if (filterToggle) {
            filterToggle.checked = true;
            applyRelevantFilter(true);
        }

        updateLivePowerSummary();

        const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
        window.history.replaceState({path: cleanUrl}, '', cleanUrl);

        saveWarsimState();
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

function saveWarsimState() {
    if (!isManualAction) return;

    const warsimData = document.getElementById("warsim-data");
    const currentKid = warsimData ? warsimData.dataset.currentKid : null;

    const state = {
        currentKid: currentKid,
        inputs: {},
        techs: {},
        activeTab: document.querySelector(".tablinks.active")?.dataset.tab || "players",
        checkboxes: {
            relevantOnly: document.getElementById('toggle-relevant-units')?.checked || false,
            myShrine: document.getElementById('my_shrine_war')?.checked || false,
            enShrine: document.getElementById('en_shrine_war')?.checked || false
        },
        wall: {
            lvl: document.getElementById('en_wall_lvl')?.value || 1
        }
    };

    document.querySelectorAll('.warsim-table input[type="text"], .warsim-table input[type="number"]').forEach(input => {
        if (input.id) state.inputs[input.id] = input.value;
    });

    document.querySelectorAll('.js-tech-input').forEach(input => {
        if (input.id) state.techs[input.id] = input.value;
    });

    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}

function loadWarsimState() {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (!saved) return;

    const state = JSON.parse(saved);

    const warsimData = document.getElementById("warsim-data");
    const currentKid = warsimData ? warsimData.dataset.currentKid : null;
    const kingdomSwitched = (state.currentKid !== currentKid);

    isManualAction = false;

    for (let id in state.inputs) {
        const el = document.getElementById(id);
        if (!el) continue;

        if (kingdomSwitched && id.endsWith("_own")) {
            const row = el.closest("tr");
            const owned = parseInt(row?.querySelector('[data-value]')?.dataset.value) || 0;

            if (owned <= 0) {
                el.value = "";
            } else {
                const prevVal = parseInt(state.inputs[id]) || 0;
                el.value = prevVal > 0 ? Math.min(prevVal, owned) : "";
            }
            el.style.color = "";
            continue;
        }

        el.value = state.inputs[id];
    }

    if (!kingdomSwitched) {
        for (let id in state.techs) {
            const el = document.getElementById(id);
            if (el) el.value = state.techs[id];
        }

        if (state.wall && document.getElementById("en_wall_lvl")) {
            document.getElementById("en_wall_lvl").value = state.wall.lvl;
        }
    }

    if (state.activeTab) {
        const tabBtn = document.querySelector(`.tablinks[data-tab="${state.activeTab}"]`);

        if (tabBtn && !tabBtn.classList.contains("active")) {
            tabBtn.click();
        }
    }

    if (state.checkboxes) {
        const filterBox = document.getElementById("toggle-relevant-units");
        if (filterBox) filterBox.checked = state.checkboxes.relevantOnly || false;

        if (!kingdomSwitched) {
            if (document.getElementById("my_shrine_war"))
                document.getElementById("my_shrine_war").checked = state.checkboxes.myShrine;
            if (document.getElementById("en_shrine_war"))
                document.getElementById("en_shrine_war").checked = state.checkboxes.enShrine;
        }
    }

    updateLivePowerSummary();

    if (state.checkboxes && state.checkboxes.relevantOnly) {
        applyRelevantFilter(true);
    }

    isManualAction = true;

    if (kingdomSwitched) {
        saveWarsimState();
    }
}

document.addEventListener("DOMContentLoaded", () => {
    const urlParams = new URLSearchParams(window.location.search);
    const hasImportData = urlParams.has("import_monsters");
    const isKeepSim = urlParams.has("keep_sim");

    if (!isKeepSim && !hasImportData) {
        localStorage.removeItem(STORAGE_KEY);
        document.cookie = "me_sim_tab=; path=/; max-age=0;";
    }

    const filterToggle = document.getElementById("toggle-relevant-units");
    if (filterToggle) {
        filterToggle.checked = false;
        applyRelevantFilter(false);
    }

    if (hasImportData) {
        localStorage.removeItem(STORAGE_KEY);
    } else {
        loadWarsimState();
    }

    resetWallToMax();
    updateLivePowerSummary();

    checkMonsterImport();

    document.addEventListener("input", (e) => {
        if (e.target.closest('.warsim-table') || e.target.classList.contains("js-tech-input") || e.target.id === "en_wall_lvl") {
            saveWarsimState();
        }
    });

    document.addEventListener("change", (e) => {
        const validIds = ["toggle-relevant-units", "my_shrine_war", "en_shrine_war"];

        if (validIds.includes(e.target.id)) {
            saveWarsimState();
        }
    });
});
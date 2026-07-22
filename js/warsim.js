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
    siegeBonus: parseFloat(warsimConstEl.dataset.siege_bonus),
    maxLvl: parseInt(warsimConstEl.dataset.max_lvl)
};
let currentSimWallHp = null;

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

    updateLivePowerSummary();
});

document.querySelectorAll('.js-tech-input, #en_wall_lvl, .warsim-table input').forEach(input => {
    input.addEventListener("input", () => {
        let val = parseInt(input.value);
        if (isNaN(val) || val < 0) val = 0;

        if (input.classList.contains('js-tech-input') || input.id === "en_wall_lvl") {
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

    // Collect data
    soldierTypes.forEach(type => {
        const countOwn = parseInt(document.getElementById(`${type}_own`).value) || 0;
        const countEnemy = parseInt(document.getElementById(`${type}_enemy`).value) || 0;
        const statsEl = document.getElementById(`${type}_atk`);
        const baseAtk = parseInt(statsEl.getAttribute("data-attack"));
        const baseDef = parseInt(document.getElementById(`${type}_def`).getAttribute("data-defense"));
        const cat = parseInt(statsEl.getAttribute("data-category"));

        // Tech Boni
        let myAtkLvl = parseInt(document.getElementById("my_tech_" + (13 + (cat * 2)))?.value) || 0;
        let myDefLvl = parseInt(document.getElementById("my_tech_" + (14 + (cat * 2)))?.value) || 0;
        let enAtkLvl = parseInt(document.getElementById("en_tech_" + (13 + (cat * 2)))?.value) || 0;
        let enDefLvl = parseInt(document.getElementById("en_tech_" + (14 + (cat * 2)))?.value) || 0;

        let aBonus = (cat === 0) ? W_CONF.infAtk : (cat === 1 ? W_CONF.cavAtk : W_CONF.arcAtk);
        let dBonus = (cat === 0) ? W_CONF.infDef : (cat === 1 ? W_CONF.cavDef : W_CONF.arcDef);

        myUnits[type] = {
            atk: baseAtk + (myAtkLvl * aBonus),
            def: baseDef + (myDefLvl * dBonus),
            count: countOwn, initial: countOwn, cat: cat
        };
        enemyUnits[type] = {
            atk: baseAtk + (enAtkLvl * aBonus),
            def: baseDef + (enDefLvl * dBonus),
            count: countEnemy, initial: countEnemy, cat: cat
        };

        totalOwnUnits += countOwn;
        totalEnemyUnits += countEnemy;
        enemyDefWithoutWall += countEnemy * (baseDef + (enDefLvl * dBonus));
    });

    if (totalOwnUnits === 0 && totalEnemyUnits === 0) return;

    // Calculate Attack Pools (Rock-Paper-Scissors)
    soldierTypes.forEach(atkType => {
        // Player attacks
        if (myUnits[atkType].count > 0) {
            let bonus = 1.0;

            soldierTypes.forEach(defType => {
                if (enemyUnits[defType].initial > 0) {
                    let enemyShare = enemyUnits[defType].initial / totalEnemyUnits;
                    let aCat = myUnits[atkType].cat;
                    let dCat = enemyUnits[defType].cat;

                    // RPS Logic
                    if ((aCat === 0 && dCat === 1) || (aCat === 1 && dCat === 2) || (aCat === 2 && dCat === 0)) {
                        bonus += (0.5 * enemyShare);
                    }
                }
            });
            playerAtkPool += (myUnits[atkType].count * myUnits[atkType].atk * bonus);
        }

        // Enemy attacks
        if (enemyUnits[atkType].count > 0) {
            let bonus = 1.0;

            soldierTypes.forEach(defType => {
                if (myUnits[defType].initial > 0) {
                    let ownShare = myUnits[defType].initial / totalOwnUnits;
                    let aCat = enemyUnits[atkType].cat;
                    let dCat = myUnits[defType].cat;

                    if ((aCat === 0 && dCat === 1) || (aCat === 1 && dCat === 2) || (aCat === 2 && dCat === 0)) {
                        bonus += (0.5 * ownShare);
                    }
                }
            });
            enemyAtkPool += (enemyUnits[atkType].count * enemyUnits[atkType].atk * bonus);
        }

        // Defender Pools
        playerDefPool += (myUnits[atkType].count * myUnits[atkType].def);
        enemyDefPool += (enemyUnits[atkType].count * enemyUnits[atkType].def);
    });

    if (totalEnemyUnits > 0) {
        enemyDefPool += wallBonus;
    }

    // 1.0 = Original (very deadly!)
    // 2.0 = Troops can sustain double the amount
    // 3.0 = Troops can sustain triple the amount
    const lethality = 2.0;

    // Calculate losses
    let pRatio = (playerDefPool > 0) ? Math.min(1.0, enemyAtkPool / (playerDefPool * lethality)) : 1.0;
    let eRatio = (enemyDefPool > 0) ? Math.min(1.0, playerAtkPool / (enemyDefPool * lethality)) : 1.0;

    const wallAbsorption = lvl * 100;
    const damageDiff = playerAtkPool - enemyDefWithoutWall;
    let wallDmgBase;

    if (damageDiff > 0) {
        wallDmgBase = Math.max(0, damageDiff - wallAbsorption) * 0.03;
    } else {
        wallDmgBase = playerAtkPool * 0.001;
    }

    const siegeLvl = parseInt(document.getElementById("my_tech_20")?.value) || 0;
    const finalWallDmg = Math.round(wallDmgBase * (1 + (siegeLvl * W_CONF.siegeBonus)));
    currentSimWallHp = Math.max(0, currentSimWallHp - finalWallDmg);

    // UI Update
    soldierTypes.forEach(type => {
        let oIn = document.getElementById(`${type}_own`);
        let eIn = document.getElementById(`${type}_enemy`);

        if (myUnits[type].initial > 0) {
            let losses = Math.round(myUnits[type].initial * pRatio);
            oIn.value = myUnits[type].initial - losses;
            oIn.style.color = (losses > 0) ? "#F55353" : "";
        }
        if (enemyUnits[type].initial > 0) {
            let losses = Math.round(enemyUnits[type].initial * eRatio);
            eIn.value = enemyUnits[type].initial - losses;
            eIn.style.color = (losses > 0) ? "#F55353" : "";
        }
    });

    updateLivePowerSummary();
}

function updateLivePowerSummary() {
    let tAtkO = 0, tDefO = 0, tAtkE = 0, tDefE = 0;
    let totalEn = 0;

    const lvl = parseInt(document.getElementById("en_wall_lvl").value) || 1;
    const wallTechLvl = parseInt(document.getElementById("en_tech_4")?.value) || 0;
    const maxHp = (lvl * W_CONF.wallDefaultHp) + (wallTechLvl * W_CONF.wallHpInc);

    if (currentSimWallHp === null) currentSimWallHp = maxHp;

    const wallBonus = calculateWallDefenseBonus(currentSimWallHp, lvl);
    document.getElementById("wall_hp_display").innerText = formatNumJS(currentSimWallHp);
    document.getElementById("wall_hp_display_max").innerText = formatNumJS(maxHp);
    document.getElementById("wall_def_display").innerText = wallBonus;

    soldierTypes.forEach(type => {
        const cO = parseInt(document.getElementById(type + "_own").value) || 0;
        const cE = parseInt(document.getElementById(type + "_enemy").value) || 0;
        const stats = document.getElementById(type + "_atk").dataset;

        totalEn += cE;

        let myA = parseInt(document.getElementById("my_tech_" + (13 + (parseInt(stats.category) * 2)))?.value) || 0;
        let myD = parseInt(document.getElementById("my_tech_" + (14 + (parseInt(stats.category) * 2)))?.value) || 0;
        let enA = parseInt(document.getElementById("en_tech_" + (13 + (parseInt(stats.category) * 2)))?.value) || 0;
        let enD = parseInt(document.getElementById("en_tech_" + (14 + (parseInt(stats.category) * 2)))?.value) || 0;

        let aB = (stats.category === "0") ? W_CONF.infAtk : (stats.category === "1" ? W_CONF.cavAtk : W_CONF.arcAtk);
        let dB = (stats.category === "0") ? W_CONF.infDef : (stats.category === "1" ? W_CONF.cavDef : W_CONF.arcDef);

        tAtkO += cO * (parseInt(stats.attack) + myA * aB);
        tDefO += cO * (parseInt(document.getElementById(type + "_def").dataset.defense) + myD * dB);
        tAtkE += cE * (parseInt(stats.attack) + enA * aB);
        tDefE += cE * (parseInt(document.getElementById(type + "_def").dataset.defense) + enD * dB);
    });

    if (totalEn > 0) {
        tDefE += wallBonus;
    }

    document.getElementById("live-atk-own").innerText = formatNumJS(tAtkO);
    document.getElementById("live-def-own").innerText = formatNumJS(tDefO);
    document.getElementById("live-atk-enemy").innerText = formatNumJS(tAtkE);
    document.getElementById("live-def-enemy").innerText = formatNumJS(tDefE);
}

document.addEventListener("DOMContentLoaded", () => {
    resetWallToMax();
    updateLivePowerSummary();
});
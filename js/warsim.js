const warsimDataEl = document.getElementById("warsim-data");
const soldierTypes = warsimDataEl ? JSON.parse(warsimDataEl.dataset.soldiers) : [];

registerAction("calculateWarOutcome", () => {
    if (typeof calculateWarOutcome === "function" && typeof soldierTypes !== "undefined") {
        calculateWarOutcome(soldierTypes);
    }
});
registerAction("resetFields", () => {
    if (typeof resetFields === "function" && typeof soldierTypes !== "undefined") {
        resetFields(soldierTypes);
    }
});

function resetFields(soldierTypes) {
    soldierTypes.forEach(type => {
        /** @type {HTMLInputElement} */
        let ownSoldierType = document.getElementById(type + "_own");
        ownSoldierType.value = "0";
        ownSoldierType.style.color = "inherit";

        /** @type {HTMLInputElement} */
        let enemySoldierType = document.getElementById(type + "_enemy");
        enemySoldierType.value = "0";
        enemySoldierType.style.color = "inherit";
    });
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

    // Collect data
    soldierTypes.forEach(type => {
        let countOwn = parseInt(document.getElementById(`${type}_own`).value) || 0;
        let countEnemy = parseInt(document.getElementById(`${type}_enemy`).value) || 0;
        let atkEl = document.getElementById(`${type}_atk`);
        let defEl = document.getElementById(`${type}_def`);

        let atk = parseInt(atkEl.getAttribute("data-attack"));
        let def = parseInt(defEl.getAttribute("data-defense"));
        let cat = parseInt(atkEl.getAttribute("data-category"));

        myUnits[type] = {count: countOwn, atk: atk, def: def, cat: cat, initial: countOwn};
        enemyUnits[type] = {count: countEnemy, atk: atk, def: def, cat: cat, initial: countEnemy};

        totalOwnUnits += countOwn;
        totalEnemyUnits += countEnemy;
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
                        console.log(`BONUS: ${atkType} (Cat ${aCat}) bekommt +${(0.5 * enemyShare * 100).toFixed(0)}% gegen ${defType} (Cat ${dCat})`);
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

    // Calculate losses
    let playerLossRatio = (playerDefPool > 0) ? Math.min(1.0, enemyAtkPool / playerDefPool) : 1.0;
    let enemyLossRatio = (enemyDefPool > 0) ? Math.min(1.0, playerAtkPool / enemyDefPool) : 1.0;
    playerLossRatio = Math.round(playerLossRatio * 1000000) / 1000000;
    enemyLossRatio = Math.round(enemyLossRatio * 1000000) / 1000000;

    console.log(`ERGEBNIS: Spieler Pool Atk ${playerAtkPool} vs Gegner Pool Def ${enemyDefPool} (Verlustrate Gegner: ${(enemyLossRatio * 100).toFixed(1)}%)`);
    console.log(`ERGEBNIS: Gegner Pool Atk ${enemyAtkPool} vs Spieler Pool Def ${playerDefPool} (Verlustrate Spieler: ${(playerLossRatio * 100).toFixed(1)}%)`);

    // Update UI
    soldierTypes.forEach(type => {
        let ownInput = document.getElementById(`${type}_own`);
        let enemyInput = document.getElementById(`${type}_enemy`);

        let ownLosses = Math.round(myUnits[type].initial * playerLossRatio);
        let enemyLosses = Math.round(enemyUnits[type].initial * enemyLossRatio);

        let ownSurvivors = Math.max(0, myUnits[type].initial - ownLosses);
        let enemySurvivors = Math.max(0, enemyUnits[type].initial - enemyLosses);

        ownInput.value = ownSurvivors;
        enemyInput.value = enemySurvivors;

        ownInput.style.color = (ownLosses > 0) ? "#F55353" : "inherit";
        enemyInput.style.color = (enemyLosses > 0) ? "#F55353" : "inherit";
    });
}
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

// function calculateWarOutcome(soldierTypes) {
//     let mySoldiers = {};
//     let enemySoldiers = {};
//     let myTotalATK = {};
//     let enemyTotalDEF = {};
//     let soldierTypeATK = {};
//     let soldierTypeDEF = {};
//
//     // Initialize totals for each soldier type
//     soldierTypes.forEach(type => {
//         mySoldiers[type] = 0;
//         enemySoldiers[type] = 0;
//         myTotalATK[type] = 0;
//         enemyTotalDEF[type] = 0;
//         soldierTypeATK[type] = 0;
//         soldierTypeDEF[type] = 0;
//     });
//
//     // Collect input values and calculate total ATK and DEF for each soldier type
//     soldierTypes.forEach(type => {
//         /** @type {HTMLInputElement} */
//         let mySoldierCount = document.getElementById(`${type}_own`);
//         /** @type {HTMLInputElement} */
//         let enemySoldierCount = document.getElementById(`${type}_enemy`);
//         /** @type {HTMLElement} */
//         let soldierAtk = document.getElementById(`${type}_atk`);
//         /** @type {HTMLElement} */
//         let soldierDef = document.getElementById(`${type}_def`);
//
//         // Use .value for inputs, and getAttribute for attack/defense data
//         mySoldiers[type] = parseInt(mySoldierCount.value);
//         enemySoldiers[type] = parseInt(enemySoldierCount.value);
//
//         myTotalATK[type] += mySoldiers[type] * parseInt(soldierAtk.getAttribute("data-attack"));
//         enemyTotalDEF[type] += enemySoldiers[type] * parseInt(soldierDef.getAttribute("data-defense"));
//
//         soldierTypeATK[type] = soldierAtk.getAttribute("data-attack");
//         soldierTypeDEF[type] = soldierDef.getAttribute("data-defense");
//     });
//
//     soldierTypes.forEach(attackerType => {
//         if (mySoldiers[attackerType] > 0) {
//             soldierTypes.forEach(defenderType => {
//                 if (enemySoldiers[defenderType] > 0) {
//                     const outcomeForMe = Math.ceil(
//                         Math.max(myTotalATK[attackerType] - enemyTotalDEF[defenderType], 0) / soldierTypeATK[attackerType]
//                     );
//                     const outcomeForEnemy = Math.ceil(
//                         Math.max(enemyTotalDEF[defenderType] - myTotalATK[attackerType], 0) / soldierTypeDEF[defenderType]
//                     );
//
//                     // Update the input fields with the new values for each soldier type
//                     mySoldiers[attackerType] = outcomeForMe;
//                     enemySoldiers[defenderType] = outcomeForEnemy;
//
//                     /** @type {HTMLInputElement} */
//                     const mySoldierInput = document.getElementById(`${attackerType}_own`);
//                     /** @type {HTMLInputElement} */
//                     const enemySoldierInput = document.getElementById(`${defenderType}_enemy`);
//
//                     mySoldierInput.value = mySoldiers[attackerType];
//                     enemySoldierInput.value = enemySoldiers[defenderType];
//
//                     // Recalculate total ATK for type and DEF for enemy type
//                     myTotalATK[attackerType] = mySoldiers[attackerType] * parseInt(
//                         document.getElementById(`${attackerType}_atk`).getAttribute("data-attack")
//                     );
//                     enemyTotalDEF[defenderType] = enemySoldiers[defenderType] * parseInt(
//                         document.getElementById(`${defenderType}_def`).getAttribute("data-defense")
//                     );
//
//                     // Change text color based on losses
//                     mySoldierInput.style.color = outcomeForMe > 0 ? "#F55353" : "inherit";
//                     enemySoldierInput.style.color = outcomeForEnemy > 0 ? "#F55353" : "inherit";
//                 }
//             });
//         }
//     });
// }
function calculateWarOutcome(soldierTypes) {
    let myUnits = {};
    let enemyUnits = {};

    let playerAtkPool = 0;
    let playerDefPool = 0;
    let enemyAtkPool = 0;
    let enemyDefPool = 0;

    let totalOwnUnits = 0;
    let totalEnemyUnits = 0;

    // 1. Daten sammeln
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

    // 2. Angriffs-Pools berechnen (mit Schere-Stein-Papier)
    soldierTypes.forEach(atkType => {
        // Spieler greift an
        if (myUnits[atkType].count > 0) {
            let bonus = 1.0;
            soldierTypes.forEach(defType => {
                if (enemyUnits[defType].initial > 0) {
                    let enemyShare = enemyUnits[defType].initial / totalEnemyUnits;
                    let aCat = myUnits[atkType].cat;
                    let dCat = enemyUnits[defType].cat;

                    // RPS Logik: 0=Inf, 1=Kav, 2=Schütze
                    if ((aCat === 0 && dCat === 1) || (aCat === 1 && dCat === 2) || (aCat === 2 && dCat === 0)) {
                        bonus += (0.5 * enemyShare);
                        console.log(`BONUS: ${atkType} (Cat ${aCat}) bekommt +${(0.5 * enemyShare * 100).toFixed(0)}% gegen ${defType} (Cat ${dCat})`);
                    }
                }
            });
            playerAtkPool += (myUnits[atkType].count * myUnits[atkType].atk * bonus);
        }

        // Gegner greift an
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

        // Verteidigungs-Pools (Bleiben wie sie sind)
        playerDefPool += (myUnits[atkType].count * myUnits[atkType].def);
        enemyDefPool += (enemyUnits[atkType].count * enemyUnits[atkType].def);
    });

    // 3. Verluste berechnen
    let playerLossRatio = (playerDefPool > 0) ? Math.min(1.0, enemyAtkPool / playerDefPool) : 1.0;
    let enemyLossRatio = (enemyDefPool > 0) ? Math.min(1.0, playerAtkPool / enemyDefPool) : 1.0;
    playerLossRatio = Math.round(playerLossRatio * 1000000) / 1000000;
    enemyLossRatio = Math.round(enemyLossRatio * 1000000) / 1000000;

    console.log(`ERGEBNIS: Spieler Pool Atk ${playerAtkPool} vs Gegner Pool Def ${enemyDefPool} (Verlustrate Gegner: ${(enemyLossRatio * 100).toFixed(1)}%)`);
    console.log(`ERGEBNIS: Gegner Pool Atk ${enemyAtkPool} vs Spieler Pool Def ${playerDefPool} (Verlustrate Spieler: ${(playerLossRatio * 100).toFixed(1)}%)`);

    // 4. UI updaten
    soldierTypes.forEach(type => {
        let ownInput = document.getElementById(`${type}_own`);
        let enemyInput = document.getElementById(`${type}_enemy`);

        // Umstieg auf Math.round()
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
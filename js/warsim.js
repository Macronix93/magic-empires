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
    let mySoldiers = {};
    let enemySoldiers = {};
    let myTotalATK = {};
    let enemyTotalDEF = {};
    let soldierTypeATK = {};
    let soldierTypeDEF = {};

    // Initialize totals for each soldier type
    soldierTypes.forEach(type => {
        mySoldiers[type] = 0;
        enemySoldiers[type] = 0;
        myTotalATK[type] = 0;
        enemyTotalDEF[type] = 0;
        soldierTypeATK[type] = 0;
        soldierTypeDEF[type] = 0;
    });

    // Collect input values and calculate total ATK and DEF for each soldier type
    soldierTypes.forEach(type => {
        /** @type {HTMLInputElement} */
        let mySoldierCount = document.getElementById(`${type}_own`);
        /** @type {HTMLInputElement} */
        let enemySoldierCount = document.getElementById(`${type}_enemy`);
        /** @type {HTMLElement} */
        let soldierAtk = document.getElementById(`${type}_atk`);
        /** @type {HTMLElement} */
        let soldierDef = document.getElementById(`${type}_def`);

        // Use .value for inputs, and getAttribute for attack/defense data
        mySoldiers[type] = parseInt(mySoldierCount.value);
        enemySoldiers[type] = parseInt(enemySoldierCount.value);

        myTotalATK[type] += mySoldiers[type] * parseInt(soldierAtk.getAttribute("data-attack"));
        enemyTotalDEF[type] += enemySoldiers[type] * parseInt(soldierDef.getAttribute("data-defense"));

        soldierTypeATK[type] = soldierAtk.getAttribute("data-attack");
        soldierTypeDEF[type] = soldierDef.getAttribute("data-defense");
    });

    soldierTypes.forEach(attackerType => {
        if (mySoldiers[attackerType] > 0) {
            soldierTypes.forEach(defenderType => {
                if (enemySoldiers[defenderType] > 0) {
                    const outcomeForMe = Math.ceil(
                        Math.max(myTotalATK[attackerType] - enemyTotalDEF[defenderType], 0) / soldierTypeATK[attackerType]
                    );
                    const outcomeForEnemy = Math.ceil(
                        Math.max(enemyTotalDEF[defenderType] - myTotalATK[attackerType], 0) / soldierTypeDEF[defenderType]
                    );

                    // Update the input fields with the new values for each soldier type
                    mySoldiers[attackerType] = outcomeForMe;
                    enemySoldiers[defenderType] = outcomeForEnemy;

                    /** @type {HTMLInputElement} */
                    const mySoldierInput = document.getElementById(`${attackerType}_own`);
                    /** @type {HTMLInputElement} */
                    const enemySoldierInput = document.getElementById(`${defenderType}_enemy`);

                    mySoldierInput.value = mySoldiers[attackerType];
                    enemySoldierInput.value = enemySoldiers[defenderType];

                    // Recalculate total ATK for type and DEF for enemy type
                    myTotalATK[attackerType] = mySoldiers[attackerType] * parseInt(
                        document.getElementById(`${attackerType}_atk`).getAttribute("data-attack")
                    );
                    enemyTotalDEF[defenderType] = enemySoldiers[defenderType] * parseInt(
                        document.getElementById(`${defenderType}_def`).getAttribute("data-defense")
                    );

                    // Change text color based on losses
                    mySoldierInput.style.color = outcomeForMe > 0 ? "#F55353" : "inherit";
                    enemySoldierInput.style.color = outcomeForEnemy > 0 ? "#F55353" : "inherit";
                }
            });
        }
    });
}
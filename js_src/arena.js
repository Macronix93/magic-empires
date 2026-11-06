let previousArenaHeaderHtml = null;

registerAction("changeArenaAttackSkill", (select) => {
    const opt = select.selectedOptions[0];
    if (!opt) return;

    const bonus = opt.dataset.bonus;
    const targetName = opt.dataset.target;

    const display = document.getElementById("arena-skill-bonus-display");
    if (display) {
        display.innerHTML = `<b>+${bonus}%</b> vs. ${targetName}`;
    }
});
registerAction("openArenaAttackInline", (btn) => {
    const oppId = btn.dataset.id;
    const oppName = btn.dataset.name;
    const oppElo = btn.dataset.elo;

    const mainView = document.getElementById("arena-main-view");
    const inlineContainer = document.getElementById("arena-attack-inline-container");
    const boxHeader = document.querySelector(".middle-container .big-box-header");

    if (!mainView || !inlineContainer) return;

    const originalBtnHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerText = "Lade Duell...";

    fetch(`ajax/arena_attack_modal.php?defender_id=${oppId}&name=${encodeURIComponent(oppName)}&elo=${oppElo}`, {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, "text/html");
            doc.getElementById("modal-title-data")?.remove();
            doc.querySelectorAll(".overlay-close-btn, button[data-on-click='closeOverlay']").forEach(el => el.remove());

            if (boxHeader) {
                if (!previousArenaHeaderHtml) {
                    previousArenaHeaderHtml = boxHeader.innerHTML;
                }
                boxHeader.innerHTML = `<img src="images/icons/icon_arena.png" class="header-icon" alt=""> Duell: ${oppName} (${oppElo} ELO)`;
            }

            const backBtnHtml = `
                <div style="margin-bottom: 15px;">
                    <button type="button" data-on-click="closeArenaAttackInline" style="padding: 4px 15px;">
                        Zurück zur Arena
                    </button>
                </div>
            `;

            inlineContainer.innerHTML = backBtnHtml + doc.body.innerHTML;

            mainView.style.display = "none";
            inlineContainer.style.display = "block";

            if (typeof bindActions === "function") {
                inlineContainer.querySelectorAll('[data-on-click], [data-on-submit], [data-on-change], [data-on-input]').forEach(bindActions);
            }
            if (typeof setup === "function") {
                setup();
            }

            const attackForm = inlineContainer.querySelector("#arena-attack-form");
            if (attackForm && typeof initArenaForm === "function") {
                attackForm.dataset.initialized = "true";
                initArenaForm(attackForm);
            }

            if (window.innerWidth <= 600) {
                const opponentHeader = inlineContainer.querySelector("#arena-opponent-header");
                if (opponentHeader) {
                    const yOffset = -20;
                    const y = opponentHeader.getBoundingClientRect().top + window.pageYOffset + yOffset;
                    window.scrollTo({top: Math.max(0, y), behavior: "smooth"});
                }
            }
        })
        .catch(err => {
            console.error("Fehler beim Laden des Duells:", err);
            displayArenaError("Fehler beim Laden des Duells.");
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = originalBtnHtml;
        });
});

registerAction("closeArenaAttackInline", () => {
    const mainView = document.getElementById("arena-main-view");
    const inlineContainer = document.getElementById("arena-attack-inline-container");
    const boxHeader = document.querySelector(".middle-container .big-box-header");

    if (inlineContainer) {
        inlineContainer.innerHTML = "";
        inlineContainer.style.display = "none";
    }

    if (boxHeader && previousArenaHeaderHtml) {
        boxHeader.innerHTML = previousArenaHeaderHtml;
        previousArenaHeaderHtml = null;
    }

    if (mainView) {
        mainView.style.display = "block";
    }

    if (typeof setup === "function") {
        setup();
    }
});
registerAction("openArenaUserModal", (el, e) => {
    if (e) e.stopPropagation();
    const userId = el.dataset.userid;
    const username = el.dataset.username || "Spieler";
    openOverlay(`ajax/arena_user_modal.php?userid=${userId}`, `Arena-Profil: ${username}`, null, false, el);
});
registerAction("changeSeasonHistoryMonth", (el) => {
    const month = el.value;
    openOverlay(`ajax/arena_season_history.php?month=${encodeURIComponent(month)}`, "Arena Saison-Archiv");
});
registerAction("openHeroProgressionModal", () => {
    openOverlay("ajax/arena_hero_progression.php?type=hero", "Champion: Werte & Progression");
});
registerAction("openSkillProgressionModal", (el) => {
    const skillId = el.dataset.id;
    const skillName = el.dataset.name || "Skill";
    openOverlay(`ajax/arena_hero_progression.php?type=skill&id=${skillId}`, `${skillName}: Progression`);
});
registerAction("refreshArena", () => {
    window.location.reload();
});
registerAction("switchArenaTab", (el) => {
    if (el.classList.contains("tab-disabled")) return;
    const tabName = el.dataset.tab;

    document.querySelectorAll(".js-arena-tab").forEach(tab => tab.style.display = "none");
    document.querySelectorAll("#arena-tabs .tablinks").forEach(btn => btn.classList.remove("active"));

    const target = document.getElementById("tab_" + tabName);
    if (target) {
        target.style.display = "block";
        el.classList.add("active");
    }

    // When switched to defense tab: reset everything to how it was before (from database)
    if (tabName === "defense") {
        resetDefenseFormToSaved();
    }

    const url = new URL(window.location);
    url.searchParams.set("tab", tabName);
    if (tabName !== "ranking") {
        url.searchParams.delete("page");
    }
    window.history.replaceState({}, '', url);
});
registerAction("rerollArenaOpponents", (btn) => {
    btn.disabled = true;
    const counterEl = document.getElementById("arena-rerolls-counter");
    const currentCounterText = counterEl ? counterEl.innerText : "";
    btn.innerHTML = `Suche... (<span id='arena-rerolls-counter'>${currentCounterText}</span>)`;

    fetch("ajax/arena_actions.php?action=reroll_opponents", {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const container = document.getElementById("arena-opponents-container");
                if (container) {
                    container.innerHTML = data.html;
                }
                if (!data.has_more_opponents) {
                    btn.disabled = true;
                    btn.innerText = "Keine neuen Gegner";
                } else {
                    btn.disabled = (data.rerolls_left <= 0);
                    btn.innerHTML = `Neue Gegner suchen (<span id='arena-rerolls-counter'>${data.rerolls_left} / ${data.max_rerolls}</span>)`;
                }
                if (typeof initAutomaticCountdowns === "function") {
                    initAutomaticCountdowns();
                }
            } else {
                displayArenaError(data.error || "Fehler beim Suchen.");
                if (data.has_more_opponents === false) {
                    btn.disabled = true;
                    btn.innerText = "Keine neuen Gegner";
                } else if (data.rerolls_left !== undefined && data.rerolls_left <= 0) {
                    btn.disabled = true;
                    btn.innerHTML = `Neue Gegner suchen (<span id='arena-rerolls-counter'>0 / ${data.max_rerolls || 3}</span>)`;
                } else {
                    btn.disabled = false;
                    btn.innerHTML = `Neue Gegner suchen (<span id='arena-rerolls-counter'>${currentCounterText}</span>)`;
                }
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = `Neue Gegner suchen (<span id='arena-rerolls-counter'>${currentCounterText}</span>)`;
        });
});
registerAction("saveArenaDefense", (form, e) => {
    if (e) e.preventDefault();
    const btn = document.getElementById("btn-save-defense");
    if (btn) btn.disabled = true;

    const troops = {};
    form.querySelectorAll(".js-arena-input").forEach(inp => {
        const id = inp.name.replace(/[^0-9]/g, '');
        troops[id] = parseInt(inp.value, 10) || 0;
    });

    const techs = {};
    form.querySelectorAll(".js-arena-tech-input").forEach(inp => {
        const id = inp.name.replace(/[^0-9]/g, '');
        techs[id] = parseInt(inp.value, 10) || 0;
    });

    const rewardKid = form.querySelector('select[name="reward_kingdom_id"]')?.value || "0";

    const formData = new URLSearchParams();
    formData.append("action", "save_defense");
    formData.append("troops", JSON.stringify(troops));
    formData.append("techs", JSON.stringify(techs));
    formData.append("reward_kingdom_id", rewardKid);

    fetch("ajax/arena_actions.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Requested-With": "XMLHttpRequest"
        },
        body: formData.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = "arena.php?tab=defense";
            } else {
                displayArenaError(data.error || "Fehler beim Speichern.");
                if (btn) btn.disabled = false;
            }
        });
});
registerAction("openArenaAttackModal", (btn) => {
    const oppId = btn.dataset.id;
    const oppName = btn.dataset.name;
    const oppElo = btn.dataset.elo;

    openOverlay(`ajax/arena_attack_modal.php?defender_id=${oppId}&name=${encodeURIComponent(oppName)}&elo=${oppElo}`, "Duell vorbereiten");
});
registerAction("executeArenaAttack", (form, e) => {
    if (e) e.preventDefault();
    const btn = document.getElementById("btn-start-arena-attack");
    if (btn) {
        btn.disabled = true;
        btn.innerText = "Kämpfe...";
    }

    const troops = {};
    form.querySelectorAll(".js-arena-input").forEach(inp => {
        const id = inp.name.replace(/[^0-9]/g, '');
        troops[id] = parseInt(inp.value, 10) || 0;
    });

    const techs = {};
    form.querySelectorAll(".js-arena-tech-input").forEach(inp => {
        const id = inp.name.replace(/[^0-9]/g, '');
        techs[id] = parseInt(inp.value, 10) || 0;
    });

    const defId = form.querySelector('input[name="defender_id"]').value;
    const skillId = form.querySelector('select[name="skill_id"]')?.value;

    const formData = new URLSearchParams();
    formData.append("action", "attack");
    formData.append("defender_id", defId);
    if (skillId) {
        formData.append("skill_id", skillId);
    }
    formData.append("troops", JSON.stringify(troops));
    formData.append("techs", JSON.stringify(techs));

    fetch("ajax/arena_actions.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Requested-With": "XMLHttpRequest"
        },
        body: formData.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeOverlay();

                const inlineContainer = document.getElementById("arena-attack-inline-container");
                const boxHeader = document.querySelector(".middle-container .big-box-header");

                if (inlineContainer) {
                    if (boxHeader) {
                        boxHeader.innerHTML = `<img src="images/icons/icon_arena.png" class="header-icon" alt=""> Duellbericht`;
                    }

                    inlineContainer.innerHTML = data.battle_html;

                    if (typeof bindActions === "function") {
                        inlineContainer.querySelectorAll('[data-on-click], [data-on-submit]').forEach(bindActions);
                    }
                    if (typeof setup === "function") {
                        setup();
                    }

                    const middleContainer = document.querySelector(".middle-container");
                    if (middleContainer) {
                        middleContainer.scrollIntoView({behavior: "smooth", block: "start"});
                    }
                } else {
                    window.location.reload();
                }
            } else {
                displayArenaError(data.error || "Fehler beim Ausführen des Kampfes.");

                if (btn) {
                    btn.disabled = false;
                    btn.innerText = "⚔️ Kampf beginnen (1 Münze)";
                }
            }
        })
        .catch(() => {
            displayArenaError("Netzwerkfehler beim Kampf.");

            if (btn) {
                btn.disabled = false;
                btn.innerText = "⚔️ Kampf beginnen (1 Münze)";
            }
        });
});
registerAction("resetArenaDefense", () => {
    const form = document.getElementById("arena-defense-form");
    if (!form) return;

    // Troop inputs
    form.querySelectorAll(".js-arena-input").forEach(inp => {
        inp.value = 0;
        const slider = form.querySelector(`.js-arena-slider[data-target="${inp.id}"]`);
        if (slider) slider.value = 0;
    });

    // Smith inputs
    form.querySelectorAll(".js-arena-tech-input").forEach(inp => {
        inp.value = 0;
        const slider = form.querySelector(`.js-arena-tech-slider[data-target="${inp.id}"]`);
        if (slider) slider.value = 0;
    });

    initArenaForm(form);
});
registerAction("selectHeroSkill", (btn, e) => {
    if (e) e.stopPropagation();

    const skillId = btn.dataset.id;
    btn.disabled = true;

    const formData = new URLSearchParams();
    formData.append("action", "select_skill");
    formData.append("skill_id", skillId);

    fetch("ajax/arena_actions.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Requested-With": "XMLHttpRequest"
        },
        body: formData.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                updateHeroSkillUI(skillId);
            } else {
                displayArenaError(data.error || "Fehler beim Wechseln der Fähigkeit.");
                btn.disabled = false;
            }
        })
        .catch(() => {
            displayArenaError("Netzwerkfehler beim Wechseln der Fähigkeit.");
            btn.disabled = false;
        });
});

function updateHeroSkillUI(selectedSkillId) {
    const selectedIdStr = String(selectedSkillId);

    document.querySelectorAll(".arena-skill-card").forEach(card => {
        const cardSkillId = String(card.dataset.skillId || card.dataset.id);
        const btnWrap = card.querySelector(".arena-skill-btn-wrap") || card.lastElementChild;

        if (cardSkillId === selectedIdStr) {
            card.style.border = "2px solid var(--link-color)";
            card.style.background = "rgba(212, 175, 55, 0.15)";

            if (btnWrap) {
                btnWrap.innerHTML = "<b class='passed' style='font-size: 12px;'>Aktiv</b>";
            }
        } else {
            card.style.border = "1px solid rgba(255,255,255,0.1)";
            card.style.background = "rgba(0,0,0,0.2)";

            if (btnWrap) {
                btnWrap.innerHTML = `<button type='button' data-on-click='selectHeroSkill' data-id='${cardSkillId}' style='padding: 3px 8px; font-size: 11px;'>Wählen</button>`;

                if (typeof bindActions === "function") {
                    btnWrap.querySelectorAll('[data-on-click]').forEach(bindActions);
                }
            }
        }
    });
}

function getArenaTechBoni() {
    const cfg = document.getElementById("arena-config");
    if (cfg && cfg.dataset.techBoni) {
        try {
            return JSON.parse(cfg.dataset.techBoni);
        } catch (e) {
            displayArenaError("Fehler beim Parsen der Arena-Tech-Boni:" + e);
        }
    }
    return {
        13: {cat: 0, type: "atk", val: 8},
        14: {cat: 0, type: "def", val: 8},
        15: {cat: 1, type: "atk", val: 8},
        16: {cat: 1, type: "def", val: 8},
        17: {cat: 2, type: "atk", val: 8},
        18: {cat: 2, type: "def", val: 8}
    };
}

function resetDefenseFormToSaved() {
    const form = document.getElementById("arena-defense-form");
    const cfg = document.getElementById("arena-config");
    if (!form || !cfg) return;

    try {
        const savedTroops = JSON.parse(cfg.dataset.defenseTroops || "{}");
        const savedTechs = JSON.parse(cfg.dataset.defenseTechs || "{}");

        form.querySelectorAll(".js-arena-input").forEach(inp => {
            const sid = inp.name.replace(/[^0-9]/g, '');
            const val = parseInt(savedTroops[sid], 10) || 0;
            inp.value = val;

            const slider = form.querySelector(`.js-arena-slider[data-target="${inp.id}"]`);
            if (slider) slider.value = val;
        });

        form.querySelectorAll(".js-arena-tech-input").forEach(inp => {
            const tid = inp.name.replace(/[^0-9]/g, '');
            const val = parseInt(savedTechs[tid], 10) || 0;
            inp.value = val;

            const slider = form.querySelector(`.js-arena-tech-slider[data-target="${inp.id}"]`);
            if (slider) slider.value = val;
        });

        initArenaForm(form);
    } catch (e) {
        console.error("Fehler beim Zurücksetzen der Verteidigung:", e);
    }
}

function initArenaForm(form) {
    if (!form) return;

    const cfg = document.getElementById("arena-config");
    const maxUnits = parseInt(cfg?.dataset.maxUnits, 10) || 5000;
    const maxTechs = parseInt(cfg?.dataset.maxTechs, 10) || 30;

    const getOtherUnitsSum = (currentInput) => {
        let sum = 0;
        form.querySelectorAll(".js-arena-input").forEach(inp => {
            if (inp !== currentInput) sum += parseInt(inp.value, 10) || 0;
        });
        return sum;
    };

    const getOtherTechsSum = (currentInput) => {
        let sum = 0;
        form.querySelectorAll(".js-arena-tech-input").forEach(inp => {
            if (inp !== currentInput) sum += parseInt(inp.value, 10) || 0;
        });
        return sum;
    };

    const updateLivePower = () => {
        const techLvl = {};
        form.querySelectorAll(".js-arena-tech-input").forEach(inp => {
            const tid = parseInt(inp.dataset.techId, 10);
            if (tid) techLvl[tid] = parseInt(inp.value, 10) || 0;
        });

        const bonuses = {0: {atk: 0, def: 0}, 1: {atk: 0, def: 0}, 2: {atk: 0, def: 0}};
        const techBoni = getArenaTechBoni();

        for (let [tid, info] of Object.entries(techBoni)) {
            const lvl = techLvl[tid] || 0;
            bonuses[info.cat][info.type] += lvl * info.val;
        }

        let totalAtk = 0;
        let totalDef = 0;

        form.querySelectorAll(".js-arena-input").forEach(inp => {
            const count = parseInt(inp.value, 10) || 0;
            if (count > 0) {
                const baseAtk = parseInt(inp.dataset.atk, 10) || 0;
                const baseDef = parseInt(inp.dataset.def, 10) || 0;
                const cat = parseInt(inp.dataset.cat, 10) || 0;

                const unitAtk = baseAtk + (bonuses[cat]?.atk || 0);
                const unitDef = baseDef + (bonuses[cat]?.def || 0);

                totalAtk += count * unitAtk;
                totalDef += count * unitDef;
            }
        });

        const heroNode = document.getElementById("hero-data-node");
        if (heroNode) {
            totalAtk += parseInt(heroNode.dataset.atk, 10) || 0;
            totalDef += parseInt(heroNode.dataset.def, 10) || 0;
        }

        const scope = form.closest(".box-content") || form.parentElement || form;
        const atkEl = scope.querySelector(".js-arena-total-atk");
        const defEl = scope.querySelector(".js-arena-total-def");
        if (atkEl) atkEl.innerText = totalAtk.toLocaleString("de-DE");
        if (defEl) defEl.innerText = totalDef.toLocaleString("de-DE");
    };

    const hasDefenseFormChanged = () => {
        if (form.id !== "arena-defense-form") return true;
        const cfg = document.getElementById("arena-config");
        if (!cfg) return true;

        const savedTroops = JSON.parse(cfg.dataset.defenseTroops || "{}");
        const savedTechs = JSON.parse(cfg.dataset.defenseTechs || "{}");
        const savedRewardKid = String(cfg.dataset.rewardKingdomId || "0");

        if (Object.keys(savedTroops).length === 0) return true;

        for (const inp of form.querySelectorAll(".js-arena-input")) {
            const sid = inp.name.replace(/[^0-9]/g, '');
            const currentVal = parseInt(inp.value, 10) || 0;
            const savedVal = parseInt(savedTroops[sid], 10) || 0;
            if (currentVal !== savedVal) return true;
        }

        for (const inp of form.querySelectorAll(".js-arena-tech-input")) {
            const tid = inp.name.replace(/[^0-9]/g, '');
            const currentVal = parseInt(inp.value, 10) || 0;
            const savedVal = parseInt(savedTechs[tid], 10) || 0;
            if (currentVal !== savedVal) return true;
        }

        const currentRewardKid = String(form.querySelector('select[name="reward_kingdom_id"]')?.value || "0");
        if (currentRewardKid !== savedRewardKid) return true;
    };

    const checkValidSubmit = () => {
        let totalUnits = 0;
        form.querySelectorAll(".js-arena-input").forEach(inp => {
            totalUnits += parseInt(inp.value, 10) || 0;
        });

        let totalTechs = 0;
        form.querySelectorAll(".js-arena-tech-input").forEach(inp => {
            totalTechs += parseInt(inp.value, 10) || 0;
        });

        const isDefenseForm = (form.id === "arena-defense-form");
        const hasChanged = isDefenseForm ? hasDefenseFormChanged() : true;

        const submitBtn = form.querySelector("#btn-save-defense, #btn-start-arena-attack");
        if (submitBtn) {
            submitBtn.disabled = (totalUnits !== maxUnits || totalTechs > maxTechs || (isDefenseForm && !hasChanged));
        }
    };

    const updateTroopsCounter = () => {
        let total = 0;
        form.querySelectorAll(".js-arena-input").forEach(inp => {
            total += parseInt(inp.value, 10) || 0;
        });

        const counter = form.querySelector("#defense-troops-counter, #attack-troops-counter");
        if (counter) {
            counter.innerText = `${total.toLocaleString("de-DE")} / ${maxUnits.toLocaleString("de-DE")}`;
            counter.className = (total === maxUnits) ? "passed" : "error";
        }

        checkValidSubmit();
        updateLivePower();
    };

    const updateTechsCounter = () => {
        let total = 0;
        form.querySelectorAll(".js-arena-tech-input").forEach(inp => {
            total += parseInt(inp.value, 10) || 0;
        });

        const counter = form.querySelector("#defense-techs-counter, #attack-techs-counter");
        if (counter) {
            counter.innerText = `${total} / ${maxTechs}`;
            counter.className = (total <= maxTechs) ? "passed" : "error";
        }

        checkValidSubmit();
        updateLivePower();
    };

    if (!form.dataset.eventsBound) {
        form.dataset.eventsBound = "true";

        const rewardSelect = form.querySelector('select[name="reward_kingdom_id"]');
        if (rewardSelect) {
            rewardSelect.addEventListener("change", () => checkValidSubmit());
        }

        form.querySelectorAll(".js-arena-slider").forEach(slider => {
            slider.step = "1";
            slider.addEventListener("input", function () {
                const targetInput = form.querySelector("#" + this.dataset.target);
                if (!targetInput) return;

                let val = parseInt(this.value, 10) || 0;
                const otherSum = getOtherUnitsSum(targetInput);
                const maxAllowed = Math.max(0, maxUnits - otherSum);

                if (val > maxAllowed) {
                    val = maxAllowed;
                    this.value = val;
                }
                targetInput.value = val;

                updateTroopsCounter();
            });
        });

        form.querySelectorAll(".js-arena-input").forEach(input => {
            input.addEventListener("input", function () {
                let val = parseInt(this.value.replace(/\D/g, ''), 10) || 0;
                const otherSum = getOtherUnitsSum(this);
                const maxAllowed = Math.max(0, maxUnits - otherSum);

                if (val > maxAllowed) val = maxAllowed;

                this.value = val;
                const slider = form.querySelector(`[data-target="${this.id}"]`);
                if (slider) slider.value = val;

                updateTroopsCounter();
            });
        });

        form.querySelectorAll(".js-arena-tech-slider").forEach(slider => {
            slider.addEventListener("input", function () {
                const targetInput = form.querySelector("#" + this.dataset.target);
                if (!targetInput) return;

                let val = parseInt(this.value, 10) || 0;
                const otherSum = getOtherTechsSum(targetInput);
                const maxAllowed = Math.min(10, Math.max(0, maxTechs - otherSum));

                if (val > maxAllowed) {
                    val = maxAllowed;
                    this.value = val;
                }
                targetInput.value = val;

                updateTechsCounter();
            });
        });

        form.querySelectorAll(".js-arena-tech-input").forEach(input => {
            input.addEventListener("input", function () {
                let val = parseInt(this.value.replace(/\D/g, ''), 10) || 0;
                const otherSum = getOtherTechsSum(this);
                const maxAllowed = Math.min(10, Math.max(0, maxTechs - otherSum));

                if (val > maxAllowed) val = maxAllowed;

                this.value = val;
                const slider = form.querySelector(`[data-target="${this.id}"]`);
                if (slider) slider.value = val;

                updateTechsCounter();
            });
        });
    }

    const dismissActiveInput = () => {
        const active = document.activeElement;

        if (active && (active.tagName === "INPUT" || active.tagName === "TEXTAREA") && active.type !== "range") {
            active.blur();
        }
    };

    form.querySelectorAll(".js-arena-slider, .js-arena-tech-slider").forEach(slider => {
        slider.addEventListener("pointerdown", dismissActiveInput, {passive: true});
        slider.addEventListener("touchstart", dismissActiveInput, {passive: true});
    });

    updateTroopsCounter();
    updateTechsCounter();
    updateLivePower();
}

function displayArenaError(message) {
    const errorHtml = `
        <div class="info-box event-error">
            <img src="images/icons/icon_error.png" alt="Fehler">
            <span>${message}</span>
        </div>`;

    const modalBody = document.getElementById("overlay-content-body");
    if (modalBody && modalBody.offsetParent !== null) {
        const oldError = modalBody.querySelector('.event-error');
        if (oldError) oldError.remove();

        modalBody.insertAdjacentHTML("afterbegin", errorHtml);
        modalBody.scrollTo({top: 0, behavior: "smooth"});
    } else {
        const inlineContainer = document.getElementById("arena-attack-inline-container");
        if (inlineContainer && inlineContainer.style.display !== "none") {
            const oldError = inlineContainer.querySelector('.event-error');
            if (oldError) oldError.remove();

            inlineContainer.insertAdjacentHTML("afterbegin", errorHtml);

            window.scrollTo({top: 0, behavior: "smooth"});
            return;
        }

        const flash = document.getElementById("middle-flash-container");
        if (flash) {
            const old = flash.querySelector('.event-error');
            if (old) old.remove();

            flash.insertAdjacentHTML("afterbegin", errorHtml);
            window.scrollTo({top: 0, behavior: "smooth"});
        } else {
            showConfirmationDialog(message, "Ok", "", () => {
            });
        }
    }
}

const arenaModalObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
            if (node.nodeType === 1) {
                const attackForm = node.id === "arena-attack-form" ? node : node.querySelector?.("#arena-attack-form");
                if (attackForm && !attackForm.dataset.initialized) {
                    attackForm.dataset.initialized = "true";
                    initArenaForm(attackForm);
                }
            }
        });
    });
});
arenaModalObserver.observe(document.body, {childList: true, subtree: true});

document.addEventListener("DOMContentLoaded", () => {
    const defForm = document.getElementById("arena-defense-form");
    if (defForm) {
        initArenaForm(defForm);
    }
});
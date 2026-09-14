registerAction("inviteToGuildDialog", (el) => {
    const userId = el.dataset.userid;
    const userName = el.dataset.username;

    showConfirmationDialog(
        `Möchtest du ${userName} wirklich in deine Gilde einladen?`,
        "Einladen",
        "Abbrechen",
        () => {
            executeGuildAction("invite", userId);
        }
    );
});
registerAction("fillProjectMax", (el) => {
    const targetId = el.dataset.target;
    const input = document.getElementById(targetId);
    if (!input || input.disabled) return;

    const maxVal = parseInt(el.dataset.max) || 0;
    input.value = maxVal > 0 ? maxVal : "";
});
registerAction("acceptGuildInvite", (el) => {
    const inviteId = el.dataset.id;
    const msgId = el.closest('[id^="msg-"]')?.id.replace('msg-', '') || '';

    fetch(`ajax/guild_actions.php?action=accept_invite&invite_id=${inviteId}&msg_id=${msgId}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    }).then(() => {
        window.location.href = "guild.php";
    });
});
registerAction("declineGuildInvite", (el) => {
    const inviteId = el.dataset.id;
    const msgId = el.closest('[id^="msg-"]')?.id.replace('msg-', '') || '';

    fetch(`ajax/guild_actions.php?action=decline_invite&invite_id=${inviteId}&msg_id=${msgId}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    }).then(() => {
        window.location.href = "guild.php";
    });
});
registerAction("confirmKickMember", (el) => {
    const uid = el.dataset.userid;
    const name = el.dataset.username;

    showConfirmationDialog(
        `Soll ${name} wirklich aus der Gilde entfernt werden?`,
        "Ja, Kicken",
        "Abbrechen",
        () => {
            executeGuildAction("kick", uid);
        }
    );
});
registerAction("changeMemberRank", (el) => {
    const uid = el.dataset.userid;
    const rankId = el.value;
    const rankName = el.options[el.selectedIndex].text;

    showConfirmationDialog(
        `Möchtest du den Rang dieses Mitglieds wirklich auf "${rankName}" ändern?`,
        "Ja, Ändern",
        "Abbrechen",
        () => {
            fetch(`ajax/guild_actions.php?action=set_rank&target_id=${uid}&rank_id=${rankId}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        const contentBody = document.getElementById("overlay-content-body");
                        const errorHtml = `
                    <div class="info-box event-error">
                        <img src="images/icons/icon_error.png" alt="Fehler">
                        <span>${data.error}</span>
                    </div>`;

                        if (!contentBody || contentBody.offsetParent === null) {
                            const existing = document.querySelector('.event-error');

                            if (existing) existing.remove();

                            document.querySelector('.big-box-content').insertAdjacentHTML("afterbegin", errorHtml);
                        } else {
                            contentBody.insertAdjacentHTML("afterbegin", errorHtml);
                        }
                    }
                });
        }
    );
});
registerAction("openGuildInfo", (el) => {
    const guildId = el.dataset.id;

    openOverlay(`ajax/guild_info.php?id=${guildId}`, "Gilden-Information");
});
registerAction("confirmLeaveGuild", (el) => {
    const cooldown = el.dataset.cooldown;

    showConfirmationDialog(
        `Möchtest du die Gilde wirklich verlassen? Falls du der Leader bist, wird die Führung automatisch übertragen.
        \nHinweis: Du kannst nach dem Austritt erst in ${cooldown} wieder einer neuen Gilde beitreten.`,
        "Ja, Austreten",
        "Abbrechen",
        () => {
            fetch(`ajax/guild_actions.php?action=leave`, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(r => r.json()).then(data => {

                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error);
                }
            });
        }
    );
});
registerAction("joinGuild", (el) => {
    const guildId = el.dataset.id;
    const guildName = el.dataset.name;

    showConfirmationDialog(
        `Möchtest du der Gilde "${guildName}" wirklich beitreten?`,
        "Ja, Beitreten",
        "Abbrechen",
        () => {
            executeGuildAction("join", guildId);
        }
    );
});
registerAction("confirmCancelInvite", (el) => {
    const inviteId = el.dataset.id;
    const name = el.dataset.name;

    showConfirmationDialog(
        `Soll die Einladung für ${name} wirklich zurückgezogen werden?`,
        "Ja, Zurückziehen",
        "Abbrechen",
        () => {
            fetch(`ajax/guild_actions.php?action=cancel_invite&invite_id=${inviteId}`, {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        displayGuildError(data.error);
                    }
                });
        }
    );
});
registerAction("switchGuildTab", (el) => {
    const tabName = el.dataset.tab;

    document.cookie = "me_guild_tab=" + tabName + "; path=/; max-age=31536000; SameSite=Lax";

    document.querySelectorAll('.js-guild-tab').forEach(tab => {
        tab.style.display = "none";
    });

    document.querySelectorAll('.tab .tablinks').forEach(btn => {
        btn.classList.remove("active");
    });

    const targetTab = document.getElementById("guild_tab_" + tabName);
    if (targetTab) {
        targetTab.style.display = "block";
        el.classList.add("active");

        if (tabName === "chat") {
            setTimeout(() => {
                if (typeof scrollDown === "function") {
                    scrollDown(true);
                }
            }, 50);
        }
    }

    const url = new URL(window.location);
    url.searchParams.set("tab", tabName);
    window.history.replaceState({}, '', url);
});
registerAction("confirmCancelProject", (el) => {
    const donated = parseInt(el.dataset.donated) || 0;

    let msg;
    if (donated > 0) {
        msg = "⚠️ ACHTUNG: Für dieses Projekt wurde bereits von Mitgliedern gespendet!\n\nWenn du als Gilden-Leader jetzt abbrichst, gibt es KEINEN Refund für die eingezahlten Ressourcen! Bist du absolut sicher?";
    } else {
        msg = "Möchtest du das aktuelle Gilden-Projekt wirklich abbrechen?";
    }

    showConfirmationDialog(
        msg,
        "Ja, abbrechen",
        "Nein",
        () => {
            window.location.href = "guild.php?tab=research&cancel_project=1";
        }
    );
});
registerAction("toggleCreateInviteOnly", (el) => {
    const input = document.getElementById("g_min_score_create");
    if (input) {
        input.disabled = el.checked;
        if (el.checked) input.value = "0";
    }
});

registerAction("toggleSettingsInviteOnly", (el) => {
    const input = document.getElementById("g_min_score_settings");
    if (input) {
        input.disabled = el.checked;
        if (el.checked) input.value = "0";
    }
});

const displayGuildError = (message) => {
    const errorHtml = `
        <div class="info-box event-error">
            <img src="images/icons/icon_error.png" alt="Fehler">
            <span>${message}</span>
        </div>`;

    const contentBody = document.getElementById("overlay-content-body");

    if (contentBody && contentBody.offsetParent !== null) {
        const oldError = contentBody.querySelector('.event-error');

        if (oldError) oldError.remove();

        contentBody.insertAdjacentHTML("afterbegin", errorHtml);
    } else {
        const existing = document.querySelector('.event-error');
        if (existing) existing.remove();

        document.querySelector('.big-box-content').insertAdjacentHTML("afterbegin", errorHtml);
        window.scrollTo({top: 0, behavior: "smooth"});
    }
};

const executeGuildAction = (action, targetId) => {
    let url = `ajax/guild_actions.php?action=${action}`;
    if (action === 'join') url += `&guild_id=${targetId}`;
    else url += `&target_id=${targetId}`;

    fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (action === "invite") {
                    const contentBody = document.getElementById("overlay-content-body");

                    if (contentBody && contentBody.offsetParent !== null) {
                        const oldBox = contentBody.querySelector('.info-box');
                        if (oldBox) oldBox.remove();

                        contentBody.insertAdjacentHTML("afterbegin", data.html);
                    } else {
                        location.reload();
                    }
                } else {
                    location.reload();
                }
            } else {
                displayGuildError(data.error);
            }
        });
};

document.addEventListener("input", (e) => {
    if (e.target.classList.contains('js-numeric-input')) {
        e.target.value = e.target.value.replace(/[^0-9]/g, '');

        if (e.target.value.length > 1 && e.target.value.startsWith('0')) {
            e.target.value = e.target.value.replace(/^0+/, '');
        }
    }

    if (e.target.classList.contains('js-internal-res-input')) {
        let val = parseInt(e.target.value.replace(/[^0-9]/g, '')) || 0;
        const needed = parseInt(e.target.dataset.needed) || 0;

        if (val > needed) {
            val = needed;
        }

        e.target.value = val > 0 ? val : "";
    }

    if (e.target.classList.contains('js-project-res-input')) {
        let val = parseInt(e.target.value.replace(/[^0-9]/g, '')) || 0;
        const needed = parseInt(e.target.dataset.needed) || 0;
        const stock = parseInt(e.target.dataset.stock) || 0;
        const maxAllowed = Math.min(needed, stock);

        if (val > maxAllowed) {
            val = maxAllowed;
        }

        e.target.value = val > 0 ? val : "";
    }
});

document.addEventListener("DOMContentLoaded", () => {
    if (window.innerWidth <= 1392) {
        const chatTab = document.getElementById("guild_tab_chat");

        if (chatTab && window.getComputedStyle(chatTab).display !== "none") {
            const chatWrapper = document.getElementById("chat-loading-wrapper") || chatTab;

            setTimeout(() => {
                const yOffset = -20;
                const y = chatWrapper.getBoundingClientRect().top + window.pageYOffset + yOffset;

                window.scrollTo({top: y, behavior: "smooth"});
            }, 250);
        }
    }
});
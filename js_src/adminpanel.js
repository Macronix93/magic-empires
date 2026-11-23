registerAction("resetRateLimitDialog", (el) => {
    const uid = el.dataset.userid;
    const name = el.dataset.username;

    showConfirmationDialog(
        `Möchtest du das Login-/Passwort-Rate-Limit für ${name} wirklich zurücksetzen?`,
        "Ja, zurücksetzen",
        "Abbrechen",
        () => {
            submitAdminPost({reset_user_rate_limit: "1", user_id: uid});
        }
    );
});
registerAction("banIpDialog", (el) => {
    const uid = el.dataset.userid;
    const ip = el.dataset.ip;
    const shouldBan = el.dataset.status === "1";

    if (shouldBan) {
        showConfirmationDialog(
            `IP ${ip} sperren:`,
            "Sperren",
            "Abbrechen",
            (reason) => {
                submitAdminPost({
                    toggle_ip_ban: "1",
                    user_id: uid,
                    ip: ip,
                    ip_ban_status: "1",
                    reason: reason
                });
            },
            {
                label: "Grund:",
                defaultValue: "Verstoß gegen die Regeln",
                placeholder: "Sperrgrund eingeben..."
            }
        );
    } else {
        showConfirmationDialog(
            `Möchtest du die IP ${ip} wirklich wieder entsperren?`,
            "Ja, entsperren",
            "Abbrechen",
            () => {
                submitAdminPost({
                    toggle_ip_ban: "1",
                    user_id: uid,
                    ip: ip,
                    ip_ban_status: "0"
                });
            }
        );
    }
});
registerAction("editUserField", (el) => {
    editField(
        el.dataset.userid,
        el.dataset.fieldid,
        el.dataset.raw,
        el.dataset.formatted
    );
});
registerAction("userDeletionDialog", (el) => {
    const uid = el.dataset.userid;
    const name = el.dataset.username;

    showConfirmationDialog(
        `Willst du den Benutzer ${name} (ID: ${uid}) wirklich löschen?`,
        "Ja",
        "Nein",
        () => {
            submitAdminPost({
                delete_user: "1",
                user_id: uid
            });
        }
    );
});
registerAction("banUserDialog", (el) => {
    const uid = el.dataset.userid;
    const name = el.dataset.username;
    const isBanned = el.dataset.status === "1";

    if (isBanned) {
        showConfirmationDialog(
            `Willst du ${name} wirklich entbannen?`,
            "Ja",
            "Abbrechen",
            () => {
                submitAdminPost({
                    toggle_ban: "1",
                    user_id: uid,
                    ban_status: "0"
                });
            }
        );
    } else {
        showConfirmationDialog(
            `Benutzer "${name}" sperren:`,
            "Bannen",
            "Abbrechen",
            (values) => {
                const hours = parseInt((values.ban_hours || "").replace(/\D/g, ""), 10) || 0;
                submitAdminPost({
                    toggle_ban: "1",
                    user_id: uid,
                    ban_status: "1",
                    reason: values.reason,
                    ban_hours: hours
                });
            },
            [
                {
                    name: "reason",
                    label: "Bann-Grund:",
                    defaultValue: "Verstoß gegen die Regeln",
                    placeholder: "Grund eingeben..."
                },
                {
                    name: "ban_hours",
                    label: "Dauer in Stunden (0 oder leer = PERMANENT):",
                    defaultValue: "0",
                    inputmode: "numeric",
                    numericOnly: true
                }
            ]
        );
    }
});

registerAction("confirmDeleteNews", (el) => {
    const newsId = el.dataset.id;

    showConfirmationDialog(
        "Soll dieser Neuigkeiten-Eintrag wirklich gelöscht werden?",
        "Ja, löschen",
        "Abbrechen",
        () => {
            const form = document.createElement("form");
            form.method = "POST";
            form.action = "news.php";

            const idInput = document.createElement("input");
            idInput.type = "hidden";
            idInput.name = "news_id";
            idInput.value = newsId;
            form.appendChild(idInput);

            const actionInput = document.createElement("input");
            actionInput.type = "hidden";
            actionInput.name = "delete_news";
            actionInput.value = "1";
            form.appendChild(actionInput);

            const tokenInput = document.createElement("input");
            tokenInput.type = "hidden";
            tokenInput.name = "csrf_token";
            tokenInput.value = getCsrfToken();
            form.appendChild(tokenInput);

            document.body.appendChild(form);
            form.submit();
        }
    );
});
registerAction("confirmDeleteLog", (el) => {
    const logId = el.dataset.id;
    const page = el.dataset.page || 1;

    showConfirmationDialog(
        "Soll dieser Log-Eintrag wirklich gelöscht werden?",
        "Ja, löschen",
        "Abbrechen",
        () => {
            submitAdminPost({
                delete_log: "1",
                log_id: logId,
                logpage: page
            });
        }
    );
});
registerAction("confirmDeleteEvent", (el) => {
    const eventId = el.dataset.id;
    const userId = el.dataset.userid;

    showConfirmationDialog(
        "Soll dieses Event (Bau/Rekrutierung/Forschung) wirklich abgebrochen werden?",
        "Ja, abbrechen",
        "Nein",
        () => {
            submitAdminPost({
                delete_event: "1",
                event_id: eventId,
                user_id: userId
            });
        }
    );
});
registerAction("confirmResetRound", (el) => {
    showConfirmationDialog(
        "Bist du absolut sicher? Dies wird die gesamte Spielwelt unwiderruflich löschen und eine neue Runde starten!",
        "JA, WELT RESETTEN",
        "Abbrechen",
        () => {
            const form = el.closest('form');
            const hidden = form.querySelector('#hidden_reset_submit');

            hidden.value = "1";
            form.submit();
        }
    );
});
registerAction("confirmMaintenance", (el) => {
    const isCurrentlyOn = el.value === "Deaktivieren";
    if (isCurrentlyOn) {
        el.closest('form').submit();
    } else {
        showConfirmationDialog(
            "Wartungsmodus aktivieren:",
            "Aktivieren",
            "Abbrechen",
            (reason) => {
                document.getElementById('maint_reason_input').value = reason;
                el.closest('form').submit();
            },
            {
                label: "Grund für die Wartung:",
                defaultValue: "Wartungsarbeiten & Updates",
                placeholder: "Wartungsgrund..."
            }
        );
    }
});
registerAction("confirmClearLog", (el) => {
    const filename = el.dataset.filename;
    const form = el.closest('form');

    showConfirmationDialog(
        `Soll die Log-Datei "${filename}" wirklich unwiderruflich geleert werden?`,
        "Ja, leeren",
        "Abbrechen",
        () => {
            form.submit();
        }
    );
});
registerAction("switchAdminTab", (el) => {
    const tabName = el.dataset.tab;

    document.querySelectorAll('.admin-tab').forEach(tab => {
        tab.style.display = "none";
    });
    document.querySelectorAll('.tablinks').forEach(btn => {
        btn.classList.remove("active");
    });
    document.getElementById("tab_" + tabName).style.display = "block";
    el.classList.add("active");

    const url = new URL(window.location);
    url.searchParams.set("tab", tabName);

    if (tabName !== "users") {
        url.searchParams.delete("userid");
        url.searchParams.delete("kingdomid");
    }
    if (tabName !== "gamelogs") {
        url.searchParams.delete("logpage");
    }

    window.history.replaceState({}, '', url);
});
registerAction("confirmStartAdvent", () => {
    showConfirmationDialog(
        "Möchtest du den Adventskalender für alle Spieler starten? Tag 1 wird sofort an alle aktiven Spieler verteilt und die Benachrichtigung aktiviert.",
        "Ja, starten",
        "Abbrechen",
        () => {
            submitAdminPost({start_advent_calendar: "1"});
        }
    );
});
registerAction("confirmStopAdvent", () => {
    showConfirmationDialog(
        "Möchtest du den Adventskalender wirklich stoppen und alle noch ungesammelten Geschenke entfernen?",
        "Ja, stoppen",
        "Abbrechen",
        () => {
            submitAdminPost({stop_advent_calendar: "1"});
        }
    );
});

if (window.location.href.indexOf("adminpanel.php") > -1) {
    if ("scrollRestoration" in history) {
        history.scrollRestoration = "manual";
    }

    document.addEventListener("DOMContentLoaded", () => {
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');

        if (activeTab === 'gamelogs' || window.location.hash === '#logs') {
            const scrollPos = sessionStorage.getItem("admin_scroll_pos");

            if (scrollPos) {
                window.scrollTo(0, parseInt(scrollPos));
            }
        } else {
            window.scrollTo(0, 0);
        }
    });

    window.addEventListener("scroll", () => {
        const urlParams = new URLSearchParams(window.location.search);

        if (urlParams.get('tab') === 'gamelogs') {
            sessionStorage.setItem("admin_scroll_pos", window.scrollY);
        }
    });
}

function submitAdminPost(params) {
    const form = document.createElement("form");
    form.method = "POST";
    form.action = "adminpanel.php";

    for (const [key, val] of Object.entries(params)) {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = key;
        input.value = val;

        form.appendChild(input);
    }

    const tokenInput = document.createElement("input");
    tokenInput.type = "hidden";
    tokenInput.name = "csrf_token";
    tokenInput.value = getCsrfToken();
    form.appendChild(tokenInput);

    document.body.appendChild(form);
    form.submit();
}

function editField(userID, fieldID, currentValue, formattedValue) {
    const td = document.getElementById("td_" + fieldID);

    if (td.querySelector('input')) return;

    const input = document.createElement("input");
    input.type = "text";
    input.value = currentValue;

    const form = document.createElement("form");
    form.method = "POST";
    form.action = '';

    const hiddenField = document.createElement("input");
    hiddenField.type = "hidden";
    hiddenField.name = "field";
    hiddenField.value = fieldID;

    const hiddenUserID = document.createElement("input");
    hiddenUserID.type = "hidden";
    hiddenUserID.name = "user_id";
    hiddenUserID.value = userID;

    const hiddenNewValue = document.createElement("input");
    hiddenNewValue.type = "hidden";
    hiddenNewValue.name = "new_value";
    hiddenNewValue.value = currentValue;

    const hiddenCurrentValue = document.createElement("input");
    hiddenCurrentValue.type = "hidden";
    hiddenCurrentValue.name = "old_value";
    hiddenCurrentValue.value = currentValue;

    const hiddenToken = document.createElement("input");
    hiddenToken.type = "hidden";
    hiddenToken.name = "csrf_token";
    hiddenToken.value = getCsrfToken();
    form.appendChild(hiddenToken);

    form.appendChild(hiddenField);
    form.appendChild(hiddenUserID);
    form.appendChild(hiddenNewValue);
    form.appendChild(hiddenCurrentValue);
    form.appendChild(input);

    td.replaceChildren();
    td.appendChild(form);

    const doCancel = () => cancelEdit(td, formattedValue);

    input.addEventListener("keydown", function (event) {
        if (event.key === "Enter") {
            input.removeEventListener("blur", doCancel);
            hiddenNewValue.value = input.value;
            form.submit();
        } else if (event.key === "Escape") {
            input.removeEventListener("blur", doCancel);
            doCancel();
        }
    });

    input.addEventListener("blur", doCancel);

    input.focus();
}

function cancelEdit(td, originalValue) {
    td.innerHTML = originalValue;
}
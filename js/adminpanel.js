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
            window.location.href = "adminpanel.php?deleteuser=" + uid;
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
            "Ja", "Abbrechen",
            () => {
                window.location.href = `adminpanel.php?userid=${uid}&unbanuser=${uid}`;
            }
        );
    } else {
        const reason = prompt(`Grund für den Bann von ${name}:`, "Verstoß gegen die Regeln");

        if (reason !== null) {
            window.location.href = `adminpanel.php?userid=${uid}&banuser=${uid}&reason=${encodeURIComponent(reason)}`;
        }
    }
});
registerAction("confirmDeleteNews", (el) => {
    const newsId = el.dataset.id;

    showConfirmationDialog(
        "Soll dieser Neuigkeiten-Eintrag wirklich gelöscht werden?",
        "Ja, löschen",
        "Abbrechen",
        () => {
            window.location.href = "news.php?delete=" + newsId;
        }
    );
});

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

function userDeletionDialog(userID) {
    showConfirmationDialog(
        'Willst du den Benutzer wirklich löschen?',
        'Ja',
        'Nein',
        () => {
            deleteUser('adminpanel.php?deleteuser=' + userID);
        }
    );
}

function deleteUser(url) {
    window.location.href = url;
}
registerAction("confirmDeleteTicket", (el) => {
    showConfirmationDialog("Soll dieses Ticket wirklich unwiderruflich gelöscht werden?",
        "Ja", "Abbrechen", () => {
            submitSupportPostAction(el.dataset.id, "delete_ticket");
        });
});

registerAction("confirmCloseTicket", (el) => {
    showConfirmationDialog("Möchtest du das Ticket als erledigt markieren und schließen?",
        "Ja, schließen", "Abbrechen", () => {
            submitSupportPostAction(el.dataset.id, "close_ticket");
        });
});
registerAction("switchSupportTab", (el) => {
    const tabName = el.dataset.tab;
    document.querySelectorAll('#support-tabs .tablinks').forEach(tab => tab.classList.remove("active"));
    document.querySelectorAll('.js-support-tab').forEach(content => content.style.display = "none");

    el.classList.add("active");
    const target = document.getElementById("support_tab_" + tabName);
    if (target) {
        target.style.display = "block";
    }

    const url = new URL(window.location);
    url.searchParams.set("tab", tabName);
    window.history.replaceState({}, '', url);
});

function submitSupportPostAction(tid, actionName) {
    const form = document.createElement("form");
    form.method = "POST";
    form.action = "support.php";

    const tidInput = document.createElement("input");
    tidInput.type = "hidden";
    tidInput.name = "tid";
    tidInput.value = tid;
    form.appendChild(tidInput);

    const actInput = document.createElement("input");
    actInput.type = "hidden";
    actInput.name = actionName;
    actInput.value = "1";
    form.appendChild(actInput);

    const tokenInput = document.createElement("input");
    tokenInput.type = "hidden";
    tokenInput.name = "csrf_token";
    tokenInput.value = getCsrfToken();
    form.appendChild(tokenInput);

    document.body.appendChild(form);
    form.submit();
}

function scrollSupportToBottom() {
    const messageSection = document.getElementById("messages-section");

    if (messageSection) {
        messageSection.scrollTop = messageSection.scrollHeight;
    }
}

window.addEventListener("load", scrollSupportToBottom);

document.addEventListener("DOMContentLoaded", () => {
    const input = document.getElementById("message-input");
    const form = document.getElementById("newmessage");

    if (input && form) {
        input.addEventListener("keydown", e => {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();

                form.requestSubmit();
            }
        });
    }

    const newTokenInput = document.getElementById("new-ticket-text");
    const newTokenForm = document.getElementById("newticketform");

    if (newTokenInput && newTokenForm) {
        newTokenInput.addEventListener("keydown", e => {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();

                newTokenForm.requestSubmit();
            }
        });
    }
});
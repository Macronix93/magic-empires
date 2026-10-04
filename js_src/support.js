registerAction("confirmDeleteTicket", (el) => {
    showConfirmationDialog("Soll dieses Ticket wirklich unwiderruflich gelöscht werden?",
        "Ja", "Abbrechen", () => {
            window.location.href = "support.php?delete=" + el.dataset.id;
        });
});
registerAction("confirmCloseTicket", (el) => {
    showConfirmationDialog("Möchtest du das Ticket als erledigt markieren und schließen?",
        "Ja, schließen", "Abbrechen", () => {
            window.location.href = "support.php?close=" + el.dataset.id;
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
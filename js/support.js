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
const SUPPORT_POLL_INTERVAL = 5000;
let supportLastId = 0;
let isPollingSupport = false;
let isSendingSupport = false;
let isSupportRateLimited = false;

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

function setSupportSendLocked(locked) {
    isSupportRateLimited = locked;
    const btn = document.querySelector('#newmessage input[type="submit"]');
    if (btn) btn.disabled = locked;
}

function showSupportChatError(message, seconds = undefined) {
    const infoBox = document.getElementById("support-chat-error");
    if (!infoBox) return;

    infoBox.replaceChildren();

    const img = document.createElement("img");
    img.src = "images/icons/icon_error.png";
    img.alt = "Fehler";

    const span = document.createElement("span");
    span.textContent = message + (seconds ? " " : "");

    if (seconds && seconds > 0) {
        setSupportSendLocked(true);

        const c = document.createElement("span");
        c.id = "counter";
        c.style.fontWeight = "bold";
        span.appendChild(c);

        if (typeof startCountdown === "function") {
            startCountdown(c, seconds, 0, null, true, true);
        }
    }

    infoBox.append(img, span);
    infoBox.style.display = "flex";
}

function scrollSupportToBottom(force = false) {
    const section = document.getElementById("messages-section");

    if (!section) return;

    if (force) {
        section.scrollTop = section.scrollHeight;
    } else {
        const isAtBottom = (section.scrollHeight - section.scrollTop - section.clientHeight) < 150;
        if (isAtBottom) {
            section.scrollTo({top: section.scrollHeight, behavior: "smooth"});
        }
    }
}

function scrollSupportInitial() {
    const section = document.getElementById("messages-section");
    if (!section) return;

    const newLine = document.getElementById("new-message-line");
    if (newLine) {
        section.scrollTop = Math.max(0, newLine.offsetTop - 10);
    } else {
        section.scrollTop = section.scrollHeight;
    }
}

function insertSupportBubble(section, bubble) {
    if (!section || !bubble || document.getElementById(bubble.id)) return;
    section.appendChild(bubble);
}

function sendSupportMessageAjax(form) {
    if (isSendingSupport || isSupportRateLimited) return;

    const input = document.getElementById("message-input");
    const section = document.getElementById("messages-section");
    if (!input || !section) return;
    if (input.value.trim() === "") return;

    document.querySelectorAll(".emoji-menu.open").forEach(m => m.classList.remove("open"));
    const submitBtn = form.querySelector('input[type="submit"]');

    isSendingSupport = true;

    if (submitBtn) submitBtn.disabled = true;

    const formData = new URLSearchParams();
    formData.append("tid", section.dataset.ticketId || "0");
    formData.append("text", input.value);

    fetch("ajax/support_chat.php", {
        method: "POST",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: formData.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                showSupportChatError(data.error, data.counter);

                if (data.closed) {
                    document.getElementById("newmessage-section")?.remove();
                }
                return;
            }

            const errBox = document.getElementById("support-chat-error");
            if (errBox) errBox.style.display = "none";

            if (data.html) {
                const temp = document.createElement("div");
                temp.innerHTML = data.html;
                temp.querySelectorAll("[id^='support-msg-']").forEach(b => insertSupportBubble(section, b));

                if (data.lastId && data.lastId > supportLastId) supportLastId = data.lastId;
                input.value = "";

                scrollSupportToBottom(true);
            }
        })
        .catch(err => {
            if (!isPageUnloading && err.name !== "AbortError") {
                showGameError("Support-Chat Fehler: " + err);
            }
        })
        .finally(() => {
            isSendingSupport = false;

            if (submitBtn && !isSupportRateLimited && !isPageUnloading) submitBtn.disabled = false;
        });
}

function pollSupportChat() {
    if (isPollingSupport) return;

    const section = document.getElementById("messages-section");
    if (!section || section.dataset.chatType !== "support") return;
    if (section.dataset.ticketOpen !== "1") return;

    const tid = section.dataset.ticketId;
    if (!tid) return;

    isPollingSupport = true;
    fetch(`ajax/support_chat.php?tid=${encodeURIComponent(tid)}&last_id=${supportLastId}`, {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(data => {
            isPollingSupport = false;
            if (data.deleted) {
                window.location.href = "support.php";
                return;
            }
            if (data.html) {
                const wasAtBottom = (section.scrollHeight - section.scrollTop - section.clientHeight) < 150;
                const temp = document.createElement("div");
                temp.innerHTML = data.html;
                temp.querySelectorAll("[id^='support-msg-']").forEach(b => insertSupportBubble(section, b));

                if (data.lastId && data.lastId > supportLastId) supportLastId = data.lastId;

                scrollSupportToBottom(wasAtBottom);
            } else {
                document.getElementById("new-message-line")?.remove();
            }

            if (data.status === 0) {
                section.dataset.ticketOpen = "0";
                document.getElementById("newmessage-section")?.remove();

                showGameWarning("Dieses Ticket wurde geschlossen" + (data.closeReason ? ` (${data.closeReason})` : "") + ".");
            }
        })
        .catch(() => {
            isPollingSupport = false;
        });
}

document.addEventListener("DOMContentLoaded", () => {
    const section = document.getElementById("messages-section");
    const meta = document.getElementById("support-chat-meta");
    if (meta) supportLastId = parseInt(meta.dataset.lastId, 10) || 0;

    if (section && section.dataset.chatType === "support") {
        scrollSupportInitial();

        setInterval(() => {
            if (!document.hidden) pollSupportChat();
        }, SUPPORT_POLL_INTERVAL);

        window.addEventListener("focus", pollSupportChat);
        document.addEventListener("visibilitychange", () => {
            if (document.visibilityState === "visible") pollSupportChat();
        });
    }

    const input = document.getElementById("message-input");
    const form = document.getElementById("newmessage");
    if (input && form) {
        form.addEventListener("submit", (e) => {
            e.preventDefault();
            sendSupportMessageAjax(form);
        });
        input.addEventListener("keydown", e => {
            if (e.key === "Enter" && !e.shiftKey) {
                if (isSupportRateLimited) {
                    e.preventDefault();
                    return;
                }

                const isMobile = window.innerWidth <= 600 || ('ontouchstart' in window || navigator.maxTouchPoints > 0);
                if (isMobile) return;

                e.preventDefault();
                sendSupportMessageAjax(form);
            }
        });
    }

    const newTicketInput = document.getElementById("new-ticket-text");
    const newTicketForm = document.getElementById("newticketform");
    if (newTicketInput && newTicketForm) {
        newTicketInput.addEventListener("keydown", e => {
            if (e.key === "Enter" && !e.shiftKey) {
                const isMobile = window.innerWidth <= 600 || ('ontouchstart' in window || navigator.maxTouchPoints > 0);
                if (isMobile) return;

                e.preventDefault();
                newTicketForm.requestSubmit();
            }
        });
    }
});

document.addEventListener("chatRateLimitEnded", () => {
    setSupportSendLocked(false);
});
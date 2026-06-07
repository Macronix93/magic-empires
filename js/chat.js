const CHAT_UPDATE_INTERVAL = 5000;
const ERROR_IMAGE_PATH = "images/icons/icon_error.png";
let isFetchingOlder = false;
let canLoadMore = true;
let lastSeenId = 0;
let isUpdatingChat = false;

registerAction("loadOlderChat", (el) => {
    const partnerId = el.dataset.partnerid;

    if (typeof loadOlderMessages === "function") {
        loadOlderMessages(partnerId);
    }
});
registerAction("filterServer", (el) => {
    if (typeof filterServerMessages === "function") {
        filterServerMessages(el);
    }
});
registerAction("deleteServerMsg", (el) => {
    const msgId = el.dataset.id;

    if (typeof deleteServerMessage === "function") {
        deleteServerMessage(msgId);
    }
});
registerAction("deleteChatMsg", (el) => {
    const msgId = el.dataset.id;

    if (typeof deleteChatMessage === "function") {
        deleteChatMessage(msgId);
    }
});
registerAction("confirmDeleteConversation", (el) => {
    const partnerId = el.dataset.id;
    const partnerName = el.dataset.name;

    if (typeof conversationDeletionDialog === "function") {
        conversationDeletionDialog(partnerId, partnerName);
    }
});

function scrollToLatestMessage() {
    /** @type {HTMLDivElement} */
    let newMessageLine = document.getElementById("new-message-line");

    if (newMessageLine) {
        let parentContainer = document.getElementById("messages-section");

        // Scroll the parent container to the bottom of the new message line
        parentContainer.scrollTop = newMessageLine.offsetTop - parentContainer.clientHeight + newMessageLine.clientHeight;
    }
}

function scrollDown() {
    let messagesSection = document.getElementById("messages-section");

    if (messagesSection) {
        messagesSection.scrollTop = messagesSection.scrollHeight - messagesSection.clientHeight;
    }
}

function sendUpdateChatRequest() {
    const urlParams = new URLSearchParams(window.location.search);
    const chatPartner = urlParams.get("s");

    if (chatPartner) updateChat(chatPartner);
}

function checkSessionSync() {
    const urlParams = new URLSearchParams(window.location.search);
    const chatPartnerInTab = urlParams.get("s");

    if (!chatPartnerInTab) return;

    updateChat(chatPartnerInTab);
}

window.addEventListener("focus", function () {
    checkSessionSync();
});

function updateChat(chatPartner) {
    if (isUpdatingChat) return;

    const tabToken = document.getElementById("chat-tab-token")?.dataset.token || "";

    isUpdatingChat = true;

    fetch(`ajax/chat_update.php?s=${encodeURIComponent(chatPartner)}&last_id=${lastSeenId}&token=${tabToken}`, {
        method: "GET",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded"
        }
    })
        .then(response => response.json())
        .then(data => {
            isUpdatingChat = false;

            if (data.error === "redirect") {
                const target = data.chatPartner === "privmsgs" ? "privmsgs" : "action=read&s=" + data.chatPartner;
                window.location.href = "messages.php?" + target;
                return;
            }

            /** @type {{ html: string, messagesToDelete: array, error: string, chatPartner: string }} */
            const response = data;
            /** @type {HTMLElement} */
            const messageSection = document.getElementById("messages-section");
            const newMessageLine = document.getElementById("new-message-line");
            const infoBox = document.querySelector(".info-box");

            if (response.error) {
                if (response.error === "redirect") {
                    location.href = "messages.php?action=read&s=" + response.chatPartner;
                } else {
                    setInfoBoxError(response.error);
                    infoBox.style.display = "flex";
                }
                return;
            }

            if (response.html === "") {
                if (newMessageLine) {
                    messageSection.removeChild(newMessageLine);
                }
            } else {
                removeEmptyPlaceholder();

                let temp = document.createElement("div");
                temp.innerHTML = response.html;
                let newBubbles = temp.querySelectorAll("[id^='msg-']");

                newBubbles.forEach(bubble => {
                    if (!document.getElementById(bubble.id)) {
                        messageSection.appendChild(bubble);
                    }
                });

                lastSeenId = data.lastId;

                scrollDown();
            }

            if (response.messagesToDelete) {
                response.messagesToDelete.forEach(item => {
                    removeChatBubble(item);
                });
            }

            if (messageSection && messageSection.innerText.trim() === "") {
                setInfoBoxError("Schreibe eine Nachricht, um den Chat zu beginnen.");

                infoBox.style.display = "flex";
                infoBox.style.margin = "0px";
            }
        })
        .catch(error => {
            console.error("Error:", error);
        });
}

function removeChatBubble(bubbleID) {
    const el = document.getElementById("msg-" + bubbleID);

    if (el) {
        const parent = el.parentNode;
        el.remove();

        const remainingMessages = parent.querySelectorAll("[id^='msg-']");

        if (remainingMessages.length === 0) {
            const placeholder = document.createElement("div");
            placeholder.id = "chat-empty-placeholder";
            placeholder.className = "info-box";
            placeholder.style.justifyContent = "center";
            placeholder.textContent = "Schreibe eine Nachricht, um den Chat zu beginnen.";
            parent.replaceChildren(placeholder);
        }
    }
}

function deleteChatMessage(messageID) {
    fetch(`ajax/chat_delete.php?m_id=${messageID}`, {
        method: "GET",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded"
        },
    })
        .then(response => response.json())
        .then(response => {
            if (response.error) {
                setInfoBoxError(response.error);
            } else {
                removeChatBubble(messageID);
            }
        })
        .catch(error => {
            console.error("Error:", error);
        });
}

function deleteServerMessage(messageID) {
    fetch(`ajax/chat_srv_delete.php?m_id=${messageID}`, {
        method: "GET",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded"
        },
    })
        .then(response => response.json())
        .then(response => {
            const infoBox = document.querySelector(".info-box");

            if (response.error) {
                infoBox.innerText = response.error;
                infoBox.style.display = "flex";
            } else {
                const newMessagesLine = document.getElementById("new-message-line");
                const chatBubble = document.getElementById("msg-" + messageID);

                if (newMessagesLine) {
                    newMessagesLine.remove();
                }

                if (chatBubble) {
                    chatBubble.remove();
                }

                // Count remaining chat bubbles
                /** @type {HTMLElement} */
                const messageSection = document.getElementById("messages-section");
                const remainingBubbles = messageSection.querySelectorAll(".server-bubble").length;

                if (remainingBubbles === 0) {
                    messageSection.innerHTML = `<div id="chat-empty-placeholder" class="info-box">Du hast keine Servernachrichten!</div>`;
                    messageSection.style.display = "flex";
                    messageSection.style.alignItems = "center";
                }
            }
        })
        .catch(error => {
            console.error("Error:", error);
        });
}

function removeEmptyPlaceholder() {
    const placeholder = document.getElementById("chat-empty-placeholder");

    if (placeholder) {
        placeholder.remove();
    }
}

function insertNewChatMessage(e) {
    if (e) e.preventDefault();

    const tabToken = document.getElementById("chat-tab-token")?.dataset.token || "";
    const messageInput = document.getElementById("message-input");
    let receiver = document.querySelector('input[name="receiver"]').value;
    const text = messageInput.value;

    if (text === "") {
        return;
    }

    const formData = new URLSearchParams();
    formData.append("receiver", receiver);
    formData.append("text", text);

    fetch("ajax/chat_insert.php", {
        method: "POST",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: formData + `&token=${tabToken}`
    })
        .then(response => response.json())
        .then(response => {
            if (response.error === "redirect") {
                const target = response.chatPartner === "privmsgs" ? "privmsgs" : "action=read&s=" + response.chatPartner;
                window.location.href = "messages.php?" + target;
                return;
            }

            const infoBox = document.querySelector(".info-box");

            if (response.error) {
                /** @type {HTMLElement} */
                let contentWrapper = infoBox.querySelector(".info-wrapper");

                if (!contentWrapper) {
                    infoBox.replaceChildren();
                    const errImg = document.createElement("img");
                    errImg.src = ERROR_IMAGE_PATH;
                    errImg.alt = "Fehler";
                    infoBox.appendChild(errImg);

                    contentWrapper = document.createElement("span");
                    contentWrapper.className = "info-wrapper";
                    contentWrapper.style.flex = "1";
                    contentWrapper.style.textAlign = "center";
                    infoBox.append(contentWrapper);
                }

                let errorTextSpan = contentWrapper.querySelector(".error-msg-text");
                if (!errorTextSpan) {
                    errorTextSpan = document.createElement("span");
                    errorTextSpan.className = "error-msg-text";
                    contentWrapper.append(errorTextSpan);
                }

                errorTextSpan.innerText = response.error;
                infoBox.style.display = "flex";

                if (response.counter !== undefined) {
                    /** @type {HTMLElement} */
                    let counterElement = document.getElementById("counter");

                    if (!counterElement) {
                        counterElement = document.createElement("span");
                        counterElement.id = "counter";
                        counterElement.style.padding = "0";

                        contentWrapper.append(counterElement);
                    }

                    const counterEl = document.getElementById("counter");
                    if (counterEl) {
                        startCountdown(counterEl, response.counter, 0, null, true);
                    }
                }
            } else if (response.html) {
                removeEmptyPlaceholder();

                document.getElementById("messages-section").insertAdjacentHTML("beforeend", response.html);
                messageInput.value = "";
                lastSeenId = response.lastId;

                if (infoBox) infoBox.style.display = "none";

                scrollDown();
            }
        })
        .catch(error => console.error("Error:", error));
}

function conversationDeletionDialog(chatPartnerID, chatPartner) {
    showConfirmationDialog(
        "Willst du die Konversation mit " + chatPartner + " wirklich löschen?",
        "Ja",
        "Nein",
        () => {
            deleteConversation("messages.php?action=delete&s=" + chatPartnerID);
        }
    );
}

function deleteConversation(url) {
    window.location.href = url;
}

function initializeChat() {
    /** @type {HTMLInputElement} */
    const messageInput = document.getElementById("message-input");
    const messageForm = document.getElementById("newmessage");
    const messageSection = document.getElementById("messages-section");
    const allMsgs = document.querySelectorAll("[id^='msg-']");
    const loadMoreBtn = document.getElementById("load-older-btn");
    const chatCfg = document.getElementById("chat-config");

    if (chatCfg) {
        canLoadMore = chatCfg.dataset.hasMore === "true";
    }

    if (loadMoreBtn && !canLoadMore) {
        loadMoreBtn.style.display = "none";
    }

    if (allMsgs.length > 0) {
        const lastMsg = allMsgs[allMsgs.length - 1];
        lastSeenId = parseInt(lastMsg.id.replace("msg-", ""));
    }

    if (messageSection) {
        messageSection.addEventListener("scroll", () => {
            checkScrollPosition();
        });
    }

    if (messageInput) {
        messageInput.addEventListener("keydown", e => {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();

                messageForm.requestSubmit();
            }
        });
    }

    // Add event listener for form submission
    messageForm.addEventListener("submit", e => {
        insertNewChatMessage(e);
    });

    // Chat update function
    setInterval(() => {
        sendUpdateChatRequest();
    }, CHAT_UPDATE_INTERVAL);

    // Scroll to latest message at the bottom
    scrollDown();
}

// Filter server log messages
function filterServerMessages(element) {
    let category = element.textContent.trim();
    let messages = document.querySelectorAll('.server-bubble');

    messages.forEach(msg => {
        if (category === "Alle" || msg.dataset.category === category) {
            msg.style.display = "block";
        } else {
            msg.style.display = "none";
        }
    });

    canLoadMore = true;

    // Update active tab
    document.querySelectorAll('.tablinks').forEach(tab => tab.classList.remove("active"));
    element.classList.add("active");

    checkScrollPosition();
}

function checkScrollPosition() {
    const messageSection = document.getElementById("messages-section");
    if (!messageSection || isFetchingOlder || !canLoadMore) return;

    const isServerInbox = window.location.search.includes("servermsgs");

    if (isServerInbox) {
        const scrollPos = messageSection.scrollTop + messageSection.clientHeight;
        if (scrollPos > messageSection.scrollHeight - 20) {
            loadOlderServerMessages();
        }
    } else {
        if (messageSection.scrollTop < 20) {
            const urlParams = new URLSearchParams(window.location.search);
            const chatPartner = urlParams.get("s");
            if (chatPartner) loadOlderMessages(chatPartner);
        }
    }
}

function loadOlderServerMessages() {
    if (isFetchingOlder || !canLoadMore) return;

    const section = document.getElementById("messages-section");
    const lastMsg = section.querySelector(".server-bubble:last-child"); // Letztes Element finden
    if (!lastMsg) return;

    const oldestId = lastMsg.id.replace("msg-", "");
    const activeTab = document.querySelector(".tablinks.active");
    const category = activeTab ? activeTab.textContent.trim() : "Alle";

    isFetchingOlder = true;

    fetch(`ajax/server_load_more.php?oldest_id=${oldestId}&category=${category}`, {
        headers: {
            "X-Requested-With": "XMLHttpRequest"
        }
    })
        .then(r => r.json())
        .then(data => {
            if (data.count > 0) {
                section.insertAdjacentHTML("beforeend", data.html); // Unten anfügen
                canLoadMore = data.hasMore;
            } else {
                canLoadMore = false;
            }
            finishLoading(null);
        })
        .catch(() => {
            isFetchingOlder = false;
        });
}

function loadOlderMessages(partnerId) {
    if (isFetchingOlder || !canLoadMore) return;

    const section = document.getElementById("messages-section");
    const firstMsg = section.querySelector("[id^='msg-']");
    if (!firstMsg) return;

    const oldestId = firstMsg.id.replace("msg-", "");
    /** @type {HTMLElement} */
    const btn = document.getElementById("load-older-btn");

    isFetchingOlder = true;
    if (btn) {
        btn.style.display = "block";
        btn.innerText = "Lade ältere Nachrichten...";
    }

    fetch(`ajax/chat_load_more.php?s=${partnerId}&oldest_id=${oldestId}`, {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(data => {
            if (data.count > 0) {
                const oldHeight = section.scrollHeight;
                btn.insertAdjacentHTML("afterend", data.html);
                const newHeight = section.scrollHeight;
                section.scrollTop = newHeight - oldHeight;

                /** @type {{hasMore: boolean}} */
                canLoadMore = data.hasMore;
            } else {
                canLoadMore = false;
            }

            finishLoading(btn);
        })
        .catch(err => {
            console.error("Fehler:", err);
            isFetchingOlder = false;

            if (btn) {
                btn.innerText = "Fehler beim Laden";
                btn.style.display = "block";
            }
        });
}

function finishLoading(btn) {
    if (btn) {
        if (canLoadMore) {
            btn.innerText = "Ältere Nachrichten laden";
        } else {
            btn.style.display = "none";
        }
    }
    setTimeout(() => {
        isFetchingOlder = false;
        checkScrollPosition();
    }, 800);
}

function setInfoBoxError(message) {
    const infoBox = document.querySelector(".info-box");
    const img = document.createElement("img");
    img.src = ERROR_IMAGE_PATH;
    img.alt = "Fehler";

    const span = document.createElement("span");
    span.textContent = message;

    infoBox.replaceChildren(img, span);
    infoBox.style.display = "flex";
}

document.addEventListener("DOMContentLoaded", () => {
    if (document.getElementById("message-input")) {
        initializeChat();
    }
});
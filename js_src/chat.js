const CHAT_UPDATE_INTERVAL = 5000;
const ERROR_IMAGE_PATH = "images/icons/icon_error.png";
let isFetchingOlder = false;
let canLoadMore = true;
let lastSeenId = 0;
let isUpdatingChat = false;
let isSendingChat = false;

registerAction("sendWorldMessage", () => sendChatMessage("world"));
registerAction("sendGuildMessage", () => sendChatMessage("guild"));
registerAction("deleteChatMsg", (el) => deleteChatMessage(el.dataset.id, "private"));
registerAction("deleteWorldChatMsg", (el) => deleteChatMessage(el.dataset.id, "world"));
registerAction("deleteGuildChatMsg", (el) => deleteChatMessage(el.dataset.id, "guild"));
registerAction("deleteServerMsg", (el) => deleteServerMessage(el.dataset.id));
registerAction("loadOlderChat", (el) => loadOlderChatMessages("private", el.dataset.partnerid));
registerAction("loadOlderWorldChat", () => loadOlderChatMessages("world"));
registerAction("loadOlderGuildMessages", () => loadOlderChatMessages("guild"));
registerAction("loadMoreServerMsgs", () => loadOlderServerMessages());
registerAction("filterServer", (el) => filterServerMessages(el));
registerAction("confirmDeleteConversation", (el) => conversationDeletionDialog(el.dataset.id, el.dataset.name));
registerAction("confirmDeleteAllServer", (el) => {
    const activeTab = document.querySelector(".tablinks.active");
    const category = activeTab ? (activeTab.dataset.category || "-1") : "-1";
    const catName = activeTab ? activeTab.textContent.trim() : "Alle";
    const catText = category === "-1" ? "ALLE Nachrichten" : `alle Nachrichten der Kategorie "${catName}"`;
    const maxId = el.dataset.maxId || 0;

    showConfirmationDialog(
        `Möchtest du wirklich ${catText} unwiderruflich löschen?`,
        "Ja", "Abbrechen",
        () => {
            fetch(`ajax/chat_delete.php?type=server&delete_all=1&category=${encodeURIComponent(category)}&max_id=${maxId}`, {
                headers: {"X-Requested-With": "XMLHttpRequest"}
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    }
                });
        }
    );
});

function sendChatMessage(chatType, extraParams = {}) {
    if (isSendingChat) return;

    const messageInput = document.getElementById("message-input");
    if (!messageInput) return;

    const text = messageInput.value;
    if (text.trim() === "") return;

    document.querySelectorAll(".emoji-menu.open").forEach(menu => menu.classList.remove("open"));

    const form = messageInput.closest("form")
        || document.getElementById(`${chatType}-chat-form`)
        || document.getElementById("newmessage");
    const submitBtn = form?.querySelector('input[type="button"], input[type="submit"], button[type="submit"]');

    isSendingChat = true;

    messageInput.disabled = true;
    if (submitBtn) submitBtn.disabled = true;

    const formData = new URLSearchParams();
    formData.append("type", chatType);
    formData.append("text", text);
    for (const [key, val] of Object.entries(extraParams)) {
        if (val !== undefined && val !== null) {
            formData.append(key, String(val));
        }
    }

    fetch("ajax/chat_insert.php", {
        method: "POST",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: formData.toString()
    })
        .then(r => r.json())
        .then(response => {
            if (response.error === "redirect") {
                const target = response.chatPartner === "privmsgs" ? "privmsgs" : "action=read&s=" + response.chatPartner;
                window.location.href = "messages.php?" + target;
                return;
            }

            if (response.error) {
                displayChatError(response.error, response.counter);
            } else if (response.html) {
                const errorBox = document.querySelector(".event-error");
                if (errorBox) errorBox.style.display = "none";

                removeEmptyPlaceholder();

                document.getElementById("messages-section").insertAdjacentHTML("beforeend", response.html);
                messageInput.value = "";

                lastSeenId = response.lastId;

                scrollDown(true);
            }
        })
        .catch(err => console.error("Chat Send Fehler:", err))
        .finally(() => {
            isSendingChat = false;

            messageInput.disabled = false;
            messageInput.focus();

            if (submitBtn) submitBtn.disabled = false;
        });
}

function insertNewChatMessage(e) {
    if (e) e.preventDefault();

    const receiver = document.querySelector('input[name="receiver"]')?.value || "";
    const tabToken = document.getElementById("chat-tab-token")?.dataset.token || "";

    sendChatMessage("private", {receiver: receiver, token: tabToken});
}

function loadOlderChatMessages(type, partnerId = null) {
    if (isFetchingOlder || !canLoadMore) return;

    const section = document.getElementById("messages-section");
    if (!section) return;

    const prefix = type === "world" ? "world-msg-" : (type === "guild" ? "guild-msg-" : "msg-");
    const firstMsg = section.querySelector(`[id^='${prefix}']`);
    if (!firstMsg) return;

    const oldestId = firstMsg.id.replace(prefix, "");
    const btn = document.getElementById("load-older-btn");

    isFetchingOlder = true;

    if (btn) {
        btn.style.display = "block";
        btn.innerText = "Lade ältere Nachrichten...";
    }

    let query = `?type=${encodeURIComponent(type)}&oldest_id=${encodeURIComponent(oldestId)}`;
    if (type === "private" && partnerId) {
        query += `&s=${encodeURIComponent(partnerId)}`;
    }

    fetch(`ajax/chat_load_more.php${query}`, {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(data => {
            if (data.count > 0) {
                const oldHeight = section.scrollHeight;
                btn.insertAdjacentHTML("afterend", data.html);

                section.scrollTop = section.scrollHeight - oldHeight;

                canLoadMore = data.hasMore;
            } else {
                canLoadMore = false;
            }
            finishLoading(btn);
        })
        .catch(err => {
            console.error("Fehler beim Laden älterer Nachrichten:", err);

            isFetchingOlder = false;

            if (btn) btn.innerText = "Fehler beim Laden";
        });
}

function loadOlderGuildMessages() {
    loadOlderChatMessages("guild");
}

window.loadOlderGuildMessages = loadOlderGuildMessages;

function loadOlderServerMessages() {
    if (isFetchingOlder || !canLoadMore) return;

    const section = document.getElementById("messages-section");
    const bubbles = section.querySelectorAll(".server-bubble");
    const lastMsg = bubbles[bubbles.length - 1];
    const btn = document.getElementById("load-more-server-btn");
    if (!lastMsg) return;

    const oldestId = lastMsg.id.replace("msg-", "");
    const activeTab = document.querySelector(".tablinks.active");
    const category = activeTab ? (activeTab.dataset.category || "-1") : "-1";

    isFetchingOlder = true;

    if (btn) btn.innerText = "Lade...";

    fetch(`ajax/chat_load_more.php?type=server&oldest_id=${oldestId}&category=${encodeURIComponent(category)}`, {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(data => {
            if (data.count > 0) {
                if (btn) {
                    btn.insertAdjacentHTML("beforebegin", data.html);
                } else {
                    section.insertAdjacentHTML("beforeend", data.html);
                }

                canLoadMore = data.hasMore;
            } else {
                canLoadMore = false;
            }

            if (btn) {
                if (!canLoadMore) {
                    btn.remove();
                } else {
                    btn.innerText = "Ältere Berichte laden";
                }
            }

            isFetchingOlder = false;

            checkServerMessagesEmpty();
        })
        .catch(() => {
            isFetchingOlder = false;
            if (btn) btn.innerText = "Fehler beim Laden";
        });
}

function checkScrollPosition() {
    const messageSection = document.getElementById("messages-section");
    if (!messageSection || isFetchingOlder || !canLoadMore) return;

    const isServerInbox = window.location.search.includes("servermsgs");
    const chatType = messageSection.dataset.chatType;

    if (isServerInbox) {
        const scrollPos = messageSection.scrollTop + messageSection.clientHeight;
        if (scrollPos > messageSection.scrollHeight - 20) {
            loadOlderServerMessages();
        }
    } else if (chatType === "world" || chatType === "guild") {
        if (messageSection.scrollTop < 20) {
            loadOlderChatMessages(chatType);
        }
    } else {
        if (messageSection.scrollTop < 20) {
            const urlParams = new URLSearchParams(window.location.search);
            const chatPartner = urlParams.get("s");
            if (chatPartner) loadOlderChatMessages("private", chatPartner);
        }
    }
}

function applyChatPrefill() {
    const prefill = sessionStorage.getItem("me_chat_prefill");
    if (!prefill) return;

    const input = document.getElementById("message-input") || document.querySelector("textarea[name='text']");
    if (input) {
        input.value = prefill + " ";

        sessionStorage.removeItem("me_chat_prefill");

        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
        input.scrollIntoView({behavior: "smooth", block: "center"});
    }
}

function scrollToLatestMessage() {
    const newMessageLine = document.getElementById("new-message-line");
    const parentContainer = document.getElementById("messages-section");
    if (!parentContainer) return;

    const isServerInbox = window.location.search.includes("servermsgs");
    if (!newMessageLine) {
        parentContainer.scrollTop = isServerInbox ? 0 : parentContainer.scrollHeight;
        return;
    }

    if (isServerInbox) {
        let target = newMessageLine.offsetTop - parentContainer.clientHeight + newMessageLine.clientHeight;
        parentContainer.scrollTop = target + 10;

        if (window.innerWidth <= 600) {
            parentContainer.scrollIntoView({behavior: "smooth", block: "center"});
        }
    } else {
        parentContainer.scrollTop = newMessageLine.offsetTop - 10;
    }
}

function scrollDown(force = false) {
    const messagesSection = document.getElementById("messages-section");
    if (messagesSection) {
        if (force) {
            messagesSection.scrollTop = messagesSection.scrollHeight;
        } else {
            const isAtBottom = (messagesSection.scrollHeight - messagesSection.scrollTop - messagesSection.clientHeight) < 150;
            if (isAtBottom) {
                messagesSection.scrollTo({
                    top: messagesSection.scrollHeight,
                    behavior: "smooth"
                });
            }
        }
    }
}

window.addEventListener("focus", () => updateChat());
document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") updateChat();
});

function updateChat(chatPartner) {
    if (isUpdatingChat) return;

    const messageSection = document.getElementById("messages-section");
    if (!messageSection) return;

    const chatType = messageSection.dataset.chatType;
    if (!chatType || !["private", "world", "guild"].includes(chatType)) return;
    if (chatType === "private" && !document.getElementById("message-input")) return;

    const effectivePartner = chatPartner || messageSection.dataset.partnerId || (new URLSearchParams(window.location.search)).get("s") || "0";
    let highestDomId = 0;
    let oldestDomId = 0;

    const bubbles = messageSection.querySelectorAll("[id^='world-msg-'], [id^='guild-msg-'], [id^='msg-']");
    bubbles.forEach(el => {
        const num = parseInt(el.id.replace(/^(?:world-msg-|guild-msg-|msg-)/, ''), 10);

        if (!isNaN(num)) {
            if (num > highestDomId) highestDomId = num;
            if (oldestDomId === 0 || num < oldestDomId) oldestDomId = num;
        }
    });

    const safeLastId = Math.max(lastSeenId, highestDomId);
    if (safeLastId <= 0) return;

    let queryParams = `?type=${encodeURIComponent(chatType)}&last_id=${safeLastId}&oldest_id=${oldestDomId}`;
    if (chatType === "private") {
        queryParams += `&s=${encodeURIComponent(effectivePartner)}`;
    }

    isUpdatingChat = true;

    fetch(`ajax/chat_update.php${queryParams}`, {
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

            const infoBox = document.querySelector(".info-box");
            if (data.error) {
                setInfoBoxError(data.error);
                if (infoBox) infoBox.style.display = "flex";
                return;
            }

            if (data.lastId && data.lastId > lastSeenId) {
                lastSeenId = data.lastId;
            }

            const newMessageLine = document.getElementById("new-message-line");
            if (data.html === "") {
                if (newMessageLine) messageSection.removeChild(newMessageLine);
            } else {
                removeEmptyPlaceholder();

                const wasAtBottom = (messageSection.scrollHeight - messageSection.scrollTop - messageSection.clientHeight) < 150;
                let temp = document.createElement("div");
                temp.innerHTML = data.html;

                let newBubbles = temp.querySelectorAll("[id^='msg-'], [id^='world-msg-'], [id^='guild-msg-']");
                newBubbles.forEach(bubble => insertChatBubbleSorted(messageSection, bubble));

                if (wasAtBottom) scrollDown(true); else scrollDown();
            }

            if (data.messagesToDelete) {
                data.messagesToDelete.forEach(item => removeChatBubble(item));
            }

            if (data.reactionUpdates && (Date.now() - (window.lastReactionClick || 0) >= 2000)) {
                Object.keys(data.reactionUpdates).forEach(msgId => {
                    const msgElement = document.getElementById("msg-" + msgId)
                        || document.getElementById("world-msg-" + msgId)
                        || document.getElementById("guild-msg-" + msgId);
                    if (msgElement) {
                        const footer = msgElement.querySelector('.chat-reaction-footer');
                        if (footer && !footer.matches(':hover') && !footer.querySelector(':hover')) {
                            const tempDiv = document.createElement("div");
                            tempDiv.innerHTML = data.reactionUpdates[msgId];
                            tempDiv.querySelectorAll('.popupbox').forEach(b => b.remove());

                            const currentDiv = footer.cloneNode(true);
                            currentDiv.querySelectorAll('.popupbox').forEach(b => b.remove());
                            if (currentDiv.innerHTML.trim() !== tempDiv.innerHTML.trim()) {
                                footer.innerHTML = data.reactionUpdates[msgId];
                            }
                        }
                    }
                });
            }

            if (data.guildUnread !== undefined) {
                const guildTabBadge = document.getElementById("badge-guild-tab");
                const chatTabActive = document.getElementById("guild_tab_chat")?.style.display !== "none";

                if (guildTabBadge && !chatTabActive) {
                    if (data.guildUnread > 0) {
                        guildTabBadge.innerText = data.guildUnread > 9 ? "9+" : data.guildUnread;
                        guildTabBadge.style.display = "inline-flex";
                    } else {
                        guildTabBadge.style.display = "none";
                    }
                }
            }

            if (chatType === "world") {
                document.querySelectorAll(".js-badge-world").forEach(b => b.style.display = "none");
            }
            if (chatType === "guild") {
                const chatTabActive = document.getElementById("guild_tab_chat")?.style.display !== "none";
                if (chatTabActive) {
                    document.querySelectorAll(".js-badge-guild, #badge-guild-tab").forEach(b => b.style.display = "none");
                }
            }

            cleanupPopups();

            if (messageSection && messageSection.innerText.trim() === "") {
                messageSection.innerText = "Schreibe eine Nachricht, um den Chat zu beginnen.";
            }
        })
        .catch(error => {
            isUpdatingChat = false;
            console.error("Chat Update Error:", error);
        });
}

function removeChatBubble(bubbleID) {
    const el = document.getElementById("msg-" + bubbleID)
        || document.getElementById("world-msg-" + bubbleID)
        || document.getElementById("guild-msg-" + bubbleID);
    if (el) {
        const parent = el.parentNode;

        el.remove();

        const remaining = parent.querySelectorAll("[id^='msg-'], [id^='world-msg-'], [id^='guild-msg-']");
        if (remaining.length === 0) {
            const placeholder = document.createElement("div");
            placeholder.id = "chat-empty-placeholder";
            placeholder.className = "info-box";
            placeholder.style.justifyContent = "center";
            placeholder.textContent = "Schreibe eine Nachricht, um den Chat zu beginnen.";

            parent.replaceChildren(placeholder);
        }
    }
}

function deleteChatMessage(messageID, type = "private") {
    fetch(`ajax/chat_delete.php?m_id=${messageID}&type=${type}`, {
        method: "GET",
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(response => {
            if (response.error) {
                setInfoBoxError(response.error);
            } else {
                removeChatBubble(messageID);
            }
        })
        .catch(err => console.error("Error:", err));
}

function deleteServerMessage(messageID) {
    fetch(`ajax/chat_delete.php?type=server&m_id=${messageID}`, {
        method: "GET",
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(response => {
            if (response.error) {
                const infoBox = document.querySelector(".info-box");
                if (infoBox) {
                    infoBox.innerText = response.error;
                    infoBox.style.display = "flex";
                }
            } else {
                document.getElementById("new-message-line")?.remove();
                document.getElementById("msg-" + messageID)?.remove();

                const messageSection = document.getElementById("messages-section");
                const remainingBubbles = messageSection ? messageSection.querySelectorAll(".server-bubble").length : 0;
                const btn = document.getElementById("load-more-server-btn");
                if (remainingBubbles === 0 && btn) {
                    btn.remove();

                    canLoadMore = false;
                }

                checkServerMessagesEmpty();

                if (remainingBubbles > 0) {
                    checkScrollPosition();
                }
            }
        })
        .catch(err => console.error("Error:", err));
}

function removeEmptyPlaceholder() {
    document.getElementById("chat-empty-placeholder")?.remove();
}

function insertChatBubbleSorted(container, newBubble) {
    if (!container || !newBubble || document.getElementById(newBubble.id)) return;

    const newId = parseInt(newBubble.id.replace(/\D/g, ''), 10) || 0;
    const existingBubbles = container.querySelectorAll("[id^='msg-'], [id^='world-msg-'], [id^='guild-msg-']");
    let inserted = false;

    for (let i = 0; i < existingBubbles.length; i++) {
        const curId = parseInt(existingBubbles[i].id.replace(/\D/g, ''), 10) || 0;
        if (curId > newId) {
            container.insertBefore(newBubble, existingBubbles[i]);
            inserted = true;
            break;
        }
    }

    if (!inserted) {
        container.appendChild(newBubble);
    }
}

function conversationDeletionDialog(chatPartnerID, chatPartner) {
    showConfirmationDialog(
        "Willst du die Konversation mit " + chatPartner + " wirklich löschen?",
        "Ja", "Nein",
        () => {
            window.location.href = "messages.php?action=delete&s=" + chatPartnerID;
        }
    );
}

function updateNavigationBadges(privCount, worldCount, guildCount, achCount) {
    const isWorldChatActive = document.getElementById("messages-section")?.dataset.chatType === "world"
        || window.location.search.includes("worldchat");
    const isGuildChatActive = document.getElementById("messages-section")?.dataset.chatType === "guild"
        || (window.location.pathname.includes("guild.php") && document.getElementById("guild_tab_chat")?.style.display !== "none");

    if (isWorldChatActive) worldCount = 0;
    if (isGuildChatActive) guildCount = 0;

    const setBadges = (selector, count) => {
        document.querySelectorAll(selector).forEach(badge => {
            if (count > 0) {
                badge.innerText = count > 9 ? "9+" : count;
                badge.style.display = "flex";
            } else {
                badge.style.display = "none";
            }
        });
    };

    setBadges(".js-badge-priv", privCount);
    if (!isWorldChatActive) setBadges(".js-badge-world", worldCount);
    if (!isGuildChatActive) setBadges(".js-badge-guild", guildCount);

    if (achCount !== undefined) {
        document.querySelectorAll(".box[data-url='achievements.php'] .msg-badge").forEach(badge => {
            if (achCount > 0) {
                badge.innerText = achCount > 9 ? "9+" : achCount;
                badge.style.display = "inline-flex";
            } else {
                badge.style.display = "none";
            }
        });
    }

    const mobileDot = document.getElementById("mobile-nav-dot");
    if (mobileDot) {
        const hasAlert = mobileDot.dataset.hasAlert === "true";
        const effectiveTotal = (privCount || 0) + (isWorldChatActive ? 0 : (worldCount || 0)) + (isGuildChatActive ? 0 : (guildCount || 0)) + (achCount || 0);
        mobileDot.style.display = (effectiveTotal > 0 || hasAlert) ? "block" : "none";
    }
}

function initializeChat() {
    const messageSection = document.getElementById("messages-section");
    if (!messageSection) return;

    const chatType = messageSection.dataset.chatType;
    const params = new URLSearchParams({type: chatType});

    if (chatType === "private") {
        params.append("s", messageSection.dataset.partnerId || "0");
        params.append("token", messageSection.dataset.token || "");
        params.append("partner_name", messageSection.dataset.partnerName || "");
    }

    fetch(`ajax/chat_init.php?${params.toString()}`, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
        .then(r => r.json())
        .then(data => {
            if (data.error === "redirect") {
                window.location.href = "messages.php";
                return;
            }

            messageSection.innerHTML = data.html;
            updateNavigationBadges(data.privUnread, data.worldUnread, data.guildUnread, data.achievementsUnread);

            const messageInput = document.getElementById("message-input");
            const messageForm = document.getElementById("newmessage") || document.getElementById("world-chat-form") || document.getElementById("guild-chat-form");
            const loadMoreBtn = document.getElementById("load-older-btn");
            const chatCfg = document.getElementById("chat-config");

            lastSeenId = data.lastId;
            if (chatCfg) {
                canLoadMore = chatCfg.dataset.hasMore === "true";
            }
            if (loadMoreBtn && !canLoadMore) {
                loadMoreBtn.style.display = "none";
            }

            messageSection.addEventListener("scroll", () => checkScrollPosition());

            if (messageInput) {
                messageInput.addEventListener("keydown", e => {
                    if (e.key === "Enter" && !e.shiftKey) {
                        e.preventDefault();
                        if (chatType === "world" || chatType === "guild") {
                            sendChatMessage(chatType);
                        } else {
                            messageForm?.requestSubmit();
                        }
                    }
                });
            }

            if (messageForm && messageForm.id === "newmessage") {
                messageForm.addEventListener("submit", e => insertNewChatMessage(e));
            }

            setInterval(() => updateChat(), CHAT_UPDATE_INTERVAL);

            // scrollDown();
            // scrollToLatestMessage();
            if (document.getElementById("new-message-line")) {
                scrollToLatestMessage();
            } else {
                scrollDown(true);
            }

            const loadingOverlay = document.getElementById("chat-loading-overlay");
            messageSection.style.opacity = "1";
            if (loadingOverlay) loadingOverlay.style.display = "none";

            applyChatPrefill();
        })
        .catch(err => console.error("Chat Init Fehler:", err));
}

function filterServerMessages(element) {
    const categoryId = element.dataset.category !== undefined ? element.dataset.category : "-1";
    const categoryName = element.textContent.trim();
    let messages = document.querySelectorAll('.server-bubble');
    let newLine = document.getElementById('new-message-line');
    let visibleCount = 0;

    messages.forEach(msg => {
        if (categoryId === "-1" || msg.dataset.category === categoryId) {
            msg.style.display = "block";
            visibleCount++;
        } else {
            msg.style.display = "none";
        }
    });

    if (newLine) {
        newLine.style.display = (categoryId === "-1") ? "flex" : "none";
    }

    const section = document.getElementById("messages-section");
    let placeholder = document.getElementById("server-empty-category");

    if (visibleCount === 0) {
        if (!placeholder && section) {
            placeholder = document.createElement("div");
            placeholder.id = "server-empty-category";
            placeholder.className = "no-event";
            placeholder.style.textAlign = "center";
            section.appendChild(placeholder);
        }
        if (placeholder) {
            placeholder.textContent = (categoryId === "-1") ? "Keine Servernachrichten vorhanden." : `Keine Servernachrichten in "${categoryName}" vorhanden.`;
            placeholder.style.display = "block";
        }
    } else {
        if (placeholder) {
            placeholder.style.display = "none";
        }
    }

    canLoadMore = true;

    document.querySelectorAll('.tablinks').forEach(tab => tab.classList.remove("active"));
    element.classList.add("active");

    checkScrollPosition();
}

function checkServerMessagesEmpty() {
    const messageSection = document.getElementById("messages-section");
    if (!messageSection) return;

    const activeTab = document.querySelector(".tablinks.active");
    const category = activeTab ? activeTab.textContent.trim() : "Alle";
    const categoryId = activeTab ? (activeTab.dataset.category || "-1") : "-1";
    const allBubbles = messageSection.querySelectorAll(".server-bubble");
    let visibleCount = 0;

    allBubbles.forEach(msg => {
        if (categoryId === "-1" || category === "Alle" || msg.dataset.category === category || msg.dataset.category === categoryId) {
            visibleCount++;
        }
    });

    const btn = document.getElementById("load-more-server-btn");
    if (visibleCount === 0 && !btn) {
        let placeholder = document.getElementById("server-empty-category");

        if (!placeholder) {
            placeholder = document.createElement("div");
            placeholder.id = "server-empty-category";
            placeholder.className = "no-event";
            placeholder.style.textAlign = "center";
            placeholder.style.opacity = "0.6";
            messageSection.appendChild(placeholder);
        }

        placeholder.textContent = (category === "Alle" || categoryId === "-1") ? "Keine Servernachrichten vorhanden." : `Keine Servernachrichten in "${categoryName}" vorhanden.`;
        placeholder.style.display = "block";
    } else if (visibleCount > 0) {
        document.getElementById("server-empty-category")?.remove();
    }
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
    displayChatError(message);
}

function displayChatError(message, seconds = undefined) {
    const infoBox = document.querySelector(".info-box");
    if (!infoBox) return;

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
    errorTextSpan.innerText = message + (seconds !== undefined ? " " : "");

    let counterElement = document.getElementById("counter");
    if (seconds !== undefined) {
        if (!counterElement) {
            counterElement = document.createElement("span");
            counterElement.id = "counter";
            counterElement.style.fontWeight = "bold";
            contentWrapper.append(counterElement);
        }
        if (typeof startCountdown === "function") {
            startCountdown(counterElement, seconds, 0, null, true);
        }
    } else {
        counterElement?.remove();
    }
    infoBox.style.display = "flex";
}

document.addEventListener("DOMContentLoaded", () => {
    const messageSection = document.getElementById("messages-section");
    const classicNewForm = document.querySelector("form[action='messages.php?action=new']");
    if (classicNewForm) {
        classicNewForm.addEventListener("submit", function () {
            const btn = this.querySelector('input[type="submit"]');
            if (btn) {
                setTimeout(() => {
                    btn.disabled = true;
                    btn.value = "Wird gesendet...";
                }, 1);
            }
        });
    }

    if (messageSection) {
        messageSection.addEventListener("scroll", () => checkScrollPosition());
        if (document.getElementById("message-input")) {
            initializeChat();
        } else {
            canLoadMore = !!document.getElementById("load-more-server-btn");
            scrollToLatestMessage();
        }
    }
    applyChatPrefill();
});
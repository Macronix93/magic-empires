const CHAT_UPDATE_INTERVAL = 5000;

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
    const queryString = window.location.search;
    const urlParams = new URLSearchParams(queryString);
    const chatPartner = urlParams.get("s");

    if (chatPartner !== undefined) {
        updateChat(chatPartner);
    }
}

function updateChat(chatPartner) {
    // Make an AJAX request to update the chat
    let xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
            /** @type {{ html: string, messagesToDelete: array, error: string }} */
            const response = JSON.parse(xhttp.responseText);

            /** @type {HTMLElement} */
            const infoBox = document.querySelector(".info-box");

            if (response.error) {
                infoBox.innerText = response.error;
                infoBox.style.display = "flex";
            } else {
                /** @type {HTMLElement} */
                let newMessageLine = document.getElementById("new-message-line");
                /** @type {HTMLElement} */
                const messageSection = document.getElementById("messages-section");

                if (response.messagesToDelete) {
                    response.messagesToDelete.forEach((item) => {
                        removeChatBubble(item);
                    })
                }

                if (response.html === "") {
                    if (newMessageLine) {
                        messageSection.removeChild(newMessageLine);
                    }
                } else {
                    document.getElementById("messages-section").innerHTML += response.html;

                    scrollToLatestMessage();
                }

                if (messageSection.innerText === "") {
                    infoBox.innerText = "Die Konversation enthält keine Nachrichten!";
                    infoBox.style.display = "flex";
                }
            }
        }
    };
    xhttp.open("GET", "ajax/chat_update.php?action=read&s=" + chatPartner, true);
    xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    xhttp.send();
}

function removeChatBubble(bubbleID) {
    document.getElementById("msg-" + bubbleID).remove();
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
            const infoBox = document.querySelector(".info-box");

            if (response.error) {
                infoBox.innerText = response.error;
                infoBox.style.display = "flex";
            } else {
                const chatBubble = document.getElementById("msg-" + messageID);

                if (chatBubble) {
                    chatBubble.remove();
                }
            }
        })
        .catch(error => {
            console.error("Error:", error);
        });
}

function insertNewChatMessage(e) {
    e.preventDefault();

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
        body: formData
    })
        .then(response => response.json())
        .then(response => {
            const infoBox = document.querySelector(".info-box");
            const currentTextBlock = document.querySelector(".info-box p");
            currentTextBlock.remove();

            if (response.error) {
                const textBlock = document.createElement("p");
                textBlock.innerText = response.error;

                infoBox.style.display = "flex";
                infoBox.append(textBlock);

                // Create a new span element for the counter, if ratelimit was reached
                if (response.counter >= response.messageLimit) {
                    /** @type {HTMLElement} */
                    const counterElement = document.createElement("span");
                    infoBox.appendChild(counterElement);
                    counterElement.id = "counter";
                    counterElement.innerText = startCountdown(response.counter);
                    counterElement.style.marginLeft = "5px";
                }
            } else if (response.html) {
                document.getElementById("messages-section").innerHTML += response.html;
                messageInput.value = "";

                if (infoBox) {
                    infoBox.style.display = "none";
                }

                scrollDown();
            }
        })
        .catch(error => {
            console.error("Error:", error);
        });
}

function conversationDeletionDialog(chatPartnerID, chatPartner) {
    showConfirmationDialog(
        'Willst du die Konversation mit ' + chatPartner + ' wirklich löschen?',
        'Ja',
        'Nein',
        () => {
            deleteConversation('messages.php?action=delete&s=' + chatPartnerID);
        }
    );
}

function deleteConversation(url) {
    window.location.href = url;
}

function initializeChat() {
    // Focus on the message input field
    /** @type {HTMLInputElement} */
    const messageInput = document.getElementById("message-input");
    messageInput.focus();

    messageInput.addEventListener("keypress", e => {
        if (e.key === "Enter" && !e.shiftKey) {
            insertNewChatMessage(e);
        }
    });

    // Add event listener for form submission
    document.getElementById("newmessage").addEventListener("submit", e => {
        insertNewChatMessage(e);
    });

    // Chat update function
    setInterval(() => {
        sendUpdateChatRequest();
    }, CHAT_UPDATE_INTERVAL);

    // Scroll to latest message at the bottom
    scrollDown();
}
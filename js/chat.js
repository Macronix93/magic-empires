const CHAT_UPDATE_INTERVAL = 5000;

function scrollToLatestMessage() {
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
            const response = JSON.parse(xhttp.responseText);

            document.getElementById("messages-section").innerHTML = response.html;

            if (response.hasNewMessages) {
                scrollToLatestMessage();
            }
        }
    };
    xhttp.open("GET", "ajax/chat_update.php?action=read&s=" + chatPartner, true);
    xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    xhttp.send();

    return undefined;
}

function insertNewChatMessage(e) {
    e.preventDefault();

    let messageInput = document.getElementById("message-input");
    let receiver = document.querySelector('input[name="receiver"]').value;
    let text = messageInput.value;

    if (text === "") {
        return;
    }

    let xhttp = new XMLHttpRequest();
    xhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
            try {
                let response = JSON.parse(this.responseText);
                const infoBox = document.querySelector(".info-box");

                if (response.error) {
                    infoBox.innerText = response.error;
                    infoBox.style.display = "flex";
                } else if (response.html) {
                    document.getElementById("messages-section").innerHTML += response.html;
                    messageInput.value = "";

                    if (infoBox) {
                        infoBox.style.display = "none";
                    }

                    scrollToLatestMessage();
                }
            } catch (e) {
                console.error("Error parsing JSON:", e);
            }
        }
    };
    xhttp.open("POST", "ajax/chat_insert.php", true);
    xhttp.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhttp.setRequestHeader("X-Requested-With", "XMLHttpRequest");

    xhttp.send("receiver=" + encodeURIComponent(receiver) + "&text=" + encodeURIComponent(text));
}

function initializeChat() {
    // Focus on the message input field
    document.getElementById("message-input").focus();

    //
    document.getElementById("message-input").addEventListener("keypress", e => {
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
    scrollToLatestMessage();
}
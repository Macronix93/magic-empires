registerAction("switchSuggestionTab", (el) => {
    const filter = el.dataset.tab;
    const targetUrl = `suggestions.php?tab=${filter}&page=1`;

    document.querySelectorAll(".tab .tablinks").forEach(tab => tab.classList.remove("active"));
    el.classList.add("active");

    fetch(targetUrl, {headers: {"X-Requested-With": "XMLHttpRequest"}})
        .then(r => r.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, "text/html");

            const newList = doc.getElementById("suggestions-list-container");
            const currentList = document.getElementById("suggestions-list-container");
            if (newList && currentList) {
                currentList.innerHTML = newList.innerHTML;
            }

            const newPagination = doc.querySelector(".pagination-container");
            const currentPagination = document.querySelector(".pagination-container");
            if (currentPagination) currentPagination.remove();

            if (newPagination && currentList) {
                currentList.parentNode.insertBefore(newPagination, currentList.nextSibling);
            }

            window.history.pushState({}, '', targetUrl);

            if (typeof setup === "function") {
                setup();
            }
        })
        .catch(err => console.error("Fehler beim Tab-Wechsel:", err));
});
registerAction("voteSuggestion", (el) => {
    const sugId = el.dataset.id;
    const type = el.dataset.type; // "up" oder "down"
    const voteBar = el.closest(".suggestion-vote-bar");

    const formData = new URLSearchParams();
    formData.append("id", sugId);
    formData.append("type", type);

    fetch("ajax/suggestion_vote.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Requested-With": "XMLHttpRequest"
        },
        body: formData.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.success && voteBar) {
                const upBtn = voteBar.querySelector(".btn-vote-up");
                const downBtn = voteBar.querySelector(".btn-vote-down");
                if (upBtn) {
                    upBtn.querySelector(".count-up").innerText = data.upvotes;
                    upBtn.classList.toggle("active-vote", data.my_vote === 1);
                }
                if (downBtn) {
                    downBtn.querySelector(".count-down").innerText = data.downvotes;
                    downBtn.classList.toggle("active-vote", data.my_vote === -1);
                }

                const upBox = document.getElementById(`pop_v_up_${sugId}_box`);
                const downBox = document.getElementById(`pop_v_down_${sugId}_box`);
                if (upBox) {
                    const list = upBox.querySelector('.voters-list');
                    if (list) list.innerHTML = data.upvoters;
                }
                if (downBox) {
                    const list = downBox.querySelector('.voters-list');
                    if (list) list.innerHTML = data.downvoters;
                }

                const card = voteBar.closest(".box-container");
                if (card) {
                    card.dataset.score = (data.upvotes - data.downvotes).toString();
                }
            }
        })
        .catch(err => console.error("Vote-Fehler:", err));
});
registerAction("confirmDeleteSuggestion", (el) => {
    const sugId = el.dataset.id;
    showConfirmationDialog(
        "Möchtest du diesen Vorschlag wirklich unwiderruflich löschen?",
        "Ja, löschen",
        "Abbrechen",
        () => {
            window.location.href = "suggestions.php?delete=" + sugId;
        }
    );
});
registerAction("editSuggestionInline", (el, event) => {
    if (event) event.stopPropagation();

    const sugId = el.dataset.id;
    const oldTitle = el.dataset.title;
    const oldContent = el.dataset.content;

    const card = el.closest(".box-container");
    const contentDiv = card.querySelector(".box-content");
    const headerTitle = card.querySelector(".box-header span:first-child");

    if (contentDiv.querySelector("form.js-edit-suggestion-form")) return;

    const formHtml = `
        <form method="POST" class="js-edit-suggestion-form" style="width: 100%; text-align: left; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 4px;">
            <input type="hidden" name="suggestion_id" value="${sugId}">
            <input type="hidden" name="edit_suggestion" value="1">
            
            <label style="font-size: 13px; color: var(--link-color);">Titel:</label><br>
            <input type="text" name="title" value="${oldTitle}" maxlength="100" style="width: 100%; margin-bottom: 10px;" required>
            
            <label style="font-size: 13px; color: var(--link-color);">Beschreibung:</label><br>
            <textarea name="content" rows="6" maxlength="2000" style="width: 100%; margin-bottom: 10px; resize: vertical;" required>${oldContent}</textarea>
            
            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <input type="submit" value="Speichern" style="font-size: 12px; padding: 4px 12px;">
                <input type="button" value="Abbrechen" data-on-click="cancelSuggestionEdit" style="font-size: 12px; padding: 4px 12px;">
            </div>
        </form>
    `;

    headerTitle.innerText = "Vorschlag bearbeiten";
    contentDiv.innerHTML = formHtml;
    el.style.display = "none";
});
registerAction("cancelSuggestionEdit", () => {
    window.location.reload();
});
registerAction("openSuggestionComments", (el) => {
    const sugId = el.dataset.id;

    openOverlay(`ajax/suggestion_comments.php?id=${sugId}`, "Kommentare");
});
registerAction("deleteSuggestionComment", (el) => {
    const sugId = el.dataset.sugid;
    const commentId = el.dataset.id;

    showConfirmationDialog("Möchtest du diesen Kommentar wirklich löschen?", "Ja", "Nein", () => {
        fetch(`ajax/suggestion_comments.php?id=${sugId}&delete_comment=${commentId}`, {
            headers: {"X-Requested-With": "XMLHttpRequest"}
        })
            .then(r => r.text())
            .then(html => {
                const content = document.getElementById("overlay-content-body");
                if (content) content.innerHTML = html;
            });
    });
});
registerAction("submitSuggestionComment", (form, e) => {
    if (e) e.preventDefault();
    const textarea = form.querySelector("#comment-input");
    const text = textarea ? textarea.value.trim() : "";
    if (text === "") return;

    const sugId = form.querySelector('input[name="suggestion_id"]').value;
    const formData = new FormData(form);
    formData.append("add_comment", "1");

    fetch(`ajax/suggestion_comments.php?id=${sugId}`, {
        method: "POST",
        headers: {"X-Requested-With": "XMLHttpRequest"},
        body: formData
    })
        .then(r => r.text())
        .then(html => {
            const content = document.getElementById("overlay-content-body");
            if (content) {
                content.innerHTML = html;

                const container = content.querySelector("[data-total-comments]");
                if (container) {
                    const realCount = container.dataset.totalComments;
                    const countBadge = document.getElementById(`comm_count_${sugId}`);
                    if (countBadge) {
                        countBadge.innerText = `${realCount}`;
                    }
                }

                scrollCommentsToBottom();
            }
        });
});
registerAction("paginateSuggestionComments", (el, e) => {
    if (e) e.preventDefault();

    const sugId = el.dataset.sugid;
    const page = el.dataset.page;

    loadSuggestionCommentsModal(sugId, page);
});

function loadSuggestionCommentsModal(sugId, page = 1, extraParams = "") {
    let url = `ajax/suggestion_comments.php?id=${sugId}&cpage=${page}`;
    if (extraParams) url += `&${extraParams}`;

    fetch(url, {headers: {"X-Requested-With": "XMLHttpRequest"}})
        .then(r => r.text())
        .then(html => {
            const content = document.getElementById("overlay-content-body");
            if (content) content.innerHTML = html;
        });
}

function initSuggestionFormValidation() {
    const form = document.getElementById("new-suggestion-form");
    if (!form) return;

    const titleInput = document.getElementById("new-sug-title");
    const contentInput = document.getElementById("new-sug-content");
    const submitBtn = document.getElementById("btn-submit-suggestion");
    const cooldownNotice = document.getElementById("sug-cooldown-notice");

    function checkValidity() {
        if (form.dataset.cooldown === "true") {
            submitBtn.disabled = true;
            return;
        }

        const tLen = titleInput ? titleInput.value.trim().length : 0;
        const cLen = contentInput ? contentInput.value.trim().length : 0;

        submitBtn.disabled = (tLen < 5 || cLen < 5);
    }

    if (titleInput && contentInput && submitBtn) {
        titleInput.addEventListener("input", checkValidity);
        contentInput.addEventListener("input", checkValidity);

        checkValidity();
    }

    const timerEl = document.getElementById("sug-cooldown-timer");
    if (timerEl) {
        const seconds = parseInt(timerEl.dataset.seconds) || 0;
        if (seconds > 0) {
            setTimeout(() => {
                form.dataset.cooldown = "false";
                if (cooldownNotice) cooldownNotice.style.display = "none";

                checkValidity();
            }, seconds * 1000);
        }
    }
}

function scrollCommentsToBottom() {
    const list = document.getElementById("comments-list");
    if (list) {
        list.scrollTop = list.scrollHeight;

        setTimeout(() => {
            list.scrollTop = list.scrollHeight;
        }, 50);
    }
}

document.addEventListener("DOMContentLoaded", () => {
    initSuggestionFormValidation();

    const overlayBody = document.getElementById("overlay-content-body");
    if (overlayBody) {
        const observer = new MutationObserver(() => {
            scrollCommentsToBottom();
        });
        observer.observe(overlayBody, {childList: true});
    }
});

document.addEventListener("keydown", (e) => {
    if (e.target && e.target.id === "comment-input" && e.key === "Enter" && !e.shiftKey) {
        e.preventDefault();

        const form = e.target.closest("form");
        if (form) {
            form.requestSubmit();
        }
    }
});
registerAction("switchSuggestionTab", (el) => {
    const filter = el.dataset.tab;

    document.querySelectorAll(".tab .tablinks").forEach(tab => tab.classList.remove("active"));
    el.classList.add("active");

    const container = document.getElementById("suggestions-list-container");
    const cards = Array.from(container.querySelectorAll(".box-container"));
    let visibleCount = 0;

    if (filter === "popular") {
        cards.sort((a, b) => (parseInt(b.dataset.score) || 0) - (parseInt(a.dataset.score) || 0));
        cards.forEach(card => {
            container.appendChild(card);
            card.style.display = "";
            visibleCount++;
        });
    } else {
        cards.sort((a, b) => (parseInt(b.dataset.id) || 0) - (parseInt(a.dataset.id) || 0));
        cards.forEach(card => {
            container.appendChild(card);
            const status = card.dataset.status;

            let show = false;
            if (filter === "all") show = true;
            else if (filter === "open" && status === "0") show = true;
            else if (filter === "approved" && status === "1") show = true;
            else if (filter === "done" && status === "2") show = true;

            card.style.display = show ? "" : "none";
            if (show) visibleCount++;
        });
    }

    const emptyBox = document.getElementById("suggestions-empty-box");
    if (emptyBox) {
        emptyBox.style.display = (visibleCount === 0) ? "flex" : "none";
    }
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

                const card = voteBar.closest(".box-container");
                if (card) {
                    card.dataset.score = (data.upvotes - data.downvotes).toString();
                }
            } else if (data.error) {
                showConfirmationDialog(data.error, "Ok", "", () => {
                });
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

document.addEventListener("DOMContentLoaded", () => {
    initSuggestionFormValidation();
});
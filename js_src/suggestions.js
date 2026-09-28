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
registerAction("claimAchievement", (btn) => {
    const achId = btn.dataset.id;
    const reward = parseInt(btn.dataset.reward, 10) || 0;

    const meta = document.getElementById("achievement-coin-meta");
    let currentCoins = meta ? parseInt(meta.dataset.coins, 10) : 0;
    let coinLimit = meta ? parseInt(meta.dataset.limit, 10) : 0;

    if (!coinLimit) {
        const coinRow = Array.from(document.querySelectorAll("#kingdom-info .split-content")).find(row =>
            row.querySelector("img[src*='icon_coins']")
        );
        if (coinRow) {
            const parts = coinRow.querySelector("span")?.innerText.split("/") || [];
            if (parts.length > 1) {
                currentCoins = parseInt(parts[0].replace(/\D/g, ''), 10) || 0;
                coinLimit = parseInt(parts[1].replace(/\D/g, ''), 10) || 0;
            }
        }
    }

    const potentialTotal = currentCoins + reward;
    const overflow = potentialTotal - coinLimit;

    if (coinLimit > 0 && overflow > 0) {
        const lostCoins = Math.min(reward, overflow);
        const actualGain = reward - lostCoins;

        let warningText;
        if (actualGain <= 0) {
            warningText = `Dein Münzspeicher ist bereits voll (${currentCoins.toLocaleString("de-DE")} / ${coinLimit.toLocaleString("de-DE")}).\n
            Wenn du die Belohnung jetzt abholst, verfallen alle +${reward} Münzen!\nMöchtest du sie trotzdem einlösen?`;
        } else {
            warningText = `Achtung: Dein Münzspeicher kann nur noch ${actualGain.toLocaleString("de-DE")} Münzen aufnehmen (Limit: ${coinLimit.toLocaleString("de-DE")}).\n
            Durch das Einsammeln verlierst du ${lostCoins.toLocaleString("de-DE")} Münzen!\nMöchtest du die Belohnung trotzdem abholen?`;
        }

        showConfirmationDialog(
            warningText,
            "Trotzdem abholen",
            "Abbrechen",
            () => {
                executeClaim(btn, achId, reward);
            }
        );
    } else {
        executeClaim(btn, achId, reward);
    }
});

function executeClaim(btn, achId, reward) {
    const wrap = document.getElementById("claim-wrap-" + achId);
    const amountText = wrap ? wrap.querySelector("b") : null;
    const originalAmountText = amountText ? amountText.textContent : `+${reward}`;
    const originalContent = btn.innerHTML;

    btn.style.display = "none";
    if (amountText) {
        amountText.textContent = reward;
    }

    const formData = new URLSearchParams();
    formData.append("id", achId);

    fetch("ajax/claim_achievement.php", {
        method: "POST",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: formData.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                btn.remove();

                const coinRow = Array.from(document.querySelectorAll("#kingdom-info .split-content")).find(row =>
                    row.querySelector("img[src*='icon_coins']")
                );
                if (coinRow) {
                    const coinSpan = coinRow.querySelector("span");
                    if (coinSpan) {
                        const parts = coinSpan.innerText.split("/");
                        const limitText = parts.length > 1 ? " / " + parts[1].trim() : "";
                        const coinLimit = data.coin_limit || (parts.length > 1 ? parseInt(parts[1].replace(/\D/g, ''), 10) : 0);

                        const newFormatted = (typeof formatNumJS === "function")
                            ? formatNumJS(data.new_coins)
                            : Number(data.new_coins).toLocaleString("de-DE");

                        coinSpan.innerText = `${newFormatted}${limitText}`;

                        if (coinLimit > 0 && data.new_coins >= coinLimit) {
                            coinSpan.className = "over-limit";
                        } else {
                            coinSpan.className = "under-limit";
                        }
                    }
                }

                const meta = document.getElementById("achievement-coin-meta");
                if (meta) {
                    meta.dataset.coins = data.new_coins;
                    if (data.coin_limit) meta.dataset.limit = data.coin_limit;
                }

                const achBadges = document.querySelectorAll(".box[data-url='achievements.php'] .msg-badge");
                achBadges.forEach(badge => {
                    if (data.remaining_claims > 0) {
                        badge.innerText = data.remaining_claims > 9 ? "9+" : data.remaining_claims;
                        badge.style.display = "inline-flex";
                    } else {
                        badge.style.display = "none";
                    }
                });

                const mobileDot = document.getElementById("mobile-nav-dot");
                if (mobileDot && data.remaining_claims === 0) {
                    const otherBadges = document.querySelectorAll("#nav-left-menu .msg-badge");
                    const hasOtherNotifications = Array.from(otherBadges).some(b => b.style.display !== "none" && b.innerText.trim() !== "");
                    const hasAlert = mobileDot.dataset.hasAlert === "true";

                    if (!hasOtherNotifications && !hasAlert) {
                        mobileDot.style.display = "none";
                    }
                }

                displayAchievementToast(`Belohnung von +${reward} Münzen erfolgreich abgeholt!`, "passed");
            } else {
                btn.style.display = "";
                btn.disabled = false;
                btn.innerHTML = originalContent;
                if (amountText) {
                    amountText.textContent = originalAmountText;
                }

                displayAchievementToast(data.error || "Diese Belohnung konnte nicht abgeholt werden.", "error");
            }
        })
        .catch(() => {
            btn.style.display = "";
            btn.disabled = false;
            btn.innerHTML = originalContent;
            if (amountText) {
                amountText.textContent = originalAmountText;
            }

            displayAchievementToast("Netzwerkfehler beim Abholen der Belohnung. Bitte erneut versuchen.", "error");
        });
}

function displayAchievementToast(message, type = "passed") {
    const flashContainer = document.getElementById("middle-flash-container");
    if (!flashContainer) return;

    flashContainer.querySelectorAll(".info-box").forEach(el => el.remove());

    const icon = (type === "passed") ? "icon_checked.png" : "icon_error.png";
    const box = document.createElement("div");
    box.className = `info-box event-${type}`;
    box.innerHTML = `<img src="images/icons/${icon}" alt=""><span>${message}</span>`;

    flashContainer.appendChild(box);

    const dismiss = () => {
        if (box.classList.contains("fade-out")) return;

        box.classList.add("fade-out");
        box.addEventListener("animationend", () => box.remove(), {once: true});
    };

    setTimeout(dismiss, 4000);
    box.addEventListener("click", dismiss);
}
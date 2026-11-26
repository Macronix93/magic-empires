registerAction("togglePushNotifications", async (el) => {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        showGameError("Dein Browser unterstützt leider keine Push-Benachrichtigungen.");
        return;
    }

    try {
        el.disabled = true;
        const reg = await navigator.serviceWorker.register('sw.js');
        await navigator.serviceWorker.ready;

        const currentSub = await reg.pushManager.getSubscription();

        if (currentSub) {
            // --- DEACTIVATE ---
            el.innerText = 'Melde ab...';

            const endpoint = currentSub.endpoint;
            await currentSub.unsubscribe();

            await fetch('ajax/push_unsubscribe.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify({endpoint: endpoint})
            });

            localStorage.removeItem('me_push_endpoint');

            window.location.reload();
        } else {
            // --- ACTIVATE ---
            const vapidKey = el.dataset.vapid;
            if (!vapidKey) {
                showGameError("Fehler: Kein VAPID Public Key hinterlegt.");
                el.disabled = false;
                return;
            }

            el.innerText = 'Warte auf Erlaubnis...';

            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                showGameError("Benachrichtigungen wurden blockiert oder abgelehnt.");
                el.disabled = false;
                el.innerText = '🔔 Benachrichtigungen auf diesem Gerät aktivieren';
                return;
            }

            const oldEndpoint = localStorage.getItem('me_push_endpoint');
            if (oldEndpoint) {
                try {
                    await fetch('ajax/push_unsubscribe.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                        body: JSON.stringify({endpoint: oldEndpoint})
                    });
                } catch (e) {
                }
            }

            const newSub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidKey)
            });

            const res = await fetch('ajax/push_subscribe.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify(newSub)
            });

            const result = await res.json();
            if (result.success) {
                localStorage.setItem('me_push_endpoint', newSub.endpoint);
                window.location.reload();
            } else {
                showGameError("Fehler: " + (result.error || "Speichern fehlgeschlagen"));
                el.disabled = false;
                el.innerText = '🔔 Benachrichtigungen auf diesem Gerät aktivieren';
            }
        }
    } catch (err) {
        showGameError('Push Toggle Fehler:' + err.message);
        el.disabled = false;
    }
});
registerAction("switchSettingsTab", (el) => {
    const tabName = el.dataset.tab;

    document.querySelectorAll('.settings-tab').forEach(tab => {
        tab.style.display = "none";
    });

    document.querySelectorAll('#settings-tabs .tablinks').forEach(btn => {
        btn.classList.remove("active");
    });

    const target = document.getElementById("tab_" + tabName);
    if (target) {
        target.style.display = "block";
    }
    el.classList.add("active");

    const url = new URL(window.location);
    url.searchParams.set("tab", tabName);
    window.history.replaceState({}, '', url);
});
registerAction("moveKingdomOrder", (btn) => {
    const dir = parseInt(btn.dataset.dir, 10);
    const item = btn.closest(".kingdom-sort-item");
    const list = document.getElementById("kingdom-sort-list");
    if (!item || !list) return;

    if (dir === -1 && item.previousElementSibling) {
        list.insertBefore(item, item.previousElementSibling);
    } else if (dir === 1 && item.nextElementSibling) {
        list.insertBefore(item.nextElementSibling, item);
    }

    updateKingdomSortNumbers();
});

document.addEventListener("input", (e) => {
    if (e.target && e.target.classList.contains("js-pagesize-input")) {
        const input = e.target;
        let raw = input.value.replace(/\D/g, '');

        if (raw.length > 1 && raw.startsWith('0')) {
            raw = raw.replace(/^0+/, '');
        }

        if (raw !== '') {
            let num = parseInt(raw, 10);
            let max = parseInt(input.dataset.max, 10) || 30;

            if (num > max) {
                num = max;
            }
            raw = num.toString();
        }

        input.value = raw;
    }
});

function updateKingdomSortNumbers(floatingItem = null, placeholder = null) {
    const list = document.getElementById("kingdom-sort-list");
    if (!list) return;

    let pos = 1;
    Array.from(list.children).forEach(child => {
        if (child === floatingItem) return;
        if (child === placeholder) {
            if (floatingItem) {
                const fPos = floatingItem.querySelector(".js-sort-pos");
                if (fPos) fPos.textContent = "#" + pos;
            }

            pos++;
        } else if (child.classList.contains("kingdom-sort-item")) {
            const posEl = child.querySelector(".js-sort-pos");
            if (posEl) posEl.textContent = "#" + pos;

            pos++;
        }
    });
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

async function initPushStatus() {
    const btn = document.getElementById("btn-push-toggle");
    if (!btn || !('serviceWorker' in navigator) || !('PushManager' in window)) return;

    try {
        const reg = await navigator.serviceWorker.getRegistration('sw.js');
        if (!reg) return;

        const sub = await reg.pushManager.getSubscription();
        const savedEndpoint = localStorage.getItem('me_push_endpoint');

        if (Notification.permission !== 'granted' || !sub) {
            btn.innerText = "🔔 Benachrichtigungen auf diesem Gerät aktivieren";

            if (savedEndpoint) {
                localStorage.removeItem('me_push_endpoint');

                if (sub) {
                    try {
                        await sub.unsubscribe();
                    } catch (e) {
                    }
                }

                await fetch('ajax/push_unsubscribe.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                    body: JSON.stringify({endpoint: savedEndpoint})
                });

                window.location.reload();
            }
        } else {
            btn.innerText = "🔕 Benachrichtigungen auf diesem Gerät deaktivieren";

            localStorage.setItem('me_push_endpoint', sub.endpoint);
        }
    } catch (e) {
        showGameError("Fehler bei Status-Prüfung:" + e);
    }
}

document.addEventListener("blur", (e) => {
    if (e.target && e.target.classList.contains("js-pagesize-input")) {
        const input = e.target;
        const min = parseInt(input.dataset.min, 10) || 5;
        const max = parseInt(input.dataset.max, 10) || 30;
        const def = parseInt(input.dataset.default, 10) || 7;

        let val = parseInt(input.value, 10);

        if (isNaN(val) || input.value.trim() === '') {
            input.value = def;
        } else if (val < min) {
            input.value = min;
        } else if (val > max) {
            input.value = max;
        }
    }
}, true);

document.addEventListener("DOMContentLoaded", () => {
    initPushStatus().then(_ => {
    });

    const avatarForm = document.getElementById("avatar-upload-form");
    if (avatarForm) {
        avatarForm.addEventListener("submit", function () {
            const btn = document.getElementById("btn-submit-avatar");
            const fileInput = document.getElementById("image");

            if (fileInput && fileInput.files.length > 0) {
                if (btn) {
                    setTimeout(() => {
                        btn.disabled = true;
                        btn.value = "Wird geprüft...";
                    }, 1);
                }
            }
        });
    }

    const genderRadios = document.querySelectorAll('input[name="gender"]');
    const titleSelect = document.querySelector('select[name="active_title_id"]');

    if (genderRadios.length > 0 && titleSelect) {
        const updateTitleOptions = (gender) => {
            titleSelect.querySelectorAll('option').forEach(opt => {
                const newText = (gender === 'f') ? opt.dataset.titleF : opt.dataset.titleM;
                if (newText) {
                    opt.textContent = newText;
                }
            });
        };

        genderRadios.forEach(radio => {
            radio.addEventListener("change", () => {
                if (radio.checked) {
                    updateTitleOptions(radio.value);
                }
            });
        });
    }

    const disableThemeCheckbox = document.getElementById("disable_special_theme_checkbox");
    if (disableThemeCheckbox) {
        disableThemeCheckbox.addEventListener("change", function () {
            const snowWrap = document.getElementById("snow_setting_wrap");

            if (snowWrap) {
                snowWrap.style.display = this.checked ? "none" : "block";
            }
        });
    }

    const sortList = document.getElementById("kingdom-sort-list");
    if (!sortList) return;

    sortList.addEventListener("dragstart", (e) => e.preventDefault());

    let draggedItem = null;
    let placeholder = null;
    let offsetX = 0;
    let offsetY = 0;

    const onPointerMove = (e) => {
        if (!draggedItem) return;

        draggedItem.style.left = (e.clientX - offsetX) + "px";
        draggedItem.style.top = (e.clientY - offsetY) + "px";

        const siblings = Array.from(sortList.querySelectorAll(".kingdom-sort-item:not(.is-dragging)"));
        let nextSibling = null;

        for (const sibling of siblings) {
            const box = sibling.getBoundingClientRect();
            const boxCenterY = box.top + (box.height / 2);
            if (e.clientY < boxCenterY) {
                nextSibling = sibling;
                break;
            }
        }

        if (nextSibling) {
            sortList.insertBefore(placeholder, nextSibling);
        } else {
            sortList.appendChild(placeholder);
        }

        updateKingdomSortNumbers(draggedItem, placeholder);
    };

    const onPointerUp = () => {
        if (!draggedItem) return;

        window.removeEventListener("pointermove", onPointerMove);
        window.removeEventListener("pointerup", onPointerUp);
        window.removeEventListener("pointercancel", onPointerUp);

        if (placeholder && placeholder.parentNode) {
            sortList.insertBefore(draggedItem, placeholder);
            placeholder.remove();
        }

        draggedItem.classList.remove("is-dragging");
        draggedItem.style.position = "";
        draggedItem.style.width = "";
        draggedItem.style.height = "";
        draggedItem.style.left = "";
        draggedItem.style.top = "";
        document.body.style.cursor = "";

        draggedItem = null;
        placeholder = null;
        updateKingdomSortNumbers();
    };

    sortList.addEventListener("pointerdown", (e) => {
        if (e.target.closest(".btn-stepper") || (e.pointerType === "mouse" && e.button !== 0)) return;

        const item = e.target.closest(".kingdom-sort-item");
        if (!item) return;

        e.preventDefault();
        draggedItem = item;

        const rect = item.getBoundingClientRect();
        offsetX = e.clientX - rect.left;
        offsetY = e.clientY - rect.top;

        placeholder = document.createElement("div");
        placeholder.className = "kingdom-sort-placeholder";
        placeholder.style.height = rect.height + "px";
        sortList.insertBefore(placeholder, item);

        item.classList.add("is-dragging");
        item.style.position = "fixed";
        item.style.width = rect.width + "px";
        item.style.height = rect.height + "px";
        item.style.left = rect.left + "px";
        item.style.top = rect.top + "px";
        document.body.style.cursor = "grabbing";

        window.addEventListener("pointermove", onPointerMove);
        window.addEventListener("pointerup", onPointerUp);
        window.addEventListener("pointercancel", onPointerUp);
    });
});
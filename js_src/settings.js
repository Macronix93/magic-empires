registerAction("togglePushNotifications", async (el) => {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        alert('Dein Browser unterstützt leider keine Push-Benachrichtigungen.');
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
                alert('Fehler: Kein VAPID Public Key hinterlegt.');
                el.disabled = false;
                return;
            }

            el.innerText = 'Warte auf Erlaubnis...';

            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                alert('Benachrichtigungen wurden blockiert oder abgelehnt.');
                el.disabled = false;
                el.innerText = '🔔 Benachrichtigungen auf diesem Gerät aktivieren';
                el.classList.remove("push-notifications-disable");
                el.classList.add("push-notifications-enable");
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
                alert('Fehler: ' + (result.error || 'Speichern fehlgeschlagen'));
                el.disabled = false;
                el.innerText = '🔔 Benachrichtigungen auf diesem Gerät aktivieren';
                el.classList.remove("push-notifications-disable");
                el.classList.add("push-notifications-enable");
            }
        }
    } catch (err) {
        console.error('Push Toggle Fehler:', err);
        alert('Vorgang fehlgeschlagen: ' + err.message);
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

    document.cookie = "me_settings_tab=" + tabName + "; path=/; max-age=31536000; SameSite=Lax";

    const url = new URL(window.location);
    url.searchParams.set("tab", tabName);
    window.history.replaceState({}, '', url);
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
            btn.classList.remove("push-notifications-disable");
            btn.classList.add("push-notifications-enable");

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
            btn.classList.remove("push-notifications-enable");
            btn.classList.add("push-notifications-disable");

            localStorage.setItem('me_push_endpoint', sub.endpoint);
        }
    } catch (e) {
        console.error("Fehler bei Status-Prüfung:", e);
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
});
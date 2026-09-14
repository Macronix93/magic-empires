self.addEventListener("push", function (event) {
    if (!event.data) return;

    let payload;
    try {
        payload = event.data.json();
    } catch (e) {
        payload = {title: "Magic Empires", body: event.data.text()};
    }

    event.waitUntil(
        clients.matchAll({type: "window", includeUncontrolled: true}).then(function (clientList) {
            const isUserActive = clientList.some(client => client.focused);

            if (isUserActive) {
                return;
            }

            const options = {
                body: payload.body || "Wichtige Meldung!",
                icon: payload.icon || "images/icons/icon_town.png",
                badge: "images/icons/icon_castle.png",
                vibrate: [200, 100, 200],
                tag: payload.tag || "game-notification",
                renotify: true,
                timestamp: Date.now(),
                data: {
                    url: payload.url || "overview.php"
                }
            };

            return self.registration.showNotification(payload.title || "Magic Empires", options);
        })
    );
});

self.addEventListener("notificationclick", function (event) {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data.url)
    );
});
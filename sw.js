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

            const uniqueTag = isUserActive
                ? "active-muted"
                : "notif-" + Date.now() + "-" + Math.floor(Math.random() * 1000);

            const options = {
                body: payload.body || "Wichtige Meldung!",
                icon: payload.icon || "images/icons/icon_town.png",
                badge: "images/icons/icon_castle.png",
                vibrate: isUserActive ? [] : [200, 100, 200],
                silent: isUserActive,
                tag: uniqueTag,
                data: {
                    url: payload.url || "overview.php"
                }
            };

            return self.registration.showNotification(payload.title || "Magic Empires", options).then(() => {
                if (isUserActive) {
                    return self.registration.getNotifications({tag: "active-muted"}).then(notifications => {
                        notifications.forEach(n => n.close());
                    });
                }
            });
        })
    );
});

self.addEventListener("notificationclick", function (event) {
    event.notification.close();
    event.waitUntil(
        clients.openWindow(event.notification.data.url)
    );
});
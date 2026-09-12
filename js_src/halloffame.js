let prefetching = new Set();
let activeCategory = null;

registerAction("filterHallOfFame", (el) => {
    const category = el.dataset.category;
    switchHofTab(category, el);
});

function switchHofTab(category, tabBtn) {
    if (activeCategory === category) return;
    activeCategory = category;

    const allButtons = document.querySelectorAll('#hof-tabs .tablinks');
    allButtons.forEach(tab => tab.classList.remove("active"));
    if (tabBtn) tabBtn.classList.add("active");

    const container = document.getElementById("hof-container");
    const target = document.getElementById("hof_content_" + category);
    if (!target || !container) return;

    const currentVisible = container.querySelector(".js-hof-tab[data-active='true']");

    if (target.dataset.loaded === "true") {
        if (currentVisible) {
            currentVisible.style.display = "none";
            currentVisible.style.opacity = "";
            currentVisible.removeAttribute("data-active");
        }
        container.style.minHeight = "";
        target.style.display = "block";
        target.setAttribute("data-active", "true");
        return;
    }

    if (currentVisible && currentVisible.offsetHeight > 0) {
        container.style.minHeight = currentVisible.offsetHeight + "px";
        currentVisible.style.opacity = "0.4";
    }

    loadHofTab(category, (html) => {
        if (activeCategory !== category) return;

        if (currentVisible) {
            currentVisible.style.display = "none";
            currentVisible.style.opacity = "";
            currentVisible.removeAttribute("data-active");
        }

        target.innerHTML = html;
        target.dataset.loaded = "true";
        target.style.display = "block";
        target.setAttribute("data-active", "true");
        container.style.minHeight = "";

        if (typeof setup === "function") {
            setup();
        }
    });
}

function loadHofTab(category, callback) {
    fetch(`ajax/halloffame_tab.php?cat=${encodeURIComponent(category)}`, {
        headers: {"X-Requested-With": "XMLHttpRequest"}
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                callback(data.html);
            } else {
                callback(`<div class="info-box event-error" style="margin-top: 20px;"><span>${data.error || 'Fehler beim Laden.'}</span></div>`);
            }
        })
        .catch(err => {
            console.error("Hall of Fame Fehler:", err);
            callback("<div class='info-box event-error' style='margin-top: 20px;'><span>Netzwerkfehler beim Laden der Rangliste.</span></div>");
        });
}

function prefetchHofTab(category) {
    const target = document.getElementById("hof_content_" + category);
    if (!target || target.dataset.loaded === "true" || prefetching.has(category)) return;

    prefetching.add(category);

    loadHofTab(category, (html) => {
        target.innerHTML = html;
        target.dataset.loaded = "true";
        prefetching.delete(category);

        if (typeof setup === "function") {
            setup();
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    const activeTab = document.querySelector("#hof-container .js-hof-tab[data-loaded='true']");
    if (activeTab) {
        activeTab.setAttribute("data-active", "true");
        activeCategory = activeTab.id.replace("hof_content_", "");
    }

    document.querySelectorAll('#hof-tabs .tablinks').forEach(btn => {
        btn.addEventListener("mouseenter", () => {
            const cat = btn.dataset.category;
            if (cat) {
                prefetchHofTab(cat);
            }
        });
    });
});
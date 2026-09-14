registerAction("switchRankingTab", (el) => {
    const tabName = el.dataset.tab;

    document.querySelectorAll('.tablinks').forEach(tab => tab.classList.remove("active"));
    document.querySelectorAll('.js-ranking-tab').forEach(content => content.style.display = "none");

    el.classList.add("active");
    const target = document.getElementById("ranking_" + tabName);
    if (target) {
        target.style.display = "block";
    }

    sessionStorage.setItem("active_ranking_tab", tabName);

    const url = new URL(window.location);
    url.searchParams.set("tab", tabName);
    if (url.searchParams.has("currentpage")) {
        url.searchParams.delete("currentpage");
    }
    window.history.replaceState({}, '', url);
});

document.addEventListener("DOMContentLoaded", () => {
    const urlParams = new URLSearchParams(window.location.search);
    const tabFromUrl = urlParams.get("tab");

    const activeTab = tabFromUrl || sessionStorage.getItem("active_ranking_tab") || "players";

    const tabBtn = document.querySelector(`[data-tab="${activeTab}"]`);
    if (tabBtn && !tabBtn.classList.contains("active")) {
        tabBtn.click();
    }
});
registerAction("switchRankingTab", (el) => {
    const tabName = el.dataset.tab;

    document.querySelectorAll('.tablinks').forEach(tab => tab.classList.remove("active"));
    document.querySelectorAll('.js-ranking-tab').forEach(content => content.style.display = "none");

    el.classList.add("active");
    document.getElementById("ranking_" + tabName).style.display = "block";

    sessionStorage.setItem("active_ranking_tab", tabName);
});

document.addEventListener("DOMContentLoaded", () => {
    const urlParams = new URLSearchParams(window.location.search);
    const hasTabParam = urlParams.has("tab");
    const hasPageParam = urlParams.has("currentpage");

    if (!hasTabParam && !hasPageParam) {
        sessionStorage.removeItem("active_ranking_tab");
    }

    const savedTab = sessionStorage.getItem("active_ranking_tab") || "players";

    const tabBtn = document.querySelector(`[data-tab="${savedTab}"]`);
    if (tabBtn) {
        tabBtn.click();
    }
});
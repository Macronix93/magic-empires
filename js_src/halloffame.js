document.addEventListener("DOMContentLoaded", () => {
    registerAction("filterHallOfFame", (el) => {
        const category = el.dataset.category;

        document.querySelectorAll('.tablinks').forEach(tab => tab.classList.remove("active"));
        el.classList.add("active");

        document.querySelectorAll('.js-hof-tab').forEach(content => {
            content.style.display = "none";
        });

        const target = document.getElementById("hof_content_" + category);
        if (target) {
            target.style.display = "block";
        }
    });
});
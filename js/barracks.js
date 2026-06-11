registerAction("filterBarracks", (el) => {
    const category = el.dataset.category;
    const allRows = document.querySelectorAll(".unit-row");
    const allTabs = document.querySelectorAll(".tablinks");

    allTabs.forEach(tab => tab.classList.remove("active"));
    el.classList.add("active");

    allRows.forEach(row => {
        if (row.dataset.unitCategory === category) {
            row.style.display = "table-row";
        } else {
            row.style.display = "none";
        }
    });

    const url = new URL(window.location);
    url.searchParams.set("cat", category);
    window.history.replaceState({}, '', url);

    document.querySelectorAll('form[action="barracks.php"]').forEach(form => {
        let catInput = form.querySelector('input[name="cat"]');

        if (catInput) {
            catInput.value = category;
        }
    });
});
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
    if (e.target.classList.contains("js-pagesize-input")) {
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

document.addEventListener("blur", (e) => {
    if (e.target.classList.contains("js-pagesize-input")) {
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